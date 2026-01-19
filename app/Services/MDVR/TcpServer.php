<?php

namespace App\Services\MDVR;

use Illuminate\Support\Facades\Log;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

/**
 * TCP Server for MDVR/JTT808 Protocol
 *
 * Handles connections from MDVR devices and processes messages
 */
class TcpServer
{
    private SocketServer $server;

    private MessageBuilder $messageBuilder;

    private array $connections = [];

    private array $devices = [];

    public function __construct()
    {
        $this->messageBuilder = new MessageBuilder;
    }

    /**
     * Start the TCP server
     */
    public function start(): void
    {
        $host = config('mdvr.server.host', '0.0.0.0');
        $port = config('mdvr.server.port', 8808);

        $this->log("Starting MDVR Server on {$host}:{$port}");

        $this->server = new SocketServer("{$host}:{$port}");

        $this->server->on('connection', function (ConnectionInterface $connection) {
            $this->handleConnection($connection);
        });

        $this->server->on('error', function (\Exception $e) {
            $this->log('Server error: '.$e->getMessage(), 'error');
        });

        $this->log('MDVR Server started successfully!');
        $this->log('Waiting for device connections...');
    }

    /**
     * Handle new connection
     */
    private function handleConnection(ConnectionInterface $connection): void
    {
        $remoteAddress = $connection->getRemoteAddress();
        $connectionId = spl_object_hash($connection);

        $this->connections[$connectionId] = [
            'connection' => $connection,
            'address' => $remoteAddress,
            'phoneNumber' => null,
            'authenticated' => false,
            'lastHeartbeat' => time(),
            'buffer' => '',
        ];

        $this->log("New connection from: {$remoteAddress}");

        // Handle incoming data
        $connection->on('data', function ($data) use ($connectionId) {
            $this->handleData($connectionId, $data);
        });

        // Handle connection close
        $connection->on('close', function () use ($connectionId, $remoteAddress) {
            $this->log("Connection closed: {$remoteAddress}");
            unset($this->connections[$connectionId]);
        });

        // Handle errors
        $connection->on('error', function (\Exception $e) use ($remoteAddress) {
            $this->log("Connection error from {$remoteAddress}: ".$e->getMessage(), 'error');
        });
    }

    /**
     * Handle incoming data
     */
    private function handleData(string $connectionId, string $data): void
    {
        if (! isset($this->connections[$connectionId])) {
            return;
        }

        $connInfo = &$this->connections[$connectionId];
        $connInfo['buffer'] .= $data;

        // Process complete messages (between 0x7E delimiters)
        while (true) {
            $buffer = $connInfo['buffer'];

            // Find start delimiter
            $startPos = strpos($buffer, chr(0x7E));
            if ($startPos === false) {
                $connInfo['buffer'] = '';
                break;
            }

            // Remove any data before start delimiter
            if ($startPos > 0) {
                $buffer = substr($buffer, $startPos);
                $connInfo['buffer'] = $buffer;
            }

            // Find end delimiter (after start)
            $endPos = strpos($buffer, chr(0x7E), 1);
            if ($endPos === false) {
                // Incomplete message, wait for more data
                break;
            }

            // Extract complete message
            $messageBytes = substr($buffer, 0, $endPos + 1);
            $connInfo['buffer'] = substr($buffer, $endPos + 1);

            // Convert to byte array
            $bytes = array_values(unpack('C*', $messageBytes));

            // Process the message
            $this->processMessage($connectionId, $bytes);
        }
    }

    /**
     * Process a complete message
     */
    private function processMessage(string $connectionId, array $rawBytes): void
    {
        $hexMessage = ProtocolHelper::bytesToHexString($rawBytes);
        $this->log("Received: {$hexMessage}");

        // Parse message
        $message = ProtocolHelper::parseMessage($rawBytes);
        if (! $message || ! $message['valid']) {
            $this->log('Invalid message received', 'warning');

            return;
        }

        $header = $message['header'];
        $body = $message['body'];

        /*
        |--------------------------------------------------------------------------
        | AGREGADO: Envío de datos a la vista Web (WebSockets)
        |--------------------------------------------------------------------------
        | Enviamos el mensaje procesado al evento MdvrMessageReceived.
        | Esto permitirá que el texto plano aparezca en tu navegador.
        */
        event(new \App\Events\MdvrMessageReceived([
            'phoneNumber' => $header['phoneNumber'],
            'messageId' => $header['messageIdHex'],
            'body' => $body, // Aquí van los datos traducidos o crudos
            'time' => now()->format('H:i:s'),
            'hex' => $hexMessage, // Útil para ver la trama original en la web
        ]));
        /*
        |--------------------------------------------------------------------------
        */

        $this->log("Message ID: {$header['messageIdHex']}, Phone: {$header['phoneNumber']}, Serial: {$header['serialNumber']}");

        // Update connection info
        if (! empty($header['phoneNumber'])) {
            $this->connections[$connectionId]['phoneNumber'] = $header['phoneNumber'];
        }

        // Handle message based on ID
        $this->handleMessageById($connectionId, $header, $body);
    }

    /**
     * Handle message by ID
     */
    private function handleMessageById(string $connectionId, array $header, array $body): void
    {
        $messageId = $header['messageId'];
        $phoneNumber = $header['phoneNumber'];
        $serialNumber = $header['serialNumber'];

        switch ($messageId) {
            case ProtocolHelper::MSG_REGISTRATION:
                $this->handleRegistration($connectionId, $header, $body);
                break;

            case ProtocolHelper::MSG_AUTHENTICATION:
                $this->handleAuthentication($connectionId, $header, $body);
                break;

            case ProtocolHelper::MSG_HEARTBEAT:
                $this->handleHeartbeat($connectionId, $header);
                break;

            case ProtocolHelper::MSG_LOCATION_REPORT:
                $this->handleLocationReport($connectionId, $header, $body);
                break;

            case ProtocolHelper::MSG_LOCATION_BATCH:
                $this->handleLocationBatch($connectionId, $header, $body);
                break;

            case ProtocolHelper::MSG_DEVICE_GENERAL_RESPONSE:
                $this->handleDeviceResponse($connectionId, $header, $body);
                break;

            case ProtocolHelper::MSG_TRANSPARENT_DATA:
                $this->handleTransparentData($connectionId, $header, $body);
                break;

            case ProtocolHelper::MSG_RESOURCE_LIST_RESPONSE:
                $this->handleResourceListResponse($connectionId, $header, $body);
                break;

            case ProtocolHelper::MSG_ALARM_ATTACHMENT_INFO:
                $this->handleAlarmAttachmentInfo($connectionId, $header, $body);
                break;

            default:
                $this->log("Unknown message ID: {$header['messageIdHex']}", 'warning');
                // Send general response anyway
                $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, $messageId, 3); // Not supported
                break;
        }
    }

    /**
     * Handle Registration (0x0100)
     */
    private function handleRegistration(string $connectionId, array $header, array $body): void
    {
        $phoneNumber = $header['phoneNumber'];
        $serialNumber = $header['serialNumber'];

        // Parse registration data
        $provinceId = ($body[0] << 8) | $body[1];
        $countyId = ($body[2] << 8) | $body[3];
        $manufacturerId = implode('', array_map('chr', array_slice($body, 4, 11)));
        $terminalModel = trim(implode('', array_map('chr', array_slice($body, 15, 30))), "\x00");
        $terminalId = trim(implode('', array_map('chr', array_slice($body, 45, 30))), "\x00");
        $plateColor = $body[75] ?? 0;
        $plateNumber = '';
        if (count($body) > 76) {
            $plateBytes = array_slice($body, 76);
            $plateNumber = mb_convert_encoding(implode('', array_map('chr', $plateBytes)), 'UTF-8', 'GBK');
        }

        $this->log("Registration - Manufacturer: {$manufacturerId}, Model: {$terminalModel}, ID: {$terminalId}, Plate: {$plateNumber}");

        // Store device info
        $this->devices[$phoneNumber] = [
            'manufacturerId' => $manufacturerId,
            'terminalModel' => $terminalModel,
            'terminalId' => $terminalId,
            'plateNumber' => $plateNumber,
            'plateColor' => $plateColor,
            'registeredAt' => date('Y-m-d H:i:s'),
            'phoneNumberRaw' => $header['phoneNumberRaw'],
        ];
        // Use Phone Number (992002) with Padding 00 format
        $authCode = $phoneNumber;
        $this->devices[$phoneNumber]['authCode'] = '';

        // Send registration response (0x8100) with result=1 (Vehicle already registered)
        // This forces device to use its internal password
        $this->devices[$phoneNumber]['authCode'] = '';

        $response = $this->messageBuilder->buildRegistrationResponseWithRawPhone(
            $header['phoneNumberRaw'],
            $header['serialNumber'],
            1, // Result: 1 = Vehicle already registered
            '' // No auth code
        );
        $this->sendResponse($connectionId, $response);

        $this->log("Registration successful - Auth code: {$authCode}");
    }

    /**
     * Handle Authentication (0x0102)
     */
    private function handleAuthentication(string $connectionId, array $header, array $body): void
    {
        $phoneNumber = $header['phoneNumber'];
        $serialNumber = $header['serialNumber'];

        if (count($body) < 1) {
            $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_AUTHENTICATION, 2);

            return;
        }

        $authCodeLength = $body[0];
        $authCode = implode('', array_map('chr', array_slice($body, 1, $authCodeLength)));

        $this->log("Authentication - Code: {$authCode}");

        // Mark connection as authenticated
        $this->connections[$connectionId]['authenticated'] = true;

        // Extract IMEI and firmware if present
        if (count($body) > $authCodeLength + 1) {
            $imei = trim(implode('', array_map('chr', array_slice($body, $authCodeLength + 1, 15))), "\x00");
            $firmware = trim(implode('', array_map('chr', array_slice($body, $authCodeLength + 16, 20))), "\x00");
            $this->log("IMEI: {$imei}, Firmware: {$firmware}");

            if (isset($this->devices[$phoneNumber])) {
                $this->devices[$phoneNumber]['imei'] = $imei;
                $this->devices[$phoneNumber]['firmware'] = $firmware;
            }
        }

        // Send success response
        $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_AUTHENTICATION, 0);
        $this->log("Authentication successful for {$phoneNumber}");
    }

    /**
     * Handle Heartbeat (0x0002)
     */
    private function handleHeartbeat(string $connectionId, array $header): void
    {
        $phoneNumber = $header['phoneNumber'];
        $serialNumber = $header['serialNumber'];

        $this->connections[$connectionId]['lastHeartbeat'] = time();
        $this->log("Heartbeat from {$phoneNumber}");

        // Send response
        $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_HEARTBEAT, 0);
    }

    /**
     * Handle Location Report (0x0200)
     */
    private function handleLocationReport(string $connectionId, array $header, array $body): void
    {
        $phoneNumber = $header['phoneNumber'];
        $serialNumber = $header['serialNumber'];

        // Parse basic location info
        $location = ProtocolHelper::parseLocationBasicInfo($body);

        $this->log("Location - Lat: {$location['latitude']}, Lng: {$location['longitude']}, Speed: {$location['speed']} km/h, ACC: ".($location['accOn'] ? 'ON' : 'OFF'));

        // Parse additional info
        $additionalInfo = ProtocolHelper::parseLocationAdditionalInfo($body);

        // Check for AI alarms (ADAS 0x64, DSM 0x65, BSD 0x67)
        $aiAlarms = [];
        foreach ([ProtocolHelper::ADDINFO_ADAS_ALARM, ProtocolHelper::ADDINFO_DSM_ALARM, ProtocolHelper::ADDINFO_BSD_ALARM, ProtocolHelper::ADDINFO_AGGRESSIVE_DRIVING] as $alarmId) {
            if (isset($additionalInfo[$alarmId])) {
                $aiAlarms[] = $additionalInfo[$alarmId];
                $this->log('AI Alarm detected: '.json_encode($additionalInfo[$alarmId]));
            }
        }

        // If AI alarms present, could trigger attachment upload request
        if (! empty($aiAlarms)) {
            $this->handleAiAlarmDetected($connectionId, $phoneNumber, $aiAlarms);
        }

        // Log additional info
        if (! empty($additionalInfo)) {
            foreach ($additionalInfo as $id => $info) {
                if (! in_array($id, [ProtocolHelper::ADDINFO_ADAS_ALARM, ProtocolHelper::ADDINFO_DSM_ALARM, ProtocolHelper::ADDINFO_BSD_ALARM, ProtocolHelper::ADDINFO_AGGRESSIVE_DRIVING])) {
                    $this->log("Additional Info [{$id}]: ".json_encode($info));
                }
            }
        }

        // Send response
        $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_LOCATION_REPORT, 0);

        // TODO: Store location in database
        // DeviceLocation::create([...]);
    }

    /**
     * Handle AI Alarm Detection
     */
    private function handleAiAlarmDetected(string $connectionId, string $phoneNumber, array $alarms): void
    {
        $this->log("Processing AI alarms for {$phoneNumber}");

        // Could send 0x9208 to request alarm attachments
        // For now, just log the alarms
        foreach ($alarms as $alarm) {
            $this->log('AI Alarm: '.json_encode($alarm));
            // TODO: Store alarm in database and optionally request attachment
        }
    }

    /**
     * Handle Location Batch (0x0704)
     */
    private function handleLocationBatch(string $connectionId, array $header, array $body): void
    {
        $phoneNumber = $header['phoneNumber'];
        $serialNumber = $header['serialNumber'];

        if (count($body) < 3) {
            $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_LOCATION_BATCH, 2);

            return;
        }

        $dataCount = ($body[0] << 8) | $body[1];
        $dataType = $body[2]; // 0: normal, 1: blind spot supplement

        $this->log("Location batch - Count: {$dataCount}, Type: {$dataType}");

        // Parse each location in the batch
        $offset = 3;
        for ($i = 0; $i < $dataCount && $offset < count($body); $i++) {
            if ($offset + 2 > count($body)) {
                break;
            }

            $bodyLength = ($body[$offset] << 8) | $body[$offset + 1];
            $offset += 2;

            if ($offset + $bodyLength > count($body)) {
                break;
            }

            $locationBody = array_slice($body, $offset, $bodyLength);
            $location = ProtocolHelper::parseLocationBasicInfo($locationBody);

            $this->log("Batch Location {$i}: Lat: {$location['latitude']}, Lng: {$location['longitude']}");

            $offset += $bodyLength;
        }

        $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_LOCATION_BATCH, 0);
    }

    /**
     * Handle Device General Response (0x0001)
     */
    private function handleDeviceResponse(string $connectionId, array $header, array $body): void
    {
        if (count($body) < 5) {
            return;
        }

        $replySerial = ($body[0] << 8) | $body[1];
        $replyId = ($body[2] << 8) | $body[3];
        $result = $body[4];

        $resultText = ['success', 'failure', 'message error', 'not supported'][$result] ?? 'unknown';
        $this->log('Device response for 0x'.sprintf('%04X', $replyId)." serial {$replySerial}: {$resultText}");
    }

    /**
     * Handle Transparent Data (0x0900)
     */
    private function handleTransparentData(string $connectionId, array $header, array $body): void
    {
        $phoneNumber = $header['phoneNumber'];
        $serialNumber = $header['serialNumber'];

        if (empty($body)) {
            $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_TRANSPARENT_DATA, 2);

            return;
        }

        $messageType = $body[0];
        $data = array_slice($body, 1);

        $this->log('Transparent data - Type: 0x'.sprintf('%02X', $messageType).', Length: '.count($data));

        // Handle different transparent message types
        switch ($messageType) {
            case 0xF1: // GPS data (Table 3.10.3)
            case 0xF3: // GPS data standard (Table 3.10.6)
                $this->log('GPS transparent data received');
                break;

            case 0x41: // OBD data
                $obdString = implode('', array_map('chr', $data));
                $this->log("OBD data: {$obdString}");
                break;

            case 0xA1: // WiFi info
                $this->log('WiFi info received');
                break;

            default:
                $this->log('Unknown transparent data type: 0x'.sprintf('%02X', $messageType));
        }

        $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_TRANSPARENT_DATA, 0);
    }

    /**
     * Handle Resource List Response (0x1205)
     */
    private function handleResourceListResponse(string $connectionId, array $header, array $body): void
    {
        if (count($body) < 6) {
            $this->log('Invalid resource list response', 'warning');

            return;
        }

        $replySerial = ($body[0] << 8) | $body[1];
        $totalResources = ($body[2] << 24) | ($body[3] << 16) | ($body[4] << 8) | $body[5];

        $this->log("Resource list response - Serial: {$replySerial}, Total: {$totalResources}");

        // Parse resource list
        $offset = 6;
        $resources = [];
        while ($offset + 28 <= count($body)) {
            $channel = $body[$offset];
            $startTime = ProtocolHelper::bcdToString(array_slice($body, $offset + 1, 6));
            $endTime = ProtocolHelper::bcdToString(array_slice($body, $offset + 7, 6));
            // Skip alarm sign (8 bytes)
            $resourceType = $body[$offset + 21];
            $streamType = $body[$offset + 22];
            $storageType = $body[$offset + 23];
            $fileSize = ($body[$offset + 24] << 24) | ($body[$offset + 25] << 16) | ($body[$offset + 26] << 8) | $body[$offset + 27];

            $resources[] = [
                'channel' => $channel,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'resourceType' => $resourceType,
                'streamType' => $streamType,
                'fileSize' => $fileSize,
            ];

            $this->log("Resource: CH{$channel}, {$startTime}-{$endTime}, Size: {$fileSize} bytes");
            $offset += 28;
        }
    }

    /**
     * Handle Alarm Attachment Info (0x1210) - Usually on attachment server
     */
    private function handleAlarmAttachmentInfo(string $connectionId, array $header, array $body): void
    {
        $phoneNumber = $header['phoneNumber'];
        $serialNumber = $header['serialNumber'];

        $this->log("Alarm attachment info received from {$phoneNumber}");

        // This is typically handled by the attachment server
        // For now, just acknowledge
        $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_ALARM_ATTACHMENT_INFO, 0);
    }

    /**
     * Send general response
     */
    private function sendGeneralResponse(string $connectionId, string $phoneNumber, int $serialNumber, int $messageId, int $result): void
    {
        $response = $this->messageBuilder->buildGeneralResponse($phoneNumber, $serialNumber, $messageId, $result);
        $this->sendResponse($connectionId, $response);
    }

    /**
     * Send response to connection
     */
    private function sendResponse(string $connectionId, array $response): void
    {
        if (! isset($this->connections[$connectionId])) {
            return;
        }

        $connection = $this->connections[$connectionId]['connection'];
        $hexResponse = ProtocolHelper::bytesToHexString($response);
        $this->log("Sending: {$hexResponse}");

        $connection->write(MessageBuilder::toBytes($response));
    }

    /**
     * Query video resources from a device
     */
    public function queryResources(string $phoneNumber, int $channel, string $startTime, string $endTime): bool
    {
        $connectionId = $this->findConnectionByPhone($phoneNumber);
        if (! $connectionId) {
            $this->log("Device {$phoneNumber} not connected", 'warning');

            return false;
        }

        $message = $this->messageBuilder->buildQueryResourcesRequest($phoneNumber, $channel, $startTime, $endTime);
        $this->sendResponse($connectionId, $message);

        return true;
    }

    /**
     * Request live video from device
     */
    public function requestLiveVideo(string $phoneNumber, int $channel, string $serverIp, int $tcpPort): bool
    {
        $connectionId = $this->findConnectionByPhone($phoneNumber);
        if (! $connectionId) {
            return false;
        }

        $message = $this->messageBuilder->buildVideoRequest($phoneNumber, $serverIp, $tcpPort, 0, $channel);
        $this->sendResponse($connectionId, $message);

        return true;
    }

    /**
     * Find connection ID by phone number
     */
    private function findConnectionByPhone(string $phoneNumber): ?string
    {
        foreach ($this->connections as $id => $conn) {
            if ($conn['phoneNumber'] === $phoneNumber) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Get connected devices
     */
    public function getConnectedDevices(): array
    {
        $devices = [];
        foreach ($this->connections as $id => $conn) {
            if ($conn['phoneNumber']) {
                $devices[] = [
                    'phoneNumber' => $conn['phoneNumber'],
                    'address' => $conn['address'],
                    'authenticated' => $conn['authenticated'],
                    'lastHeartbeat' => $conn['lastHeartbeat'],
                ];
            }
        }

        return $devices;
    }

    /**
     * Log message
     */
    private function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[{$timestamp}] [MDVR] {$message}";

        echo $formattedMessage.PHP_EOL;

        if (config('mdvr.logging.enabled', true)) {
            Log::{$level}("[MDVR] {$message}");
        }
    }
}
