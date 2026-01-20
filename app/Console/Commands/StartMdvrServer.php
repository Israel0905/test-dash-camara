<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:serve {--host=0.0.0.0} {--port=8808}';
    protected $description = 'Servidor JTT808-2019 - Fix Registro Ciclo';

    public function handle(): int
    {
        $host = $this->option('host');
        $port = $this->option('port');
        $socket = new SocketServer("$host:$port");

        $this->info("Servidor escuchando en $host:$port...");

        $socket->on('connection', function (ConnectionInterface $connection) {
            $connection->on('data', function ($data) use ($connection) {
                $hex = bin2hex($data);
                if (!str_contains($hex, '7e')) return;

                // Extraer ID y Serial de la petición
                $msgId = substr($hex, 2, 4);
                $terminalId = substr($hex, 10, 20); // 10 bytes BCD
                $serialPeticion = substr($hex, 32, 4); // Serial en v2019 está tras el byte de versión

                if ($msgId === '0100') {
                    $this->info("[$terminalId] Registro recibido. Enviando 0x8100...");
                    $this->send8100($connection, $terminalId, $serialPeticion);
                } elseif ($msgId === '0102') {
                    $this->info("[$terminalId] Autentificación recibida. Enviando 0x8001...");
                    $this->send8001($connection, $terminalId, $serialPeticion, '0102');
                }
            });
        });

        Loop::run();
        return Command::SUCCESS;
    }

    private function send8100($connection, $terminalId, $serialPeticion)
    {
        $authCode = "ULV123";
        $authHex = bin2hex($authCode);

        // Cuerpo 8100: Serial Petición(2) + Resultado(1: 0=éxito) + Código(n)
        $body = $serialPeticion . "00" . $authHex;

        $packet = $this->build2019Packet("8100", $terminalId, $body);
        $connection->write(hex2bin($packet));
    }

    private function send8001($connection, $terminalId, $serialPeticion, $repliedId)
    {
        // Cuerpo 8001: Serial Petición(2) + ID Petición(2) + Resultado(1: 0=éxito)
        $body = $serialPeticion . $repliedId . "00";

        $packet = $this->build2019Packet("8001", $terminalId, $body);
        $connection->write(hex2bin($packet));
    }

    private function build2019Packet($msgId, $terminalId, $body)
    {
        // 1. Atributos del mensaje (2 bytes)
        // Bit 14 debe ser 1 para versión 2019.
        $length = strlen($body) / 2;
        $attrInt = $length | 0x4000;
        $msgAttr = str_pad(dechex($attrInt), 4, '0', STR_PAD_LEFT);

        // 2. Protocol Version (1 byte) -> Obligatorio 0x01 para 2019
        $version = "01";

        // 3. Serial del mensaje del servidor (2 bytes) -> Puede ser fijo o incremental
        $serverSerial = "0001";

        // Construir contenido para Checksum: ID + ATTR + VER + TERM + SERIAL + BODY
        $content = $msgId . $msgAttr . $version . $terminalId . $serverSerial . $body;

        // 4. Calcular Checksum (XOR de cada byte)
        $checksum = 0;
        for ($i = 0; $i < strlen($content); $i += 2) {
            $checksum ^= hexdec(substr($content, $i, 2));
        }
        $checksumHex = str_pad(dechex($checksum), 2, '0', STR_PAD_LEFT);

        // 5. Escapado (Importante): Si algún byte es 7e o 7d debe escaparse.
        // Para este ejemplo sencillo de registro, asumimos que no hay 7e en el body.

        return "7e" . $content . $checksumHex . "7e";
    }
}
