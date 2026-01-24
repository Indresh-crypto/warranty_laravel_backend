<?php

namespace App\Mail;

use App\Models\WDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WarrantyProvisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public WDevice $device) {}

    public function build()
    {
        return $this->subject('Warranty Provisioned Successfully')
            ->view('emails.warranty_provision');
    }
}