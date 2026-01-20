<?php



namespace App\Console\Commands;



use Illuminate\Console\Command;



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

                        $input = @socket_read($s, 4096);

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

        // En 2019 el Serial está en la posición 15 y 16

        $terminalSerial = ($payload[15] << 8) | $payload[16];

        $phoneRaw = array_slice($payload, 5, 10);



        $this->info("[RECV] ID: 0x" . sprintf('%04X', $msgId) . " | Serial: $terminalSerial");



        if ($msgId === 0x0100) {

            $this->respondRegistration($socket, $phoneRaw, $terminalSerial);
        } elseif ($msgId === 0x0102) {

            $this->info("¡AUTENTICACIÓN RECIBIDA! Enviando respuesta general...");

            $this->respondGeneral($socket, $phoneRaw, $terminalSerial, 0x0102);
        }
    }



    private function respondRegistration($socket, $phoneRaw, $terminalSerial)

    {

        // INTENTO A: Usar un Auth Code que termine en 0x00 (Null Terminator)

        // A veces el equipo lo lee como string de C y si no hay nulo, se sigue de largo.

        $authCode = "123456";



        $body = [

            ($terminalSerial >> 8) & 0xFF,

            $terminalSerial & 0xFF,

            0x00, // Resultado: Éxito

        ];



        foreach (str_split($authCode) as $char) {

            $body[] = ord($char);
        }



        // Agregamos un byte NULO al final por si el MDVR espera terminación de cadena

        // Esto cambiará la longitud del cuerpo de 9 a 10.

        $body[] = 0x00;



        $this->sendPacket($socket, 0x8100, $phoneRaw, $body);
    }



    private function respondGeneral($socket, $phoneRaw, $terminalSerial, $replyId)

    {

        // CUERPO 0x8001: Serial(2) + MsgID(2) + Result(1)

        $body = [

            ($terminalSerial >> 8) & 0xFF,

            $terminalSerial & 0xFF,

            ($replyId >> 8) & 0xFF,

            $replyId & 0xFF,

            0x00

        ];

        $this->sendPacket($socket, 0x8001, $phoneRaw, $body);
    }



    private function sendPacket($socket, $msgId, $phoneRaw, $body)

    {

        $bodyLen = count($body);

        $attr = (1 << 14) | $bodyLen; // Bit 14 activo (Versión 2019)



        $header = [

            ($msgId >> 8) & 0xFF,

            $msgId & 0xFF,   // Message ID

            ($attr >> 8) & 0xFF,

            ($attr & 0xFF),   // Propiedades

            0x01,                                  // Protocol Version

        ];



        foreach ($phoneRaw as $b) {

            $header[] = $b;
        } // Teléfono (10 bytes)



        // El Serial del Servidor SOLO va aquí, en el Header.

        static $srvSerial = 0;

        $header[] = ($srvSerial >> 8) & 0xFF;

        $header[] = $srvSerial & 0xFF;

        $srvSerial = ($srvSerial + 1) % 65535;



        // Mostrar Header para tu paz mental

        $this->comment("Header: " . implode(' ', array_map(fn($b) => sprintf('%02X', $b), $header)));

        $this->comment("Cuerpo: " . implode(' ', array_map(fn($b) => sprintf('%02X', $b), $body)));



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



        socket_write($socket, pack('C*', ...$final));

        $this->info("[SEND] 0x" . sprintf('%04X', $msgId) . ": " . implode(' ', array_map(fn($b) => sprintf('%02X', $b), $final)));
    }
}
