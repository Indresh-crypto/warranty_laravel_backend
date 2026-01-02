<?php

namespace App\Events;

use App\Models\WarrantyClaim;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClaimRaised
{
    use Dispatchable, SerializesModels;

    public function __construct(public WarrantyClaim $claim)
    {
        //
    }
}