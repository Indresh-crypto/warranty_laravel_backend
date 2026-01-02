<?php

namespace App\Mail;

use App\Models\WarrantyClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ClaimRaisedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public WarrantyClaim $claim,
        public bool $isCompany = false
    ) {}

    public function build()
    {
        $mail = $this->subject(
                $this->isCompany
                    ? 'New Warranty Claim Raised'
                    : 'Your Warranty Claim Has Been Registered'
            )
            ->view('emails.claim_raised', [
                'claim' => $this->claim,
                'isCompany' => $this->isCompany
            ]);

        // 📎 Attach photos
        foreach ($this->claim->photos as $photo) {
            $filePath = storage_path('app/public/' . $photo->photo_path);
            if (file_exists($filePath)) {
                $mail->attach($filePath);
            }
        }

        return $mail;
    }
}