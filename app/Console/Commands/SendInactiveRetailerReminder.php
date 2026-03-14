<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;
use App\Models\WDevice;
use Mail;
use Carbon\Carbon;
use DB;

class SendInactiveRetailerReminder extends Command
{
    protected $signature = 'email:inactive-retailers';
    protected $description = 'Send reminder email to retailers who are not selling warranties';

    public function handle()
    {
        $retailers = Company::select(
                'companies.id',
                'companies.business_name',
                'companies.contact_email',
                DB::raw('MAX(w_devices.created_at) as last_sale')
            )
            ->leftJoin('w_devices', 'w_devices.retailer_id', '=', 'companies.id')
            ->whereNotNull('companies.contact_email')
            ->where('companies.role', 5)
            ->groupBy(
                'companies.id',
                'companies.business_name',
                'companies.contact_email'
            )
            ->get();

        foreach ($retailers as $retailer) {

            if (!$retailer->last_sale) {

                $inactiveDays = 'never';

            } else {

                // Ignore time and calculate only date difference
                $lastSaleDate = Carbon::parse($retailer->last_sale)->startOfDay();
                $today = now()->startOfDay();

                $inactiveDays = $lastSaleDate->diffInDays($today);
            }

            // Send email if inactive >= 1 day OR never sold
            if ($inactiveDays === 'never' || $inactiveDays >= 1) {

                Mail::send('emails.retailer_inactive', [
                    'retailer_name' => $retailer->business_name,
                    'inactive_days' => $inactiveDays
                ], function ($message) use ($retailer) {

                    $message->to($retailer->contact_email)
                        ->subject('Warranty Portal Activity Reminder');

                });

            }

            // Debug logs
            $this->info("Retailer: " . $retailer->business_name);
            $this->info("Last Sale: " . ($retailer->last_sale ?? 'NULL'));
            $this->info("Inactive Days: " . $inactiveDays);
        }

        $this->info("Reminder process completed.");
    }
}