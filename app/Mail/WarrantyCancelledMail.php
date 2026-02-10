<?php

namespace App\Mail;

use App\Models\WDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WarrantyCancelledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public WDevice $device,
        public ?string $reason = null
    ) {}

    public function build()
    {
        return $this->subject('Warranty Cancelled')
            ->view('emails.warranty_cancelled', [
                'device' => $this->device,
                'reason' => $this->reason
            ]);
    }
}