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

class SendAgentPendingWhatsapp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $companyId;

    public function __construct(int $companyId)
    {
        $this->companyId = $companyId;
    }

    public function handle(): void
    {
        $company = Company::find($this->companyId);

        if (!$company || empty($company->contact_phone)) {
            Log::warning('Agent WhatsApp skipped: invalid company or phone', [
                'company_id' => $this->companyId
            ]);
            return;
        }

        $destination = '91' . ltrim($company->contact_phone, '0');

        $agentName     = $company->business_name ?? 'Agent';
        $pendingStep   = 'Pending Verification';
        $supportName   = 'Goelectronix Support';
        $supportNumber = '919876543210';

        try {
            $response = Http::asForm()
                ->withHeaders([
                    'apikey' => config('services.gupshup.key'),
                ])
                ->post('https://api.gupshup.io/wa/api/v1/template/msg', [
                    'channel'     => 'whatsapp',
                    'source'      => config('services.gupshup.source'),
                    'destination' => $destination,
                    'src.name'    => config('services.gupshup.app_name'),
                    'template'    => json_encode([
                        'id'     => 'acf73b2f-135b-43b4-8c85-cdbd683fbf12',
                        'params' => [
                            $agentName,
                            $pendingStep,
                            $supportName,
                            $supportNumber,
                        ],
                    ]),
                ]);

            // ✅ STORE RESPONSE IN COMPANY TABLE
            $company->update([
                'wa_response' => json_encode([
                    'template'   => 'agent_pending',
                    'status'     => $response->status(),
                    'success'    => $response->successful(),
                    'body'       => $response->json(),
                    'sent_at'    => now(),
                ])
            ]);

            if ($response->failed()) {
                Log::error('Agent pending WhatsApp failed', [
                    'company_id' => $this->companyId,
                    'response'   => $response->body(),
                ]);
            } else {
                Log::info('Agent pending WhatsApp sent', [
                    'company_id' => $this->companyId,
                    'destination'=> $destination,
                ]);
            }

        } catch (\Throwable $e) {

            // ❌ STORE FAILURE RESPONSE
            $company->update([
                'wa_response' => json_encode([
                    'template' => 'agent_pending',
                    'failed'   => true,
                    'error'    => $e->getMessage(),
                    'sent_at'  => now(),
                ])
            ]);

            Log::critical('Agent pending WhatsApp crashed', [
                'company_id' => $this->companyId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}