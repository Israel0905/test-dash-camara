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

        if (! @socket_bind($socket, $address, $port)) {
            $this->error("Error: Puerto $port ocupado.");

            return;
        }

        socket_listen($socket);
        $this->info('=====================================================');
        $this->info("[DEBUG MDVR] ESCUCHANDO EN PUERTO $port");
        $this->info('=====================================================');

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 1000000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $clients[] = socket_accept($socket);
                        $this->warn('[CONN] Cámara conectada.');
                    } else {
                        $input = @socket_read($s, 4096);
                        if ($input) {
                            $this->processBuffer($s, $input);
                        } else {
                            socket_close($s);
                            unset($clients[array_search($s, $clients)]);
                            $this->error('[DESC] Cámara desconectada.');
                        }
                    }
                }
            }
        }
    }

    private function processBuffer($socket, $input)
    {
        $rawHex = strtoupper(bin2hex($input));
        $this->line("\n<fg=yellow>[RAW RECV]</>: ".implode(' ', str_split($rawHex, 2)));

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

        if (count($data) < 15) {
            return;
        }

        // Payload sin delimitadores 7E ni checksum
        $payload = array_slice($data, 1, -2);

        // 2. PARSE HEADER 2019 (Tabla 2.2.2)
        $msgId = ($payload[0] << 8) | $payload[1];
        $attr = ($payload[2] << 8) | $payload[3];
        $is2019 = ($attr >> 14) & 0x01;

        // En 2019 el Protocol Version es el byte 4
        $protocolVer = $payload[4];
        $phone = bin2hex(pack('C*', ...array_slice($payload, 5, 10)));
        // El Serial de la cámara está en bytes 15-16
        $devSerial = ($payload[15] << 8) | $payload[16];
        $body = array_slice($payload, 17);

        $this->info(sprintf(
            '[INFO] ID: 0x%04X | Serial: %d | Phone: %s | Ver2019: %s',
            $msgId,
            $devSerial,
            $phone,
            ($is2019 ? 'SI' : 'NO')
        ));

        // 3. RESPUESTAS
        $phoneRaw = array_slice($payload, 5, 10);
        $phoneHex = implode(' ', array_map(fn ($b) => sprintf('%02X', $b), $phoneRaw));
        $this->line("   Phone RAW (para respuesta): <fg=cyan>$phoneHex</>");

        if ($msgId === 0x0100) {
            $this->comment('   -> Procesando Registro...');
            $this->respondRegistration($socket, $phoneRaw, $devSerial, $body);
        } else {
            $this->comment('   -> Enviando Respuesta General (0x8001)...');
            $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);

            if ($msgId === 0x0200) {
                $this->parseLocation($body);
            }
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial, $body)
    {
        // =====================================================
        // PARSEAR CUERPO DEL REGISTRO (Tabla 3.3.1 - 100 bytes)
        // =====================================================
        $this->info('   ┌─────────────────────────────────────────────────┐');
        $this->info('   │          DATOS DE REGISTRO 0x0100               │');
        $this->info('   └─────────────────────────────────────────────────┘');

        // Byte 0-1: Province ID (WORD)
        $provinceId = isset($body[0], $body[1]) ? ($body[0] << 8) | $body[1] : 0;
        $this->line('   Province ID: '.$provinceId);

        // Byte 2-3: County ID (WORD)
        $countyId = isset($body[2], $body[3]) ? ($body[2] << 8) | $body[3] : 0;
        $this->line('   County ID: '.$countyId);

        // Byte 4-14: Manufacturer ID (11 bytes ASCII)
        $manufacturerBytes = array_slice($body, 4, 11);
        $manufacturer = trim(implode('', array_map('chr', $manufacturerBytes)));
        $this->line("   Manufacturer: <fg=cyan>$manufacturer</>");

        // Byte 15-44: Terminal Model (30 bytes ASCII)
        $modelBytes = array_slice($body, 15, 30);
        $model = trim(implode('', array_map('chr', array_filter($modelBytes, fn ($b) => $b > 0))));
        $this->line("   Model: <fg=cyan>$model</>");

        // Byte 45-74: Terminal ID (30 bytes ASCII)
        $terminalIdBytes = array_slice($body, 45, 30);
        $terminalId = trim(implode('', array_map('chr', array_filter($terminalIdBytes, fn ($b) => $b > 0))));
        $this->line("   Terminal ID: <fg=yellow>$terminalId</>");

        // Byte 75: License Plate Color
        $plateColor = $body[75] ?? 0;
        $this->line('   Plate Color: '.$plateColor);

        // Byte 76+: License Plate (variable)
        $plateBytes = array_slice($body, 76);
        $plate = trim(implode('', array_map('chr', array_filter($plateBytes, fn ($b) => $b > 0))));
        $this->line('   Plate: '.($plate ?: '(vacío)'));

        // =====================================================
        // CONSTRUIR RESPUESTA 0x8100 (Estructura Final Corregida)
        // =====================================================
        // Usamos una contraseña simple de 6 dígitos
        $authCode = '123456';  // Contraseña de sesión

        $this->info('   ─────────────────────────────────────────────────');
        $this->info("   Auth Code a enviar: <fg=green>$authCode</> (longitud: ".strlen($authCode).')');

        // ESTRUCTURA FINAL CORREGIDA (12 bytes total):
        // ┌────────┬────────┬────────┬────────┬────────┬────────┬───────────────────┐
        // │ Byte 0 │ Byte 1 │ Byte 2 │ Byte 3 │ Byte 4 │ Byte 5 │ Byte 6+           │
        // ├────────┼────────┼────────┼────────┼────────┼────────┼───────────────────┤
        // │ Serial │ Serial │ReplyID │ReplyID │ Result │ Length │ Auth Code         │
        // │  High  │  Low   │  (01)  │  (00)  │  (00)  │  (06)  │ "123456"          │
        // └────────┴────────┴────────┴────────┴────────┴────────┴───────────────────┘
        //
        // Byte 0-1: Reply Serial Number (copia del recibido)
        // Byte 2-3: Reply ID (0x0100) - Llena el hueco, confirma que respondemos al registro
        // Byte 4:   Result (0x00 = Éxito) - CRUCIAL: Aquí es donde el dispositivo lee el resultado
        // Byte 5:   Length (0x06) - Tabla 3.4
        // Byte 6+:  Auth Code ("123456")

        $responseBody = [
            ($devSerial >> 8) & 0xFF,  // Byte 0: Reply Serial High
            $devSerial & 0xFF,          // Byte 1: Reply Serial Low
            0x01,                        // Byte 2: Reply ID High (0x0100)
            0x00,                        // Byte 3: Reply ID Low
            0x00,                        // Byte 4: Result = Éxito
            strlen($authCode),           // Byte 5: Length (0x06)
        ];

        // Byte 6+: Auth Code como bytes ASCII
        foreach (str_split($authCode) as $char) {
            $responseBody[] = ord($char);
        }

        // Mostrar hex del body para debug
        $bodyHex = implode(' ', array_map(fn ($b) => sprintf('%02X', $b), $responseBody));
        $this->line("   Body HEX: <fg=magenta>$bodyHex</>");

        $this->sendPacket($socket, 0x8100, $phoneRaw, $responseBody);
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

        // =====================================================
        // DEBUG: Mostrar Header y Body por separado
        // =====================================================
        $headerHex = implode(' ', array_map(fn ($b) => sprintf('%02X', $b), $header));
        $bodyHex = implode(' ', array_map(fn ($b) => sprintf('%02X', $b), $body));

        $this->info('   ┌─────────────────────────────────────────────────┐');
        $this->info('   │          PAQUETE DE RESPUESTA 0x'.sprintf('%04X', $msgId).'             │');
        $this->info('   └─────────────────────────────────────────────────┘');
        $this->line('   <fg=white>HEADER ('.count($header)." bytes):</> <fg=blue>$headerHex</>");
        $this->line('   <fg=white>BODY   ('.count($body)." bytes):</> <fg=magenta>$bodyHex</>");

        // Unir todo para el Checksum
        $full = array_merge($header, $body);

        // --- CÁLCULO XOR REAL ---
        $cs = 0;
        foreach ($full as $byte) {
            $cs ^= $byte;
        }
        $full[] = $cs;

        $this->line('   <fg=white>CHECKSUM:</> <fg=yellow>'.sprintf('%02X', $cs).'</>');

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
        $this->line('<fg=green>[SEND HEX]</>: '.implode(' ', str_split($hexOut, 2)));

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
            0x00,                         // Resultado: 0 (Éxito)
        ];

        $this->comment('   -> Confirmando mensaje 0x'.sprintf('%04X', $replyMsgId));
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }
}
