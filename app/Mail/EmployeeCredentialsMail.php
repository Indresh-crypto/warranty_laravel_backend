<?php

namespace App\Mail;

use App\Models\CompanyEmployee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmployeeCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public CompanyEmployee $employee;
    public string $password;

    public function __construct(CompanyEmployee $employee, string $password)
    {
        $this->employee = $employee;
        $this->password = $password;
    }

    public function build()
    {
        return $this->subject('Your Employee Account Credentials')
            ->view('emails.employee_credentials');
    }
}