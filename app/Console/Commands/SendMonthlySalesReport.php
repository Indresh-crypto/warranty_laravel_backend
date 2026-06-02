<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use Mail;
use App\Mail\MonthlySalesReportMail;
use App\Models\Company;
use App\Models\WDevice;
use DB;

class SendMonthlySalesReport extends Command
{
    protected $signature = 'report:monthly-sales';
    protected $description = 'Send Monthly Sales Report';

    public function handle()
    {
        $startDate = Carbon::now()->startOfMonth();
        $endDate   = Carbon::now()->endOfMonth();

        $reportData = WDevice::join('companies', 'companies.id', '=', 'w_devices.retailer_id')
            ->select(
                'companies.business_name',
                'companies.company_code',
                DB::raw('COUNT(w_devices.id) as total_qty'),
                DB::raw('SUM(w_devices.product_price) as total_amount')
            )
            ->whereBetween('w_devices.created_at', [$startDate, $endDate])
            ->groupBy('companies.id', 'companies.business_name', 'companies.company_code')
            ->orderByDesc('total_qty')
            ->get();

        $totalQty = $reportData->sum('total_qty');
        $totalAmount = $reportData->sum('total_amount');

        $admins = Company::whereIn('role', [1,2])
            ->whereNotNull('contact_email')
            ->pluck('contact_email')
            ->toArray();

        $admins[] = 'indresh@goelectronix.com';
        $admins = array_unique($admins);

        Mail::to($admins)->send(
            new MonthlySalesReportMail(
                $reportData,
                $totalQty,
                $totalAmount,
                $startDate->format('d M') . ' - ' . $endDate->format('d M Y')
            )
        );

        $this->info('Monthly sales report sent.');
    }
}