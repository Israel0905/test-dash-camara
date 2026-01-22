<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartMdvrServer extends Command
{
    protected $signature = 'mdvr:start {--port=8808}';

    protected $description = 'Servidor JT/T 808 Robusto - Manejo de Buffers';

    // Buffers para guardar fragmentos de cada cámara
    private $clientBuffers = [];

    // Nombres descriptivos de los mensajes JTT808
    private $msgNames = [
        0x0001 => 'ACK Terminal',
        0x0002 => 'Heartbeat',
        0x0100 => 'Registro',
        0x0102 => 'Autenticación',
        0x0200 => 'GPS',
        0x0704 => 'GPS Lote',
        0x0900 => 'Datos Extra',
        0x8001 => 'ACK Servidor',
        0x8100 => 'Registro OK',
    ];

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
        $this->newLine();
        $this->info('╔═══════════════════════════════════════════════════╗');
        $this->info('║     SERVIDOR MDVR JTT808 - Puerto '.str_pad($port, 5).'          ║');
        $this->info('╚═══════════════════════════════════════════════════╝');
        $this->newLine();

        $clients = [$socket];
        while (true) {
            $read = $clients;
            $write = $except = null;
            if (socket_select($read, $write, $except, 0, 100000) > 0) {
                foreach ($read as $s) {
                    if ($s === $socket) {
                        $newSocket = socket_accept($socket);
                        $clients[] = $newSocket;
                        $this->clientBuffers[spl_object_id($newSocket)] = '';
                        $this->info('🟢 <fg=green>CONECTADA</> Nueva cámara');
                    } else {
                        $input = @socket_read($s, 65535);
                        if ($input) {
                            $this->handleTcpStream($s, $input);
                        } else {
                            socket_close($s);
                            unset($this->clientBuffers[spl_object_id($s)]);
                            unset($clients[array_search($s, $clients)]);
                            $this->error('🔴 DESCONECTADA');
                            $this->newLine();
                        }
                    }
                }
            }
        }
    }

    private function getMsgName($msgId)
    {
        return $this->msgNames[$msgId] ?? sprintf('0x%04X', $msgId);
    }

    private function handleTcpStream($socket, $input)
    {
        $id = spl_object_id($socket);

        if (! isset($this->clientBuffers[$id])) {
            $this->clientBuffers[$id] = '';
        }
        $this->clientBuffers[$id] .= $input;

        while (($start = strpos($this->clientBuffers[$id], chr(0x7E))) !== false) {
            $end = strpos($this->clientBuffers[$id], chr(0x7E), $start + 1);

            if ($end === false) {
                break;
            }

            $packet = substr($this->clientBuffers[$id], $start, $end - $start + 1);
            $this->clientBuffers[$id] = substr($this->clientBuffers[$id], $end + 1);
            $this->parseSinglePacket($socket, $packet);
        }
    }

    private function parseSinglePacket($socket, $packet)
    {
        $bytes = array_values(unpack('C*', $packet));

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

        $calcCs = 0;
        $receivedCs = array_pop($data);
        foreach ($data as $b) {
            $calcCs ^= $b;
        }
        if ($calcCs !== $receivedCs) {
            return;
        }

        $msgId = ($data[0] << 8) | $data[1];
        $protoVer = $data[4];
        $phone = bin2hex(pack('C*', ...array_slice($data, 5, 10)));
        $devSerial = ($data[15] << 8) | $data[16];
        $body = array_slice($data, 17);
        $phoneRaw = array_slice($data, 5, 10);

        // LOG MEJORADO: Mensaje recibido
        $msgName = $this->getMsgName($msgId);
        $this->line(sprintf(
            '   📥 <fg=yellow>%-15s</> #%-3d │ Tel: %s',
            $msgName, $devSerial, substr($phone, -8)
        ));

        if ($msgId === 0x0100) {
            $this->respondRegistration($socket, $phoneRaw, $devSerial, $body, $protoVer);
        } else {
            $this->respondGeneral($socket, $phoneRaw, $devSerial, $msgId, $protoVer);
        }
    }

    private function respondRegistration($socket, $phoneRaw, $devSerial, $body, $protoVer)
    {
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
        $attr = ($protoVer === 1 ? 0x4000 : 0x0000) | count($body);
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

        // LOG MEJORADO: Respuesta enviada
        $replyName = $this->getMsgName($msgId);
        $this->line(sprintf('   📤 <fg=green>%-15s</> ✓', $replyName));

        $result = @socket_write($socket, pack('C*', ...$final));
        if ($result === false) {
            $this->error('   ❌ Error enviando: '.socket_strerror(socket_last_error($socket)));
        }
    }
}
