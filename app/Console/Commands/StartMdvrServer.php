<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MDVR\MessageBuilder;
use App\Services\MDVR\ProtocolHelper;

class StartMdvrServer extends Command
{
    /**
     * El nombre y firma del comando.
     */
    protected $signature = 'mdvr:start {--port=8808}';

    /**
     * Descripción del comando.
     */
    protected $description = 'Inicia el servidor TCP para recibir conexiones de MDVR (JT/T 808)';

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
        $this->line("<fg=gray>[RAW] $hex</>");

        $message = ProtocolHelper::parseMessage($bytes);
        if (!$message || !$message['valid']) {
            return;
        }

        $header = $message['header'];
        $msgId = $header['messageId'];
        $phoneRaw = $header['phoneNumberRaw'];
        $terminalSerial = $header['serialNumber'];

        $this->info("[RECV] Msg: 0x" . sprintf('%04X', $msgId) . " | Phone: {$header['phoneNumber']} | Serial: $terminalSerial");

        switch ($msgId) {
            case 0x0100: // REGISTRO
                $this->handleRegistration($socket, $phoneRaw, $terminalSerial);
                break;

            case 0x0102: // AUTENTICACIÓN
                $this->handleAuthentication($socket, $header);
                break;

            case 0x0002: // HEARTBEAT
                $this->handleGeneralResponse($socket, $header, 0x0002);
                break;
        }
    }

    private function handleRegistration($socket, $phoneRaw, $terminalSerial)
    {
        $authCode = "123456";

        // Cuerpo del mensaje 0x8100 (Tabla 3.3.2)
        $body = [
            ($terminalSerial >> 8) & 0xFF, // Reply Serial MSB
            $terminalSerial & 0xFF,        // Reply Serial LSB
            0x00,                          // Result: Success
        ];
        // Añadir Auth Code como String
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }

        // --- CONSTRUCCIÓN MANUAL DEL HEADER 2019 (Tabla 2.2.2) ---
        $msgId = 0x8100;
        $bodyLen = count($body);

        // Propiedades: Bit 14 = 1 (Version), Bits 0-9 = Body Length
        $properties = (1 << 14) | $bodyLen;

        $header = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($properties >> 8) & 0xFF,
            $properties & 0xFF,
            0x01, // PROTOCOL VERSION (Tabla 2.2.2 - Start Byte 4)
        ];

        // Teléfono (BCD 10 bytes según tu manual Tabla 2.2.2)
        // El manual pide 10 bytes para el teléfono en 2019
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        // Message Serial (del servidor)
        static $serverSerial = 0;
        $header[] = ($serverSerial >> 8) & 0xFF;
        $header[] = $serverSerial & 0xFF;
        $serverSerial++;

        // Unir Header + Body para el Checksum
        $fullMessage = array_merge($header, $body);

        // 2.2.4 Check Code (XOR)
        $checksum = 0;
        foreach ($fullMessage as $byte) {
            $checksum ^= $byte;
        }
        $fullMessage[] = $checksum;

        // 2.2.1 Escape Processing
        $escapedMessage = [0x7E];
        foreach ($fullMessage as $byte) {
            if ($byte === 0x7E) {
                $escapedMessage[] = 0x7D;
                $escapedMessage[] = 0x02;
            } elseif ($byte === 0x7D) {
                $escapedMessage[] = 0x7D;
                $escapedMessage[] = 0x01;
            } else {
                $escapedMessage[] = $byte;
            }
        }
        $escapedMessage[] = 0x7E;

        $this->send($socket, $escapedMessage, "0x8100 JT/T 808-2019");
    }

    private function handleAuthentication($socket, $header)
    {
        // El equipo envía 0x0102 después de un registro exitoso. 
        // Respondemos con una Respuesta General 0x8001
        $body = [
            ($header['serialNumber'] >> 8) & 0xFF,
            $header['serialNumber'] & 0xFF,
            (0x0102 >> 8) & 0xFF,
            0x0102 & 0xFF,
            0x00, // Resultado: OK
        ];

        $response = $this->builder->buildMessageWithRawPhone(0x8001, $body, $header['phoneNumberRaw'], null);
        $this->send($socket, $response, "Respuesta Autenticación (0x8001)");
        $this->info("<fg=green;options=bold>¡EQUIPO ONLINE Y AUTENTICADO!</>");
    }

    private function handleGeneralResponse($socket, $header, $replyMsgId)
    {
        $body = [
            ($header['serialNumber'] >> 8) & 0xFF,
            $header['serialNumber'] & 0xFF,
            ($replyMsgId >> 8) & 0xFF,
            $replyMsgId & 0xFF,
            0x00,
        ];
        $response = $this->builder->buildMessageWithRawPhone(0x8001, $body, $header['phoneNumberRaw'], null);
        $this->send($socket, $response, "General Response to 0x" . sprintf('%04X', $replyMsgId));
    }

    private function send($socket, $data, $label)
    {
        $binary = pack('C*', ...$data);
        @socket_write($socket, $binary, strlen($binary));
        $this->comment("[SEND] $label: " . ProtocolHelper::bytesToHexString($data));
    }
}
