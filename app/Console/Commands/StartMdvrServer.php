<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:serve {--host=0.0.0.0} {--port=8808}';
    protected $description = 'Servidor ULV/JTT808 para Registro y Autentificación';

    public function handle(): int
    {
        $host = $this->option('host');
        $port = $this->option('port');

        $this->info("Iniciando Servidor ULV en $host:$port (Sin SSL)");

        $socket = new SocketServer("$host:$port");

        $socket->on('connection', function (ConnectionInterface $connection) {
            $this->line("<info>[+]</info> Nueva conexión desde: " . $connection->getRemoteAddress());

            $connection->on('data', function ($data) use ($connection) {
                $this->handlePacket($connection, $data);
            });
        });

        Loop::run();
        return Command::SUCCESS;
    }

    private function handlePacket($connection, $data)
    {
        $hex = bin2hex($data);
        // El protocolo JT808/ULV envuelve los paquetes en 0x7e
        if (!str_starts_with($hex, '7e') || !str_ends_with($hex, '7e')) return;

        // Extraer ID del Mensaje (Bytes 1 y 2)
        $msgId = substr($hex, 2, 4);

        // Extraer Terminal ID (En 2019 son 20 caracteres hex / 10 bytes BCD)
        $terminalId = substr($hex, 10, 20);

        // Extraer Serial del Mensaje (Bytes 15-16 aprox dependiendo de la versión)
        $serial = substr($hex, 30, 4);

        switch ($msgId) {
            case '0100': // REGISTRO
                $this->info("[$terminalId] Solicitud de Registro recibida.");
                $this->sendRegistrationResponse($connection, $terminalId, $serial);
                break;

            case '0102': // AUTENTIFICACIÓN
                $this->info("[$terminalId] Solicitud de Autentificación recibida.");
                $this->sendGeneralResponse($connection, $terminalId, $serial, '0102');
                break;
        }
    }

    private function sendRegistrationResponse($connection, $terminalId, $serial)
    {
        $msgId = "8100"; // Respuesta de registro
        $authCode = bin2hex("ULV123"); // Código de autenticación que pedirá el terminal

        // Cuerpo: Serial Respuesta (2) + Resultado (1: 0=éxito) + Código Auth
        $body = $serial . "00" . $authCode;
        $response = $this->buildPacket($msgId, $terminalId, $body);

        $connection->write(hex2bin($response));
        $this->comment(" -> Respuesta de Registro enviada (0x8100)");
    }

    private function sendGeneralResponse($connection, $terminalId, $serial, $repliedMsgId)
    {
        $msgId = "8001"; // Respuesta general de plataforma

        // Cuerpo: Serial Respuesta (2) + ID Mensaje Respondido (2) + Resultado (1: 0=éxito)
        $body = $serial . $repliedMsgId . "00";
        $response = $this->buildPacket($msgId, $terminalId, $body);

        $connection->write(hex2bin($response));
        $this->comment(" -> Respuesta General enviada (OK)");
    }

    private function buildPacket($msgId, $terminalId, $body)
    {
        $msgProp = str_pad(dechex(strlen($body) / 2), 4, '0', STR_PAD_LEFT);
        $version = "01"; // Indicador de protocolo 2019

        // Paquete sin delimitadores ni checksum
        $packet = $msgId . $msgProp . $version . $terminalId . "0001" . $body;

        // Checksum (XOR de todos los bytes)
        $checksum = 0;
        for ($i = 0; $i < strlen($packet); $i += 2) {
            $checksum ^= hexdec(substr($packet, $i, 2));
        }
        $checksumHex = str_pad(dechex($checksum), 2, '0', STR_PAD_LEFT);

        return "7e" . $packet . $checksumHex . "7e";
    }
}
