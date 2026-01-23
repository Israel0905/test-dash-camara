<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8809}';
    protected $description = 'Servidor JT/T808-2019 ULV MDVR (PHP 8.x estable)';

    private array $buffers = [];
    private array $sessions = [];
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
                    if ($client === false) continue;

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

            $raw = substr($buffer, $start + 1, $end - $start - 1);
            $buffer = substr($buffer, $end + 1);

            $this->processFrame($sock, $raw);
        }
    }

    private function processFrame($sock, string $raw): void
    {
        $this->line('[RAW IN ] ' . strtoupper(bin2hex($raw)));

        $data = $this->unescape($raw);
        if (count($data) < 20) return;

        $recvCs = array_pop($data);
        $calcCs = 0;
        foreach ($data as $b) $calcCs ^= $b;
        if ($recvCs !== $calcCs) {
            $this->warn('[DROP] Checksum inválido');
            return;
        }

        $msgId = ($data[0] << 8) | $data[1];
        $attr  = ($data[2] << 8) | $data[3];
        $is2019 = ($attr & 0x4000) !== 0;

        if ($is2019) {
            // Version 2019: [MsgId:2][Attr:2][Ver:1][TermId:10][Serial:2]...
            $phoneBcd = array_slice($data, 5, 10);
            $serial   = ($data[15] << 8) | $data[16];
        } else {
            // Version 2013: [MsgId:2][Attr:2][TermId:6][Serial:2]...
            $phoneBcd = array_slice($data, 4, 6);
            $serial   = ($data[10] << 8) | $data[11];
        }
        
        $this->line('[DEBUG] PhoneBCD Hex: ' . strtoupper(bin2hex(pack('C*', ...$phoneBcd))));

        $termId = $this->bcdToString($phoneBcd);

        $sid = spl_object_id($sock);

        $this->info(sprintf(
            '[RECV] Sock=%d Msg=0x%04X Serial=%d Term=%s State=%s Ver=%s',
            $sid,
            $msgId,
            $serial,
            $termId,
            $this->sessions[$sid] ?? 'NONE',
            $is2019 ? '2019' : '2013'
        ));

        if ($msgId === 0x0100) {
            $this->handleRegister($sock, $phoneBcd, $serial, $termId, $data[4] ?? 1, $is2019);
        } elseif ($msgId === 0x0102) {
            $this->handleAuth($sock, $phoneBcd, $serial, $data[4] ?? 1, $is2019);
        } else {
            $this->handleGeneral($sock, $phoneBcd, $serial, $msgId, $data[4] ?? 1, $is2019);
        }
    }

    /* ===================== HANDLERS ===================== */

    private function handleRegister($sock, array $phoneBcd, int $serial, string $termId, int $ver, bool $is2019): void
    {
        $sid = spl_object_id($sock);
        $this->sessions[$sid] = 'REGISTERED';

        if (!isset($this->authCodes[$termId])) {
            $this->authCodes[$termId] = '000000'; // Default Auth Code
        }

        $body = [
            ($serial >> 8) & 0xFF,
            $serial & 0xFF,
            0x00 // OK
        ];

        foreach (str_split($this->authCodes[$termId]) as $c) {
            $body[] = ord($c);
        }

        $this->info('[SEND] 0x8100 Registro OK');
        $this->sendPacket($sock, 0x8100, $phoneBcd, $body, $ver, $is2019);
    }

    private function handleAuth($sock, array $phoneBcd, int $serial, int $ver, bool $is2019): void
    {
        $this->sessions[spl_object_id($sock)] = 'ONLINE';

        $body = [
            ($serial >> 8) & 0xFF,
            $serial & 0xFF,
            0x01, // MsgId High
            0x02, // MsgId Low (0x0102)
            0x00
        ];

        $this->info('[SEND] 0x8001 Auth OK');
        $this->sendPacket($sock, 0x8001, $phoneBcd, $body, $ver, $is2019);
    }

    private function handleGeneral($sock, array $phoneBcd, int $serial, int $msgId, int $ver, bool $is2019): void
    {
        if (($this->sessions[spl_object_id($sock)] ?? '') !== 'ONLINE') return;

        $body = [
            ($serial >> 8) & 0xFF,
            $serial & 0xFF,
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            0x00
        ];

        $this->sendPacket($sock, 0x8001, $phoneBcd, $body, $ver, $is2019);
    }

    /* ===================== SEND ===================== */

    private function sendPacket($sock, int $msgId, array $phoneBcd, array $body, int $ver = 1, bool $is2019 = false): void
    {
        static $srvSerial = 1;

        $attr = count($body);
        if ($is2019) {
            $attr |= 0x4000;
        }

        $packet = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($attr >> 8) & 0xFF,
            $attr & 0xFF,
        ];

        if ($is2019) {
            $packet[] = $ver;
        }

        $packet = array_merge($packet, $phoneBcd, [
            ($srvSerial >> 8) & 0xFF,
            $srvSerial & 0xFF,
            ...$body
        ]);

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
            } else $escaped[] = $b;
        }

        $frame = array_merge([0x7E], $escaped, [0x7E]);
        $out = pack('C*', ...$frame);

        $this->line('[RAW OUT] ' . strtoupper(bin2hex($out)));
        @socket_write($sock, $out);
    }

    /* ===================== UTILS ===================== */

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
        $s = '';
        foreach ($bcd as $b) {
            $s .= ($b >> 4) & 0x0F;
            $s .= $b & 0x0F;
        }
        return ltrim($s, '0');
    }
}
