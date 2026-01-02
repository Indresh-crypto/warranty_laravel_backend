<?php

namespace App\Listeners;

use App\Events\CustomerRegistered;
use App\Mail\WelcomeCustomerMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendCustomerWelcomeEmail
{
    public function handle(CustomerRegistered $event): void
    {
        Mail::to($event->customer->email)
            ->queue(new WelcomeCustomerMail($event->customer));
    }
}

