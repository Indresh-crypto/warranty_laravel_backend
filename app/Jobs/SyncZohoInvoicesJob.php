<?php

namespace App\Jobs;

use App\Http\Controllers\WarrantyPaymentFlowController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncZohoInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $company_id;

    public function __construct($company_id)
    {
        $this->company_id = $company_id;
    }

    public function handle()
    {
        app(WarrantyPaymentFlowController::class)
            ->syncAllInvoices($this->company_id);
    }
}