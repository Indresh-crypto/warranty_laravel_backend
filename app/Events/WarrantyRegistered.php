<?php

namespace App\Events;

use App\Models\WDevice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarrantyRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(public WDevice $device)
    {
        //
    }
}