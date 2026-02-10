<?php

namespace App\Mail;

use App\Models\WLead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeadWonMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public WLead $lead
    ) {}

    public function build()
    {
        return $this->subject('🎉 Congratulations! Welcome to GoElectronix')
            ->markdown('emails.lead_won', [
                'lead' => $this->lead,
            ]);
    }
}