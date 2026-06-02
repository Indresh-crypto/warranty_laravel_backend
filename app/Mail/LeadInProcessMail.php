<?php

namespace App\Mail;

use App\Models\WLead;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeadInProcessMail extends Mailable
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
        

            
        return $this->subject('Lead Approved - GoElectronix')
            ->view('emails.lead_approved')   //  Use view instead of markdown since HTML
            ->with([
                'lead'    => $this->lead,
                'company' => $this->company
            ]);
    }
}