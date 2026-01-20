<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:serve {--host=0.0.0.0} {--port=8808}';
    protected $description = 'Servidor ULV/JTT808-2019 Registro y Autentificación';

    public function handle(): int
    {
        $host = $this->option('host');
        $port = $this->option('port');

        $socket = new SocketServer("$host:$port");

        $this->info("╔══════════════════════════════════════════════════════════╗");
        $this->info("║          Servidor ULV - JTT808-2019 (Sin SSL)            ║");
        $this->info("╚══════════════════════════════════════════════════════════╝");

        $socket->on('connection', function (ConnectionInterface $connection) {
            $this->line("<info>[+]</info> Nueva conexión: " . $connection->getRemoteAddress());

            $connection->on('data', function ($data) use ($connection) {
                $hex = bin2hex($data);

                // 1. Limpiar posibles ruidos y verificar delimitador 7e
                if (!str_contains($hex, '7e')) return;

                // Extraer ID de Mensaje (Bytes 1-2)
                $msgId = substr($hex, 2, 4);

                // Extraer Terminal ID (En 2019 son 20 caracteres hex / 10 bytes)
                // Según tu log es: 01000000000000009920
                $terminalId = substr($hex, 10, 20);

                // Extraer Serial del Mensaje (Bytes 15-16 en v2019)
                $serial = substr($hex, 30, 4);

                if ($msgId === '0100') {
                    $this->info("[$terminalId] Solicitud de Registro (0x0100)");
                    $this->sendRegistrationResponse($connection, $terminalId, $serial);
                } elseif ($msgId === '0102') {
                    $this->info("[$terminalId] Solicitud de Autentificación (0x0102)");
                    $this->sendGeneralResponse($connection, $terminalId, $serial, '0102');
                }
            });
        });

        Loop::run();
        return Command::SUCCESS;
    }

    private function sendRegistrationResponse($connection, $terminalId, $serial)
    {
        $msgId = "8100";
        $authCode = "AUTH123"; // Código simple de ejemplo
        $authHex = bin2hex($authCode);

        // Cuerpo 8100: Serial Petición(2) + Resultado(1: 0=ok) + Código(n)
        $body = $serial . "00" . $authHex;

        $packet = $this->buildPacket($msgId, $terminalId, $body);
        $connection->write(hex2bin($packet));
        $this->comment(" -> Respuesta 0x8100 enviada. Esperando 0x0102...");
    }

    private function sendGeneralResponse($connection, $terminalId, $serial, $repliedId)
    {
        $msgId = "8001";
        // Cuerpo 8001: Serial Petición(2) + ID Petición(2) + Resultado(1: 0=ok)
        $body = $serial . $repliedId . "00";

        $packet = $this->buildPacket($msgId, $terminalId, $body);
        $connection->write(hex2bin($packet));
        $this->info(" -> Autentificación EXITOSA.");
    }

    private function buildPacket($msgId, $terminalId, $body)
    {
        // Propiedades: Bit 14 indica que es versión 2019 (0100 0000...)
        // Para simplificar, calculamos longitud y activamos el flag de versión
        $len = strlen($body) / 2;
        $propInt = $len | 0x4000; // Flag de versión 2019 activado
        $msgProp = str_pad(dechex($propInt), 4, '0', STR_PAD_LEFT);

        $protocolVersion = "01"; // Versión 2019

        // Estructura: ID(2) + Prop(2) + Ver(1) + Term(10) + Serial(2) + Body
        $header = $msgId . $msgProp . $protocolVersion . $terminalId . "0001";
        $fullContent = $header . $body;

        // Calcular Checksum XOR
        $check = 0;
        for ($i = 0; $i < strlen($fullContent); $i += 2) {
            $check ^= hexdec(substr($fullContent, $i, 2));
        }
        $checksumHex = str_pad(dechex($check), 2, '0', STR_PAD_LEFT);

        return "7e" . $fullContent . $checksumHex . "7e";
    }
}
