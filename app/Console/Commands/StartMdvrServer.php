<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Versión 2019';

    // Propiedad para rastrear el serial por cada socket conectado
    private $clientSerials = [];

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
        $this->info("[DEBUG MDVR] ESCUCHANDO EN PUERTO $port (MODO 2019)");
        $this->info('=====================================================');

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 1000000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $newSocket = socket_accept($socket);
                        $clients[] = $newSocket;
                        // Inicializamos el serial para esta nueva conexión
                        $this->clientSerials[(int)$newSocket] = 1;
                        $this->warn('[CONN] Cámara conectada.');
                    } else {
                        $input = @socket_read($s, 8192);
                        if ($input) {
                            $this->splitAndProcess($s, $input);
                        } else {
                            // Limpiamos el serial al desconectar
                            unset($this->clientSerials[(int)$s]);
                            @socket_close($s);
                            unset($clients[array_search($s, $clients)]);
                            $this->error('[DESC] Cámara desconectada.');
                        }
                    }
                }
            }
        }
    }

    private function splitAndProcess($socket, $input)
    {
        $hex = bin2hex($input);
        if (preg_match_all('/7e(..*?)7e/', $hex, $matches)) {
            $count = count($matches[0]);
            if ($count > 1) {
                $this->warn("[BUFFER] Se detectaron $count paquetes pegados. Procesando uno a uno...");
            }

            foreach ($matches[0] as $packetHex) {
                $this->processBuffer($socket, hex2bin($packetHex));
            }
        } else {
            $this->error("[ERROR] Datos malformados recibidos (Sin delimitadores 7E)");
        }
    }

    private function processBuffer($socket, $input)
    {
        $rawHex = strtoupper(bin2hex($input));
        $this->line("\n<fg=yellow>[RAW RECV]</>: " . implode(' ', str_split($rawHex, 2)));

        $bytes = array_values(unpack('C*', $input));

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

        if (count($data) < 15) return;

        $payload = array_slice($data, 1, -2);

        $msgId = ($payload[0] << 8) | $payload[1];
        $attr = ($payload[2] << 8) | $payload[3];

        $phoneRaw = array_slice($payload, 5, 10);
        $phone = bin2hex(pack('C*', ...$phoneRaw));
        $devSerial = ($payload[15] << 8) | $payload[16];
        $body = array_slice($payload, 17);

        $this->info(sprintf(
            '[MSG] 0x%04X | Serial Cam: %d | Phone: %s',
            $msgId,
            $devSerial,
            $phone
        ));

        switch ($msgId) {
            case 0x0100: // Registro
                $this->comment('   -> Procesando Registro (0x0100)...');
                // IMPORTANTE: Al ser un registro nuevo, reiniciamos el serial del servidor para este socket
                $this->clientSerials[(int)$socket] = 1;
                $this->respondRegistration($socket, $phoneRaw, $devSerial, $body);
                break;

            case 0x0002: // Heartbeat
                $this->info('   -> [HEARTBEAT] Confirmando...');
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;

            case 0x0704: // Batch Location
                $this->info('   -> [BATCH LOCATION] Recibido lote de posiciones.');
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;

            default:
                $this->comment("   -> Enviando Respuesta General (0x8001) para ID: 0x" . sprintf('%04X', $msgId));
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial, $body)
    {
        $authCode = '123456';
        $responseBody = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            0x00, // Resultado: Éxito
        ];
        foreach (str_split($authCode) as $char) {
            $responseBody[] = ord($char);
        }

        $this->sendPacket($socket, 0x8100, $phoneRaw, $responseBody);
    }

    private function respondGeneral($socket, $phoneRaw, $devSerial, $replyMsgId)
    {
        $body = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            ($replyMsgId >> 8) & 0xFF,
            $replyMsgId & 0xFF,
            0x00, // OK
        ];
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $bodyLen = count($body);
        $attr = 0x4000 | ($bodyLen & 0x03FF);

        // Obtenemos el serial actual para este socket (por defecto 1)
        $srvSerial = $this->clientSerials[(int)$socket] ?? 1;

        $header = [
            ($msgId >> 8) & 0xFF,
            ($msgId & 0xFF),
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF),
            0x01,
        ];

        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

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

        $binOut = pack('C*', ...$final);
        $this->line('<fg=green>[SEND HEX]</>: ' . strtoupper(bin2hex($binOut)) . " (SrvSerial: $srvSerial)");

        @socket_write($socket, $binOut);

        // Incrementamos el serial para el próximo paquete de este cliente
        $this->clientSerials[(int)$socket] = ($srvSerial + 1) % 65535;
    }
}
