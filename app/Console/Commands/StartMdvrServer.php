<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Parser de Datos';

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($socket, $address, $port);
        socket_listen($socket);

        $this->info("=====================================================");
        $this->info("[MDVR] SERVIDOR ACTIVO EN PUERTO $port");
        $this->info("=====================================================");

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 1000000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $clients[] = socket_accept($socket);
                        $this->warn("[CONN] Nueva cámara conectada");
                    } else {
                        $input = @socket_read($s, 4096);
                        if ($input) $this->processBuffer($s, $input);
                        else {
                            socket_close($s);
                            unset($clients[array_search($s, $clients)]);
                            $this->error("[DESC] Cámara desconectada");
                        }
                    }
                }
            }
        }
    }

    private function processBuffer($socket, $input)
    {
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
        $payload = array_slice($data, 1, -2);

        // Header 2019 (17 bytes)
        $msgId = ($payload[0] << 8) | $payload[1];
        $terminalSerial = ($payload[15] << 8) | $payload[16];
        $phoneRaw = array_slice($payload, 5, 10);
        $body = array_slice($payload, 17); // El cuerpo empieza después del byte 17

        $this->line("<fg=cyan>[RECV]</> ID: 0x" . sprintf('%04X', $msgId) . " | Serial: $terminalSerial");

        // Lógica de respuesta según el mensaje
        switch ($msgId) {
            case 0x0100: // Registro
                $this->respondRegistration($socket, $phoneRaw, $terminalSerial);
                break;

            case 0x0102: // Autenticación
                $this->info("   -> Cámara Autenticada OK");
                $this->respondGeneral($socket, $phoneRaw, $terminalSerial, $msgId);
                break;

            case 0x0002: // Heartbeat
                $this->line("   -> Latido (Keep-alive)");
                $this->respondGeneral($socket, $phoneRaw, $terminalSerial, $msgId);
                break;

            case 0x0200: // Ubicación en tiempo real
                $this->parseLocation($body);
                $this->respondGeneral($socket, $phoneRaw, $terminalSerial, $msgId);
                break;

            case 0x0704: // Ubicación histórica (Batch)
                $this->warn("   -> Recibiendo ráfaga de datos históricos (0x0704)");
                $this->respondGeneral($socket, $phoneRaw, $terminalSerial, $msgId);
                break;

            default:
                $this->respondGeneral($socket, $phoneRaw, $terminalSerial, $msgId);
                break;
        }
    }

    private function parseLocation($body)
    {
        if (count($body) < 28) return;

        // Según Tabla 3.10.1 del manual JT/T 808
        $alarm = ($body[0] << 24) | ($body[1] << 16) | ($body[2] << 8) | $body[3];
        $status = ($body[4] << 24) | ($body[5] << 16) | ($body[6] << 8) | $body[7];

        // Lat/Lon son DWORD (1/1000000 de grado)
        $lat = (($body[8] << 24) | ($body[9] << 16) | ($body[10] << 8) | $body[11]) / 1000000;
        $lon = (($body[12] << 24) | ($body[13] << 16) | ($body[14] << 8) | $body[15]) / 1000000;

        // Altitud (WORD)
        $alt = ($body[16] << 8) | $body[17];

        // Velocidad (WORD) 1/10 km/h
        $speed = (($body[18] << 8) | $body[19]) / 10;

        $this->info("   [GPS] Lat: $lat | Lon: $lon | Vel: $speed km/h | Alt: $alt m");
    }

    private function respondRegistration($socket, $phoneRaw, $terminalSerial)
    {
        $authCode = "123456";
        $body = [($terminalSerial >> 8) & 0xFF, $terminalSerial & 0xFF, 0x00];
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }
        $body[] = 0x00;
        $this->sendPacket($socket, 0x8100, $phoneRaw, $body);
    }

    private function respondGeneral($socket, $phoneRaw, $terminalSerial, $replyId)
    {
        $body = [
            ($terminalSerial >> 8) & 0xFF,
            $terminalSerial & 0xFF,
            ($replyId >> 8) & 0xFF,
            $replyId & 0xFF,
            0x00 // Éxito
        ];
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $attr = (1 << 14) | count($body);
        $header = [($msgId >> 8) & 0xFF, $msgId & 0xFF, ($attr >> 8) & 0xFF, ($attr & 0xFF), 0x01];
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        static $srvSerial = 0;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial = ($srvSerial + 1) % 65535;

        $full = array_merge($header, $body);
        $cs = 0;
        foreach ($full as $b) {
            $cs ^= $b;
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

        socket_write($socket, pack('C*', ...$final));
    }
}
