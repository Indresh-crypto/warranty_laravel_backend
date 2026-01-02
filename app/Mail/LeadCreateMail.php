<?php

namespace App\Mail;

use App\Models\WLead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeadCreateMail extends Mailable
{
    use Queueable, SerializesModels;

    public $lead;
    public $signinUrl;
    public $password;

    public function __construct(WLead $lead, $signinUrl, $password)
    {
        $this->lead = $lead;
        $this->signinUrl = $signinUrl;
        $this->password = $password; // plain password
    }

    public function build()
    {
        return $this->subject('Welcome to GoElectronix – Login Details')
            ->markdown('emails.welcome_lead')
            ->with([
                'lead'      => $this->lead,
                'signinUrl' => $this->signinUrl,
                'password'  => $this->password
            ]);
    }
}