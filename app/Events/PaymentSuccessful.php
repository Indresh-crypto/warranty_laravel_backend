<?php

namespace App\Events;

use App\Models\OnlinePayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentSuccessful
{
    use Dispatchable, SerializesModels;

    public $payment;

    public function __construct(OnlinePayment $payment)
    {
        $this->payment = $payment;
    }
}
