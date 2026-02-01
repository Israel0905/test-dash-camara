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

    // Contador de intentos de registro por terminal
    protected $registrationAttempts = [];

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

            $this->line("\n<fg=yellow>[RAW IN ]</> " . strtoupper(bin2hex($singlePacket)));

            // --- UNESCAPE ---
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
                continue;
            }

            // Parsear header
            $msgId = ($packetData[0] << 8) | $packetData[1];
            $attr = ($packetData[2] << 8) | $packetData[3];
            $is2019 = ($attr >> 14) & 0x01;
            $bodyLength = $attr & 0x03FF;

            $this->clientProtocols[$clientId] = $is2019 ? '2019' : '2011';

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
                $this->handleRegistration($socket, $phoneRaw, $devSerial, $body, $phoneKey);
            } elseif ($msgId === 0x0102) {
                $this->handleAuthentication($socket, $phoneRaw, $devSerial, $body, $phoneKey);
            } elseif ($msgId === 0x0002) {
                $this->handleHeartbeat($socket, $phoneRaw, $devSerial);
            } else {
                $this->handleOtherMessage($socket, $phoneRaw, $devSerial, $msgId);
            }
        }
    }

    private function handleRegistration($socket, $phoneRaw, $devSerial, $body, $phoneKey)
    {
        $phoneStr = implode('', array_map('chr', array_slice($phoneRaw, 0, 6)));
        
        // Limitar intentos de registro (evitar bucle infinito)
        if (!isset($this->registrationAttempts[$phoneKey])) {
            $this->registrationAttempts[$phoneKey] = 0;
        }
        
        $this->registrationAttempts[$phoneKey]++;
        
        if ($this->registrationAttempts[$phoneKey] > 3) {
            $this->error("   -> [REG ERR] Demasiados intentos de registro para $phoneStr. Cerrando conexión.");
            $this->closeConnection($socket);
            return;
        }
        
        $this->info("   -> [REG] Intentando registro ($this->registrationAttempts[$phoneKey]/3) para: $phoneStr");
        
        // Parsear datos de registro del body (Table 3.3.1)
        // Province ID (2 bytes), County ID (2 bytes), Manufacturer ID (11 bytes), etc.
        // Pero para la respuesta solo necesitamos el serial del mensaje
        
        // Enviar respuesta 0x8100 según el formato EXACTO del manual
        $this->sendUltravisionRegistrationResponse($socket, $phoneRaw, $devSerial, $phoneStr);
    }

    private function sendUltravisionRegistrationResponse($socket, $phoneRaw, $devSerial, $phoneStr)
    {
        // FORMATO SEGÚN EL MANUAL ULTRAVISION Y EL ARCHIVO decode.txt:
        // 1. Reply serial number (2 bytes) - Serial del mensaje 0x0100 recibido
        // 2. Result (1 byte) - 0=success
        // 3. Authentication code (STRING) - Según decode.txt: "8390123456789" + null
        
        // En tu caso, el terminal ID es "992001" (6 bytes)
        // Pero según el ejemplo del manual, el auth code debe tener al menos 13 bytes + null
        
        // Crear código de autenticación: "AUTH" + terminal ID + timestamp
        $authCode = "AUTH" . $phoneStr . time();
        // Limitar a 13 bytes (como en el ejemplo)
        $authCode = substr($authCode, 0, 13);
        
        $body = [
            ($devSerial >> 8) & 0xFF,  // Reply serial high
            $devSerial & 0xFF,         // Reply serial low
            0x00,                      // Result: 0=success
        ];
        
        // Agregar auth code como string (13 bytes + null terminator = 14 bytes)
        for ($i = 0; $i < 13; $i++) {
            $body[] = isset($authCode[$i]) ? ord($authCode[$i]) : 0x00;
        }
        $body[] = 0x00; // Null terminator
        
        // Guardar auth code para verificación posterior
        $phoneKey = implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw));
        $this->authCodes[$phoneKey] = $authCode;
        
        $this->info("   -> [SEND] 0x8100 -> Auth code: $authCode");
        $this->sendPacket($socket, 0x8100, $phoneRaw, $body);
    }

    private function handleAuthentication($socket, $phoneRaw, $devSerial, $body, $phoneKey)
    {
        $phoneStr = implode('', array_map('chr', array_slice($phoneRaw, 0, 6)));
        
        $this->info("   -> [AUTH] Autenticación recibida de: $phoneStr");
        
        // Parsear auth code del body (Table 3.4)
        if (count($body) >= 1) {
            $authLength = $body[0];
            $receivedAuth = '';
            
            for ($i = 1; $i <= $authLength && $i < count($body); $i++) {
                $receivedAuth .= chr($body[$i]);
            }
            
            $this->info("   -> [AUTH] Código recibido: $receivedAuth");
            
            // Verificar contra el código guardado
            $expectedAuth = $this->authCodes[$phoneKey] ?? '';
            
            if ($expectedAuth && $receivedAuth === $expectedAuth) {
                $this->info("   -> [AUTH] Autenticación EXITOSA");
                $this->connectionState[$phoneKey] = 'authenticated';
                
                // Responder con éxito
                $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, 0x0102, 0x00);
                
                // Resetear contador de intentos
                $this->registrationAttempts[$phoneKey] = 0;
                
                // Opcional: enviar configuración después de autenticar
                // $this->sendInitialConfiguration($socket, $phoneRaw);
                
            } else {
                $this->error("   -> [AUTH] FALLIDA. Esperado: $expectedAuth, Recibido: $receivedAuth");
                $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, 0x0102, 0x01); // Failure
            }
        } else {
            $this->error("   -> [AUTH] Body vacío o inválido");
            $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, 0x0102, 0x01); // Failure
        }
    }

    private function handleHeartbeat($socket, $phoneRaw, $devSerial)
    {
        $this->info("   -> [HEART] Heartbeat recibido");
        $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, 0x0002, 0x00);
    }

    private function handleOtherMessage($socket, $phoneRaw, $devSerial, $msgId)
    {
        $phoneKey = implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw));
        
        // Verificar si el terminal está autenticado
        $state = $this->connectionState[$phoneKey] ?? 'unknown';
        
        if ($state !== 'authenticated' && $msgId !== 0x0100 && $msgId !== 0x0102) {
            $this->error("   -> [ERR] Terminal no autenticado intentando enviar 0x" . sprintf('%04X', $msgId));
            return;
        }
        
        switch ($msgId) {
            case 0x0900:
                $this->info("   -> [TRANSP] Datos transparentes");
                $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, $msgId, 0x00);
                break;
                
            case 0x0704:
                $this->info("   -> [BATCH] Reporte de ubicación en lote");
                $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, $msgId, 0x00);
                break;
                
            case 0x0200:
                $this->info("   -> [LOC] Reporte de ubicación individual");
                $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, $msgId, 0x00);
                // ProcessMdvrLocation::dispatch($phoneKey, ...);
                break;
                
            default:
                $this->info("   -> [UNKNOWN] Mensaje 0x" . sprintf('%04X', $msgId));
                $this->sendGeneralResponse($socket, $phoneRaw, $devSerial, $msgId, 0x00);
        }
    }

    private function sendGeneralResponse($socket, $phoneRaw, $devSerial, $replyId, $result)
    {
        // Table 3.1.2 - Respuesta general (0x8001)
        $body = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            ($replyId >> 8) & 0xFF,
            $replyId & 0xFF,
            $result, // 0=success, 1=failure, etc.
        ];
        
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
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
        
        // Construir header
        $attr = count($body);
        
        if ($protocol === '2019') {
            $attr |= 0x4000; // Bit 14 = 1 (versión 2019)
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
        
        // Enviar con verificación de error
        $data = pack('C*', ...$final);
        $result = @socket_write($socket, $data);
        
        if ($result === false) {
            $errorCode = socket_last_error($socket);
            $errorMsg = socket_strerror($errorCode);
            $this->error("   -> [SEND ERR] Error al enviar: $errorMsg");
            $this->closeConnection($socket);
            return;
        }
        
        $hex = strtoupper(bin2hex($data));
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

        // Limpiar todas las referencias a este socket
        foreach ($this->terminalSockets as $terminalId => $socket) {
            if ($socket === $s) {
                unset($this->terminalSockets[$terminalId]);
                unset($this->connectionState[$terminalId]);
                unset($this->registrationAttempts[$terminalId]);
                unset($this->authCodes[$terminalId]);
                break;
            }
        }

        $key = array_search($s, $this->clients, true);
        if ($key !== false) {
            unset($this->clients[$key]);
        }

        unset($this->clientBuffers[$id], $this->clientProtocols[$id], $this->clientVersions[$id]);

        $this->error("[DESC] Cámara desconectada (ID $id)");
    }
}