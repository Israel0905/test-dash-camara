<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8809}';
    protected $description = 'Servidor JT/T 808-2019 (PHP 8.x) – MDVR / ULV';

    /** @var array<int,string> */
    private array $buffers = [];

    /** @var array<int,string> */
    private array $sessions = [];

    /** @var array<string,string> */
    private array $authCodes = [];

    public function handle(): void
    {
        $port = (int)$this->option('port');

        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($server, '0.0.0.0', $port)) {
            $this->error('[FATAL] No se pudo bindear el puerto');
            return;
        }

        socket_listen($server);
        $this->info("[BOOT] JT/T808-2019 escuchando en puerto {$port}");

        $clients = [$server];

        while (true) {
            $read = $clients;
            $write = $except = null;

            if (@socket_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($read as $sock) {

                if ($sock === $server) {
                    $client = socket_accept($server);
                    if ($client === false) {
                        continue;
                    }

                    $id = spl_object_id($client);
                    $clients[] = $client;
                    $this->buffers[$id]  = '';
                    $this->sessions[$id] = 'NEW';

                    $this->warn("[CONN] Cámara conectada (ID {$id})");
                    continue;
                }

                $data = @socket_read($sock, 4096, PHP_BINARY_READ);
                if ($data === '' || $data === false) {
                    $this->disconnect($sock, $clients);
                    continue;
                }

                $id = spl_object_id($sock);
                $this->buffers[$id] .= $data;

                $this->consumeFrames($sock);
            }
        }
    }

    private function disconnect($sock, array &$clients): void
    {
        $id = spl_object_id($sock);
        unset($this->buffers[$id], $this->sessions[$id]);

        @socket_close($sock);
        $clients = array_values(array_filter($clients, fn($s) => $s !== $sock));

        $this->error("[DESC] Cámara desconectada (ID {$id})");
    }

    /* ================= FRAME PARSER ================= */

    private function consumeFrames($sock): void
    {
        $id = spl_object_id($sock);
        $buffer = $this->buffers[$id];

        while (true) {
            $start = strpos($buffer, "\x7E");
            if ($start === false) break;

            $end = strpos($buffer, "\x7E", $start + 1);
            if ($end === false) break;

            $raw = substr($buffer, $start + 1, $end - $start - 1);
            $buffer = substr($buffer, $end + 1);

            $this->processFrame($sock, $raw);
        }

        $this->buffers[$id] = $buffer;
    }

    private function processFrame($sock, string $rawFrame): void
    {
        $this->line('[RAW IN ] ' . strtoupper(bin2hex($rawFrame)));

        $data = $this->unescape($rawFrame);
        if (count($data) < 12) {
            $this->warn('[DROP] Frame demasiado corto');
            return;
        }

        // checksum
        $recvCs = array_pop($data);
        $calcCs = 0;
        foreach ($data as $b) $calcCs ^= $b;

        if ($recvCs !== $calcCs) {
            $this->warn('[DROP] Checksum inválido');
            return;
        }

        /*
         * JT/T808-2019 HEADER (12 bytes)
         * 0-1  MsgID
         * 2-3  Attr
         * 4-9  Terminal Phone (BCD 6 bytes)
         * 10-11 Serial
         */

        $msgId  = ($data[0] << 8) | $data[1];
        $attr   = ($data[2] << 8) | $data[3];
        $phone  = array_slice($data, 4, 6);
        $serial = ($data[10] << 8) | $data[11];

        $termId = $this->bcdToString($phone);
        $sockId = spl_object_id($sock);

        $this->info(sprintf(
            '[RECV] Sock=%d Msg=0x%04X Serial=%d Term=%s State=%s',
            $sockId,
            $msgId,
            $serial,
            $termId,
            $this->sessions[$sockId] ?? 'NONE'
        ));

        switch ($msgId) {
            case 0x0100:
                $this->handleRegister($sock, $phone, $termId);
                break;

            case 0x0102:
                $this->handleAuth($sock, $phone);
                break;

            default:
                $this->handleGeneral($sock, $phone, $serial, $msgId);
        }
    }

    /* ================= HANDLERS ================= */

    private function handleRegister($sock, array $phone, string $termId): void
    {
        $this->sessions[spl_object_id($sock)] = 'REGISTERED';

        if (!isset($this->authCodes[$termId])) {
            // ULV real: auth = terminal ID
            $this->authCodes[$termId] = $termId;
        }

        // Result(1) + AuthCode(N)
        $body = [0x00];
        foreach (str_split($this->authCodes[$termId]) as $c) {
            $body[] = ord($c);
        }

        $this->info('[SEND] 0x8100 Registro OK');
        $this->sendPacket($sock, 0x8100, $phone, $body);
    }

    private function handleAuth($sock, array $phone): void
    {
        $this->sessions[spl_object_id($sock)] = 'ONLINE';

        // Common response OK
        $body = [0x00];

        $this->info('[SEND] 0x8001 Auth OK');
        $this->sendPacket($sock, 0x8001, $phone, $body);
    }

    private function handleGeneral($sock, array $phone, int $serial, int $msgId): void
    {
        if (($this->sessions[spl_object_id($sock)] ?? '') !== 'ONLINE') {
            $this->warn('[DROP] Mensaje fuera de sesión ONLINE');
            return;
        }

        $body = [
            ($serial >> 8) & 0xFF,
            $serial & 0xFF,
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            0x00
        ];

        $this->sendPacket($sock, 0x8001, $phone, $body);
    }

    /* ================= SEND ================= */

    private function sendPacket($sock, int $msgId, array $phone, array $body): void
    {
        static $srvSerial = 1;

        $attr = count($body);

        $packet = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($attr >> 8) & 0xFF,
            $attr & 0xFF,
            ...$phone,
            ($srvSerial >> 8) & 0xFF,
            $srvSerial & 0xFF,
            ...$body
        ];

        $srvSerial = ($srvSerial + 1) & 0xFFFF;

        $cs = 0;
        foreach ($packet as $b) $cs ^= $b;
        $packet[] = $cs;

        $escaped = [];
        foreach ($packet as $b) {
            if ($b === 0x7E) {
                $escaped[] = 0x7D;
                $escaped[] = 0x02;
            } elseif ($b === 0x7D) {
                $escaped[] = 0x7D;
                $escaped[] = 0x01;
            } else {
                $escaped[] = $b;
            }
        }

        $frame = array_merge([0x7E], $escaped, [0x7E]);
        $out = pack('C*', ...$frame);

        $this->line('[RAW OUT] ' . strtoupper(bin2hex($out)));
        @socket_write($sock, $out);
    }

    /* ================= UTILS ================= */

    private function unescape(string $raw): array
    {
        $bytes = array_values(unpack('C*', $raw));
        $out = [];

        for ($i = 0; $i < count($bytes); $i++) {
            if ($bytes[$i] === 0x7D && isset($bytes[$i + 1])) {
                $out[] = ($bytes[++$i] === 0x01) ? 0x7D : 0x7E;
            } else {
                $out[] = $bytes[$i];
            }
        }

        return $out;
    }

    private function bcdToString(array $bcd): string
    {
        $out = '';
        foreach ($bcd as $b) {
            $out .= sprintf('%02X', $b);
        }
        return ltrim($out, '0');
    }
}
