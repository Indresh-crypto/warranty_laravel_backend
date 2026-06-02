<?php

namespace App\Mail;

use App\Models\WLead;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeadCreateSystemMail extends Mailable
{
    use Queueable, SerializesModels;

    public $lead;
    public $company;

    public function __construct($leadId, $companyId)
    {
        $this->lead = WLead::findOrFail($leadId);
        $this->company = Company::findOrFail($companyId);
    }

    public function build()
    {
        return $this->subject('Lead Received - GoElectronix')
            ->view('emails.lead_new')   // Use view instead of markdown since HTML
            ->with([
                'lead'    => $this->lead,
                'company' => $this->company
            ]);
    }
}