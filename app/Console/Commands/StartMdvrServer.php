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

        // CUERPO 0x8100 ESTRICTO 2019
        $body = [
            ($terminalSerial >> 8) & 0xFF, // Byte 0-1: Reply Serial
            $terminalSerial & 0xFF,
            0x00,                          // Byte 2: Resultado (0 = Éxito)
            // El estándar 2019 requiere que el AuthCode sea precedido por su longitud
            strlen($authCode)
        ];
        foreach (str_split($authCode) as $char) {
            $body[] = ord($char);
        }

        // CABECERA 2019 ESTRICTA
        $msgId = 0x8100;
        $bodyLen = count($body);
        $properties = (1 << 14) | $bodyLen; // Bit 14 activo (Versión 2019)

        $header = [
            ($msgId >> 8) & 0xFF,
            $msgId & 0xFF,
            ($properties >> 8) & 0xFF,
            $properties & 0xFF,
            0x01, // Version del protocolo
        ];

        // EL TELÉFONO: En el log el equipo manda 6 bytes. 
        // Vamos a mandarle los 6 bytes EXACTOS que él mandó.
        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        // Serial del mensaje del servidor
        static $serverSerial = 1;
        $header[] = ($serverSerial >> 8) & 0xFF;
        $header[] = $serverSerial & 0xFF;
        $serverSerial++;

        // UNIR, CHECKSUM Y ESCAPE
        $fullMessage = array_merge($header, $body);
        $checksum = 0;
        foreach ($fullMessage as $byte) {
            $checksum ^= $byte;
        }
        $fullMessage[] = $checksum;

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

        $this->send($socket, $escapedMessage, "0x8100 FIX FINAL");
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
