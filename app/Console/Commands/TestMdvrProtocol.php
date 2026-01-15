<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MDVR\ProtocolHelper;
use App\Services\MDVR\MessageBuilder;

class TestMdvrProtocol extends Command
{
    protected $signature = 'mdvr:test {--message= : Hex message to parse}';
    protected $description = 'Test MDVR protocol parsing with a hex message';

    public function handle(): int
    {
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║              MDVR Protocol Test Utility                   ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Test with provided message or default example
        $hexMessage = $this->option('message') ?? 
            '7E 92 05 40 18 01 00 00 00 00 02 23 99 99 99 99 00 02 00 24 09 13 00 00 00 24 09 13 23 59 59 00 00 00 00 00 00 00 00 00 00 00 CE 7E';

        $this->info('Testing message parsing...');
        $this->line("Input: {$hexMessage}");
        $this->newLine();

        // Convert hex to bytes
        $bytes = ProtocolHelper::hexStringToBytes($hexMessage);
        $this->line('Bytes: ' . count($bytes));

        // Parse message
        $message = ProtocolHelper::parseMessage($bytes);

        if (!$message) {
            $this->error('Failed to parse message!');
            return Command::FAILURE;
        }

        $this->info('✓ Message parsed successfully!');
        $this->newLine();

        // Display header info
        $header = $message['header'];
        $this->table(
            ['Field', 'Value'],
            [
                ['Message ID', $header['messageIdHex'] . ' (' . $this->getMessageName($header['messageId']) . ')'],
                ['Body Length', $header['bodyLength']],
                ['Protocol Version', $header['protocolVersion']],
                ['Phone Number', $header['phoneNumber']],
                ['Serial Number', $header['serialNumber']],
                ['Encryption', $header['encryption']],
                ['Multi-packet', $header['isMultiPacket'] ? 'Yes' : 'No'],
            ]
        );

        // Parse body based on message type
        $this->newLine();
        $this->info('Message Body Analysis:');
        $this->parseMessageBody($header['messageId'], $message['body']);

        // Test building a response
        $this->newLine();
        $this->info('Building response message...');
        
        $builder = new MessageBuilder();
        $response = $builder->buildGeneralResponse(
            $header['phoneNumber'],
            $header['serialNumber'],
            $header['messageId'],
            0 // Success
        );

        $this->line('Response: ' . ProtocolHelper::bytesToHexString($response));

        $this->newLine();
        $this->info('✓ All tests passed!');

        return Command::SUCCESS;
    }

    private function getMessageName(int $messageId): string
    {
        $names = [
            0x0001 => 'Device General Response',
            0x0002 => 'Heartbeat',
            0x0100 => 'Registration',
            0x0102 => 'Authentication',
            0x0200 => 'Location Report',
            0x0704 => 'Location Batch',
            0x0900 => 'Transparent Data',
            0x1205 => 'Resource List Response',
            0x1210 => 'Alarm Attachment Info',
            0x1211 => 'File Info',
            0x1212 => 'File Complete',
            0x8001 => 'Server General Response',
            0x8100 => 'Registration Response',
            0x9101 => 'Video Request',
            0x9102 => 'Video Control',
            0x9201 => 'Playback Request',
            0x9202 => 'Playback Control',
            0x9205 => 'Query Resources',
            0x9206 => 'File Upload Instruction',
            0x9207 => 'File Upload Control',
            0x9208 => 'Alarm Attachment Request',
            0x9212 => 'File Complete Response',
        ];

        return $names[$messageId] ?? 'Unknown';
    }

    private function parseMessageBody(int $messageId, array $body): void
    {
        switch ($messageId) {
            case 0x9205: // Query Resources
                $this->parseQueryResources($body);
                break;

            case 0x0200: // Location Report
                $this->parseLocationReport($body);
                break;

            default:
                $this->line('Body (hex): ' . ProtocolHelper::bytesToHexString($body));
        }
    }

    private function parseQueryResources(array $body): void
    {
        if (count($body) < 24) {
            $this->warn('Incomplete query resources body');
            return;
        }

        $channel = $body[0];
        $startTime = ProtocolHelper::bcdToString(array_slice($body, 1, 6));
        $endTime = ProtocolHelper::bcdToString(array_slice($body, 7, 6));
        $resourceType = $body[21] ?? 0;
        $streamType = $body[22] ?? 0;
        $storageType = $body[23] ?? 0;

        $resourceTypes = ['Audio+Video', 'Audio', 'Video', 'Audio or Video'];
        $streamTypes = ['All', 'Main', 'Sub'];
        $storageTypes = ['All'];

        $this->table(
            ['Field', 'Value'],
            [
                ['Channel', $channel],
                ['Start Time', "20{$startTime}"],
                ['End Time', "20{$endTime}"],
                ['Resource Type', $resourceTypes[$resourceType] ?? 'Unknown'],
                ['Stream Type', $streamTypes[$streamType] ?? 'Unknown'],
                ['Storage Type', $storageTypes[$storageType] ?? 'Unknown'],
            ]
        );
    }

    private function parseLocationReport(array $body): void
    {
        $location = ProtocolHelper::parseLocationBasicInfo($body);
        
        if (empty($location)) {
            $this->warn('Could not parse location');
            return;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['Latitude', $location['latitude']],
                ['Longitude', $location['longitude']],
                ['Altitude', $location['altitude'] . ' m'],
                ['Speed', $location['speed'] . ' km/h'],
                ['Direction', $location['direction'] . '°'],
                ['Time', $location['time']],
                ['ACC', $location['accOn'] ? 'ON' : 'OFF'],
                ['Located', $location['located'] ? 'Yes' : 'No'],
                ['Alarms', implode(', ', $location['alarms']) ?: 'None'],
            ]
        );

        // Parse additional info if present
        if (count($body) > 28) {
            $additional = ProtocolHelper::parseLocationAdditionalInfo($body);
            if (!empty($additional)) {
                $this->newLine();
                $this->info('Additional Info:');
                foreach ($additional as $id => $info) {
                    $this->line(sprintf('  [0x%02X] %s', $id, json_encode($info)));
                }
            }
        }
    }
}
