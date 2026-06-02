<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Company;

class SendPendingActivationWhatsapp extends Command
{
    protected $signature = 'wa:pending-activation';

    protected $description =
        'Send daily WhatsApp reminder for pending activation retailers';

    public function handle(): int
    {
        $companies = Company::query()

            ->where('role', 5)

            ->whereDoesntHave('wDevices')

            ->whereNotNull('contact_phone')

            ->get();
            
        if ($companies->isEmpty()) {

            $this->info('No pending retailers found.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($companies as $company) {

            try {

                $mobile = $this->formatMobile(
                    $company->contact_phone
                );

                if (!$mobile) {

                    $failed++;

                    continue;
                }

                $template = json_encode([

                    'id' =>
                        'fa1ab9f9-867b-4f3e-9b82-0c3476772e51',

                    'params' => [

                        trim(
                            ($company->business_name ?? 'Retailer')
                            . ' | ' .
                            ($company->company_code ?? '')
                        )
                    ]

                ], JSON_UNESCAPED_SLASHES);

                $message = json_encode([

                    'image' => [

                        'link' =>
                            'https://warrantymitra.com/apending-wa.jpeg'
                    ],

                    'type' => 'image'

                ], JSON_UNESCAPED_SLASHES);

                $response = Http::asForm()

                    ->withHeaders([

                      'apikey' => 'xmzzeoeowfppicbquvp3zupvntzeqh2j',
                        'Cache-Control' =>
                            'no-cache'
                    ])

                    ->timeout(30)

                    ->post(
                        'https://api.gupshup.io/wa/api/v1/template/msg',
                        [

                            'channel' =>
                                'whatsapp',

                            'source' =>
                                '918828272570',

                            'destination' =>
                                $mobile,

                            'src.name' =>
                                'WarrantyMitra',

                            'template' =>
                                $template,

                            'message' =>
                                $message
                        ]
                    );

                if ($response->successful()) {

                    $sent++;

                    Log::info(
                        'PENDING ACTIVATION WA SENT',
                        [

                            'company_id' =>
                                $company->id,

                            'mobile' =>
                                $mobile,

                            'response' =>
                                $response->json()
                        ]
                    );

                } else {

                    $failed++;

                    Log::error(
                        'PENDING ACTIVATION WA FAILED',
                        [

                            'company_id' =>
                                $company->id,

                            'mobile' =>
                                $mobile,

                            'status' =>
                                $response->status(),

                            'response' =>
                                $response->body()
                        ]
                    );
                }

                sleep(1);

            } catch (\Throwable $e) {

                $failed++;

                Log::error(
                    'PENDING ACTIVATION WA ERROR',
                    [

                        'company_id' =>
                            $company->id ?? null,

                        'error' =>
                            $e->getMessage(),

                        'line' =>
                            $e->getLine()
                    ]
                );
            }
        }

        $this->info(
            "Completed. Sent: {$sent}, Failed: {$failed}"
        );

        return self::SUCCESS;
    }

    private function formatMobile(?string $mobile): ?string
    {
        if (!$mobile) {
            return null;
        }

        $mobile = preg_replace('/\D/', '', $mobile);

        if (strlen($mobile) === 10) {
            return '91' . $mobile;
        }

        if (strlen($mobile) === 12) {
            return $mobile;
        }

        return null;
    }
}