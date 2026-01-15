<?php

namespace App\Console\Commands;

use App\Services\MDVR\TcpServer;
use Illuminate\Console\Command;
use React\EventLoop\Loop;

class StartMdvrServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mdvr:serve 
                            {--host= : Host to bind to}
                            {--port= : Port to listen on}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the MDVR/JTT808 TCP server to receive data from dash cameras';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $host = $this->option('host') ?? config('mdvr.server.host', '0.0.0.0');
        $port = $this->option('port') ?? config('mdvr.server.port', 8808);

        // Override config
        config(['mdvr.server.host' => $host]);
        config(['mdvr.server.port' => $port]);

        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║           MDVR Server - JTT808/JTT1078 Protocol           ║');
        $this->info('╠══════════════════════════════════════════════════════════╣');
        $this->info("║  Host: {$host}");
        $this->info("║  Port: {$port}");
        $this->info('║  Protocol: JTT808-2019 / JTT1078-2016');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();
        $this->info('Press Ctrl+C to stop the server.');
        $this->newLine();

        try {
            $server = new TcpServer;
            $server->start();

            // Run the event loop
            Loop::run();
        } catch (\Exception $e) {
            $this->error('Failed to start server: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
