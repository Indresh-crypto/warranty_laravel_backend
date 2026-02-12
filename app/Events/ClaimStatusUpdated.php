<?php

namespace App\Events;

use App\Models\WarrantyClaim;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClaimStatusUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public WarrantyClaim $claim,
        public string $status
    ) {}
}