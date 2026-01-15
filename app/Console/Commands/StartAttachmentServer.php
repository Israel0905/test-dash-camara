<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MDVR\AttachmentServer;
use React\EventLoop\Loop;

class StartAttachmentServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mdvr:attachments 
                            {--host= : Host to bind to}
                            {--port= : Port to listen on}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the MDVR attachment server for receiving video/image files';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $host = $this->option('host') ?? config('mdvr.attachment_server.host', '0.0.0.0');
        $port = $this->option('port') ?? config('mdvr.attachment_server.port', 8809);

        // Override config
        config(['mdvr.attachment_server.host' => $host]);
        config(['mdvr.attachment_server.port' => $port]);

        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║         MDVR Attachment Server - File Receiver            ║');
        $this->info('╠══════════════════════════════════════════════════════════╣');
        $this->info("║  Host: {$host}");
        $this->info("║  Port: {$port}");
        $this->info('║  Storage: ' . config('mdvr.storage_path'));
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();
        $this->info('Press Ctrl+C to stop the server.');
        $this->newLine();

        try {
            $server = new AttachmentServer();
            $server->start();

            // Run the event loop
            Loop::run();
        } catch (\Exception $e) {
            $this->error('Failed to start attachment server: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
