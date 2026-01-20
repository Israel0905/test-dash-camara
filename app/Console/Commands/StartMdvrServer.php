<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Full Protocol 2019';

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($socket, $address, $port)) {
            $this->error("No se pudo enlazar el puerto $port. Asegúrate de que no esté en uso.");
            return;
        }

        socket_listen($socket);
        $this->info("=====================================================");
        $this->info("[MDVR] SERVIDOR JT/T 808 INICIADO EN PUERTO $port");
        $this->info("=====================================================");

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 1000000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $newSocket = socket_accept($socket);
                        $clients[] = $newSocket;
                        $this->warn("[CONN] Nueva cámara conectada");
                    } else {
                        $input = @socket_read($s, 4096);
                        if ($input) {
                            $this->processBuffer($s, $input);
                        } else {
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

        // 1. Unescape (Eliminar 0x7D y restaurar bytes originales)
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

        // Payload sin bytes 7E de inicio/fin y sin checksum
        $payload = array_slice($data, 1, -2);

        // Estructura Header 2019 (17 bytes)
        $msgId = ($payload[0] << 8) | $payload[1];
        $terminalSerial = ($payload[15] << 8) | $payload[16];
        $phoneRaw = array_slice($payload, 5, 10);
        $body = array_slice($payload, 17);

        $this->line("<fg=cyan>[RECV]</> ID: 0x" . sprintf('%04X', $msgId) . " | Serial: $terminalSerial");

        // 2. Lógica de Respuesta
        if ($msgId === 0x0100) {
            // REGISTRO: Requiere respuesta 0x8100 con código de auth
            $this->respondRegistration($socket, $phoneRaw, $terminalSerial);
        } else {
            // RESPUESTA GENERAL 0x8001: Crítica para mantener conexión viva
            $this->respondGeneral($socket, $phoneRaw, $terminalSerial, $msgId);

            // 3. Procesamiento de Datos específicos
            switch ($msgId) {
                case 0x0102:
                    $this->info("   -> [AUTH] Cámara autenticada correctamente.");
                    break;
                case 0x0002:
                    $this->line("   -> [KEEP-ALIVE] Heartbeat recibido.");
                    break;
                case 0x0200:
                    $this->parseLocation($body);
                    break;
                case 0x0704:
                    $this->warn("   -> [BATCH] Recibiendo datos GPS históricos.");
                    break;
            }
        }
    }

    private function parseLocation($body)
    {
        if (count($body) < 28) return;

        // Según protocolo JT/T 808:
        // Byte 0-3: Alarma, Byte 4-7: Status
        // Byte 8-11: Latitud (DWORD), Byte 12-15: Longitud (DWORD)
        $latRaw = ($body[8] << 24) | ($body[9] << 16) | ($body[10] << 8) | $body[11];
        $lonRaw = ($body[12] << 24) | ($body[13] << 16) | ($body[14] << 8) | $body[15];

        $lat = $latRaw / 1000000;
        $lon = $lonRaw / 1000000;

        // Byte 18-19: Velocidad (WORD) unit 1/10km/h
        $speed = (($body[18] << 8) | $body[19]) / 10;

        $this->info("   [GPS] Ubicación: https://www.google.com/maps?q=$lat,$lon");
        $this->info("   [GPS] Velocidad: $speed km/h");
    }

    private function respondRegistration($socket, $phoneRaw, $terminalSerial)
    {
        $authCode = "123456";

        // Cuerpo: Serial Terminal(2) + Resultado(1) + AuthCode(N) + Nulo(1)
        $body = [
            ($terminalSerial >> 8) & 0xFF,
            $terminalSerial & 0xFF,
            0x00, // 0: Éxito
        ];
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }
        $body[] = 0x00; // Terminador nulo para cámaras chinas

        $this->sendPacket($socket, 0x8100, $phoneRaw, $body);
        $this->warn("   -> [REG] Respuesta de registro enviada.");
    }

    private function respondGeneral($socket, $phoneRaw, $terminalSerial, $replyId)
    {
        // Cuerpo 0x8001: Serial Cámara(2) + ID Mensaje Cámara(2) + Resultado(1)
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
        $attr = (1 << 14) | count($body); // Protocolo 2019 habilitado

        $header = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF),
            0x01, // Version 2019
        ];

        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        static $srvSerial = 0;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial = ($srvSerial + 1) % 65535;

        $full = array_merge($header, $body);

        // Checksum XOR
        $cs = 0;
        foreach ($full as $b) {
            $cs ^= $b;
        }
        $full[] = $cs;

        // Escapado (0x7E -> 0x7D 0x02, 0x7D -> 0x7D 0x01)
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

        @socket_write($socket, pack('C*', ...$final));
    }
}
