<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8809}';
    protected $description = 'Servidor JT/T 808-2019 ULV (PHP 8.x estable con debug)';

    /** @var array<int,string> */
    private array $buffers = [];

    /** @var array<int,string> */
    private array $sessions = [];

    /** @var array<string,string> */
    private array $authCodes = [];

    public function handle(): void
    {
        $port = (int) $this->option('port');

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
                $this->error('[SOCKET] socket_select falló');
                continue;
            }

            foreach ($read as $sock) {
                if ($sock === $server) {
                    $client = socket_accept($server);
                    if ($client === false) {
                        $this->warn('[SOCKET] accept falló');
                        continue;
                    }

                    $id = spl_object_id($client);
                    $clients[] = $client;
                    $this->buffers[$id] = '';
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

    /* ===================== FRAME HANDLING ===================== */

    private function consumeFrames($sock): void
    {
        $id = spl_object_id($sock);
        $buffer = $this->buffers[$id];

        // MODO 1: JT/T clásico con delimitador 0x7E
        if (strpos($buffer, "\x7E") !== false) {
            while (true) {
                $start = strpos($buffer, "\x7E");
                if ($start === false) {
                    $this->buffers[$id] = $buffer;
                    return;
                }

                $end = strpos($buffer, "\x7E", $start + 1);
                if ($end === false) {
                    $this->buffers[$id] = $buffer;
                    return;
                }

                $rawFrame = substr($buffer, $start + 1, $end - $start - 1);
                $buffer = substr($buffer, $end + 1);

                $this->processFrame($sock, $rawFrame);
            }
        }

        // MODO 2: ULV TCP sin 0x7E (frame completo)
        $this->buffers[$id] = '';
        $this->processFrame($sock, $buffer);
    }

    private function processFrame($sock, string $rawFrame): void
    {
        $this->line('[RAW IN ] ' . strtoupper(bin2hex($rawFrame)));

        $data = $this->unescape($rawFrame);
        if (count($data) < 18) {
            $this->warn('[DROP] Frame demasiado corto');
            return;
        }

        $recvChecksum = array_pop($data);
        $calcChecksum = 0;
        foreach ($data as $b) {
            $calcChecksum ^= $b;
        }

        if ($recvChecksum !== $calcChecksum) {
            $this->warn(sprintf(
                '[DROP] Checksum inválido recv=%02X calc=%02X',
                $recvChecksum,
                $calcChecksum
            ));
            return;
        }

        $msgId  = ($data[0] << 8) | $data[1];
        $phone  = array_slice($data, 5, 10);
        $serial = ($data[15] << 8) | $data[16];
        $termId = bin2hex(pack('C*', ...$phone));
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
                $this->handleRegister($sock, $phone, $serial, $termId);
                break;

            case 0x0102:
                $this->handleAuth($sock, $phone, $serial);
                break;

            default:
                $this->handleGeneral($sock, $phone, $serial, $msgId);
        }
    }

    /* ===================== HANDLERS ===================== */

    private function handleRegister($sock, array $phone, int $serial, string $termId): void
    {
        $this->sessions[spl_object_id($sock)] = 'REGISTERED';

        if (!isset($this->authCodes[$termId])) {
            $this->authCodes[$termId] = 'ULV';
        }

        $body = [
            ($serial >> 8) & 0xFF,
            $serial & 0xFF,
            0x00,
            ...array_map('ord', str_split($this->authCodes[$termId]))
        ];

        $this->info('[SEND] 0x8100 Registro OK');
        $this->sendPacket($sock, 0x8100, $phone, $body);
    }

    private function handleAuth($sock, array $phone, int $serial): void
    {
        $this->sessions[spl_object_id($sock)] = 'ONLINE';

        $body = [
            ($serial >> 8) & 0xFF,
            $serial & 0xFF,
            0x00
        ];

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

    /* ===================== SEND ===================== */

    private function sendPacket($sock, int $msgId, array $phone, array $body): void
    {
        static $srvSerial = 1;

        $attr = 0x4000 | count($body);

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
        foreach ($packet as $b) {
            $cs ^= $b;
        }
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
