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

        // Atributos: Bit 14 ACTIVADO (0x4000) para indicar 2019
        $attr = 0x4000 | $bodyLen;

        $header = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF, // ID Mensaje
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF), // Atributos con Bit 14
            0x01,                                // Versión 2019 (Obligatorio)
        ];

        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        // Serial del Servidor (Independiente)
        static $srvSerial = 1;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial = ($srvSerial + 1) % 65535;

        // Unir todo para el Checksum
        $full = array_merge($header, $body);

        // --- CÁLCULO XOR REAL ---
        $cs = 0;
        foreach ($full as $byte) {
            $cs ^= $byte;
        }
        $full[] = $cs;

        // --- ESCAPADO ---
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

    /**
     * Respuesta General del Servidor (Plataforma) -> Terminal
     * ID Mensaje: 0x8001
     */
    private function respondGeneral($socket, $phoneRaw, $deviceSerial, $replyMsgId)
    {
        // Estructura del Cuerpo (Tabla 3.1.2):
        // 1. Reply Serial Number (WORD): El serial del mensaje que mandó la cámara
        // 2. Reply Message ID (WORD): El ID del mensaje que mandó la cámara (ej: 0x0102, 0x0002)
        // 3. Result (BYTE): 0 = Éxito/Confirmado, 1 = Fallo, 2 = Mensaje Erróneo...

        $body = [
            ($deviceSerial >> 8) & 0xFF, // Serial de la cámara (High)
            $deviceSerial & 0xFF,        // Serial de la cámara (Low)
            ($replyMsgId >> 8) & 0xFF,   // ID del mensaje que confirmamos (High)
            $replyMsgId & 0xFF,          // ID del mensaje que confirmamos (Low)
            0x00                         // Resultado: 0 (Éxito)
        ];

        $this->comment("   -> Confirmando mensaje 0x" . sprintf('%04X', $replyMsgId));
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }
}
