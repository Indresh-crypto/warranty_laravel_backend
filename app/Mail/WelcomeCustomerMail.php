<?php

namespace App\Mail;

use App\Models\WCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeCustomerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public WCustomer $customer)
    {
        //
    }

    public function build()
    {
        return $this->subject('Welcome to ' . config('app.name'))
            ->view('emails.welcome_customer', [
                'customer' => $this->customer
            ]);
    }
}