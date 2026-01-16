<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast; // IMPORTANTE
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MdvrMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $data;

    public function __construct($data)
    {
        // Aquí recibimos el texto traducido o el array de datos
        $this->data = $data;
    }

    public function broadcastOn(): array
    {
        // Definimos un canal público llamado 'mdvr-terminal'
        return [
            new Channel('mdvr-terminal'),
        ];
    }
}
