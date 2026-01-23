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
                            $this->closeConnection($s, $clients);
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
            $phoneHex = implode('', array_map(fn ($b) => sprintf('%02X', $b), $phoneRaw));

            $this->info(sprintf('[INFO V%s] ID: 0x%04X | Serial: %d | Terminal: %s',
                $this->clientProtocols[$clientId], $msgId, $devSerial, $phoneHex));

            // --- RESPUESTAS ---
            if ($msgId === 0x0100) {
                $this->respondRegistration($socket, $phoneRaw, $devSerial, $body);
            } elseif ($msgId === 0x0001) {
                $this->info('   -> [OK] La cámara confirmó nuestro mensaje.');

                continue;
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
        $authCode = '123456';
        $responseBody = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            0x00,
            strlen($authCode), // Byte de longitud para Auth Code
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

    private function closeConnection($s, &$clients)
    {
        $id = spl_object_id($s);
        @socket_close($s);
        // NO BORRAMOS terminalSerials para soportar reconexión
        unset($clients[array_search($s, $clients)], $this->clientBuffers[$id], $this->clientProtocols[$id]);
        $this->error("[DESC] Cámara desconectada (ID $id).");
    }
}
