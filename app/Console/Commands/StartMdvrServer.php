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

    // Buffers y Protocolos por Socket ID (Sesión TCP actual)
    protected $clientBuffers = [];

    protected $clientProtocols = [];

    protected $clients = [];          // Added: Track all active sockets

    protected $terminalSockets = [];  // Added: Track socket by Terminal ID for cleanup

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
            $buffer = substr($buffer, $end); // Soporte para delimitador compartido

            if (strlen($singlePacket) < 12) {
                continue;
            }

            $this->line("\n<fg=yellow>[RAW RECV]</>: ".strtoupper(bin2hex($singlePacket)));

            // --- UNESCAPE ---
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

            if (count($data) < 12) {
                continue;
            }

            $payload = array_slice($data, 1, -2);
            $msgId = ($payload[0] << 8) | $payload[1];
            $attr = ($payload[2] << 8) | $payload[3];
            $is2019 = ($attr >> 14) & 0x01;
            $hasSub = ($attr >> 13) & 0x01;

            $this->clientProtocols[$clientId] = $is2019 ? '2019' : '2011';

            // --- DYNAMIC HEADER PARSING ---
            if ($is2019) {
                $phoneRaw = array_slice($payload, 5, 10);
                $devSerial = ($payload[15] << 8) | $payload[16];
                $headerLen = 17;
            } else {
                $phoneRaw = array_slice($payload, 4, 6);
                $devSerial = ($payload[10] << 8) | $payload[11];
                $headerLen = 12;
            }
            if ($hasSub) {
                $headerLen += 4;
            }

            $body = array_slice($payload, $headerLen);

            // Convertir Phone a Hex para logs y serial persistence
            $phoneKey = implode('', array_map(fn ($b) => sprintf('%02X', $b), $phoneRaw));
            $phoneHex = $phoneKey; // Mantener compatibilidad con variable usada en logs

            // --- CLEANUP DUPLICATE SESSIONS (FIX SOCKET FLAPPING) ---
            // Si ya existe un socket registrado para esta terminal y es diferente al actual, lo cerramos.
            if (isset($this->terminalSockets[$phoneHex]) && $this->terminalSockets[$phoneHex] !== $socket) {
                $oldSocket = $this->terminalSockets[$phoneHex];
                // Verificamos si sigue en la lista de clientes activos antes de intentar cerrar
                if (in_array($oldSocket, $this->clients, true)) {
                    $this->warn("   -> [CLEANUP] Cerrando socket duplicado/fantasma para Terminal: $phoneHex");
                    $this->closeConnection($oldSocket);
                }
            }
            $this->terminalSockets[$phoneHex] = $socket; // Registramos el socket actual como el válido

            $this->info(sprintf('[INFO V%s] ID: 0x%04X | Serial: %d | Terminal: %s',
                $this->clientProtocols[$clientId], $msgId, $devSerial, $phoneHex));

            // --- RESPUESTAS ---
            if ($msgId === 0x0100) {
                // RESET CONDICIONAL: Solo si la cámara empieza de 0 (nueva sesión real).
                // Si es un reintento (serial 1, 2...), NO reseteamos para mantener la secuencia.
                if ($devSerial === 0) {
                    $this->terminalSerials[$phoneKey] = 0;
                    $this->info('   -> [RESET] Nueva sesión detectada (Serial 0). Secuencia reiniciada.');
                }

                $this->respondRegistration($socket, $phoneRaw, $devSerial, $body);
            } elseif ($msgId === 0x0001) {
                $this->info('   -> [OK] La cámara confirmó nuestro mensaje.');

                continue;
            } elseif ($msgId === 0x0102) {
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);

                // Enviamos una configuración de intervalo de latido (Heartbeat) a 30s para estabilizar sesión.
                // Mensaje 0x8103: Set Terminal Parameters
                // Body: [Count=1] [ID=0x00000001] [Len=4] [Value=30]
                $paramBody = [
                    0x01,                   // Cantidad de parámetros: 1
                    0x00, 0x00, 0x00, 0x01, // ID Parámetro: Heartbeat Interval
                    0x04,                   // Longitud: 4 bytes
                    0x00, 0x00, 0x00, 0x1E,  // Valor: 30 segundos
                ];
                $this->info('   -> Configurando Parámetros (0x8103) para evitar desconexión...');
                $this->sendPacket($socket, 0x8103, $phoneRaw, $paramBody);

                // FIX: Eliminamos 0x8104 para evitar sobrecargar la negociación inicial o causar loops en puerto 8810
                // Nos basamos solo en 0x8103 (Heartbeat) para estabilizar.
            } else {
                // Confirmación General para TODOS (evita timeouts)
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);

                // --- PROCESAMIENTO ASÍNCRONO ---
                if ($msgId === 0x0200) {
                    $bodyHex = bin2hex(pack('C*', ...$body));
                    $this->info('   -> [QUEUE] Enviando GPS a ProcessMdvrLocation...');
                    ProcessMdvrLocation::dispatch($phoneHex, $bodyHex);
                }
            }
        }
    }

    private function getNextSerial($phoneKey)
    {
        if (! isset($this->terminalSerials[$phoneKey])) {
            $this->terminalSerials[$phoneKey] = 0;
        }
        $current = $this->terminalSerials[$phoneKey];
        $this->terminalSerials[$phoneKey] = ($current + 1) % 65535;

        return $current;
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial, $body)
    {
        $authCode = "123456\0"; // Terminador nulo requerido por N6
        $responseBody = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            0x00,
        ];
        foreach (str_split($authCode) as $char) {
            $responseBody[] = ord($char);
        }

        $this->info('   -> Enviando Respuesta Registro (0x8100)...');
        $this->sendPacket($socket, 0x8100, $phoneRaw, $responseBody);
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

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $protocol = $this->clientProtocols[spl_object_id($socket)] ?? '2019';
        $attr = count($body);
        $header = [];

        if ($protocol === '2019') {
            $attr |= 0x4000;
            $header = [($msgId >> 8) & 0xFF, $msgId & 0xFF, ($attr >> 8) & 0xFF, $attr & 0xFF, 0x01];
        } else {
            $header = [($msgId >> 8) & 0xFF, $msgId & 0xFF, ($attr >> 8) & 0xFF, $attr & 0xFF];
        }

        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        // USA SERIAL PERSISTENTE POR TELÉFONO
        $phoneKey = implode('', array_map(fn ($b) => sprintf('%02X', $b), $phoneRaw));
        $srvSerial = $this->getNextSerial($phoneKey);

        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;

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
        $this->line('<fg=green>[SEND HEX]</>: '.strtoupper(bin2hex(pack('C*', ...$final))));
    }

    private function closeConnection($s)
    {
        $id = spl_object_id($s);
        @socket_close($s);
        // NO BORRAMOS terminalSerials para soportar reconexión

        $key = array_search($s, $this->clients);
        if ($key !== false) {
            unset($this->clients[$key]);
        }

        unset($this->clientBuffers[$id], $this->clientProtocols[$id]);
        $this->error("[DESC] Cámara desconectada (ID $id).");
    }
}
