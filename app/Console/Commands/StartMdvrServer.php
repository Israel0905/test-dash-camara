<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8809}';
    protected $description = 'Servidor JT/T 808-2019 ULV estable (PHP 8.x)';

    private array $buffers = [];
    private array $sessions = [];
    private array $authCodes = [];

    public function handle(): void
    {
        $port = (int)$this->option('port');

        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($server, '0.0.0.0', $port)) {
            $this->error('No se pudo bindear el puerto');
            return;
        }

        socket_listen($server);
        $this->info("[BOOT] JT/T808 escuchando en puerto {$port}");

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
                    $this->buffers[$id] = '';
                    $this->sessions[$id] = 'NEW';

                    $this->info("[CONN] Cámara conectada (ID {$id})");
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
        $this->warn("[DESC] Cámara desconectada (ID {$id})");
    }

    /* ================= FRAME ================= */

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

    private function processFrame($sock, string $raw): void
    {
        $this->line('[RAW IN ] ' . strtoupper(bin2hex($raw)));

        $data = $this->unescape($raw);
        if (count($data) < 18) return;

        $checksum = array_pop($data);
        $calc = 0;
        foreach ($data as $b) $calc ^= $b;
        if ($checksum !== $calc) return;

        $msgId = ($data[0] << 8) | $data[1];
        $phone = array_slice($data, 5, 10);
        $serial = ($data[15] << 8) | $data[16];
        $termId = ltrim(bin2hex(pack('C*', ...$phone)), '0');

        $sid = spl_object_id($sock);

        $this->info(sprintf(
            '[RECV] Sock=%d Msg=0x%04X Serial=%d Term=%s State=%s',
            $sid,
            $msgId,
            $serial,
            $termId,
            $this->sessions[$sid] ?? 'NONE'
        ));

        if ($msgId === 0x0100) {
            $this->handleRegister($sock, $phone, $serial, $termId);
        } elseif ($msgId === 0x0102) {
            $this->handleAuth($sock, $phone, $serial);
        }
    }

    /* ================= HANDLERS ================= */

    private function handleRegister($sock, array $phone, int $recvSerial, string $termId): void
    {
        $this->sessions[spl_object_id($sock)] = 'REGISTERED';

        if (!isset($this->authCodes[$termId])) {
            $this->authCodes[$termId] = $termId; // ULV real
        }

        $body = [
            ($recvSerial >> 8) & 0xFF,
            $recvSerial & 0xFF,
            0x00
        ];

        foreach (str_split($this->authCodes[$termId]) as $c) {
            $body[] = ord($c);
        }

        $this->info('[SEND] 0x8100 Registro OK');
        $this->sendPacket($sock, 0x8100, $phone, $body);
    }

    private function handleAuth($sock, array $phone, int $recvSerial): void
    {
        $this->sessions[spl_object_id($sock)] = 'ONLINE';

        $body = [
            ($recvSerial >> 8) & 0xFF,
            $recvSerial & 0xFF,
            0x00
        ];

        $this->info('[SEND] 0x8001 Auth OK');
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
            0x01,
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
}
