<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 Estable - Manejo de Ráfagas';

    // Mantener el serial del servidor persistente
    private static $srvSerial = 1;

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        // Aumentar buffer de lectura para no perder datos de ráfagas 0x0704
        socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 65536);

        if (!@socket_bind($socket, $address, $port)) {
            $this->error("Error: Puerto $port ocupado.");
            return;
        }

        socket_listen($socket);
        $this->info("=====================================================");
        $this->info("[MDVR SERVER] ESCUCHANDO EN PUERTO $port");
        $this->info("=====================================================");

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 100000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $newSocket = socket_accept($socket);
                        $clients[] = $newSocket;
                        $this->warn('[CONN] Cámara conectada.');
                    } else {
                        // Leer hasta 16KB para capturar múltiples paquetes 0x0704
                        $input = @socket_read($s, 16384);
                        if ($input) {
                            $this->splitAndProcess($s, $input);
                        } else {
                            @socket_close($s);
                            $index = array_search($s, $clients);
                            if ($index !== false) unset($clients[$index]);
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
        // Expresión regular que busca CUALQUIER COSA entre dos 7E
        // La 'i' es para case-insensitive y 'U' para que sea "greedy" correctamente
        if (preg_match_all('/7e(..*?)7e/i', $hex, $matches)) {
            foreach ($matches[0] as $packetHex) {
                $this->processBuffer($socket, hex2bin($packetHex));
            }
        }
    }

    private function processBuffer($socket, $input)
    {
        $rawHex = strtoupper(bin2hex($input));
        $bytes = array_values(unpack('C*', $input));

        // 1. UNESCAPE
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

        // 2. PARSE HEADER
        $msgId = ($payload[0] << 8) | $payload[1];
        $phoneRaw = array_slice($payload, 5, 10);
        $devSerial = ($payload[15] << 8) | $payload[16];

        $this->info(sprintf('[RECV] 0x%04X | Serial: %d', $msgId, $devSerial));

        // 3. RESPUESTAS
        switch ($msgId) {
            case 0x0100: // Registro
                $this->respondRegistration($socket, $phoneRaw, $devSerial);
                break;
            default:
                // Responder a 0x0102, 0x0002, 0x0704, 0x0900 de forma genérica
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial)
    {
        $authCode = '123456';
        $body = [($devSerial >> 8) & 0xFF, $devSerial & 0xFF, 0x00];
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }
        $this->sendPacket($socket, 0x8100, $phoneRaw, $body);
    }

    private function respondGeneral($socket, $phoneRaw, $devSerial, $replyMsgId)
    {
        $body = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            ($replyMsgId >> 8) & 0xFF,
            $replyMsgId & 0xFF,
            0x00,
        ];
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $bodyLen = count($body);
        $attr = 0x4000 | ($bodyLen & 0x03FF);

        $header = [
            ($msgId >> 8) & 0xFF,
            ($msgId & 0xFF),
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF),
            0x01, // 2019
        ];
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        $header[] = (self::$srvSerial >> 8) & 0xFF;
        $header[] = (self::$srvSerial & 0xFF);
        self::$srvSerial = (self::$srvSerial + 1) % 65535;

        $full = array_merge($header, $body);
        $cs = 0;
        foreach ($full as $byte) {
            $cs ^= $byte;
        }
        $full[] = $cs;

        $escaped = [];
        foreach ($full as $b) {
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

        $final = array_merge([0x7E], $escaped, [0x7E]);
        $binary = pack('C*', ...$final);

        $this->line('<fg=green>[SEND]</> ' . strtoupper(bin2hex($binary)));
        @socket_write($socket, $binary);
    }
}
