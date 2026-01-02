<?php

namespace App\Events;

use App\Models\WCustomer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerRegistered
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public WCustomer $customer)
    {
        //
    }
}