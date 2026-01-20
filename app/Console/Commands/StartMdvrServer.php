<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MDVR\MessageBuilder;
use App\Services\MDVR\ProtocolHelper;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 para Ultravision N6';

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($socket, $address, $port);
        socket_listen($socket);
        $this->info("[MDVR] Servidor iniciado en $port");

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 1000000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $clients[] = socket_accept($socket);
                    } else {
                        $input = @socket_read($s, 2048);
                        if ($input) $this->processBuffer($s, $input);
                        else {
                            socket_close($s);
                            unset($clients[array_search($s, $clients)]);
                        }
                    }
                }
            }
        }
    }

    private function processBuffer($socket, $input)
    {
        $bytes = array_values(unpack('C*', $input));
        $this->line("<fg=gray>[RECV] " . ProtocolHelper::bytesToHexString($bytes) . "</>");

        $message = ProtocolHelper::parseMessage($bytes);
        if (!$message || !$message['valid']) return;

        $header = $message['header'];
        if ($header['messageId'] === 0x0100) {
            $this->handleRegistration($socket, $header);
        }
    }

    private function handleRegistration($socket, $header)
    {
        $authCode = "123456";
        $terminalSerial = $header['serialNumber'];
        $phoneRaw = $header['phoneNumberRaw']; // Tomamos los bytes EXACTOS que mandó (6 bytes)

        // 1. CUERPO (Tabla 3.3.2)
        $body = [
            ($terminalSerial >> 8) & 0xFF,
            $terminalSerial & 0xFF,
            0x00, // Result: Success
        ];
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }

        // 2. HEADER (Tabla 2.2.2)
        $msgId = 0x8100;
        $bodyLen = count($body);
        $properties = (1 << 14) | $bodyLen; // Bit 14 = Version 2019

        $fullHeader = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($properties >> 8) & 0xFF,
            $properties & 0xFF,
            0x01, // Protocol Version
        ];

        // IMPORTANTE: Metemos el teléfono tal cual lo mandó el equipo (6 bytes)
        // No agregamos padding de 10 bytes porque el log muestra que el equipo usa 6.
        foreach ($phoneRaw as $b) {
            $fullHeader[] = $b;
        }

        static $serverSerial = 0;
        $fullHeader[] = ($serverSerial >> 8) & 0xFF;
        $fullHeader[] = $serverSerial & 0xFF;
        $serverSerial++;

        // 3. CHECKSUM Y ESCAPE
        $packet = array_merge($fullHeader, $body);
        $checksum = 0;
        foreach ($packet as $byte) {
            $checksum ^= $byte;
        }
        $packet[] = $checksum;

        $final = [0x7E];
        foreach ($packet as $byte) {
            if ($byte === 0x7E) {
                $final[] = 0x7D;
                $final[] = 0x02;
            } elseif ($byte === 0x7D) {
                $final[] = 0x7D;
                $final[] = 0x01;
            } else {
                $final[] = $byte;
            }
        }
        $final[] = 0x7E;

        $binary = pack('C*', ...$final);
        socket_write($socket, $binary, strlen($binary));
        $this->info("[SEND] 0x8100: " . ProtocolHelper::bytesToHexString($final));
    }
}
