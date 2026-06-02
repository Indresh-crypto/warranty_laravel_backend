<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CompanyUserWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    
    public $company;
    public $signinUrl;
    public $password;

  
    public function __construct(Company $company, $signinUrl, $password)
    {
        $this->company =   $company;
        $this->signinUrl = $signinUrl;
        $this->password =  $password; // plain password
    }
    

  public function build()
{
    return $this->subject('🎉 Congratulations! Welcome to GoElectronix')
        ->markdown('emails.welcome_user')
        ->with([
            'company'   => $this->company,
            'signinUrl' => $this->signinUrl,
            'password'  => $this->password,
        ]);
}
}