<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;

class LogoutSpecificRoles extends Command
{
    protected $signature = 'logout:roles';
    protected $description = 'Logout all users with role 1 and 2';

    public function handle()
    {
        Company::whereIn('role', [1, 2])
            ->update(['is_logout' => true]);

        $this->info('All role 1 and 2 users logged out successfully.');

        return Command::SUCCESS;
    }
}