<?php

namespace App\Jobs;

use App\Models\WDevice;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\WarrantyBulkInvoiceLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class GenerateWarrantyRetailerInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    public function handle()
    {
        /*
        |--------------------------------------------------------------------------
        | STEP 1: LOAD ELIGIBLE DEVICES
        |--------------------------------------------------------------------------
        */

        $rawDevices = WDevice::with('customer')
            ->where('is_pay_later', 1)
            ->whereNull('invoice_status')
            ->orderBy('company_id')
            ->orderBy('retailer_id')
            ->get();

        Log::info('BULK-INVOICE: RAW DEVICES', [
            'count' => $rawDevices->count(),
            'ids'   => $rawDevices->pluck('id')->toArray()
        ]);

        if ($rawDevices->isEmpty()) {
            return;
        }

        $devices = $rawDevices->groupBy(['company_id', 'retailer_id']);

        foreach ($devices as $companyId => $retailers) {

            $company = Company::find($companyId);

            if (
                !$company ||
                !$company->zoho_access_token ||
                !$company->zoho_org_id
            ) {
                Log::warning('BULK-INVOICE: COMPANY SKIPPED', [
                    'company_id' => $companyId
                ]);
                continue;
            }

            foreach ($retailers as $retailerId => $retailerDevices) {

                $log = WarrantyBulkInvoiceLog::create([
                    'company_id'   => $companyId,
                    'retailer_id'  => $retailerId,
                    'status'       => 'started',
                    'device_count' => $retailerDevices->count(),
                    'total_amount' => $retailerDevices->sum('product_price')
                ]);

                DB::beginTransaction();

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | STEP 2: BUILD LINE ITEMS (WITH CUSTOMER DETAILS)
                    |--------------------------------------------------------------------------
                    */

                    $lineItems = [];

                    foreach ($retailerDevices as $device) {

                        $companyProduct = CompanyProduct::where('company_id', $companyId)
                            ->where('product_id', $device->product_id)
                            ->first();

                        if (!$companyProduct || !$companyProduct->zoho_item_id) {
                            throw new \Exception(
                                "Zoho item missing for product ID {$device->product_id}"
                            );
                        }

                        $customer = $device->customer;

                        $description  = ($device->w_code ?? '-') . "\n";     // Warranty ID
                        $description .= ($customer->name ?? '-') . "\n";
                        $description .= ($device->model ?? '-') . "\n";
                        
                        $lineItems[] = [
                            'item_id'     => $companyProduct->zoho_item_id,
                            'name'        => $device->product_name,
                            'rate'        => $device->product_price,
                            'quantity'    => 1,
                            'description' => $description
                        ];
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | STEP 3: CREATE ZOHO INVOICE
                    |--------------------------------------------------------------------------
                    */

                    $retailer = Company::find($retailerId);

                    if (!$retailer || !$retailer->zoho_id) {
                        throw new \Exception("Retailer Zoho ID missing: {$retailerId}");
                    }

                    $payload = [
                        'customer_id' => $retailer->zoho_id,
                        'reference_number' =>
                            'WTY-BLK-' . now()->format('Ym') . '-' . $retailerId . '-' . time(),
                        'date' => now()->toDateString(),
                        'line_items' => $lineItems,
                        'is_inclusive_tax'=>true
                    ];

                    $client = new Client();

                    $response = $client->post(
                        'https://www.zohoapis.in/books/v3/invoices',
                        [
                            'headers' => [
                                'Authorization' =>
                                    'Zoho-oauthtoken ' . $company->zoho_access_token
                            ],
                            'query' => [
                                'organization_id' => $company->zoho_org_id
                            ],
                            'json' => $payload
                        ]
                    );

                    $body = json_decode($response->getBody(), true);

                    if (empty($body['invoice'])) {
                        throw new \Exception('Zoho invoice creation failed');
                    }

                    $invoice = $body['invoice'];

                    /*
                    |--------------------------------------------------------------------------
                    | STEP 3.5: MARK INVOICE AS SENT
                    |--------------------------------------------------------------------------
                    */

                    $client->post(
                        "https://www.zohoapis.in/books/v3/invoices/{$invoice['invoice_id']}/status/sent",
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

                    $getResponse = $client->get(
                        "https://www.zohoapis.in/books/v3/invoices/{$invoice['invoice_id']}",
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

                    $getBody = json_decode($getResponse->getBody(), true);

                    if (empty($getBody['invoice'])) {
                        throw new \Exception('Unable to fetch updated invoice');
                    }

                    $updatedInvoice = $getBody['invoice'];

                    /*
                    |--------------------------------------------------------------------------
                    | STEP 4: UPDATE DEVICES
                    |--------------------------------------------------------------------------
                    */

                    WDevice::whereIn('id', $retailerDevices->pluck('id'))
                        ->update([
                            'invoice_id'           => $updatedInvoice['invoice_id'],
                            'invoice_status'       => $updatedInvoice['status'] ?? 'sent',
                            'invoice_created_date' => $updatedInvoice['date'] ?? now()->toDateString(),
                            'invoice_json'         => json_encode($updatedInvoice),
                            'status'               => 1,
                            'is_approved'          => 0
                        ]);

                    /*
                    |--------------------------------------------------------------------------
                    | STEP 5: UPDATE LOG
                    |--------------------------------------------------------------------------
                    */

                    $log->update([
                        'invoice_id'    => $invoice['invoice_id'],
                        'status'        => 'success',
                        'response_json' => json_encode($invoice)
                    ]);

                    DB::commit();

                } catch (\Exception $e) {

                    DB::rollBack();

                    Log::error('BULK-INVOICE FAILED', [
                        'company_id'  => $companyId,
                        'retailer_id' => $retailerId,
                        'error'       => $e->getMessage()
                    ]);

                    $log->update([
                        'status'        => 'failed',
                        'error_message' => $e->getMessage()
                    ]);
                }
            }
        }
    }
}