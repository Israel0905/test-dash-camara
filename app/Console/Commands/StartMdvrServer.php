<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MDVR\MessageBuilder;
use App\Services\MDVR\ProtocolHelper;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';
    protected $description = 'Servidor JT/T 808 compatible con Ultravision N6 (Protocolo 2019)';

    private $builder;

    public function __construct(MessageBuilder $builder)
    {
        parent::__construct();
        $this->builder = $builder;
    }

    public function handle()
    {
        $port = $this->option('port');
        $address = '0.0.0.0';

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($socket, $address, $port)) {
            $this->error("No se pudo enlazar al puerto $port");
            return;
        }

        socket_listen($socket);
        $this->info("[MDVR] Servidor iniciado en $address:$port");

        $clients = [$socket];

        while (true) {
            $read = $clients;
            $write = null;
            $except = null;

            if (socket_select($read, $write, $except, 0, 1000000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $newSocket = socket_accept($socket);
                        $clients[] = $newSocket;
                        socket_getpeername($newSocket, $ip);
                        $this->info("[MDVR] Nueva conexión desde: $ip");
                    } else {
                        $input = @socket_read($s, 2048);
                        if ($input === false || $input === '') {
                            $key = array_search($s, $clients);
                            unset($clients[$key]);
                            socket_close($s);
                            continue;
                        }
                        $this->processBuffer($s, $input);
                    }
                }
            }
        }
    }

    private function processBuffer($socket, $input)
    {
        $bytes = array_values(unpack('C*', $input));
        $hex = ProtocolHelper::bytesToHexString($bytes);
        $this->line("<fg=gray>[RAW RECV] $hex</>");

        $message = ProtocolHelper::parseMessage($bytes);
        if (!$message || !$message['valid']) return;

        $header = $message['header'];
        $msgId = $header['messageId'];
        $phoneRaw = $header['phoneNumberRaw'];
        $terminalSerial = $header['serialNumber'];

        $this->info("[RECV] Msg: 0x" . sprintf('%04X', $msgId) . " | Serial: $terminalSerial");

        switch ($msgId) {
            case 0x0100: // REGISTRO
                $this->handleRegistration($socket, $phoneRaw, $terminalSerial);
                break;
            case 0x0102: // AUTENTICACIÓN
                $this->handleAuthentication($socket, $phoneRaw, $terminalSerial);
                break;
            case 0x0002: // HEARTBEAT
                $this->handleGeneralResponse($socket, $phoneRaw, $terminalSerial, 0x0002);
                break;
        }
    }

    private function handleRegistration($socket, $phoneRaw, $terminalSerial)
    {
        $authCode = "123456";

        // 1. CUERPO (Tabla 3.3.2 del manual)
        $body = [
            ($terminalSerial >> 8) & 0xFF, // Reply Serial MSB
            $terminalSerial & 0xFF,        // Reply Serial LSB
            0x00,                          // Result: 0 (Success)
        ];
        // Auth Code como STRING (sin byte de longitud según tabla 3.3.2)
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }

        $this->sendJTTMessage($socket, 0x8100, $phoneRaw, $body, "Respuesta Registro (0x8100)");
    }

    private function handleAuthentication($socket, $phoneRaw, $terminalSerial)
    {
        // Respuesta General (Tabla 3.1.2)
        $body = [
            ($terminalSerial >> 8) & 0xFF,
            $terminalSerial & 0xFF,
            (0x0102 >> 8) & 0xFF,
            0x0102 & 0xFF,
            0x00, // Result: Success
        ];
        $this->sendJTTMessage($socket, 0x8001, $phoneRaw, $body, "Respuesta Autenticación (0x8001)");
        $this->info("<fg=green;options=bold>¡EQUIPO ONLINE Y AUTENTICADO!</>");
    }

    private function handleGeneralResponse($socket, $phoneRaw, $terminalSerial, $replyId)
    {
        $body = [
            ($terminalSerial >> 8) & 0xFF,
            $terminalSerial & 0xFF,
            ($replyId >> 8) & 0xFF,
            $replyId & 0xFF,
            0x00,
        ];
        $this->sendJTTMessage($socket, 0x8001, $phoneRaw, $body, "General Response (0x8001)");
    }

    /**
     * CONSTRUCCIÓN DEL MENSAJE SIGUIENDO ESTRICTAMENTE EL MANUAL V2.0.0-2019
     */
    private function sendJTTMessage($socket, $msgId, $phoneRaw, $body, $label)
    {
        // Propiedades (Tabla 2.2.2.1)
        // Bit 14 = 1 (Version ID para 2019), Bits 0-9 = Body Length
        $bodyLen = count($body);
        $properties = (1 << 14) | $bodyLen;

        // HEADER (Tabla 2.2.2)
        $header = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($properties >> 8) & 0xFF,
            $properties & 0xFF,
            0x01, // Protocol Version (Initial value is 1)
        ];

        // TELÉFONO (BCD[10]): El manual exige 10 bytes. 
        // Si el MDVR mandó 6, rellenamos con 4 ceros al inicio.
        $padding = 10 - count($phoneRaw);
        for ($i = 0; $i < $padding; $i++) {
            $header[] = 0x00;
        }
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        // Serial del mensaje del servidor
        static $serverSerial = 0;
        $header[] = ($serverSerial >> 8) & 0xFF;
        $header[] = $serverSerial & 0xFF;
        $serverSerial = ($serverSerial + 1) % 65535;

        // UNIR Y CHECKSUM (Punto 2.2.4)
        $full = array_merge($header, $body);
        $checksum = 0;
        foreach ($full as $byte) {
            $checksum ^= $byte;
        }
        $full[] = $checksum;

        // ESCAPE (Punto 2.2.1)
        $escaped = [0x7E];
        foreach ($full as $byte) {
            if ($byte === 0x7E) {
                $escaped[] = 0x7D;
                $escaped[] = 0x02;
            } elseif ($byte === 0x7D) {
                $escaped[] = 0x7D;
                $escaped[] = 0x01;
            } else {
                $escaped[] = $byte;
            }
        }
        $escaped[] = 0x7E;

        $binary = pack('C*', ...$escaped);
        @socket_write($socket, $binary, strlen($binary));

        $hex = ProtocolHelper::bytesToHexString($escaped);
        $this->comment("[SEND] $label: $hex");
    }
}
