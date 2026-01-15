<?php

namespace App\Events;

use App\Models\CompanyEmployee;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmployeeCreated
{
    use Dispatchable, SerializesModels;

    public CompanyEmployee $employee;
    public string $plainPassword;

    public function __construct(CompanyEmployee $employee, string $plainPassword)
    {
        $this->employee = $employee;
        $this->plainPassword = $plainPassword;
    }
}