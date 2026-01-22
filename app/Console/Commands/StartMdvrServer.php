<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6 - Versión 2019';

    private $clientSerials = [];

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        // CORRECCIÓN: Configuración de Keep-Alive para Ubuntu Server
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);

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
                        if ($newSocket) {
                            $clients[] = $newSocket;
                            $this->clientSerials[spl_object_id($newSocket)] = 1;
                            $this->warn('[CONN] Cámara conectada.');
                        }
                    } else {
                        $input = @socket_read($s, 8192);
                        if ($input) {
                            $this->splitAndProcess($s, $input);
                        } else {
                            unset($this->clientSerials[spl_object_id($s)]);
                            @socket_close($s);
                            $key = array_search($s, $clients);
                            if ($key !== false) unset($clients[$key]);
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
            foreach ($matches[0] as $packetHex) {
                $this->processBuffer($socket, hex2bin($packetHex));
            }
        }
    }

    private function processBuffer($socket, $input)
    {
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

        // CORRECCIÓN: El número de teléfono en 2019 ocupa 10 bytes (BCD)
        $phoneRaw = array_slice($payload, 5, 10);
        $devSerial = ($payload[15] << 8) | $payload[16];

        switch ($msgId) {
            case 0x0100: // Registro
                $this->comment('   -> Procesando Registro (0x0100)...');
                $this->clientSerials[spl_object_id($socket)] = 1;
                $this->respondRegistration($socket, $phoneRaw, $devSerial);
                break;

            case 0x0102: // Autenticación (Mensaje que sigue al registro exitoso)
                $this->info('   -> [AUTH] Cámara autenticándose...');
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
            ($devSerial >> 8) & 0xFF, // Responde al Serial de la cámara
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
            ($devSerial >> 8) & 0xFF, // Serial del mensaje que respondes
            $devSerial & 0xFF,
            ($replyMsgId >> 8) & 0xFF, // ID del mensaje que respondes
            $replyMsgId & 0xFF,
            0x00, // Resultado: OK
        ];
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body)
    {
        $bodyLen = count($body);
        // Atributo 2019: Bit 14 en 1 (0x4000)
        $attr = 0x4000 | ($bodyLen & 0x03FF);

        $objId = spl_object_id($socket);
        $srvSerial = $this->clientSerials[$objId] ?? 1;

        $header = [
            ($msgId >> 8) & 0xFF,
            ($msgId & 0xFF),
            ($attr >> 8) & 0xFF,
            ($attr & 0xFF),
            0x01, // Protocol Version 2019 obligatorio
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
        @socket_write($socket, $binOut);

        $this->clientSerials[$objId] = ($srvSerial + 1) % 65535;
    }
}
