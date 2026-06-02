<?php

namespace App\Jobs;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendRetailerWonWhatsapp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $companyId
    ) {}

  public function handle(): void
{
    try {

        Log::info('WhatsApp Job Started', [
            'company_id' => $this->companyId
        ]);

        $company = Company::find($this->companyId);

        if (!$company || !$company->contact_phone) {
            Log::warning('Company or phone missing', [
                'company_id' => $this->companyId
            ]);
            return;
        }

        // 🔥 TEMP TEST NUMBER (ok for testing)
        $destination = '917039475973';

        /*
        |--------------------------------------------------------------------------
        | STEP 1: OPT-IN
        |--------------------------------------------------------------------------
        */
        $optinResponse = Http::retry(3, 1000)
            ->timeout(30)
            ->withHeaders([
                'apikey' => 'xmzzeoeowfppicbquvp3zupvntzeqh2j',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ])
            ->asForm()
            ->post('https://api.gupshup.io/wa/api/v1/opt/in', [
                'user'    => $destination,
                'channel' => 'whatsapp',
                'source'  => '918828272570'
            ]);

        Log::info('Opt-in Response', [
            'status' => $optinResponse->status(),
            'body'   => $optinResponse->body()
        ]);

        sleep(2);

        /*
        |--------------------------------------------------------------------------
        | STEP 2: TEMPLATE
        |--------------------------------------------------------------------------
        */
        $params = [
            $company->business_name ?? 'Retailer',
            $company->company_code ?? '',
            $company->contact_phone ?? '',
            now()->format('Y-m-d'),
            'https://retailer.goelectronix.com/signin'
        ];

        $response = Http::retry(3, 1000)
            ->timeout(30)
            ->withHeaders([
                'apikey' => 'xmzzeoeowfppicbquvp3zupvntzeqh2j',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ])
            ->asForm()
            ->post('https://api.gupshup.io/wa/api/v1/template/msg', [

                'channel'     => 'whatsapp',
                'source'      => '919372011028',
                'destination' => $destination,
                'src.name'    => 'WarrantyMitra',

                'template' => json_encode([
                    "id"     => "8c63e4e7-cb0a-4105-9c57-b7ead5d04e73",
                    "params" => $params
                ]),

                'message' => json_encode([
                    "image" => [
                        "link" => "https://warrantymitra.com/img/logo.png"
                    ],
                    "type" => "image"
                ])
            ]);

        Log::info('Template Response', [
            'status' => $response->status(),
            'body'   => $response->body()
        ]);

    } catch (\Throwable $e) {

        Log::error('WhatsApp JOB ERROR', [
            'error' => $e->getMessage(),
            'line'  => $e->getLine(),
            'file'  => $e->getFile()
        ]);
    }
}
}