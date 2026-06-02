<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;
use App\Models\WDevice;
use Carbon\Carbon;
use Mail;
use App\Mail\DailyNoSalesReminderMail;

class SendDailyNoSalesReminder extends Command
{
    protected $signature = 'sales:daily-reminder';
    protected $description = 'Send reminder email to retailers who did not sell today';

    public function handle()
    {
        $today = Carbon::today();

        // Get all retailers
        $retailers = Company::where('role', 5)
            ->where('status', 1)
            ->whereNotNull('contact_email')
            ->get();

        foreach ($retailers as $retailer) {

            // Check if device entry exists today
            $soldToday = WDevice::where('retailer_id', $retailer->id)
                ->whereDate('created_at', $today)
                ->exists();

            // If NOT sold today → send mail
            if (!$soldToday) {

                $loginUrl = "https://retailer.goelectronix.com/signin?email=" . urlencode($retailer->contact_email);

                Mail::to($retailer->contact_email)
                    ->queue(new DailyNoSalesReminderMail($retailer, $loginUrl));
            }
        }

        $this->info('Daily no-sales reminder emails sent.');
    }
}