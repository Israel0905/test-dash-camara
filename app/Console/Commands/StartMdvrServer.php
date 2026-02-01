<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMdvrLocation;
use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8809}';
    protected $description = 'Servidor JT/T 808 compatible con Ultravision N6 (2011/2019)';

    // Persistencia en memoria
    protected $terminalSerials = [];
    protected $deviceStates = []; 
    protected $clientBuffers = [];
    protected $clientProtocols = [];
    protected $clientVersions = [];
    protected $clients = [];
    protected $terminalSockets = [];

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
            if ($end === false) break;

            $packetLength = $end - $start + 1;
            $singlePacket = substr($buffer, $start, $packetLength);
            $buffer = substr($buffer, $end + 1);

            if (strlen($singlePacket) < 12) continue;

            $this->line("\n<fg=yellow>[RAW RECV]</>: " . strtoupper(bin2hex($singlePacket)));

            // --- UNESCAPE ---
            $bytes = array_values(unpack('C*', $singlePacket));
            $data = [];
            for ($i = 0; $i < count($bytes); $i++) {
                if ($bytes[$i] === 0x7D && isset($bytes[$i + 1])) {
                    if ($bytes[$i + 1] === 0x01) { $data[] = 0x7D; $i++; }
                    elseif ($bytes[$i + 1] === 0x02) { $data[] = 0x7E; $i++; }
                } else { $data[] = $bytes[$i]; }
            }

            $payload = array_slice($data, 1, -2);
            $msgId = ($payload[0] << 8) | $payload[1];
            $attr = ($payload[2] << 8) | $payload[3];
            $is2019 = ($attr >> 14) & 0x01;

            if ($is2019) {
                $this->clientVersions[$clientId] = $payload[4] ?? 0x01;
                $phoneRaw = array_slice($payload, 5, 10);
                $devSerial = ($payload[15] << 8) | $payload[16];
                $headerLen = 17;
            } else {
                $phoneRaw = array_slice($payload, 4, 6);
                $devSerial = ($payload[10] << 8) | $payload[11];
                $headerLen = 12;
            }

            $phoneKey = implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw));
            $this->clientProtocols[$clientId] = $is2019 ? '2019' : '2011';
            $body = array_slice($payload, $headerLen);

            if (isset($this->terminalSockets[$phoneKey]) && $this->terminalSockets[$phoneKey] !== $socket) {
                $this->closeConnection($this->terminalSockets[$phoneKey]);
            }
            $this->terminalSockets[$phoneKey] = $socket;

            $this->info(sprintf('[INFO V%s] ID: 0x%04X | Serial: %d | Terminal: %s', 
                $this->clientProtocols[$clientId], $msgId, $devSerial, $phoneKey));

            // --- LÓGICA DE RESPUESTA ---
            switch ($msgId) {
                case 0x0100: // Registro
                    // AJUSTE 1: Forzar reset de secuencia a 0 en cada intento de registro
                    $this->terminalSerials[$phoneKey] = 0;
                    $this->deviceStates[$phoneKey] = 'REGISTERED';
                    $this->info('   -> [STATE] REGISTRO: Reset de Serial a 0.');
                    $this->respondRegistration($socket, $phoneRaw, $devSerial);
                    break;

                case 0x0102: // Autenticación
                    $this->deviceStates[$phoneKey] = 'AUTHENTICATED';
                    $this->info('   -> [STATE] AUTENTICADO.');
                    $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                    break;

                case 0x0200: // Ubicación
                case 0x0704: // Ubicación en lote (Pesado)
                    // AJUSTE 2: Responder ACK DE INMEDIATO antes de procesar para evitar Timeout
                    $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                    $this->info("   -> [ACK QUICK] Ubicación confirmada.");
                    
                    // Procesar asíncronamente
                    ProcessMdvrLocation::dispatch($phoneKey, bin2hex(pack('C*', ...$body)));
                    break;

                case 0x0002: // Heartbeat
                    $this->info('   -> [KEEP-ALIVE] Heartbeat.');
                    $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                    break;

                default:
                    $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                    break;
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
        $authCode = "992001"; // Usamos el ID como código para simplificar
        $body = [($devSerial >> 8) & 0xFF, $devSerial & 0xFF, 0x00];
        foreach (str_split($authCode) as $char) { $body[] = ord($char); }
        $this->sendPacket($socket, 0x8100, $phoneRaw, $body);
    }

    private function respondGeneral($socket, $phoneRaw, $devSerial, $replyId)
    {
        $body = [($devSerial >> 8) & 0xFF, $devSerial & 0xFF, ($replyId >> 8) & 0xFF, $replyId & 0xFF, 0x00];
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $clientId = spl_object_id($socket);
        $protocol = $this->clientProtocols[$clientId] ?? '2019';
        $phoneKey = implode('', array_map(fn($b) => sprintf('%02X', $b), $phoneRaw));
        
        // Obtenemos el serial para este paquete
        $srvSerial = $this->getNextSerial($phoneKey);

        $attr = count($body);
        if ($protocol === '2019') {
            $attr |= 0x4000;
            $ver = $this->clientVersions[$clientId] ?? 0x01;
            $header = [($msgId >> 8) & 0xFF, $msgId & 0xFF, ($attr >> 8) & 0xFF, $attr & 0xFF, $ver];
        } else {
            $header = [($msgId >> 8) & 0xFF, $msgId & 0xFF, ($attr >> 8) & 0xFF, $attr & 0xFF];
        }

        foreach ($phoneRaw as $b) { $header[] = $b; }
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;

        $full = array_merge($header, $body);
        $cs = 0;
        foreach ($full as $b) { $cs ^= $b; }
        $full[] = $cs;

        $final = [0x7E];
        foreach ($full as $b) {
            if ($b === 0x7E) { $final[] = 0x7D; $final[] = 0x02; }
            elseif ($b === 0x7D) { $final[] = 0x7D; $final[] = 0x01; }
            else { $final[] = $b; }
        }
        $final[] = 0x7E;

        @socket_write($socket, pack('C*', ...$final));
        $this->line('<fg=green>[SEND HEX]</>: ' . strtoupper(bin2hex(pack('C*', ...$final))));
    }

    private function closeConnection($s)
    {
        if (!$s || (!is_resource($s) && !($s instanceof \Socket))) return;
        $id = spl_object_id($s);
        foreach ($this->terminalSockets as $terminalId => $socket) {
            if ($socket === $s) {
                unset($this->terminalSockets[$terminalId]);
                break;
            }
        }
        $key = array_search($s, $this->clients, true);
        if ($key !== false) unset($this->clients[$key]);
        @socket_close($s);
        unset($this->clientBuffers[$id], $this->clientProtocols[$id], $this->clientVersions[$id]);
        $this->error("[DESC] Socket cerrado (ID $id).");
    }
}