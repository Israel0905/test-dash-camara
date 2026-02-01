<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMdvrLocation;
use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8809}';

    protected $description = 'Servidor JT/T 808 compatible con Ultravision N6 (2019)';

    // Seriales persistentes por ID de Terminal
    protected $terminalSerials = [];

    // Buffers por Socket ID
    protected $clientBuffers = [];

    protected $clients = [];

    // Rastreo de sockets activos por Terminal
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
        $this->info("[DEBUG MDVR] ESCUCHANDO EN PUERTO $port (Protocolo 2019)");
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
            
            // Parsear header (Table 2.2.2 - Protocolo 2019)
            // Header de 17 bytes para 2019
            if (count($payload) < 17) {
                $this->error("   -> [ERROR] Paquete demasiado corto: " . count($payload) . " bytes");
                continue;
            }
            
            $msgId = ($payload[0] << 8) | $payload[1];
            $attr = ($payload[2] << 8) | $payload[3];
            $protocolVersion = $payload[4]; // Byte de versión de protocolo (inicial 1)
            $phoneRaw = array_slice($payload, 5, 10); // BCD[10] = 10 bytes
            $devSerial = ($payload[15] << 8) | $payload[16];
            $headerLen = 17;
            
            $body = array_slice($payload, $headerLen);
            
            // Convertir número de terminal a string para identificarlo
            $phoneHex = '';
            foreach ($phoneRaw as $byte) {
                $phoneHex .= sprintf('%02X', $byte);
            }
            $phoneKey = $phoneHex;
            
            // Mostrar número de terminal de forma legible (BCD)
            $phoneBCD = '';
            foreach ($phoneRaw as $byte) {
                // Convertir byte BCD a dos dígitos decimales
                $high = ($byte >> 4) & 0x0F;
                $low = $byte & 0x0F;
                $phoneBCD .= $high . $low;
            }
            $phoneBCD = ltrim($phoneBCD, '0');

            // --- EVITAR SESIONES DUPLICADAS ---
            if (isset($this->terminalSockets[$phoneKey]) && 
                $this->terminalSockets[$phoneKey] !== $socket) {
                $oldSocket = $this->terminalSockets[$phoneKey];
                if (in_array($oldSocket, $this->clients, true)) {
                    $this->warn("   -> [CLEANUP] Cerrando sesión previa para Terminal: $phoneBCD");
                    $this->closeConnection($oldSocket);
                }
            }
            $this->terminalSockets[$phoneKey] = $socket;

            $this->info(sprintf('[INFO] ID: 0x%04X | Serial: %d | Terminal: %s',
                $msgId, $devSerial, $phoneBCD));

            // --- MANEJO DE MENSAJES ---
            switch ($msgId) {
                case 0x0100: // Registro
                    $this->info('   -> [REG] Registro recibido');
                    
                    $phoneHex = implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw));
                    $alreadyRegistered = isset($this->terminalSerials[$phoneHex]);
                    
                    if (!$alreadyRegistered || $devSerial === 0) {
                        $this->terminalSerials[$phoneHex] = 0;
                        if ($devSerial === 0) {
                            $this->info('   -> [RESET] Forzando inicio de secuencia a 0.');
                        }
                    }
                    
                    // PRUEBA ESTAS DOS VERSIONES:
                    // 1. Versión SIMPLE (recomendada primero)
                    // $this->respondRegistrationSimple($socket, $phoneRaw, $devSerial, $body);
                    
                    // 2. Versión COMPLETA con auth code
                    $this->respondRegistrationWithAuth($socket, $phoneRaw, $devSerial, $body);
                    break;
                    
                case 0x0001: // Confirmación (Table 3.1.1)
                    $this->info('   -> [ACK] Confirmación recibida');
                    break;
                    
                case 0x0102: // Autenticación (Table 3.4)
                    $this->info('   -> [AUTH] Autenticación recibida');
                    $this->processAuthentication($socket, $phoneRaw, $devSerial, $body);
                    $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                    break;
                    
                case 0x0900: // Datos transparentes (Table 3.10.1)
                    $this->info('   -> [TRANSP] Datos transparentes recibidos');
                    $this->processTransparentData($body);
                    $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                    break;
                    
                case 0x0704: // Reporte de ubicación en lote (Table 3.6.1)
                    $this->info('   -> [BATCH] Reporte de ubicación en lote');
                    $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                    $this->processLocationBatch($phoneKey, $body);
                    break;
                    
                case 0x0200: // Reporte de ubicación individual (Table 3.5.1)
                    $this->info('   -> [LOC] Reporte de ubicación individual');
                    $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                    // Despachar job para procesar ubicación
                    ProcessMdvrLocation::dispatch($phoneKey, bin2hex(pack('C*', ...$body)));
                    break;
                    
                case 0x0002: // Heartbeat (Table 3.2)
                    $this->info('   -> [HEART] Heartbeat recibido');
                    $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                    break;
                    
                default:
                    $this->info("   -> [UNKNOWN] Mensaje no manejado (0x" . sprintf('%04X', $msgId) . ")");
                    // Responder con respuesta general para cualquier mensaje no manejado
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

    private function respondRegistrationSimple($socket, $phoneRaw, $devSerial, $body)
    {
        // Table 3.3.2 - Respuesta SIMPLE como en el ejemplo exitoso
        // Solo 3 bytes: reply serial (2) + result (1)
        // ¡NO enviar auth code! Como en el ejemplo 7E 81 00 40 11 01...
        
        $responseBody = [
            ($devSerial >> 8) & 0xFF,  // Reply serial high
            $devSerial & 0xFF,         // Reply serial low
            0x00,                      // Result: 0=success
        ];
        
        $this->info("   -> [REG] 0x8100 enviado (simple, solo 3 bytes)");
        $this->sendPacket($socket, 0x8100, $phoneRaw, $responseBody);
    }

    private function respondRegistrationWithAuth($socket, $phoneRaw, $devSerial, $body)
    {
        // VERSIÓN ALTERNATIVA: Con auth code (como especifica el manual)
        // Table 3.3.2 - Respuesta completa con auth code
        
        // Convertir phoneRaw a string BCD
        $phoneBCD = '';
        foreach ($phoneRaw as $byte) {
            $high = ($byte >> 4) & 0x0F;
            $low = $byte & 0x0F;
            $phoneBCD .= $high . $low;
        }
        $phoneBCD = ltrim($phoneBCD, '0');
        
        // Usar el ID del terminal como auth code
        $authCode = $phoneBCD;
        
        $responseBody = [
            ($devSerial >> 8) & 0xFF,  // Reply serial high
            $devSerial & 0xFF,         // Reply serial low
            0x00,                      // Result: 0=success
        ];
        
        // Agregar código de autenticación (STRING)
        $authBytes = [];
        for ($i = 0; $i < strlen($authCode); $i++) {
            $authBytes[] = ord($authCode[$i]);
        }
        // Terminador NULL
        $authBytes[] = 0x00;
        
        $responseBody = array_merge($responseBody, $authBytes);
        
        $this->authCodes[implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw))] = $authCode;
        $this->info("   -> [REG] 0x8100 enviado (con auth code: $authCode)");
        $this->sendPacket($socket, 0x8100, $phoneRaw, $responseBody);
    }

    private function processAuthentication($socket, $phoneRaw, $devSerial, $body)
    {
        // Table 3.4 - Procesar autenticación
        if (count($body) >= 1) {
            $authLength = $body[0];
            $authCode = '';
            for ($i = 1; $i <= $authLength && $i < count($body); $i++) {
                $authCode .= chr($body[$i]);
            }
            $this->info("   -> [AUTH] Código recibido: $authCode");
            
            // Verificar código
            $phoneHex = implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw));
            $expectedCode = $this->authCodes[$phoneHex] ?? '';
            
            if ($authCode === $expectedCode) {
                $this->info("   -> [AUTH] Código válido");
            } else {
                $this->error("   -> [AUTH] Código inválido. Recibido: '$authCode', Esperado: '$expectedCode'");
            }
        }
    }

    private function processTransparentData($body)
    {
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
                        $this->info("   -> [TRANSP] Datos personalizados");
                    } else {
                        $this->info("   -> [TRANSP] Tipo desconocido");
                    }
            }
        }
    }

    private function processLocationBatch($phoneKey, $body)
    {
        if (count($body) >= 3) {
            $dataItemCount = ($body[0] << 8) | $body[1];
            $dataType = $body[2];
            $this->info("   -> [BATCH] Número de items: $dataItemCount, Tipo: $dataType");
            
            $offset = 3;
            for ($i = 0; $i < $dataItemCount && $offset < count($body); $i++) {
                if ($offset + 2 <= count($body)) {
                    $itemLength = ($body[$offset] << 8) | $body[$offset + 1];
                    $offset += 2;
                    
                    if ($offset + $itemLength <= count($body)) {
                        // Procesar datos de ubicación
                        $offset += $itemLength;
                    } else {
                        break;
                    }
                }
            }
        }
    }

    private function respondGeneral($socket, $phoneRaw, $devSerial, $replyId)
    {
        // Table 3.1.2 - Respuesta general (0x8001)
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
        $phoneKey = implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw));
        $srvSerial = $this->getNextSerial($phoneKey);
        
        // Construir header para protocolo 2019
        $attr = count($body);
        $attr |= 0x4000;  // Bit 14 = 1 (versión 2019)
        
        $header = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($attr >> 8) & 0xFF,
            $attr & 0xFF,
            0x01,  // Protocol version byte
        ];
        
        // Terminal phone number (BCD[10])
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }
        
        // Message serial number
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        
        // Unir header + body
        $full = array_merge($header, $body);
        
        // Calcular checksum
        $cs = 0;
        foreach ($full as $b) {
            $cs ^= $b;
        }
        $full[] = $cs;
        
        // Escapar
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

        unset($this->clientBuffers[$id]);

        $this->error("[DESC] Socket liberado y memoria limpia (ID $id).");
    }
}