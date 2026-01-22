<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';

    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Debug Mode';

    public function handle()
    {
        // 1. Evitar que el script muera por tiempo
        set_time_limit(0);

        $port = $this->option('port');
        $address = '0.0.0.0';

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        // 2. Configuración Robusta del Socket
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        // CORRECCIÓN CRÍTICA: Desactivar el algoritmo de Nagle (TCP_NODELAY)
        // Esto hace que las respuestas pequeñas (como el Latido) salgan INMEDIATAMENTE
        // Si la constante no está definida, usa el valor 1.
        $tcpNoDelay = defined('TCP_NODELAY') ? TCP_NODELAY : 1;
        socket_set_option($socket, SOL_TCP, $tcpNoDelay, 1);

        if (! @socket_bind($socket, $address, $port)) {
            $this->error("Error: Puerto $port ocupado.");

            return;
        }

        socket_listen($socket);
        $this->info('=====================================================');
        $this->info("[DEBUG MDVR] ESCUCHANDO EN PUERTO $port (MODO TURBO)");
        $this->info('=====================================================');

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 100000) > 0) { // Timeout reducido a 0.1s para más velocidad
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $clients[] = socket_accept($socket);
                        $this->warn('[CONN] Cámara conectada.');
                    } else {
                        // CORRECCIÓN CRÍTICA: Aumentar buffer de lectura
                        // Los paquetes 0x0704 pueden ser enormes. 4096 es muy poco.
                        // Subimos a 65535 bytes para tragar todo el paquete de una vez.
                        $input = @socket_read($s, 65535);

                        if ($input) {
                            $this->processBuffer($s, $input);
                        } else {
                            // Detectar desconexión real
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
        // 1. SOLUCIÓN "STICKY PACKETS"
        // La cámara manda muchos mensajes juntos. Usamos explode para separarlos por el delimitador 7E.
        // El caracter 0x7E es '~' en ASCII.
        $rawPackets = explode(chr(0x7E), $input);

        foreach ($rawPackets as $packetData) {
            // Si el paquete está vacío o es muy corto (ruido), lo saltamos
            if (strlen($packetData) < 3) {
                continue;
            }

            // PROCESAR CADA MENSAJE INDIVIDUALMENTE
            // Nota: packetData ya viene SIN los 7E de inicio/fin porque explode los quitó.

            $bytes = array_values(unpack('C*', $packetData));

            // 2. UNESCAPE (Manual Cap 2.2.1)
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

            // Validar longitud mínima después de unescape
            if (count($data) < 12) { // Header(12) + Checksum(1) mínimo
                continue;
            }

            // Ya tenemos el payload limpio (Header + Body + Checksum)
            // No necesitamos array_slice porque explode ya quitó los 7E externos
            $payload = $data;

            // Verificar Checksum (XOR de todo menos el último byte)
            $calcCs = 0;
            $len = count($payload);
            $receivedCs = $payload[$len - 1]; // Último byte es el checksum

            for ($k = 0; $k < $len - 1; $k++) {
                $calcCs ^= $payload[$k];
            }

            if ($calcCs !== $receivedCs) {
                // Si el checksum falla, es un fragmento corrupto, lo ignoramos
                // $this->error("Checksum error: Cal: $calcCs Rec: $receivedCs");
                continue;
            }

            // Quitar el checksum del final para procesar
            $bodyWithHeader = array_slice($payload, 0, -1);

            // 3. PARSE HEADER 2019
            $msgId = ($bodyWithHeader[0] << 8) | $bodyWithHeader[1];
            $attr = ($bodyWithHeader[2] << 8) | $bodyWithHeader[3];
            $is2019 = ($attr >> 14) & 0x01;

            $protocolVer = $bodyWithHeader[4];
            $phone = bin2hex(pack('C*', ...array_slice($bodyWithHeader, 5, 10)));
            $devSerial = ($bodyWithHeader[15] << 8) | $bodyWithHeader[16];

            // El cuerpo empieza en el byte 17
            $body = array_slice($bodyWithHeader, 17);

            // LOG LIMPIO (Solo lo esencial)
            $this->info(sprintf(
                '[MSG] ID: 0x%04X | Serial: %d | Phone: %s',
                $msgId, $devSerial, $phone
            ));

            // 4. RESPUESTAS
            $phoneRaw = array_slice($bodyWithHeader, 5, 10);

            if ($msgId === 0x0100) {
                $this->respondRegistration($socket, $phoneRaw, $devSerial, $body);
            } else {
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);

                // Si quieres guardar la ubicación:
                if ($msgId === 0x0200 || $msgId === 0x0704) {
                    // $this->parseLocation($body);
                }
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
        // ESTRUCTURA ESTÁNDAR JTT808 (9 bytes total):
        // ┌────────┬────────┬────────┬───────────────────────────┐
        // │ Byte 0 │ Byte 1 │ Byte 2 │ Byte 3+                   │
        // ├────────┼────────┼────────┼───────────────────────────┤
        // │ Serial │ Serial │ Result │ Auth Code (STRING)        │
        // │  High  │  Low   │  (00)  │ "123456"                  │
        // └────────┴────────┴────────┴───────────────────────────┘
        //
        // Byte 0-1: Reply Serial Number (copia del recibido)
        // Byte 2:   Result (0x00 = Éxito)
        // Byte 3+:  Auth Code (STRING) - Sin byte de longitud

        $responseBody = [
            ($devSerial >> 8) & 0xFF,  // Byte 0: Reply Serial High
            $devSerial & 0xFF,          // Byte 1: Reply Serial Low
            0x00,                        // Byte 2: Result = Éxito (0x00)
        ];

        // Byte 3+: Auth Code como bytes ASCII (SIN byte de longitud)
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
        // $this->line('   <fg=white>HEADER ('.count($header)." bytes):</> <fg=blue>$headerHex</>");
        // $this->line('   <fg=white>BODY   ('.count($body)." bytes):</> <fg=magenta>$bodyHex</>");

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
