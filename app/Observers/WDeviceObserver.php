<?php

namespace App\Observers;

use App\Models\WDevice;
use App\Jobs\UpdateDailySales;

class WDeviceObserver
{
    public function created(WDevice $device)
    {
        // ✅ Skip invalid data
        if (!$device->product_id || !$device->retailer_id) {
            return;
        }

        // ✅ Dispatch job ONLY (no DB here)
        if (app()->environment('local')) {
            // local → run instantly (no queue needed)
            UpdateDailySales::dispatchSync($device);
        } else {
            // production → async queue
            UpdateDailySales::dispatch($device)->onQueue('analytics');
        }
    }
}