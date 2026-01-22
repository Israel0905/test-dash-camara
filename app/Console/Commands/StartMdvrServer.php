<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';

    protected $description = 'Servidor JT/T 808 Robusto - Manejo de Buffers';

    // Buffers para guardar fragmentos de cada cámara
    private $clientBuffers = [];

    public function handle()
    {
        set_time_limit(0);
        $port = $this->option('port');
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        $tcpNoDelay = defined('TCP_NODELAY') ? TCP_NODELAY : 1;
        socket_set_option($socket, SOL_TCP, $tcpNoDelay, 1);

        if (! @socket_bind($socket, '0.0.0.0', $port)) {
            $this->error("Error: Puerto $port ocupado.");

            return;
        }

        socket_listen($socket);
        $this->info('=====================================================');
        $this->info("[SERVIDOR ROBUSTO] ESCUCHANDO EN PUERTO $port");
        $this->info('=====================================================');

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 100000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $newSocket = socket_accept($socket);
                        $clients[] = $newSocket;
                        // FIX PHP 8: Use spl_object_id instead of (int) cast
                        $this->clientBuffers[spl_object_id($newSocket)] = '';
                        $this->warn('[CONN] Cámara conectada.');
                    } else {
                        $input = @socket_read($s, 65535);
                        if ($input) {
                            $this->handleTcpStream($s, $input);
                        } else {
                            socket_close($s);
                            // FIX PHP 8
                            unset($this->clientBuffers[spl_object_id($s)]);
                            unset($clients[array_search($s, $clients)]);
                            $this->error('[DESC] Cámara desconectada.');
                        }
                    }
                }
            }
        }
    }

    private function handleTcpStream($socket, $input)
    {
        // FIX PHP 8
        $id = spl_object_id($socket);

        // Añadir lo nuevo al buffer que ya teníamos de esta cámara
        if (! isset($this->clientBuffers[$id])) {
            $this->clientBuffers[$id] = '';
        }
        $this->clientBuffers[$id] .= $input;

        // Buscar paquetes completos delimitados por 0x7E
        while (($start = strpos($this->clientBuffers[$id], chr(0x7E))) !== false) {
            // Buscar el final del paquete
            $end = strpos($this->clientBuffers[$id], chr(0x7E), $start + 1);

            if ($end === false) {
                // El paquete está incompleto, esperamos a la siguiente lectura
                break;
            }

            // Extraer el paquete completo incluyendo los 7E
            $packet = substr($this->clientBuffers[$id], $start, $end - $start + 1);

            // Eliminar lo procesado del buffer
            $this->clientBuffers[$id] = substr($this->clientBuffers[$id], $end + 1);

            // Procesar el paquete único
            $this->parseSinglePacket($socket, $packet);
        }
    }

    private function parseSinglePacket($socket, $packet)
    {
        $bytes = array_values(unpack('C*', $packet));

        // 1. Quitar los 7E y Unescape
        $payloadRaw = array_slice($bytes, 1, -1);
        $data = [];
        for ($i = 0; $i < count($payloadRaw); $i++) {
            if ($payloadRaw[$i] === 0x7D && isset($payloadRaw[$i + 1])) {
                $data[] = ($payloadRaw[$i + 1] === 0x01) ? 0x7D : 0x7E;
                $i++;
            } else {
                $data[] = $payloadRaw[$i];
            }
        }

        if (count($data) < 13) {
            return;
        }

        // 2. Checksum
        $calcCs = 0;
        $receivedCs = array_pop($data);
        foreach ($data as $b) {
            $calcCs ^= $b;
        }
        if ($calcCs !== $receivedCs) {
            return;
        }

        // 3. Header y Respuesta
        $msgId = ($data[0] << 8) | $data[1];

        // FIX 1: Capturar la versión del protocolo que envía la cámara (byte 4)
        $protoVer = $data[4];

        $phone = bin2hex(pack('C*', ...array_slice($data, 5, 10)));
        $devSerial = ($data[15] << 8) | $data[16];
        $body = array_slice($data, 17);

        $this->info(sprintf('[MSG] ID: 0x%04X | Serial: %d | Phone: %s | ProtoVer: %d', $msgId, $devSerial, $phone, $protoVer));

        $phoneRaw = array_slice($data, 5, 10);

        if ($msgId === 0x0100) {
            $this->respondRegistration($socket, $phoneRaw, $devSerial, $body, $protoVer);
        } else {
            $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId, $protoVer);
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial, $body, $protoVer)
    {
        $this->info('   -> Procesando Registro...');
        $authCode = '123456';
        $responseBody = [($devSerial >> 8) & 0xFF, $devSerial & 0xFF, 0x00];
        foreach (str_split($authCode) as $char) {
            $responseBody[] = ord($char);
        }
        $this->sendPacket($socket, 0x8100, $phoneRaw, $responseBody, $protoVer);
    }

    private function respondGeneral($socket, $phoneRaw, $devSerial, $replyId, $protoVer)
    {
        $body = [($devSerial >> 8) & 0xFF, $devSerial & 0xFF, ($replyId >> 8) & 0xFF, $replyId & 0xFF, 0x00];
        $this->sendPacket($socket, 0x8001, $phoneRaw, $body, $protoVer);
    }

    private function sendPacket($socket, $msgId, $phoneRaw, $body, $protoVer = 0x01)
    {
        // FIX 2: Cambiar 0x4000 por 0x0000 (texto plano, sin cifrar)
        // El bit 14 (0x4000) puede ser interpretado como "paquete encriptado" por algunas cámaras
        $attr = 0x0000 | count($body);

        // FIX 1 (continuación): Usar la versión del protocolo que envió la cámara
        $header = [($msgId >> 8) & 0xFF, $msgId & 0xFF, ($attr >> 8) & 0xFF, $attr & 0xFF, $protoVer];

        foreach ($phoneRaw as $b) {
            $header[] = $b;
        }

        static $srvSerial = 1;
        $header[] = ($srvSerial >> 8) & 0xFF;
        $header[] = $srvSerial & 0xFF;
        $srvSerial = ($srvSerial + 1) % 65535;

        $full = array_merge($header, $body);
        $cs = 0;
        foreach ($full as $b) {
            $cs ^= $b;
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

        @socket_write($socket, pack('C*', ...$final));
    }
}
