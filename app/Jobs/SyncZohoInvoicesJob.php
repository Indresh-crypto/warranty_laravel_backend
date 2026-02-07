<?php

namespace App\Jobs;

use App\Models\WDevice;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class SyncZohoInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 3;

    public function handle()
    {
        Log::info('ZOHO SYNC STARTED');

        $devices = WDevice::whereNotNull('invoice_id')
            ->whereNotNull('company_id')
            ->get()
            ->groupBy('company_id');

        foreach ($devices as $companyId => $companyDevices) {

            $company = Company::find($companyId);

            if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
                Log::warning('ZOHO SYNC SKIPPED COMPANY', [
                    'company_id' => $companyId
                ]);
                continue;
            }

            $client = new Client();

            foreach ($companyDevices as $device) {

                try {

                    $response = $client->get(
                        "https://www.zohoapis.in/books/v3/invoices/{$device->invoice_id}",
                        [
                            'headers' => [
                                'Authorization' =>
                                    'Zoho-oauthtoken ' . $company->zoho_access_token
                            ],
                            'query' => [
                                'organization_id' => $company->zoho_org_id
                            ]
                        ]
                    );

                    $body = json_decode($response->getBody(), true);

                    if (empty($body['invoice'])) {
                        continue;
                    }

                    $invoice = $body['invoice'];

                    $updateData = [
                        'invoice_status' => $invoice['status'],
                        'invoice_json'   => json_encode($invoice),
                    ];

                    // Auto mark payment if paid
                    if ($invoice['status'] === 'paid') {
                        $updateData['payment_status'] = 1;
                        $updateData['status'] = 1;
                    }

                    $device->update($updateData);

                } catch (\Exception $e) {

                    Log::error('ZOHO SYNC ERROR', [
                        'device_id' => $device->id,
                        'invoice_id' => $device->invoice_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        Log::info('ZOHO SYNC COMPLETED');
    }
}