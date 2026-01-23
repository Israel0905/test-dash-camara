<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';

    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Debug Mode';

    // NUEVO: Array para guardar el serial de cada cámara conectada
    protected $clientSerials = [];

    protected $clientBuffers = []; // Memoria para paquetes incompletos

    protected $clientProtocols = []; // 2011 vs 2019

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
                        $newClient = socket_accept($socket);
                        if ($newClient) {
                            $clients[] = $newClient;
                            $this->clientSerials[spl_object_id($newClient)] = 0; // Iniciar en 0 para esta cámara
                            $this->clientBuffers[spl_object_id($newClient)] = ''; // Iniciar búfer vacío
                            // Protocolo por defecto (se actualizará al recibir primer paquete)
                            $this->clientProtocols[spl_object_id($newClient)] = '2019';
                            $this->warn('[CONN] Cámara conectada (ID '.spl_object_id($newClient).').');
                        }
                    } else {
                        $input = @socket_read($s, 4096);
                        if ($input) {
                            $this->clientBuffers[spl_object_id($s)] .= $input;
                            $this->processBuffer($s);
                        } else {
                            socket_close($s);
                            unset($clients[array_search($s, $clients)]);
                            unset($this->clientSerials[spl_object_id($s)]); // Borrar serial
                            unset($this->clientBuffers[spl_object_id($s)]); // Borrar búfer
                            unset($this->clientProtocols[spl_object_id($s)]); // Borrar protocolo
                            $this->error('[DESC] Cámara desconectada (ID '.spl_object_id($s).').');
                        }
                    }
                }
            }
        }
    }

    private function processBuffer($socket)
    {
        $buffer = &$this->clientBuffers[spl_object_id($socket)];

        while (true) {
            // Buscar el primer 7E (Inicio)
            $start = strpos($buffer, chr(0x7E));
            if ($start === false) {
                $buffer = ''; // Basura, limpiamos
                break;
            }

            // Buscar el segundo 7E (Fin) a partir de la siguiente posición
            $end = strpos($buffer, chr(0x7E), $start + 1);
            if ($end === false) {
                // Tenemos un inicio pero no un fin. El paquete está incompleto.
                // Rompemos el bucle y esperamos a la siguiente lectura del socket.
                break;
            }

            // ¡Tenemos un paquete completo! Lo extraemos.
            $packetLength = $end - $start + 1;
            $singlePacket = substr($buffer, $start, $packetLength);

            // Cortamos el búfer para quitar el paquete que ya procesamos
            // (SIN +1 para soportar Shared Delimiter)
            $buffer = substr($buffer, $end);

            // Si el paquete es muy corto (ej: "7E 7E"), lo ignoramos
            if (strlen($singlePacket) < 12) { // 12 es el mínimo header 2011
                continue;
            }

            // ===============================================
            // PROCESAMIENTO
            // ===============================================
            $rawHex = strtoupper(bin2hex($singlePacket));
            $this->line("\n<fg=yellow>[RAW RECV]</>: ".implode(' ', str_split($rawHex, 2)));

            $bytes = array_values(unpack('C*', $singlePacket));

            // --- UNESCAPE ---
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

            // Validar longitud mínima tras unescape
            if (count($data) < 12) {
                continue;
            }

            // Payload sin delimitadores 7E ni checksum
            $payload = array_slice($data, 1, -2);

            // --- PARSE HEADER DINÁMICO (2011 vs 2019) ---
            $msgId = ($payload[0] << 8) | $payload[1];
            $attr = ($payload[2] << 8) | $payload[3];

            $is2019 = ($attr >> 14) & 0x01; // Bit 14
            $hasSubPackets = ($attr >> 13) & 0x01; // Bit 13

            // Guardar preferencia de versión
            $protocol = $is2019 ? '2019' : '2011';
            $this->clientProtocols[spl_object_id($socket)] = $protocol;

            $headerLen = 0;
            $phoneRaw = [];
            $devSerial = 0;

            if ($is2019) {
                // Estructura 2019
                // MsgId(2) + Attr(2) + Ver(1) + Phone(10) + Serial(2) = 17 bytes
                $headerLen = 17;
                $phoneRaw = array_slice($payload, 5, 10); // Offset 5, Length 10
                $devSerial = ($payload[15] << 8) | $payload[16];
            } else {
                // Estructura 2011
                // MsgId(2) + Attr(2) + Phone(6) + Serial(2) = 12 bytes
                $headerLen = 12;
                $phoneRaw = array_slice($payload, 4, 6); // Offset 4, Length 6
                $devSerial = ($payload[10] << 8) | $payload[11];
            }

            // Si hay subpaquetes, el header crece 4 bytes (Package Info)
            if ($hasSubPackets) {
                $headerLen += 4;
            }

            $phoneHex = implode('', array_map(fn ($b) => sprintf('%02X', $b), $phoneRaw));
            $body = array_slice($payload, $headerLen);

            $this->info(sprintf(
                '[INFO V%s] ID: 0x%04X | Serial: %d | Phone: %s | Frag: %s',
                $protocol,
                $msgId,
                $devSerial,
                $phoneHex,
                $hasSubPackets ? 'SI' : 'NO'
            ));

            // --- RESPUESTAS ---
            // Usamos $phoneRaw original (6 o 10 bytes) para responder en el mismo formato
            $phoneHexDisplay = implode(' ', array_map(fn ($b) => sprintf('%02X', $b), $phoneRaw));
            $this->line("   Phone RAW (Respuesta): <fg=cyan>$phoneHexDisplay</>");

            if ($msgId === 0x0100) {
                $this->comment('   -> Procesando Registro...');
                $this->respondRegistration($socket, $phoneRaw, $devSerial, $body);

            } elseif ($msgId === 0x0001) {
                $this->info('   -> [OK] La cámara confirmó nuestro mensaje. No responder.');

                continue;

            } else {
                // REVERTIDO: 0x0900 se maneja aquí (0x8001) para evitar reset de la cámara
                $this->comment('   -> Enviando Respuesta General (0x8001)...');
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);

                if ($msgId === 0x0200 && method_exists($this, 'parseLocation')) {
                    $this->parseLocation($body);
                }
            }
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial, $body)
    {
        $this->info('   ┌─────────────────────────────────────────────────┐');
        $this->info('   │          DATOS DE REGISTRO 0x0100               │');
        $this->info('   └─────────────────────────────────────────────────┘');

        // Nota: Los offsets del body del registro (0x0100) son fijos según el estándar
        // JTT808 2013/2019 suelen ser iguales en el cuerpo del registro.
        // Byte 0-1: Province, 2-3: County, 4-8: Manufacturer (5 bytes en 2011? 11 en 2019?)
        // En este punto asumimos la estructura que hemos estado usando.

        $provinceId = isset($body[0], $body[1]) ? ($body[0] << 8) | $body[1] : 0;
        $this->line('   Province ID: '.$provinceId);

        // ... resto del parsing informativo ...
        // Simplificado para no hacer el código gigante, lo importante es la respuesta

        // =====================================================
        // CONSTRUIR RESPUESTA 0x8100
        // =====================================================
        $authCode = '123456';

        $responseBody = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            0x00, // Éxito
        ];

        foreach (str_split($authCode) as $char) {
            $responseBody[] = ord($char);
        }

        $this->sendPacket($socket, 0x8100, $phoneRaw, $responseBody);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $protocol = $this->clientProtocols[spl_object_id($socket)] ?? '2019';
        $bodyLen = count($body);
        $attr = $bodyLen; // Base length

        $header = [];

        // Estructura del Header según versión
        if ($protocol === '2019') {
            $attr |= 0x4000; // Bit 14 = 1 para 2019

            // MsgId(2)
            $header[] = ($msgId >> 8) & 0xFF;
            $header[] = $msgId & 0xFF;
            // Attr(2)
            $header[] = ($attr >> 8) & 0xFF;
            $header[] = $attr & 0xFF;
            // Version(1)
            $header[] = 0x01; // Protocol Version
            // Phone (10 bytes) - Asumimos que phoneRaw tiene el tamaño correcto o lo enviamos tal cual
            foreach ($phoneRaw as $b) {
                $header[] = $b;
            }
        } else {
            // 2011
            // MsgId(2)
            $header[] = ($msgId >> 8) & 0xFF;
            $header[] = $msgId & 0xFF;
            // Attr(2) - Bit 14 es 0
            $header[] = ($attr >> 8) & 0xFF;
            $header[] = $attr & 0xFF;
            // NO hay byte de versión
            // Phone (6 bytes)
            foreach ($phoneRaw as $b) {
                $header[] = $b;
            }
        }

        // Serial del Servidor (Independiente por cliente)
        $srvSerial = $this->clientSerials[spl_object_id($socket)] ?? 0;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;

        // Incrementar serial
        $this->clientSerials[spl_object_id($socket)] = ($srvSerial + 1) % 65535;

        // =====================================================
        // SEND
        // =====================================================
        $headerHex = implode(' ', array_map(fn ($b) => sprintf('%02X', $b), $header));
        $this->line('   <fg=white>HEADER ('.count($header)." bytes) [$protocol]:</> <fg=blue>$headerHex</>");

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

        @socket_write($socket, pack('C*', ...$final));
    }

    private function respondGeneral($socket, $phoneRaw, $deviceSerial, $replyMsgId)
    {
        $body = [
            ($deviceSerial >> 8) & 0xFF,
            $deviceSerial & 0xFF,
            ($replyMsgId >> 8) & 0xFF,
            $replyMsgId & 0xFF,
            0x00,
        ];

        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function parseLocation($body)
    {
        // Placeholder
    }
}
