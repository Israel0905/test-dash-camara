<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Debug Mode';

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!@socket_bind($socket, $address, $port)) {
            $this->error("Error: Puerto $port ocupado.");
            return;
        }

        socket_listen($socket);
        $this->info("=====================================================");
        $this->info("[DEBUG MDVR] ESCUCHANDO EN PUERTO $port");
        $this->info("=====================================================");

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 1000000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $clients[] = socket_accept($socket);
                        $this->warn("[CONN] Cámara conectada.");
                    } else {
                        $input = @socket_read($s, 4096);
                        if ($input) {
                            $this->processBuffer($s, $input);
                        } else {
                            socket_close($s);
                            unset($clients[array_search($s, $clients)]);
                            $this->error("[DESC] Cámara desconectada.");
                        }
                    }
                }
            }
        }
    }

    private function processBuffer($socket, $input)
    {
        $rawHex = strtoupper(bin2hex($input));
        $this->line("\n<fg=yellow>[RAW RECV]</>: " . implode(' ', str_split($rawHex, 2)));

        $bytes = array_values(unpack('C*', $input));

        // 1. UNESCAPE (Manual Cap 2.2.1)
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

        // Payload sin delimitadores 7E ni checksum
        $payload = array_slice($data, 1, -2);

        // 2. PARSE HEADER 2019 (Tabla 2.2.2)
        $msgId = ($payload[0] << 8) | $payload[1];
        $attr  = ($payload[2] << 8) | $payload[3];
        $is2019 = ($attr >> 14) & 0x01;

        // En 2019 el Protocol Version es el byte 4
        $protocolVer = $payload[4];
        $phone = bin2hex(pack('C*', ...array_slice($payload, 5, 10)));
        // El Serial de la cámara está en bytes 15-16
        $devSerial = ($payload[15] << 8) | $payload[16];
        $body = array_slice($payload, 17);

        $this->info(sprintf(
            "[INFO] ID: 0x%04X | Serial: %d | Phone: %s | Ver2019: %s",
            $msgId,
            $devSerial,
            $phone,
            ($is2019 ? 'SI' : 'NO')
        ));

        // 3. RESPUESTAS
        $phoneRaw = array_slice($payload, 5, 10);
        if ($msgId === 0x0100) {
            $this->comment("   -> Procesando Registro...");
            $this->respondRegistration($socket, $phoneRaw, $devSerial);
        } else {
            $this->comment("   -> Enviando Respuesta General (0x8001)...");
            $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);

            if ($msgId === 0x0200) $this->parseLocation($body);
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial)
    {
        $authCode = "123456";
        // Cuerpo 0x8100 2019 (Tabla 3.3.2): 
        // Reply Serial(2) + Result(1) + AuthCode(String)
        $body = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            0x00, // Success
        ];
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }
        $body[] = 0x00; // Null terminator para Ultravision

        $this->sendPacket($socket, 0x8100, $phoneRaw, $body);
    }

    private function respondGeneral($socket, $phoneRaw, $devSerial, $replyId)
    {
        // Cuerpo 0x8001 (Tabla 3.1.2):
        // Reply Serial(2) + Reply Msg ID(2) + Result(1)
        $body = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            ($replyId >> 8) & 0xFF,
            $replyId & 0xFF,
            0x00 // Success
        ];
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        // Attr: Bit 14=1 (Version 2019)
        $attr = (1 << 14) | count($body);

        $header = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF, // ID
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF), // Attr
            0x01,                                // Protocol Ver 2019
        ];
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        static $srvSerial = 1;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial = ($srvSerial + 1) % 65535;

        $full = array_merge($header, $body);

        // XOR Checksum
        $cs = 0;
        foreach ($full as $b) {
            $cs ^= $b;
        }
        $full[] = $cs;

        // Escape
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

        $hexOut = strtoupper(bin2hex(pack('C*', ...$final)));
        $this->line("<fg=green>[SEND HEX]</>: " . implode(' ', str_split($hexOut, 2)));

        @socket_write($socket, pack('C*', ...$final));
    }

    private function parseLocation($body)
    {
        if (count($body) < 28) return;
        $lat = (($body[8] << 24) | ($body[9] << 16) | ($body[10] << 8) | $body[11]) / 1000000;
        $lon = (($body[12] << 24) | ($body[13] << 16) | ($body[14] << 8) | $body[15]) / 1000000;
        $this->warn("   [Ubicación] $lat, $lon");
    }
}
