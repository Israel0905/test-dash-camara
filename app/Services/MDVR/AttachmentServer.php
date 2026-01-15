<?php

namespace App\Services\MDVR;

use React\Socket\SocketServer;
use React\Socket\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Attachment Server for receiving video/image files from MDVR devices
 * 
 * Handles 0x1210, 0x1211, file stream, and 0x1212 messages
 */
class AttachmentServer
{
    private SocketServer $server;
    private MessageBuilder $messageBuilder;
    private array $connections = [];
    private array $fileTransfers = [];

    public function __construct()
    {
        $this->messageBuilder = new MessageBuilder();
    }

    /**
     * Start the attachment server
     */
    public function start(): void
    {
        $host = config('mdvr.attachment_server.host', '0.0.0.0');
        $port = config('mdvr.attachment_server.port', 8809);

        $this->log("Starting MDVR Attachment Server on {$host}:{$port}");

        $this->server = new SocketServer("{$host}:{$port}");

        $this->server->on('connection', function (ConnectionInterface $connection) {
            $this->handleConnection($connection);
        });

        $this->server->on('error', function (\Exception $e) {
            $this->log("Server error: " . $e->getMessage(), 'error');
        });

        // Ensure storage directory exists
        $storagePath = config('mdvr.storage_path', storage_path('app/mdvr'));
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        $this->log("MDVR Attachment Server started successfully!");
        $this->log("Files will be saved to: {$storagePath}");
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
            'buffer' => '',
            'currentFile' => null,
            'fileHandle' => null,
            'bytesReceived' => 0,
        ];

        $this->log("New attachment connection from: {$remoteAddress}");

        // Handle incoming data
        $connection->on('data', function ($data) use ($connectionId) {
            $this->handleData($connectionId, $data);
        });

        // Handle connection close
        $connection->on('close', function () use ($connectionId, $remoteAddress) {
            $this->log("Attachment connection closed: {$remoteAddress}");
            $this->closeFileHandle($connectionId);
            unset($this->connections[$connectionId]);
        });

        $connection->on('error', function (\Exception $e) use ($remoteAddress) {
            $this->log("Connection error from {$remoteAddress}: " . $e->getMessage(), 'error');
        });
    }

    /**
     * Handle incoming data
     */
    private function handleData(string $connectionId, string $data): void
    {
        if (!isset($this->connections[$connectionId])) {
            return;
        }

        $connInfo = &$this->connections[$connectionId];

        // Check if this is file stream data (starts with 0x30 0x31 0x63 0x64)
        $bytes = array_values(unpack('C*', $data));
        
        if (count($bytes) >= 4 && 
            $bytes[0] === 0x30 && $bytes[1] === 0x31 && 
            $bytes[2] === 0x63 && $bytes[3] === 0x64) {
            // This is a file stream packet
            $this->handleFileStreamPacket($connectionId, $bytes);
            return;
        }

        // Otherwise, treat as protocol message
        $connInfo['buffer'] .= $data;

        while (true) {
            $buffer = $connInfo['buffer'];

            $startPos = strpos($buffer, chr(0x7E));
            if ($startPos === false) {
                $connInfo['buffer'] = '';
                break;
            }

            if ($startPos > 0) {
                $buffer = substr($buffer, $startPos);
                $connInfo['buffer'] = $buffer;
            }

            $endPos = strpos($buffer, chr(0x7E), 1);
            if ($endPos === false) {
                break;
            }

            $messageBytes = substr($buffer, 0, $endPos + 1);
            $connInfo['buffer'] = substr($buffer, $endPos + 1);

            $bytes = array_values(unpack('C*', $messageBytes));
            $this->processMessage($connectionId, $bytes);
        }
    }

    /**
     * Process a complete protocol message
     */
    private function processMessage(string $connectionId, array $rawBytes): void
    {
        $hexMessage = ProtocolHelper::bytesToHexString($rawBytes);
        $this->log("Received: {$hexMessage}");

        $message = ProtocolHelper::parseMessage($rawBytes);
        if (!$message || !$message['valid']) {
            $this->log("Invalid message received", 'warning');
            return;
        }

        $header = $message['header'];
        $body = $message['body'];

        $this->connections[$connectionId]['phoneNumber'] = $header['phoneNumber'];

        switch ($header['messageId']) {
            case ProtocolHelper::MSG_ALARM_ATTACHMENT_INFO: // 0x1210
                $this->handleAlarmAttachmentInfo($connectionId, $header, $body);
                break;

            case ProtocolHelper::MSG_FILE_INFO: // 0x1211
                $this->handleFileInfo($connectionId, $header, $body);
                break;

            case ProtocolHelper::MSG_FILE_COMPLETE: // 0x1212
                $this->handleFileComplete($connectionId, $header, $body);
                break;

            default:
                $this->log("Unknown message: {$header['messageIdHex']}");
                $this->sendGeneralResponse($connectionId, $header['phoneNumber'], $header['serialNumber'], $header['messageId'], 3);
        }
    }

    /**
     * Handle Alarm Attachment Info (0x1210)
     */
    private function handleAlarmAttachmentInfo(string $connectionId, array $header, array $body): void
    {
        $phoneNumber = $header['phoneNumber'];
        $serialNumber = $header['serialNumber'];

        // Parse terminal ID (7 bytes)
        $terminalId = trim(implode('', array_map('chr', array_slice($body, 0, 7))), "\x00");

        // Alarm identification (16 bytes) at offset 7
        $alarmIdentification = array_slice($body, 7, 16);

        // Alarm number (32 bytes) at offset 23
        $alarmNumber = trim(implode('', array_map('chr', array_slice($body, 23, 32))), "\x00");

        // Info type (1 byte) at offset 55
        $infoType = $body[55] ?? 0;

        // Number of attachments (1 byte) at offset 56
        $numAttachments = $body[56] ?? 0;

        $this->log("Alarm Attachment Info - Terminal: {$terminalId}, Alarm#: {$alarmNumber}, Attachments: {$numAttachments}");

        // Store transfer info
        $this->fileTransfers[$connectionId] = [
            'terminalId' => $terminalId,
            'alarmNumber' => $alarmNumber,
            'numAttachments' => $numAttachments,
            'filesReceived' => 0,
            'files' => [],
        ];

        // Parse attachment list
        $offset = 57;
        for ($i = 0; $i < $numAttachments && $offset < count($body); $i++) {
            $filenameLength = $body[$offset] ?? 0;
            $offset++;

            if ($offset + $filenameLength + 4 > count($body)) break;

            $filename = implode('', array_map('chr', array_slice($body, $offset, $filenameLength)));
            $offset += $filenameLength;

            $fileSize = ($body[$offset] << 24) | ($body[$offset + 1] << 16) | 
                       ($body[$offset + 2] << 8) | $body[$offset + 3];
            $offset += 4;

            $this->fileTransfers[$connectionId]['files'][$filename] = [
                'expectedSize' => $fileSize,
                'receivedSize' => 0,
                'complete' => false,
            ];

            $this->log("Expected file: {$filename} ({$fileSize} bytes)");
        }

        // Send general response
        $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_ALARM_ATTACHMENT_INFO, 0);
    }

    /**
     * Handle File Info (0x1211)
     */
    private function handleFileInfo(string $connectionId, array $header, array $body): void
    {
        $phoneNumber = $header['phoneNumber'];
        $serialNumber = $header['serialNumber'];

        if (count($body) < 2) {
            $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_FILE_INFO, 2);
            return;
        }

        $filenameLength = $body[0];
        $filename = implode('', array_map('chr', array_slice($body, 1, $filenameLength)));
        $fileType = $body[$filenameLength + 1] ?? 0;
        
        $offset = $filenameLength + 2;
        $fileSize = 0;
        if ($offset + 4 <= count($body)) {
            $fileSize = ($body[$offset] << 24) | ($body[$offset + 1] << 16) | 
                       ($body[$offset + 2] << 8) | $body[$offset + 3];
        }

        $fileTypes = ['IMAGE', 'AUDIO', 'VIDEO', 'TEXT', 'OTHER'];
        $fileTypeStr = $fileTypes[$fileType] ?? 'UNKNOWN';

        $this->log("File Info - Name: {$filename}, Type: {$fileTypeStr}, Size: {$fileSize} bytes");

        // Prepare to receive file
        $storagePath = config('mdvr.storage_path', storage_path('app/mdvr'));
        $deviceFolder = $this->connections[$connectionId]['phoneNumber'] ?? 'unknown';
        $dateFolder = date('Y-m-d');
        
        $fullPath = "{$storagePath}/{$deviceFolder}/{$dateFolder}";
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        $filePath = "{$fullPath}/{$filename}";
        $this->connections[$connectionId]['currentFile'] = [
            'name' => $filename,
            'path' => $filePath,
            'type' => $fileType,
            'expectedSize' => $fileSize,
            'receivedSize' => 0,
        ];

        // Open file for writing
        $this->connections[$connectionId]['fileHandle'] = fopen($filePath, 'wb');

        $this->sendGeneralResponse($connectionId, $phoneNumber, $serialNumber, ProtocolHelper::MSG_FILE_INFO, 0);
    }

    /**
     * Handle File Stream Packet (Table 4.5)
     */
    private function handleFileStreamPacket(string $connectionId, array $bytes): void
    {
        // Frame header: 0x30 0x31 0x63 0x64 (4 bytes)
        // Filename: 50 bytes
        // Data offset: 4 bytes
        // Length: 4 bytes
        // Data body: variable

        if (count($bytes) < 62) {
            $this->log("Invalid file stream packet (too short)", 'warning');
            return;
        }

        $filename = trim(implode('', array_map('chr', array_slice($bytes, 4, 50))), "\x00");
        $dataOffset = ($bytes[54] << 24) | ($bytes[55] << 16) | ($bytes[56] << 8) | $bytes[57];
        $dataLength = ($bytes[58] << 24) | ($bytes[59] << 16) | ($bytes[60] << 8) | $bytes[61];
        $dataBody = array_slice($bytes, 62, $dataLength);

        // Write to file
        $connInfo = &$this->connections[$connectionId];
        if (isset($connInfo['fileHandle']) && $connInfo['fileHandle']) {
            fwrite($connInfo['fileHandle'], pack('C*', ...$dataBody));
            $connInfo['currentFile']['receivedSize'] += $dataLength;

            // Log progress every 64KB
            if ($connInfo['currentFile']['receivedSize'] % 65536 < $dataLength) {
                $received = $connInfo['currentFile']['receivedSize'];
                $expected = $connInfo['currentFile']['expectedSize'];
                $percent = $expected > 0 ? round(($received / $expected) * 100, 1) : 0;
                $this->log("File progress: {$filename} - {$percent}% ({$received}/{$expected} bytes)");
            }
        }
    }

    /**
     * Handle File Complete (0x1212)
     */
    private function handleFileComplete(string $connectionId, array $header, array $body): void
    {
        $phoneNumber = $header['phoneNumber'];

        if (count($body) < 2) {
            return;
        }

        $filenameLength = $body[0];
        $filename = implode('', array_map('chr', array_slice($body, 1, $filenameLength)));
        $fileType = $body[$filenameLength + 1] ?? 0;
        
        $offset = $filenameLength + 2;
        $fileSize = 0;
        if ($offset + 4 <= count($body)) {
            $fileSize = ($body[$offset] << 24) | ($body[$offset + 1] << 16) | 
                       ($body[$offset + 2] << 8) | $body[$offset + 3];
        }

        $this->log("File Complete - Name: {$filename}, Size: {$fileSize} bytes");

        // Close file handle
        $this->closeFileHandle($connectionId);

        // Verify file size
        $connInfo = $this->connections[$connectionId];
        $receivedSize = $connInfo['currentFile']['receivedSize'] ?? 0;
        $needsRetransmit = $receivedSize < $fileSize;

        // Send file complete response (0x9212)
        $response = $this->messageBuilder->buildFileCompleteResponse(
            $phoneNumber,
            $filename,
            $fileType,
            $needsRetransmit ? 0x01 : 0x00,
            [] // retransmit packets if needed
        );
        $this->sendResponse($connectionId, $response);

        if ($needsRetransmit) {
            $this->log("File incomplete, requesting retransmit", 'warning');
        } else {
            $this->log("File saved successfully: {$connInfo['currentFile']['path']}");
        }

        // Clear current file info
        $this->connections[$connectionId]['currentFile'] = null;

        // Update transfer progress
        if (isset($this->fileTransfers[$connectionId])) {
            $this->fileTransfers[$connectionId]['filesReceived']++;
        }
    }

    /**
     * Close file handle for connection
     */
    private function closeFileHandle(string $connectionId): void
    {
        if (isset($this->connections[$connectionId]['fileHandle']) && 
            $this->connections[$connectionId]['fileHandle']) {
            fclose($this->connections[$connectionId]['fileHandle']);
            $this->connections[$connectionId]['fileHandle'] = null;
        }
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
     * Send response
     */
    private function sendResponse(string $connectionId, array $response): void
    {
        if (!isset($this->connections[$connectionId])) {
            return;
        }

        $connection = $this->connections[$connectionId]['connection'];
        $hexResponse = ProtocolHelper::bytesToHexString($response);
        $this->log("Sending: {$hexResponse}");

        $connection->write(MessageBuilder::toBytes($response));
    }

    /**
     * Log message
     */
    private function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[{$timestamp}] [MDVR-ATTACH] {$message}";

        echo $formattedMessage . PHP_EOL;

        if (config('mdvr.logging.enabled', true)) {
            Log::{$level}("[MDVR-ATTACH] {$message}");
        }
    }
}
