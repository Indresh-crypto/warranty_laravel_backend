<?php

namespace App\Mail;

use App\Models\WarrantyClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ClaimStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public WarrantyClaim $claim,
        public string $status,
        public bool $isCompany = false
    ) {}

    public function build()
    {
        return $this->subject(
            $this->getSubject()
        )->view('emails.claim_status', [
            'claim' => $this->claim,
            'status' => $this->status,
            'isCompany' => $this->isCompany
        ]);
    }

    private function getSubject(): string
    {
        return match ($this->status) {
            'approved' => 'Your Warranty Claim Has Been Approved',
            'picked_up' => 'Device Picked Up for Warranty Claim',
            'estimate_sent' => 'Repair Estimate Submitted',
            'repair_in_progress' => 'Repair Approved & In Progress',
            'completed' => 'Warranty Claim Completed',
            default => 'Warranty Claim Update'
        };
    }
}