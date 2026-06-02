<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RetailerAgreementMail extends Mailable
{
    use SerializesModels;

    public $company;

    public function __construct($company)
    {
        $this->company = $company;
    }

    public function build()
    {
        return $this->subject('Retailer Agreement Confirmation - GoElectronix')
            ->view('emails.retailer-agreement')
            ->with([
                'Retailer_Name'  => $this->company->business_name,
                'Retailer_Code'  => $this->company->company_code,
                'Retailer_Phone' => $this->company->contact_phone,
                'Onboard_Date'   => now()->format('d M Y')
            ]);
    }
}