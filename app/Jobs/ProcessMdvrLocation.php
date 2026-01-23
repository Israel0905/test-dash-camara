<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMdvrLocation implements ShouldQueue
{
    use Queueable;

    public $phone;
    public $bodyHex;

    /**
     * Create a new job instance.
     */
    public function __construct($phone, $bodyHex)
    {
        $this->phone = $phone;
        $this->bodyHex = $bodyHex;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        \Illuminate\Support\Facades\Log::info("ProcessMdvrLocation: Processing GPS for Phone {$this->phone}");
        // Aquí iría la lógica pesada de parsing y DB
    }
}
