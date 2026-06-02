<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WDevice;
use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\EmailLog;
use App\Mail\DailyRetailerReportMail;

class SendDailyRetailerReport extends Command
{
    protected $signature = 'report:daily-retailer';
    protected $description = 'Send daily retailer WhatsApp + Email report';

    public function handle()
    {
        try {
            $date = Carbon::yesterday()->toDateString();

            WDevice::selectRaw('retailer_id, COUNT(*) as total_activation, SUM(product_price) as total_business')
                ->whereDate('created_at', $date)
                ->whereNotNull('retailer_id')
                ->groupBy('retailer_id')
                ->chunk(100, function ($devices) use ($date) {

                    foreach ($devices as $deviceData) {

                        $company = Company::find($deviceData->retailer_id);

                        if (!$company) {
                            continue;
                        }

                        $retailerName = $company->business_name ?? 'Retailer';
                        $retailerCode = $company->company_code ?? '';
                        $dateFormatted = Carbon::parse($date)->format('d-m-Y');

                        $totalActivation = $deviceData->total_activation ?? 0;
                        $totalBusiness = $deviceData->total_business ?? 0;

                        $retailerEarning = $totalBusiness * 0.1;
                        $walletUsage = 0;
                        $walletTopup = 0;
                        $closingBalance = $company->wallet_balance ?? 0;

                        // =========================
                        // 🔥 EMAIL SEND
                        // =========================
                        if (!empty($company->contact_email) && filter_var($company->contact_email, FILTER_VALIDATE_EMAIL)) {

                            // ❗ Prevent duplicate send
                            $alreadySent = EmailLog::where('company_id', $company->id)
                                ->whereDate('created_at', $date)
                                ->exists();

                            if (!$alreadySent) {

                                $trackId = Str::uuid();

                                EmailLog::create([
                                    'company_id' => $company->id,
                                    'track_id' => $trackId
                                ]);

                                $data = [
                                    'name' => $retailerName . ' & ' . $retailerCode,
                                    'date' => $dateFormatted,
                                    'activation' => $totalActivation,
                                    'business' => $totalBusiness,
                                    'earning' => $retailerEarning,
                                    'wallet_usage' => $walletUsage,
                                    'wallet_topup' => $walletTopup,
                                    'balance' => $closingBalance,
                                    'track_id' => $trackId
                                ];

                                Mail::to($company->contact_email)
                                    ->queue(new DailyRetailerReportMail($data));
                            }
                        }

                        // =========================
                        // 🔥 WHATSAPP SEND
                        // =========================
                        if (!empty($company->contact_phone)) {

                            Http::retry(3, 1000)
                                ->asForm()
                                ->withHeaders([
                                    'apikey' => config('services.gupshup.api_key')
                                ])
                                ->post('https://api.gupshup.io/wa/api/v1/template/msg', [
                                    'channel' => 'whatsapp',
                                    'source' => '918828272570',
                                    'destination' => $company->contact_phone,
                                    'src.name' => 'GoelectronixWarranty',
                                    'template' => json_encode([
                                        "id" => "58e07d47-e7d6-4bb9-b878-d49e0b70a21b",
                                        "params" => [
                                            $retailerName . ' & ' . $retailerCode,
                                            $dateFormatted,
                                            (string)$totalActivation,
                                            (string)$totalBusiness,
                                            (string)$retailerEarning,
                                            "0",
                                            "0",
                                            (string)$closingBalance,
                                            "Thank you for your business"
                                        ]
                                    ])
                                ]);
                        }
                    }
                });

        } catch (\Exception $e) {
            Log::error('Daily Retailer Report Failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
}