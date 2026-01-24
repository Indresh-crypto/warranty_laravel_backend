<?php

namespace App\Events;

use App\Models\WDevice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarrantyRegisteredProvision
{
    use Dispatchable, SerializesModels;

    public function __construct(public WDevice $device)
    {
        //
    }
}