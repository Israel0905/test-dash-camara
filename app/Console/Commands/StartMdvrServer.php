<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Versión 2019 Estable';

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
        $this->info("[DEBUG MDVR] ESCUCHANDO EN PUERTO $port (JTT808-2019)");
        $this->info('=====================================================');

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 100000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $clients[] = socket_accept($socket);
                        $this->warn('[CONN] Cámara conectada.');
                    } else {
                        $input = @socket_read($s, 8192);
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

    private function splitAndProcess($socket, $input)
    {
        $hex = bin2hex($input);
        // Expresión regular para capturar paquetes completos 7E...7E
        if (preg_match_all('/7e(..*?)7e/', $hex, $matches)) {
            foreach ($matches[0] as $packetHex) {
                $this->processBuffer($socket, hex2bin($packetHex));
            }
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

        // Payload sin delimitadores ni checksum
        $payload = array_slice($data, 1, -2);

        // 2. PARSE HEADER 2019
        $msgId = ($payload[0] << 8) | $payload[1];
        $attr = ($payload[2] << 8) | $payload[3];
        $phoneRaw = array_slice($payload, 5, 10);
        $devSerial = ($payload[15] << 8) | $payload[16];
        $body = array_slice($payload, 17);

        $this->info(sprintf('[MSG] 0x%04X | Serial: %d | Phone: %s', $msgId, $devSerial, bin2hex(pack('C*', ...$phoneRaw))));

        // 3. RESPUESTAS
        switch ($msgId) {
            case 0x0100: // Registro
                $this->comment('   -> Procesando Registro (0x0100)...');
                $this->respondRegistration($socket, $phoneRaw, $devSerial);
                break;
            case 0x0102: // Autenticación (Siguiente paso después del registro)
                $this->info('   -> [AUTH] Terminal autenticándose...');
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;
            default:
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial)
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
        $attr = 0x4000 | ($bodyLen & 0x03FF); // Bit 14 = Protocolo 2019

        $header = [
            ($msgId >> 8) & 0xFF,
            ($msgId & 0xFF),
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF),
            0x01, // Versión 2019
        ];
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        static $srvSerial = 1;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial = ($srvSerial + 1) % 65535;

        // 1. Unir Header + Body
        $fullContent = array_merge($header, $body);

        // 2. Calcular Checksum SOBRE EL CONTENIDO COMPLETO
        $cs = 0;
        foreach ($fullContent as $byte) {
            $cs ^= $byte;
        }
        $fullContent[] = $cs; // Añadimos el checksum al final del contenido

        // 3. ESCAPADO FINAL (Crucial para estabilidad)
        // Se escapan todos los bytes del contenido + el checksum
        $escaped = [];
        foreach ($fullContent as $b) {
            if ($b === 0x7E) {
                $escaped[] = 0x7D;
                $escaped[] = 0x02;
            } elseif ($b === 0x7D) {
                $escaped[] = 0x7D;
                $escaped[] = 0x01;
            } else {
                $escaped[] = $b;
            }
        }

        // 4. Empaquetar con delimitadores
        $final = array_merge([0x7E], $escaped, [0x7E]);
        $binary = pack('C*', ...$final);

        $this->line('<fg=green>[SEND HEX]</>: ' . strtoupper(bin2hex($binary)));
        @socket_write($socket, $binary);
    }
}
