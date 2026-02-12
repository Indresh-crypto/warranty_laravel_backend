<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeCompanyMail extends Mailable
{
    use Queueable, SerializesModels;

    public $company;
    public $signinUrl;

    public function __construct(Company $company, $signinUrl)
    {
        $this->company = $company;
        $this->signinUrl = $signinUrl;
    }

    public function build()
    {
        return $this->subject('Welcome to GoElectronix - Login OTP Included')
                    ->markdown('emails.welcome_company')
                    ->with([
                        'company'   => $this->company,
                        'signinUrl' => $this->signinUrl,
                        'otp'       => $this->company->otp
                    ]);
    }
}