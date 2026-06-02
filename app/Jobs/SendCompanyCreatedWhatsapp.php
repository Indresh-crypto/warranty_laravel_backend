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
use Carbon\Carbon;

class SendCompanyCreatedWhatsapp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $companyId
    ) {}

    public function handle(): void
    {
        $company = Company::find($this->companyId);

        if (!$company || !$company->contact_phone) {
            Log::warning('WhatsApp skipped: company or phone missing', [
                'company_id' => $this->companyId
            ]);
            return;
        }

        $destination = '91' . ltrim($company->contact_phone, '0');

      $params = [
            trim($company->business_name ?? 'Retailer'),
            $company->org_code,
            $company->contact_phone,
            optional($company->created_at)->format('d-m-Y'),
            'https://warrantynew.goelectronix.co.in'
        ];
        
       $response = Http::withHeaders([
                'Cache-Control' => 'no-cache',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'apikey' => 'xmzzeoeowfppicbquvp3zupvntzeqh2j',
            ])->asForm()->post('https://api.gupshup.io/wa/api/v1/template/msg', [
            
                'channel' => 'whatsapp',
                'source' => '918828272570',
                'destination' => $destination,
                'src.name' => 'WarrantyMitra',
            
                'template' => json_encode([
                    "id" => "8c63e4e7-cb0a-4105-9c57-b7ead5d04e73",
                    "params" => $params
                ], JSON_UNESCAPED_SLASHES),
            
                'message' => json_encode([
                    "image" => [
                        "link" => "https://media.licdn.com/dms/image/v2/D4D0BAQExgePoZh64lg/company-logo_200_200/company-logo_200_200/0/1706707195923/goelectronix_technologies_private_limited_logo?e=2147483647&v=beta&t=x5psH1cSOKyZVaPyjtvnNu6MHvQmPQWowNF2PVBUUps"
                    ],
                    "type" => "image"
                ], JSON_UNESCAPED_SLASHES)
            
            ]);

        if ($response->failed()) {
            Log::error('Gupshup WhatsApp failed', [
                'company_id' => $company->id,
                'response'   => $response->body()
            ]);
        } else {
            Log::info('WhatsApp sent successfully', [
                'company_id' => $company->id
            ]);
        }
    }
}