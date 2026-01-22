<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Versión 2019';

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
                        $clients[] = socket_accept($socket);
                        $this->warn('[CONN] Cámara conectada.');
                    } else {
                        $input = @socket_read($s, 8192); // Buffer más grande para ráfagas
                        if ($input) {
                            $this->splitAndProcess($s, $input);
                        } else {
                            @socket_close($s);
                            unset($clients[array_search($s, $clients)]);
                            $this->error('[DESC] Cámara desconectada.');
                        }
                    }
                }
            }
        }
    }

    /**
     * Separa el buffer en paquetes individuales basados en el delimitador 0x7E
     */
    private function splitAndProcess($socket, $input)
    {
        $hex = bin2hex($input);
        // El manual dice que los paquetes empiezan y terminan con 7E.
        // Usamos una expresión regular para encontrar cada paquete completo.
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

        // 1. UNESCAPE (Manual Cap 2.2.1)
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

        // Payload sin delimitadores 7E ni checksum
        $payload = array_slice($data, 1, -2);

        // 2. PARSE HEADER 2019 (Tabla 2.2.2)
        $msgId = ($payload[0] << 8) | $payload[1];
        $attr = ($payload[2] << 8) | $payload[3];
        $is2019 = ($attr >> 14) & 0x01;

        // En 2019: Byte 4 = Versión, Bytes 5-14 = Teléfono (10 bytes)
        $phoneRaw = array_slice($payload, 5, 10);
        $phone = bin2hex(pack('C*', ...$phoneRaw));
        $devSerial = ($payload[15] << 8) | $payload[16];
        $body = array_slice($payload, 17);

        $this->info(sprintf(
            '[MSG] 0x%04X | Serial: %d | Phone: %s',
            $msgId,
            $devSerial,
            $phone
        ));

        // 3. LOGICA DE RESPUESTA
        switch ($msgId) {
            case 0x0100: // Registro
                $this->comment('   -> Procesando Registro (0x0100)...');
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
            0x00,
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
        $attr = 0x4000 | ($bodyLen & 0x03FF); // Bit 14 para 2019

        $header = [
            ($msgId >> 8) & 0xFF,
            ($msgId & 0xFF),
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF),
            0x01, // Protocol Version Obligatorio
        ];

        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        static $srvSerial = 1;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial = ($srvSerial + 1) % 65535;

        $full = array_merge($header, $body);

        // Checksum XOR
        $cs = 0;
        foreach ($full as $byte) {
            $cs ^= $byte;
        }
        $full[] = $cs;

        // Escapado
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

        $hexOut = strtoupper(bin2hex(pack('C*', ...$final)));
        $this->line('<fg=green>[SEND HEX]</>: ' . implode(' ', str_split($hexOut, 2)));

        @socket_write($socket, pack('C*', ...$final));
    }
}
