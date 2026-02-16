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
        try {
    
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
                'Pending verification',
                'Support Team',
                '90-000-000',
                'https://retailer.goelectronix.co.in'
            ];
    
            Log::info('Company WhatsApp sending', [
                'company_id' => $company->id,
                'destination' => $destination,
                'params' => $params
            ]);
    
            $response = Http::timeout(20)
                ->asForm()
                ->withHeaders([
                    'apikey'        => config('services.gupshup.key'),
                    'Cache-Control' => 'no-cache',
                ])
                ->post('https://api.gupshup.io/wa/api/v1/template/msg', [
                    'channel'     => 'whatsapp',
                    'source'      => config('services.gupshup.source'),
                    'destination' => $destination,
                    'src.name'    => 'GoelectronixWarranty',
                    'template'    => json_encode([
                        'id' => 'c94ca922-937d-4a7d-badc-ac967cf70f46',
                        'params' => $params
                    ])
                ]);
    
            if ($response->failed()) {
    
                Log::error('Company WhatsApp failed', [
                    'company_id' => $company->id,
                    'response'   => $response->body()
                ]);
    
                throw new \Exception('Gupshup API failed');
            }
    
            Log::info('Company WhatsApp success', [
                'company_id' => $company->id,
                'response'   => $response->json()
            ]);
    
        } catch (\Throwable $e) {
    
            Log::error('Company WhatsApp Job crashed', [
                'company_id' => $this->companyId,
                'error'      => $e->getMessage()
            ]);
    
            throw $e; // allow retry
        }
    }
}