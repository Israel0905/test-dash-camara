<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808} {--video-port=8810}';
    protected $description = 'Servidor JT/T 808 Dual-Port para Ultravision N6';

    private $clientSerials = [];
    private $portLabels = [];

    public function handle()
    {
        $commandPort = (int) $this->option('port');
        $videoPort = (int) $this->option('video-port');
        $address = '0.0.0.0';

        // Crear socket para puerto de comandos (8808)
        $commandSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($commandSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($commandSocket, SOL_SOCKET, SO_KEEPALIVE, 1);

        if (!@socket_bind($commandSocket, $address, $commandPort)) {
            $this->error("Error: Puerto $commandPort ocupado.");
            return;
        }
        socket_listen($commandSocket);

        // Crear socket para puerto de video (8810)
        $videoSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($videoSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($videoSocket, SOL_SOCKET, SO_KEEPALIVE, 1);

        if (!@socket_bind($videoSocket, $address, $videoPort)) {
            $this->error("Error: Puerto $videoPort ocupado.");
            return;
        }
        socket_listen($videoSocket);

        // Guardar referencia de qué puerto es cada socket
        $this->portLabels[spl_object_id($commandSocket)] = 'CMD';
        $this->portLabels[spl_object_id($videoSocket)] = 'VIDEO';

        $this->info('╔═══════════════════════════════════════════════════════╗');
        $this->info('║        SERVIDOR MDVR DUAL-PORT (JTT808 2019)          ║');
        $this->info('╠═══════════════════════════════════════════════════════╣');
        $this->info("║  Puerto Comandos: $commandPort                              ║");
        $this->info("║  Puerto Video:    $videoPort                              ║");
        $this->info('╚═══════════════════════════════════════════════════════╝');

        // Ambos sockets de escucha van al array de clientes
        $clients = [$commandSocket, $videoSocket];
        $servers = [$commandSocket, $videoSocket];

        while (true) {
            $read = $clients;
            $write = $except = null;

            if (socket_select($read, $write, $except, 0, 100000) > 0) {
                foreach ($read as $s) {
                    // Si es uno de los sockets de servidor, aceptar nueva conexión
                    if (in_array($s, $servers)) {
                        $newSocket = socket_accept($s);
                        if ($newSocket) {
                            socket_set_option($newSocket, SOL_SOCKET, SO_KEEPALIVE, 1);
                            $clients[] = $newSocket;
                            $this->clientSerials[spl_object_id($newSocket)] = 1;

                            // Identificar de qué puerto viene
                            $portType = ($s === $commandSocket) ? 'CMD' : 'VIDEO';
                            $this->portLabels[spl_object_id($newSocket)] = $portType;

                            $ip = $this->getIp($newSocket);
                            $this->warn("\n[CONN:$portType] Nueva conexión desde $ip");
                        }
                    } else {
                        // Es un cliente existente, leer datos
                        $input = @socket_read($s, 65535);
                        if ($input) {
                            $this->splitAndProcess($s, $input);
                        } else {
                            $objId = spl_object_id($s);
                            $portType = $this->portLabels[$objId] ?? '???';
                            unset($this->clientSerials[$objId]);
                            unset($this->portLabels[$objId]);
                            @socket_close($s);
                            $key = array_search($s, $clients);
                            if ($key !== false) unset($clients[$key]);
                            $this->error("[DESC:$portType] Conexión cerrada.");
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
        $objId = spl_object_id($socket);
        $portType = $this->portLabels[$objId] ?? '???';
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

        $this->line("\n<fg=cyan>[$portType] MSG 0x" . sprintf('%04X', $msgId) . " | Serial: $devSerial | Phone: $phone</>");

        switch ($msgId) {
            case 0x0100:
                $this->info("   ↳ Registro - Respondiendo 0x8100");
                $this->clientSerials[$objId] = 1;
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
                $this->comment("   ↳ Mensaje 0x" . sprintf('%04X', $msgId) . " - Respondiendo 0x8001");
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
        $portType = $this->portLabels[$objId] ?? '???';

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

        $this->line("<fg=green>   ← [$portType] REPLY 0x" . sprintf('%04X', $msgId) . " | SrvSerial: $srvSerial</>");

        $this->clientSerials[$objId] = ($srvSerial + 1) % 65535;
    }

    private function getIp($socket)
    {
        socket_getpeername($socket, $address);
        return $address ?? 'unknown';
    }
}
