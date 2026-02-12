<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarrantyRegisterWhatsapp
{
    use Dispatchable, SerializesModels;

    public $device;

    public function __construct($device)
    {
        $this->device = $device;
    }
}
