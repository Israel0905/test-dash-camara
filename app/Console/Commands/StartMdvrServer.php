<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Single Port';

    private $clientSerials = [];

    public function handle()
    {
        $port = (int) $this->option('port');
        $address = '0.0.0.0';

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);

        if (!@socket_bind($socket, $address, $port)) {
            $this->error("Error: Puerto $port ocupado.");
            return;
        }
        socket_listen($socket);

        $this->info('╔═══════════════════════════════════════════════════════╗');
        $this->info("║        SERVIDOR MDVR - PUERTO $port                     ║");
        $this->info('╚═══════════════════════════════════════════════════════╝');

        $clients = [$socket];

        while (true) {
            $read = $clients;
            $write = $except = null;

            if (socket_select($read, $write, $except, 0, 100000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $newSocket = socket_accept($s);
                        if ($newSocket) {
                            socket_set_option($newSocket, SOL_SOCKET, SO_KEEPALIVE, 1);
                            $clients[] = $newSocket;
                            $this->clientSerials[spl_object_id($newSocket)] = 1;
                            $ip = $this->getIp($newSocket);
                            $this->warn("\n[CONN] Nueva conexión desde $ip");
                        }
                    } else {
                        $input = @socket_read($s, 65535);
                        if ($input) {
                            $this->splitAndProcess($s, $input);
                        } else {
                            $objId = spl_object_id($s);
                            unset($this->clientSerials[$objId]);
                            @socket_close($s);
                            $key = array_search($s, $clients);
                            if ($key !== false) unset($clients[$key]);
                            $this->error('[DESC] Conexión cerrada.');
                        }
                    }
                }
            }
        }
    }

    private function splitAndProcess($socket, $input)
    {
        $hex = bin2hex($input);
        if (preg_match_all('/7e(..*?)7e/', $hex, $matches)) {
            foreach ($matches[0] as $packetHex) {
                $this->processBuffer($socket, hex2bin($packetHex));
            }
        }
    }

    private function processBuffer($socket, $input)
    {
        $rawHex = strtoupper(bin2hex($input));
        $bytes = array_values(unpack('C*', $input));

        // 1. Unescape
        $data = [];
        for ($i = 0; $i < count($bytes); $i++) {
            if ($bytes[$i] === 0x7D && isset($bytes[$i + 1])) {
                if ($bytes[$i + 1] === 0x01) {
                    $data[] = 0x7D;
                    $i++;
                } elseif ($bytes[$i + 1] === 0x02) {
                    $data[] = 0x7E;
                    $i++;
                }
            } else {
                $data[] = $bytes[$i];
            }
        }

        if (count($data) < 15) return;

        // Estructura Header 2019
        $msgId = ($data[1] << 8) | $data[2];
        $phoneRaw = array_slice($data, 6, 10);
        $phone = bin2hex(pack('C*', ...$phoneRaw));
        $devSerial = ($data[16] << 8) | $data[17];

        $this->line("\n<fg=cyan>MSG 0x" . sprintf('%04X', $msgId) . " | Serial: $devSerial | Phone: $phone</>");
        $this->line("<fg=gray>RAW: " . implode(' ', str_split($rawHex, 2)) . "</>");

        switch ($msgId) {
            case 0x0100:
                $this->info("   ↳ Registro - Respondiendo 0x8100");
                $this->clientSerials[spl_object_id($socket)] = 1;
                $this->respondRegistration($socket, $phoneRaw, $devSerial);
                break;
            case 0x0002:
                $this->info("   ↳ Heartbeat - Respondiendo 0x8001");
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;
            case 0x0102:
                $this->info("   ↳ Autenticación - Respondiendo 0x8001");
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;
            case 0x0704:
                $this->info("   ↳ GPS Batch - Respondiendo 0x8001");
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;
            case 0x0900:
                $this->info("   ↳ Data Passthrough - Respondiendo 0x8001");
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;
            default:
                $this->comment("   ↳ Msg 0x" . sprintf('%04X', $msgId) . " - Respondiendo 0x8001");
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial)
    {
        $authCode = '123456';
        $responseBody = [
            ($devSerial >> 8) & 0xFF,
            ($devSerial & 0xFF),
            0x00, // RESULTADO: ÉXITO
        ];

        foreach (str_split($authCode) as $char) {
            $responseBody[] = ord($char);
        }

        $this->sendPacket($socket, 0x8100, $phoneRaw, $responseBody);
    }

    private function respondGeneral($socket, $phoneRaw, $devSerial, $replyMsgId)
    {
        $body = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            ($replyMsgId >> 8) & 0xFF,
            $replyMsgId & 0xFF,
            0x00,
        ];
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $objId = spl_object_id($socket);
        $srvSerial = $this->clientSerials[$objId] ?? 1;

        $bodyLen = count($body);
        $attr = 0x4000 | ($bodyLen & 0x03FF);

        $header = [
            ($msgId >> 8) & 0xFF,
            ($msgId & 0xFF),
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF),
            0x01,
        ];
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;

        $full = array_merge($header, $body);

        $cs = 0;
        foreach ($full as $byte) {
            $cs ^= $byte;
        }
        $full[] = $cs;

        $final = [0x7E];
        foreach ($full as $b) {
            if ($b === 0x7E) {
                $final[] = 0x7D;
                $final[] = 0x02;
            } elseif ($b === 0x7D) {
                $final[] = 0x7D;
                $final[] = 0x01;
            } else {
                $final[] = $b;
            }
        }
        $final[] = 0x7E;

        $binOut = pack('C*', ...$final);
        @socket_write($socket, $binOut);

        $this->line("<fg=green>   ← REPLY 0x" . sprintf('%04X', $msgId) . " | SrvSerial: $srvSerial</>");

        $this->clientSerials[$objId] = ($srvSerial + 1) % 65535;
    }

    private function getIp($socket)
    {
        socket_getpeername($socket, $address);
        return $address ?? 'unknown';
    }
}
