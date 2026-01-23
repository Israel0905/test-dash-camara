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

    // Rastreo de sockets activos por Terminal para evitar duplicados (Socket Flapping)
    protected $terminalSockets = [];

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
            $buffer = substr($buffer, $end);

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

            $payload = array_slice($data, 1, -2);
            $msgId = ($payload[0] << 8) | $payload[1];
            $attr = ($payload[2] << 8) | $payload[3];
            $is2019 = ($attr >> 14) & 0x01;

            $this->clientProtocols[$clientId] = $is2019 ? '2019' : '2011';

            // --- PARSING DEL ENCABEZADO (Tabla 2.2.2-1) ---
            if ($is2019) {
                $phoneRaw = array_slice($payload, 5, 10);
                $devSerial = ($payload[15] << 8) | $payload[16];
                $headerLen = 17;
            } else {
                $phoneRaw = array_slice($payload, 4, 6);
                $devSerial = ($payload[10] << 8) | $payload[11];
                $headerLen = 12;
            }
            $body = array_slice($payload, $headerLen);
            $phoneKey = implode('', array_map(fn ($b) => sprintf('%02X', $b), $phoneRaw));

            // --- EVITAR SESIONES DUPLICADAS (Fix Flapping) ---
            if (isset($this->terminalSockets[$phoneKey]) && $this->terminalSockets[$phoneKey] !== $socket) {
                $oldSocket = $this->terminalSockets[$phoneKey];
                $this->warn("   -> [CLEANUP] Cerrando socket fantasma para terminal $phoneKey");
                $this->closeConnection($oldSocket);
            }
            $this->terminalSockets[$phoneKey] = $socket;

            $this->info(sprintf('[INFO V%s] ID: 0x%04X | Serial: %d | Terminal: %s',
                $this->clientProtocols[$clientId], $msgId, $devSerial, $phoneKey));

            // --- RESPUESTAS (Handshake Lógico del Manual) ---
            if ($msgId === 0x0100) {
                // Si la cámara re-inicia (Serial 0), nosotros también.
                if ($devSerial === 0) {
                    $this->terminalSerials[$phoneKey] = 0;
                }
                $this->respondRegistration($socket, $phoneRaw, $devSerial);
            } elseif ($msgId === 0x0102) {
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                // Handshake obligatorio para evitar timeout (Sección 3.17)
                $this->info('   -> Enviando Configuración Inicial (0x8103)...');
                $this->sendPacket($socket, 0x8103, $phoneRaw, [0x01, 0x00, 0x00, 0x00, 0x01, 0x04, 0x00, 0x00, 0x00, 0x1E]);
            } else {
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                if ($msgId === 0x0200) {
                    ProcessMdvrLocation::dispatch($phoneKey, bin2hex(pack('C*', ...$body)));
                }
            }
        }
    }

    private function getNextSerial($phoneKey)
    {
        $current = $this->terminalSerials[$phoneKey] ?? 0;
        $this->terminalSerials[$phoneKey] = ($current + 1) % 65535;

        return $current;
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial)
    {
        $authCode = "123456\0"; // Formato del archivo 8100消息解析 decode.txt
        $body = [($devSerial >> 8) & 0xFF, $devSerial & 0xFF, 0x00];
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }
        $this->sendPacket($socket, 0x8100, $phoneRaw, $body);
    }

    private function respondGeneral($socket, $phoneRaw, $devSerial, $replyId)
    {
        $body = [($devSerial >> 8) & 0xFF, $devSerial & 0xFF, ($replyId >> 8) & 0xFF, $replyId & 0xFF, 0x00];
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $protocol = $this->clientProtocols[spl_object_id($socket)] ?? '2019';
        $attr = count($body);
        $phoneKey = implode('', array_map(fn ($b) => sprintf('%02X', $b), $phoneRaw));
        $srvSerial = $this->getNextSerial($phoneKey);

        if ($protocol === '2019') {
            $attr |= 0x4000;
            $header = [($msgId >> 8) & 0xFF, $msgId & 0xFF, ($attr >> 8) & 0xFF, $attr & 0xFF, 0x01];
        } else {
            $header = [($msgId >> 8) & 0xFF, $msgId & 0xFF, ($attr >> 8) & 0xFF, $attr & 0xFF];
        }

        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;

        $full = array_merge($header, $body);
        $cs = 0;
        foreach ($full as $b) {
            $cs ^= $b;
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
        $key = array_search($s, $this->clients);
        if ($key !== false) {
            unset($this->clients[$key]);
        }
        unset($this->clientBuffers[$id], $this->clientProtocols[$id]);
        $this->error("[DESC] Cámara desconectada (ID $id).");
    }
}
