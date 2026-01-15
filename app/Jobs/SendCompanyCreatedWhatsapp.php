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

        $response = Http::asForm()->withHeaders([
            'apikey'        => config('services.gupshup.key'),
            'Cache-Control' => 'no-cache',
        ])->post('https://api.gupshup.io/wa/api/v1/template/msg', [
            'channel'     => 'whatsapp',
            'source'      => config('services.gupshup.source'),
            'destination' => $destination,
            'src.name'    => 'GoelectronixWarranty',
            'template'    => json_encode([
                'id' => 'c7683016-ffb6-4ccf-9aee-0c729afd1348',
                'params' => [
                    $company->business_name ?? 'Retailer',
                    'Pending verification',
                    'Support Team',
                    '90-000-000'
                ]
            ])
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