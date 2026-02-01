<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMdvrLocation;
use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8809}';

    protected $description = 'Servidor JT/T 808 compatible con Ultravision N6 (2011/2019)';

    // Seriales persistentes por ID de Terminal (Teléfono)
    protected $terminalSerials = [];

    // Buffers y Protocolos por Socket ID
    protected $clientBuffers = [];

    protected $clientProtocols = [];

    protected $clients = [];

    protected $clientVersions = []; // Track protocol version byte

    // Rastreo de sockets activos por Terminal para evitar duplicados (Socket Flapping)
    protected $terminalSockets = [];

    // Cache de autenticación por terminal
    protected $authCodes = [];

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

        $this->clients = [$socket];
        while (true) {
            $read = $this->clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 1000000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $newClient = socket_accept($socket);
                        if ($newClient) {
                            $this->clients[] = $newClient;
                            $clientId = spl_object_id($newClient);
                            $this->clientBuffers[$clientId] = '';
                            $this->clientProtocols[$clientId] = '2019';
                            $this->clientVersions[$clientId] = 0x01; // Default 2019 version
                            $this->warn("[CONN] Cámara conectada (ID $clientId).");
                        }
                    } else {
                        $input = @socket_read($s, 4096);
                        if ($input) {
                            $this->clientBuffers[spl_object_id($s)] .= $input;
                            $this->processBuffer($s);
                        } else {
                            $this->closeConnection($s);
                        }
                    }
                }
            }
        }
    }

    private function processBuffer($socket)
    {
        $clientId = spl_object_id($socket);
        $buffer = &$this->clientBuffers[$clientId];

        while (true) {
            $start = strpos($buffer, chr(0x7E));
            if ($start === false) {
                $buffer = '';
                break;
            }

            $end = strpos($buffer, chr(0x7E), $start + 1);
            if ($end === false) {
                break;
            }

            $packetLength = $end - $start + 1;
            $singlePacket = substr($buffer, $start, $packetLength);
            $buffer = substr($buffer, $end + 1);

            if (strlen($singlePacket) < 12) {
                continue;
            }

            $this->line("\n<fg=yellow>[RAW RECV]</>: ".strtoupper(bin2hex($singlePacket)));

            // --- UNESCAPE (Sección 2.1 del manual) ---
            $bytes = array_values(unpack('C*', $singlePacket));
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

            // Quitar 0x7E inicial y final
            $payload = array_slice($data, 1, -2);
            
            // Parsear header (Table 2.2.2)
            $msgId = ($payload[0] << 8) | $payload[1];
            $attr = ($payload[2] << 8) | $payload[3];
            $is2019 = ($attr >> 14) & 0x01;
            $bodyLength = $attr & 0x03FF; // 10 bits para longitud

            $this->clientProtocols[$clientId] = $is2019 ? '2019' : '2011';

            // --- PARSING DEL ENCABEZADO (Tabla 2.2.2-1) ---
            if ($is2019) {
                // Versión 2019: header de 17 bytes
                $this->clientVersions[$clientId] = $payload[4] ?? 0x01;
                $phoneRaw = array_slice($payload, 5, 10);
                $devSerial = ($payload[15] << 8) | $payload[16];
                $headerLen = 17;
            } else {
                // Versión 2011: header de 12 bytes
                $phoneRaw = array_slice($payload, 4, 6);
                $devSerial = ($payload[10] << 8) | $payload[11];
                $headerLen = 12;
            }
            
            $body = array_slice($payload, $headerLen);
            $phoneKey = implode('', array_map(fn ($b) => sprintf('%02X', $b), $phoneRaw));
            $phoneStr = implode('', array_map('chr', array_slice($phoneRaw, 0, 6)));

            // --- EVITAR SESIONES DUPLICADAS (Fix Flapping) ---
            if (isset($this->terminalSockets[$phoneKey]) && $this->terminalSockets[$phoneKey] !== $socket) {
                $oldSocket = $this->terminalSockets[$phoneKey];
                if (in_array($oldSocket, $this->clients, true)) {
                    $this->warn("   -> [CLEANUP] Cerrando sesión previa para Terminal: $phoneKey");
                    $this->closeConnection($oldSocket);
                }
            }
            $this->terminalSockets[$phoneKey] = $socket;

            $this->info(sprintf('[INFO V%s] ID: 0x%04X | Serial: %d | Terminal: %s',
                $this->clientProtocols[$clientId], $msgId, $devSerial, $phoneKey));

            // --- MANEJO DE MENSAJES SEGÚN PROTOCOLO ULTRAVISION ---
            if ($msgId === 0x0100) {
                // Mensaje de registro (Table 3.3.1)
                $this->info('   -> [REG] Registro recibido');
                if ($devSerial === 0) {
                    $this->terminalSerials[$phoneKey] = 0;
                    $this->info('   -> [RESET] Forzando inicio de secuencia a 0.');
                }
                $this->respondRegistration($socket, $phoneRaw, $devSerial, $body);
                
            } elseif ($msgId === 0x0001) {
                // Confirmación del dispositivo (Table 3.1.1)
                $this->info('   -> [ACK] La cámara confirmó nuestro mensaje.');
                continue;
                
            } elseif ($msgId === 0x0102) {
                // Autenticación (Table 3.4)
                $this->info('   -> [AUTH] Autenticación recibida');
                $this->processAuthentication($socket, $phoneRaw, $devSerial, $body);
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                
            } elseif ($msgId === 0x0900) {
                // Datos transparentes (Table 3.10.1)
                $this->info('   -> [TRANSP] Datos transparentes recibidos');
                $this->processTransparentData($socket, $phoneRaw, $devSerial, $body);
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                
            } elseif ($msgId === 0x0704) {
                // Reporte de ubicación en lote (Table 3.6.1)
                $this->info('   -> [BATCH] Reporte de ubicación en lote');
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                $this->processLocationBatch($socket, $phoneKey, $body);
                
            } elseif ($msgId === 0x0200) {
                // Reporte de ubicación individual (Table 3.5.1)
                $this->info('   -> [LOC] Reporte de ubicación individual');
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                ProcessMdvrLocation::dispatch($phoneKey, bin2hex(pack('C*', ...$body)));
                
            } elseif ($msgId === 0x0002) {
                // Heartbeat (Table 3.2)
                $this->info('   -> [HEART] Heartbeat recibido');
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                
            } else {
                // Otros mensajes - respuesta general
                $this->info("   -> [UNKNOWN] Mensaje no manejado específicamente");
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
            }
        }
    }

    private function getNextSerial($phoneKey)
    {
        $current = $this->terminalSerials[$phoneKey] ?? 0;
        $this->terminalSerials[$phoneKey] = ($current + 1) % 65535;
        return $current;
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial, $body)
    {
        // Table 3.3.2 - Respuesta a registro (0x8100)
        // 1. Reply serial number (2 bytes) - Serial del mensaje recibido
        // 2. Result (1 byte) - 0=success
        // 3. Authentication code (STRING)
        
        $phoneStr = implode('', array_map('chr', array_slice($phoneRaw, 0, 6)));
        
        // Generar código de autenticación basado en el ID del terminal
        // En tu log exitoso, usabas "992001" (el ID del terminal)
        $authCode = $phoneStr; // "992001"
        
        $responseBody = [
            ($devSerial >> 8) & 0xFF,  // Reply serial high
            $devSerial & 0xFF,         // Reply serial low
            0x00,                      // Result: 0=success
        ];
        
        // Agregar código de autenticación (máx 8 bytes según especificación)
        for ($i = 0; $i < 8; $i++) {
            $responseBody[] = isset($authCode[$i]) ? ord($authCode[$i]) : 0x00;
        }
        
        $this->authCodes[implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw))] = $authCode;
        $this->info("   -> [REG] 0x8100 enviado. Auth code: $authCode");
        $this->sendPacket($socket, 0x8100, $phoneRaw, $responseBody);
    }

    private function processAuthentication($socket, $phoneRaw, $devSerial, $body)
    {
        // Table 3.4 - Procesar autenticación
        // Byte 0: Authentication code length
        // Byte 1+: Authentication code content
        // Luego: Terminal IMEI (15 bytes)
        // Luego: Firmware version (20 bytes)
        
        if (count($body) >= 1) {
            $authLength = $body[0];
            $authCode = '';
            for ($i = 1; $i <= $authLength && $i < count($body); $i++) {
                $authCode .= chr($body[$i]);
            }
            $this->info("   -> [AUTH] Código recibido: $authCode");
            
            // Verificar código (en tu caso, debería ser "992001")
            $expectedCode = implode('', array_map('chr', array_slice($phoneRaw, 0, 6)));
            if ($authCode === $expectedCode) {
                $this->info("   -> [AUTH] Código válido");
                // Enviar configuración inicial opcionalmente
                // $this->sendInitialConfiguration($socket, $phoneRaw);
            } else {
                $this->error("   -> [AUTH] Código inválido: $authCode, esperado: $expectedCode");
            }
        }
    }

    private function processTransparentData($socket, $phoneRaw, $devSerial, $body)
    {
        // Table 3.10.1 - Datos transparentes
        if (count($body) > 0) {
            $transparentType = $body[0];
            $this->info("   -> [TRANSP] Tipo: 0x" . sprintf('%02X', $transparentType));
            
            switch ($transparentType) {
                case 0xF1:
                    $this->info("   -> [TRANSP] GPS extendido (148 bytes)");
                    break;
                case 0xF3:
                    $this->info("   -> [TRANSP] GPS estándar");
                    break;
                case 0x41:
                    $this->info("   -> [TRANSP] Datos OBD");
                    break;
                case 0xA1:
                    $this->info("   -> [TRANSP] Información WiFi");
                    break;
                default:
                    if ($transparentType >= 0xF0 && $transparentType <= 0xFF) {
                        $this->info("   -> [TRANSP] Datos personalizados del usuario");
                    } else {
                        $this->info("   -> [TRANSP] Tipo desconocido");
                    }
            }
        }
    }

    private function processLocationBatch($socket, $phoneKey, $body)
    {
        // Table 3.6.1 - Procesar reporte de ubicación en lote
        if (count($body) >= 3) {
            $dataItemCount = ($body[0] << 8) | $body[1];
            $dataType = $body[2];
            $this->info("   -> [BATCH] Número de items: $dataItemCount, Tipo: $dataType");
            
            // Aquí puedes procesar cada bloque de datos de ubicación
            // Cada bloque comienza en offset 3
            $offset = 3;
            for ($i = 0; $i < $dataItemCount && $offset < count($body); $i++) {
                if ($offset + 2 <= count($body)) {
                    $itemLength = ($body[$offset] << 8) | $body[$offset + 1];
                    $offset += 2;
                    
                    if ($offset + $itemLength <= count($body)) {
                        // $itemData = array_slice($body, $offset, $itemLength);
                        // Procesar datos de ubicación (similar a 0x0200)
                        $offset += $itemLength;
                    } else {
                        break;
                    }
                }
            }
        }
    }

    private function sendInitialConfiguration($socket, $phoneRaw)
    {
        // Comando 0x8103 - Configuración de parámetros (Table 3.17.1)
        // Enviar configuración inicial (opcional)
        $params = [
            0x00, 0x01, 0x00, 0x02, 0x00, 0x3C, // 0x0001: Heartbeat interval (60 segundos)
            0x00, 0x29, 0x00, 0x02, 0x00, 0x0A, // 0x0029: Parking report interval (10 segundos)
            0x00, 0x30, 0x00, 0x02, 0x00, 0x01, // 0x0030: Moving report interval (1 segundo)
        ];
        
        $paramCount = count($params) / 6;
        $body = [$paramCount];
        foreach ($params as $byte) {
            $body[] = $byte;
        }
        
        $this->info("   -> [CFG] Enviando configuración inicial");
        $this->sendPacket($socket, 0x8103, $phoneRaw, $body);
    }

    private function respondGeneral($socket, $phoneRaw, $devSerial, $replyId)
    {
        // Table 3.1.2 - Respuesta general (0x8001)
        // 1. Reply serial number (2 bytes)
        // 2. Reply ID (2 bytes)
        // 3. Result (1 byte) - 0=success
        
        $body = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            ($replyId >> 8) & 0xFF,
            $replyId & 0xFF,
            0x00,  // Result: success
        ];
        
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $clientId = spl_object_id($socket);
        $protocol = $this->clientProtocols[$clientId] ?? '2019';
        $protocolVersion = $this->clientVersions[$clientId] ?? 0x01;
        
        $phoneKey = implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw));
        $srvSerial = $this->getNextSerial($phoneKey);
        
        // Construir header según Table 2.2.2
        $attr = count($body);
        
        if ($protocol === '2019') {
            $attr |= 0x4000;  // Bit 14 = 1 (versión 2019)
            $header = [
                ($msgId >> 8) & 0xFF,
                $msgId & 0xFF,
                ($attr >> 8) & 0xFF,
                $attr & 0xFF,
                $protocolVersion,  // Protocol version byte
            ];
        } else {
            $header = [
                ($msgId >> 8) & 0xFF,
                $msgId & 0xFF,
                ($attr >> 8) & 0xFF,
                $attr & 0xFF,
            ];
        }
        
        // Terminal phone number (BCD[10] o BCD[6])
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }
        
        // Message serial number (2 bytes)
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        
        // Unir header + body
        $full = array_merge($header, $body);
        
        // Calcular checksum (XOR de todos los bytes)
        $cs = 0;
        foreach ($full as $b) {
            $cs ^= $b;
        }
        $full[] = $cs;
        
        // Escapar 0x7E y 0x7D según sección 2.2.1
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
        
        // Enviar
        @socket_write($socket, pack('C*', ...$final));
        $hex = strtoupper(bin2hex(pack('C*', ...$final)));
        $this->line("<fg=green>[SEND HEX]</>: $hex");
        $this->info("   -> [SEND] 0x" . sprintf('%04X', $msgId) . " | Serial: $srvSerial | Len: " . count($body));
    }

    private function closeConnection($s)
    {
        if (! $s || (! is_resource($s) && ! ($s instanceof \Socket))) {
            return;
        }

        $id = spl_object_id($s);

        try {
            @socket_close($s);
        } catch (\Throwable $e) {
            // Socket ya cerrado
        }

        // Limpiar terminal de la lista de sockets activos
        foreach ($this->terminalSockets as $terminalId => $socket) {
            if ($socket === $s) {
                unset($this->terminalSockets[$terminalId]);
                break;
            }
        }

        // Limpieza de memoria
        $key = array_search($s, $this->clients, true);
        if ($key !== false) {
            unset($this->clients[$key]);
        }

        unset($this->clientBuffers[$id], $this->clientProtocols[$id], $this->clientVersions[$id]);

        $this->error("[DESC] Socket liberado y memoria limpia (ID $id).");
    }
}