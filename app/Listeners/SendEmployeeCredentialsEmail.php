<?php

namespace App\Listeners;

use App\Events\EmployeeCreated;
use App\Mail\EmployeeCredentialsMail;
use Illuminate\Support\Facades\Mail;

class SendEmployeeCredentialsEmail
{
    public function handle(EmployeeCreated $event): void
    {
        $employee = $event->employee;

        if (empty($employee->official_email)) {
            return; // no email, silently skip
        }

        Mail::to($employee->official_email)
            ->send(new EmployeeCredentialsMail(
                $employee,
                $event->plainPassword
            ));
    }
}