<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Protocolo 2019';

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
        $this->info("[SISTEMA] ESCUCHANDO EN PUERTO $port (JT/T 808-2019)");
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
                        $this->warn('[CONN] Nueva cámara conectada.');
                    } else {
                        $input = @socket_read($s, 4096);
                        if ($input) {
                            $this->processBuffer($s, $input);
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

    private function processBuffer($socket, $input)
    {
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

        // Payload sin delimitadores 7E ni checksum final
        $payload = array_slice($data, 1, -2);

        // 2. PARSE HEADER 2019
        $msgId = ($payload[0] << 8) | $payload[1];
        $attr = ($payload[2] << 8) | $payload[3];
        $phoneRaw = array_slice($payload, 5, 10);
        $devSerial = ($payload[15] << 8) | $payload[16]; // Serial del mensaje de la cámara
        $body = array_slice($payload, 17);

        $this->info(sprintf("\n[RECV] ID: 0x%04X | Serial: %d", $msgId, $devSerial));

        // 3. LÓGICA DE ESTADOS (HANDSHAKE)
        switch ($msgId) {
            case 0x0100:
                $this->comment('   -> [PASO 1] Registro solicitado.');
                $this->respondRegistration($socket, $phoneRaw, $devSerial);
                break;

            case 0x0102:
                $this->info('   -> [PASO 2] Autenticación recibida (Login).');
                // ESTA ES LA RESPUESTA QUE EVITA QUE SE DESCONECTE
                $this->respondGeneral($socket, $phoneRaw, $devSerial, 0x0102);
                $this->info('   -> [LISTO] Sesión establecida correctamente.');
                break;

            case 0x0002:
                $this->line('   -> Heartbeat recibido.');
                $this->respondGeneral($socket, $phoneRaw, $devSerial, 0x0002);
                break;

            case 0x0200:
                $this->info('   -> Datos de ubicación recibidos.');
                $this->respondGeneral($socket, $phoneRaw, $devSerial, 0x0200);
                // Aquí podrías llamar a parseLocation($body);
                break;

            default:
                $this->line("   -> Mensaje 0x" . sprintf('%04X', $msgId) . " confirmado.");
                $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId);
                break;
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial)
    {
        $authCode = '123456';

        // Cuerpo 0x8100: Serial(2) + Resultado(1) + AuthCode(String)
        $responseBody = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            0x00, // Éxito
        ];

        foreach (str_split($authCode) as $char) {
            $responseBody[] = ord($char);
        }

        $this->sendPacket($socket, 0x8100, $phoneRaw, $responseBody);
    }

    private function respondGeneral($socket, $phoneRaw, $devSerial, $replyMsgId)
    {
        // Cuerpo 0x8001: Serial(2) + ID Respuesta(2) + Resultado(1)
        $body = [
            ($devSerial >> 8) & 0xFF,
            $devSerial & 0xFF,
            ($replyMsgId >> 8) & 0xFF,
            $replyMsgId & 0xFF,
            0x00, // 0 = Éxito / Confirmado
        ];

        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $attr = 0x4000 | count($body); // Bit 14 para 2019 + Longitud

        // Header 2019: ID(2) + Attr(2) + Ver(1) + Phone(10) + Serial(2)
        $header = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($attr >> 8) & 0xFF,
            $attr & 0xFF,
            0x01, // Protocol Version
        ];

        foreach ($phoneRaw as $b) $header[] = $b;

        static $srvSerial = 1;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial = ($srvSerial + 1) % 65535;

        $full = array_merge($header, $body);

        // Checksum XOR
        $cs = 0;
        foreach ($full as $byte) $cs ^= $byte;
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
        $this->line("<fg=green>[SEND]</> 0x" . sprintf('%04X', $msgId) . " | HEX: " . implode(' ', str_split($hexOut, 2)));

        @socket_write($socket, pack('C*', ...$final));
    }
}
