<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Company;
use App\Models\WDevice;

class UpdateInactiveRetailers extends Command
{
    protected $signature = 'retailers:update-inactive';

    protected $description = 'Update retailer flags and last sold date';

    public function handle()
    {
        $days = 4;

        $inactiveDate = now()->subDays($days);

        $retailers = Company::where('role', 5)
            ->where('flag', '!=', 'in_progress')
            ->get();

        foreach ($retailers as $retailer) {

            $lastSale = WDevice::where(
                    'retailer_id',
                    $retailer->id
                )
                ->latest('created_at')
                ->value('created_at');

            /*
            |--------------------------------------------------------------------------
            | FLAG LOGIC
            |--------------------------------------------------------------------------
            */

            if (!$lastSale) {

                $flag = 'activation_pending';

            } elseif ($lastSale >= $inactiveDate) {

                $flag = 'working';

            } else {

                $flag = 'inactive';
            }

            $retailer->update([

                'last_sold_at' => $lastSale,

                'flag' => $flag
            ]);
        }

        $this->info(
            'Retailer flags and sold dates updated successfully.'
        );

        return self::SUCCESS;
    }
}