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

    protected $clientVersions = [];

    // Rastreo de sockets activos por Terminal para evitar duplicados
    protected $terminalSockets = [];

    // Cache de autenticación por terminal
    protected $authCodes = [];

    // Estado de conexión por terminal
    protected $connectionState = [];

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
        $this->info("[BOOT] JT/T808 escuchando en puerto $port");
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
                            $this->clientVersions[$clientId] = 0x01;
                            $this->warn("[CONN] Cámara conectada (ID $clientId)");
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
                // No hay inicio de paquete, limpiar buffer
                $buffer = '';
                break;
            }

            $end = strpos($buffer, chr(0x7E), $start + 1);
            if ($end === false) {
                // No hay fin de paquete, mantener en buffer
                break;
            }

            $packetLength = $end - $start + 1;
            $singlePacket = substr($buffer, $start, $packetLength);
            
            // Eliminar el paquete procesado del buffer
            $buffer = substr($buffer, $end + 1);

            if (strlen($singlePacket) < 12) {
                continue;
            }

            $this->line("\n<fg=yellow>[RAW IN ]</> " . strtoupper(bin2hex($singlePacket)));

            // --- UNESCAPE (Sección 2.1 del manual) ---
            $bytes = array_values(unpack('C*', $singlePacket));
            $unescaped = [];
            
            for ($i = 0; $i < count($bytes); $i++) {
                if ($bytes[$i] === 0x7D && isset($bytes[$i + 1])) {
                    if ($bytes[$i + 1] === 0x01) {
                        $unescaped[] = 0x7D;
                        $i++;
                    } elseif ($bytes[$i + 1] === 0x02) {
                        $unescaped[] = 0x7E;
                        $i++;
                    } else {
                        $unescaped[] = $bytes[$i];
                    }
                } else {
                    $unescaped[] = $bytes[$i];
                }
            }

            // Eliminar 0x7E inicial y final
            $packetData = array_slice($unescaped, 1, -2);
            
            if (count($packetData) < 12) {
                $this->error("   -> [ERR] Paquete demasiado corto");
                continue;
            }

            // Parsear header (Table 2.2.2)
            $msgId = ($packetData[0] << 8) | $packetData[1];
            $attr = ($packetData[2] << 8) | $packetData[3];
            $is2019 = ($attr >> 14) & 0x01;
            $bodyLength = $attr & 0x03FF;

            $this->clientProtocols[$clientId] = $is2019 ? '2019' : '2011';

            // Parsear según versión
            if ($is2019) {
                $this->clientVersions[$clientId] = $packetData[4] ?? 0x01;
                $phoneRaw = array_slice($packetData, 5, 10);
                $devSerial = ($packetData[15] << 8) | $packetData[16];
                $headerLen = 17;
            } else {
                $phoneRaw = array_slice($packetData, 4, 6);
                $devSerial = ($packetData[10] << 8) | $packetData[11];
                $headerLen = 12;
            }

            $body = array_slice($packetData, $headerLen);
            $phoneKey = implode('', array_map(fn ($b) => sprintf('%02X', $b), $phoneRaw));
            $phoneStr = implode('', array_map('chr', array_slice($phoneRaw, 0, 6)));

            $this->info(sprintf('[RECV] Msg=0x%04X Serial=%d Term=%s Ver=%s',
                $msgId, $devSerial, $phoneStr, $this->clientProtocols[$clientId]));

            // Manejar sesiones duplicadas
            if (isset($this->terminalSockets[$phoneKey]) && $this->terminalSockets[$phoneKey] !== $socket) {
                $oldSocket = $this->terminalSockets[$phoneKey];
                if (in_array($oldSocket, $this->clients, true)) {
                    $this->warn("   -> [DUP] Cerrando sesión duplicada para: $phoneKey");
                    $this->closeConnection($oldSocket);
                }
            }
            $this->terminalSockets[$phoneKey] = $socket;

            // Manejar mensajes
            if ($msgId === 0x0100) {
                $this->handleRegistration($socket, $phoneRaw, $devSerial, $body);
            } elseif ($msgId === 0x0102) {
                $this->handleAuthentication($socket, $phoneRaw, $devSerial, $body);
            } elseif ($msgId === 0x0900) {
                $this->handleTransparentData($socket, $phoneRaw, $devSerial, $body);
            } elseif ($msgId === 0x0704) {
                $this->handleBatchLocation($socket, $phoneRaw, $devSerial, $body);
            } elseif ($msgId === 0x0200) {
                $this->handleLocationReport($socket, $phoneKey, $phoneRaw, $devSerial, $body);
            } elseif ($msgId === 0x0002) {
                $this->handleHeartbeat($socket, $phoneRaw, $devSerial);
            } else {
                $this->handleUnknownMessage($socket, $phoneRaw, $devSerial, $msgId);
            }
        }
    }

    private function handleRegistration($socket, $phoneRaw, $devSerial, $body)
    {
        $phoneKey = implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw));
        $phoneStr = implode('', array_map('chr', array_slice($phoneRaw, 0, 6)));
        
        $this->info("   -> [REG] Procesando registro para terminal: $phoneStr");
        
        // Si es serial 0, resetear estado
        if ($devSerial === 0) {
            $this->terminalSerials[$phoneKey] = 0;
            $this->connectionState[$phoneKey] = 'registering';
            $this->info("   -> [REG] Iniciando registro nuevo");
        }
        
        // Responder con 0x8100 según Table 3.3.2
        $this->sendRegistrationResponse($socket, $phoneRaw, $devSerial, $phoneStr);
    }

    private function sendRegistrationResponse($socket, $phoneRaw, $devSerial, $phoneStr)
    {
        // Según Table 3.3.2 y el ejemplo en 8100消息解析 decode.txt
        // Body: [reply_serial_high, reply_serial_low, result, auth_code...]
        
        $body = [
            ($devSerial >> 8) & 0xFF,  // Reply serial high
            $devSerial & 0xFF,         // Reply serial low
            0x00,                      // Result: 0=success
        ];
        
        // Código de autenticación: usar el ID del terminal (6 bytes)
        // En el ejemplo del manual es "AUTH0000001115" (13 chars + null)
        // En tu caso, según el log, usas "992001" (6 bytes)
        $authCode = $phoneStr; // "992001"
        
        // Agregar auth code (hasta 8 bytes, rellenar con null si es necesario)
        for ($i = 0; $i < 8; $i++) {
            $body[] = isset($authCode[$i]) ? ord($authCode[$i]) : 0x00;
        }
        
        $this->info("   -> [SEND] 0x8100 -> Respondiendo con ID como Auth: $authCode");
        $this->sendPacket($socket, 0x8100, $phoneRaw, $body);
    }

    private function handleAuthentication($socket, $phoneRaw, $devSerial, $body)
    {
        $phoneKey = implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw));
        
        $this->info("   -> [AUTH] Autenticación recibida");
        
        // Parsear auth code del body (Table 3.4)
        if (count($body) >= 1) {
            $authLength = $body[0];
            $receivedAuth = '';
            for ($i = 1; $i <= $authLength && $i < count($body); $i++) {
                $receivedAuth .= chr($body[$i]);
            }
            $this->info("   -> [AUTH] Código recibido: $receivedAuth");
            
            // Verificar auth code (debe ser "992001")
            $expectedAuth = implode('', array_map('chr', array_slice($phoneRaw, 0, 6)));
            if ($receivedAuth === $expectedAuth) {
                $this->info("   -> [AUTH] Autenticación exitosa");
                $this->connectionState[$phoneKey] = 'authenticated';
                
                // Responder con 0x8001 (Table 3.1.2)
                $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, 0x0102);
                
                // Opcional: enviar configuración inicial
                $this->sendInitialConfiguration($socket, $phoneRaw);
            } else {
                $this->error("   -> [AUTH] Error: código inválido");
                $this->connectionState[$phoneKey] = 'auth_failed';
                $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, 0x0102, 0x01); // Result: failure
            }
        }
    }

    private function handleTransparentData($socket, $phoneRaw, $devSerial, $body)
    {
        $this->info("   -> [TRANSP] Datos transparentes recibidos");
        
        // Responder con 0x8001
        $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, 0x0900);
        
        // Parsear tipo de datos transparentes (primer byte)
        if (count($body) > 0) {
            $transparentType = $body[0];
            $this->info("   -> [TRANSP] Tipo: 0x" . sprintf('%02X', $transparentType));
        }
    }

    private function handleBatchLocation($socket, $phoneRaw, $devSerial, $body)
    {
        $this->info("   -> [BATCH] Reporte de ubicación en lote");
        
        // Responder con 0x8001
        $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, 0x0704);
        
        // Parsear datos batch (Table 3.6.1)
        if (count($body) >= 3) {
            $dataItemCount = ($body[0] << 8) | $body[1];
            $dataType = $body[2];
            $this->info("   -> [BATCH] Items: $dataItemCount, Tipo: $dataType");
        }
    }

    private function handleLocationReport($socket, $phoneKey, $phoneRaw, $devSerial, $body)
    {
        $this->info("   -> [LOC] Reporte de ubicación individual");
        
        // Responder con 0x8001
        $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, 0x0200);
        
        // Procesar ubicación en background job
        ProcessMdvrLocation::dispatch($phoneKey, bin2hex(pack('C*', ...$body)));
    }

    private function handleHeartbeat($socket, $phoneRaw, $devSerial)
    {
        $this->info("   -> [HEART] Heartbeat recibido");
        
        // Responder con 0x8001
        $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, 0x0002);
    }

    private function handleUnknownMessage($socket, $phoneRaw, $devSerial, $msgId)
    {
        $this->info("   -> [UNKNOWN] Mensaje no manejado: 0x" . sprintf('%04X', $msgId));
        
        // Responder con 0x8001 genérico
        $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, $msgId);
    }

    private function sendGeneralResponse($socket, $phoneRaw, $devSerial, $replyId, $result = 0x00)
    {
        // Table 3.1.2 - Respuesta general (0x8001)
        $body = [
            ($devSerial >> 8) & 0xFF,  // Reply serial high
            $devSerial & 0xFF,         // Reply serial low
            ($replyId >> 8) & 0xFF,    // Reply ID high
            $replyId & 0xFF,           // Reply ID low
            $result,                   // Result: 0=success, 1=failure, etc.
        ];
        
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendInitialConfiguration($socket, $phoneRaw)
    {
        // Opcional: enviar configuración inicial según Table 3.17.1
        // En este caso, NO enviaremos configuración automáticamente
        // ya que la cámara Ultravision parece funcionar sin ella
        $this->info("   -> [CFG] Configuración inicial opcional (no enviada)");
    }

    private function getNextSerial($phoneKey)
    {
        $current = $this->terminalSerials[$phoneKey] ?? 0;
        $this->terminalSerials[$phoneKey] = ($current + 1) % 65535;
        return $current;
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
                $protocolVersion,
            ];
        } else {
            $header = [
                ($msgId >> 8) & 0xFF,
                $msgId & 0xFF,
                ($attr >> 8) & 0xFF,
                $attr & 0xFF,
            ];
        }
        
        // Terminal phone number
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }
        
        // Message serial number
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        
        // Unir header + body
        $full = array_merge($header, $body);
        
        // Calcular checksum (XOR)
        $cs = 0;
        foreach ($full as $b) {
            $cs ^= $b;
        }
        $full[] = $cs;
        
        // Escapar 0x7E y 0x7D
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
        $this->line("<fg=green>[RAW OUT]</> $hex");
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
            // Ignorar error si ya está cerrado
        }

        // Limpiar de terminalSockets
        foreach ($this->terminalSockets as $terminalId => $socket) {
            if ($socket === $s) {
                unset($this->terminalSockets[$terminalId]);
                break;
            }
        }

        // Limpiar de clients
        $key = array_search($s, $this->clients, true);
        if ($key !== false) {
            unset($this->clients[$key]);
        }

        unset($this->clientBuffers[$id], $this->clientProtocols[$id], $this->clientVersions[$id]);

        $this->error("[DESC] Cámara desconectada (ID $id)");
    }
}