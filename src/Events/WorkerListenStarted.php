<?php

namespace FrockDev\ToolsForLaravel\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkerListenStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

}
