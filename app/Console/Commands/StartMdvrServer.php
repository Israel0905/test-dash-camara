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
                            @socket_close($s);
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

        // 1. Desescapar
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
        $payload = array_slice($data, 1, -2); // Quitar 7E inicial y final + Checksum

        $msgId = ($payload[0] << 8) | $payload[1];
        $attr  = ($payload[2] << 8) | $payload[3];

        // --- DETECTAR VERSIÓN 2019 ---
        $isV2019 = ($attr & 0x4000) > 0;

        if ($isV2019) {
            // Estructura 2019: ID(2) + Attr(2) + Ver(1) + Phone(20) + Serial(2)
            $phoneRaw = array_slice($payload, 5, 20);
            $devSerial = ($payload[25] << 8) | $payload[26];
            $body = array_slice($payload, 27);
        } else {
            // Estructura vieja (tu cámara parece mandar 10 bytes aquí si no es 2019)
            $phoneRaw = array_slice($payload, 4, 10);
            $devSerial = ($payload[14] << 8) | $payload[15];
            $body = array_slice($payload, 16);
        }

        $this->info(sprintf("[INFO] ID: 0x%04X | Serial Cam: %d | Phone: %s", $msgId, $devSerial, bin2hex(pack('C*', ...$phoneRaw))));

        if ($msgId === 0x0100) {
            $this->comment("   -> Procesando Registro...");
            $this->respondRegistration($socket, $phoneRaw, $devSerial);
        } else {
            $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
            if ($msgId === 0x0200) $this->parseLocation($body);
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial)
    {
        $authCode = "123456";

        // Cuerpo 0x8100 (Tabla 3.3.2): Reply Serial(2) + Result(1) + Auth Code
        $body = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            0x00, // Éxito
        ];

        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }

        $this->sendPacket($socket, 0x8100, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $bodyLen = count($body);
        $attr = 0x4000 | $bodyLen; // BIT 14 SIEMPRE 1 PARA 2019

        $header = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($attr >> 8) & 0xFF,
            $attr & 0xFF,
            0x01, // Protocol Version (Obligatorio en 2019)
        ];

        // --- EL TRUCO DEL MANUAL: FORZAR 20 BYTES ---
        // Si el teléfono tiene 10 bytes, le pegamos 10 ceros a la IZQUIERDA.
        $terminalId = array_pad(array_slice($phoneRaw, -10), -20, 0x00);
        foreach ($terminalId as $b) {
            $header[] = $b;
        }

        static $srvSerial = 1;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial = ($srvSerial + 1) % 65535;

        $full = array_merge($header, $body);

        $cs = 0;
        foreach ($full as $byte) {
            $cs ^= $byte;
        }
        $full[] = $cs;

        // Escapado
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

    private function respondGeneral($socket, $phoneRaw, $terminalSerial, $replyId)
    {
        $body = [
            ($terminalSerial >> 8) & 0xFF,
            $terminalSerial & 0xFF,
            ($replyId >> 8) & 0xFF,
            $replyId & 0xFF,
            0x00 // OK
        ];
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function parseLocation($body)
    {
        if (count($body) < 28) return;
        $lat = (($body[8] << 24) | ($body[9] << 16) | ($body[10] << 8) | $body[11]) / 1000000;
        $lon = (($body[12] << 24) | ($body[13] << 16) | ($body[14] << 8) | $body[15]) / 1000000;
        $this->warn("   [GPS] $lat, $lon");
    }
}
