<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(
            new \App\Jobs\GenerateWarrantyRetailerInvoicesJob
        )
        ->twiceMonthly(1, 16)
        ->withoutOverlapping()
        ->runInBackground();
        
        
        $schedule->command('email:inactive-retailers')
             ->dailyAt('23:00');
             
        $schedule->command('notify:due-tasks')->everyHour();
        $schedule->command('sales:daily-reminder')->dailyAt('20:00');

        $schedule->command('report:daily-sales')->dailyAt('23:10');
        $schedule->command('report:weekly-sales')->weeklyOn(1, '23:20');
        $schedule->command('report:monthly-sales')->monthlyOn(1, '23:30');
        $schedule->command('report:inactive-retailers')->dailyAt('10:00');

        $schedule->command('report:daily-retailer')->dailyAt('01:00');
        
        $schedule->command('payouts:generate')->monthlyOn(1, '01:00');
        
               
        $schedule->command('wa:pending-activation')
            ->dailyAt('10:00')
            ->withoutOverlapping()
            ->runInBackground();
        
        $schedule->command('wa:pending-activation')
            ->dailyAt('16:00')
            ->withoutOverlapping()
            ->runInBackground();
            
        $schedule->command('retailers:update-inactive')
         ->dailyAt('01:00');
    
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
    
    
}