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

    public WDevice $device;

    public function __construct(WDevice $device)
    {
        $this->device = $device;
    }

    public function build()
    {
        return $this->subject('Payment Successful – Warranty Activated')
            ->view('emails.payment_completed')
            ->with([
                'device' => $this->device,
                'customer' => $this->device->customer,
                'paymentId' => $this->device->payment_id,
                'amount' => $this->device->product_price,
            ]);
    }
}