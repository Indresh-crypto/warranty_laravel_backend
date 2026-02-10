<?php

namespace App\Mail;

use App\Models\WDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public WDevice $device
    ) {}

    public function build()
    {
        return $this->subject('✅ Payment Received – Warranty Activated')
            ->markdown('emails.payment_completed', [
                'device' => $this->device
            ]);
    }
}