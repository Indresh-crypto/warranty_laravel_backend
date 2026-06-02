<?php

namespace App\Jobs;

use App\Models\WDevice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class UpdateDailySales implements ShouldQueue
{
    use Queueable;

    protected $device;

    /**
     * Pass device safely
     */
    public function __construct(WDevice $device)
    {
        $this->device = $device;
    }

    /**
     * Execute the job
     */
  public function handle(): void
  {
    $device = WDevice::find($this->device->id);

    if (!$device || !$device->product_id || !$device->retailer_id) {
        return;
    }

    $date   = now()->toDateString();
    $amount = $device->product_price ?? 0;

    DB::statement("
        INSERT INTO daily_sales (
            date, retailer_id, product_id, category_id, company_id,
            total_sales, total_amount, created_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            total_sales = total_sales + 1,
            total_amount = total_amount + VALUES(total_amount),
            updated_at = NOW()
    ", [
        $date,
        $device->retailer_id,
        $device->product_id,
        $device->category_id,
        $device->company_id,
        $amount
    ]);
}
}