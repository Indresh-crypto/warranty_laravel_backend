<?php

namespace App\Mail;

use App\Models\WDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WarrantyActivationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public WDevice $device) {}

    public function build()
    {
        return $this->subject('Your Warranty is Activated')
            ->view('emails.warranty_activation', [
                'device' => $this->device
            ]);
    }
}