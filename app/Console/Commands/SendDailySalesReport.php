<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

use App\Mail\DailySalesReportMail;
use App\Models\Company;
use App\Models\WDevice;

class SendDailySalesReport extends Command
{
    protected $signature = 'report:daily-sales';

    protected $description =
        'Send Daily Sales Report to Admin, MCP and CP';

    public function handle()
    {
        try {

            $today = Carbon::today();

            /*
            |--------------------------------------------------------------------------
            | RETAILER WISE SALES
            |--------------------------------------------------------------------------
            */

            $reportData = WDevice::join(
                    'companies',
                    'companies.id',
                    '=',
                    'w_devices.retailer_id'
                )
                ->select(

                    'companies.id as retailer_id',

                    'companies.business_name',

                    'companies.company_code',

                    'companies.company_id',

                    'companies.agent_id',

                    DB::raw(
                        'COUNT(w_devices.id) as total_qty'
                    ),

                    DB::raw(
                        'SUM(w_devices.product_price) as total_amount'
                    )
                )
                ->whereDate(
                    'w_devices.created_at',
                    $today
                )
                ->groupBy(
                    'companies.id',
                    'companies.business_name',
                    'companies.company_code',
                    'companies.company_id',
                    'companies.agent_id'
                )
                ->orderByDesc('total_qty')
                ->get();

            /*
            |--------------------------------------------------------------------------
            | TOTALS
            |--------------------------------------------------------------------------
            */

            $totalQty =
                $reportData->sum('total_qty');

            $totalAmount =
                $reportData->sum('total_amount');

            /*
            |--------------------------------------------------------------------------
            | COLLECT EMAILS
            |--------------------------------------------------------------------------
            */

            $emails = [];

            /*
            |--------------------------------------------------------------------------
            | ADMIN EMAILS (ROLE = 1)
            |--------------------------------------------------------------------------
            */

            $adminEmails = Company::where(
                    'role',
                    1
                )
                ->whereNotNull(
                    'contact_email'
                )
                ->pluck('contact_email')
                ->toArray();

            $emails = array_merge(
                $emails,
                $adminEmails
            );

            /*
            |--------------------------------------------------------------------------
            | MCP + CP EMAILS
            |--------------------------------------------------------------------------
            */

            foreach ($reportData as $row) {

                /*
                |--------------------------------------------------------------------------
                | MCP EMAIL (ROLE = 2)
                |--------------------------------------------------------------------------
                */

                if (!empty($row->company_id)) {

                    $mcpEmail = Company::where(
                            'id',
                            $row->company_id
                        )
                        ->where('role', 2)
                        ->value('contact_email');

                    if (!empty($mcpEmail)) {

                        $emails[] =
                            $mcpEmail;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | CP EMAIL (ROLE = 4)
                |--------------------------------------------------------------------------
                */

                if (!empty($row->agent_id)) {

                    $cpEmail = Company::where(
                            'id',
                            $row->agent_id
                        )
                        ->where('role', 4)
                        ->value('contact_email');

                    if (!empty($cpEmail)) {

                        $emails[] =
                            $cpEmail;
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | FIXED EMAIL
            |--------------------------------------------------------------------------
            */

            $emails[] =
                'indresh@goelectronix.com';

            /*
            |--------------------------------------------------------------------------
            | REMOVE DUPLICATES
            |--------------------------------------------------------------------------
            */

            $emails = array_values(

                array_unique(

                    array_filter($emails)
                )
            );

            /*
            |--------------------------------------------------------------------------
            | NO EMAIL FOUND
            |--------------------------------------------------------------------------
            */

            if (empty($emails)) {

                $this->error(
                    'No emails found.'
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | SEND MAIL
            |--------------------------------------------------------------------------
            */

            Mail::to($emails)->queue(

                new DailySalesReportMail(

                    $reportData,

                    $totalQty,

                    $totalAmount,

                    $today->format('d M Y')
                )
            );

            /*
            |--------------------------------------------------------------------------
            | LOG
            |--------------------------------------------------------------------------
            */

            \Log::info(

                'DAILY SALES REPORT SENT',

                [

                    'total_emails' =>
                        count($emails),

                    'emails' =>
                        $emails,

                    'total_qty' =>
                        $totalQty,

                    'total_amount' =>
                        $totalAmount
                ]
            );

            $this->info(
                'Daily sales report sent successfully.'
            );

        } catch (\Throwable $e) {

            \Log::error(

                'DAILY SALES REPORT FAILED',

                [

                    'message' =>
                        $e->getMessage(),

                    'line' =>
                        $e->getLine(),

                    'file' =>
                        $e->getFile()
                ]
            );

            $this->error(
                $e->getMessage()
            );
        }
    }
}