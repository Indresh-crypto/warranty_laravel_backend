<?php

namespace App\Jobs;

use App\Models\WDevice;
use App\Models\WarrantyFlowLog;
use App\Models\Company;
use App\Http\Controllers\WarrantyPaymentFlowController;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use App\Services\WhatsappService;

class WarrantyCreditFlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;
    public $tries = 3;
    public $timeout = 120;

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {
        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | LOAD COMPANY
            |--------------------------------------------------------------------------
            */

            $company = Company::find($this->payload['company_id']);

            if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
                throw new \Exception('Company Zoho credentials missing');
            }

            /*
            |--------------------------------------------------------------------------
            | PREVENT DUPLICATE DEVICE
            |--------------------------------------------------------------------------
            */

           
           $exists = WDevice::where('product_id', $this->payload['product_id'])
                ->where(function ($query) {
            
                    $query->where('imei1', $this->payload['imei1']);
            
                    if (!empty($this->payload['imei2'])) {
                        $query->orWhere('imei2', $this->payload['imei2']);
                    }
            
                    if (!empty($this->payload['serial'])) {
                        $query->orWhere('serial', $this->payload['serial']);
                    }
            
                })
                ->exists();
                

            if ($exists) {
                throw new \Exception('Device already exists');
            }

            /*
            |--------------------------------------------------------------------------
            | CREATE DEVICE (FULL STRUCTURE)
            |--------------------------------------------------------------------------
            */

           $product_mrp = (($this->payload['product_mrp'] ?? 0) / 100)
                            * ($this->payload['device_price'] ?? 0);

            $device = WDevice::create([

                'imei1' => $this->payload['imei1'] ?? null,
                'imei2' => $this->payload['imei2'] ?? null,
                'serial' => $this->payload['serial'] ?? null,

                'brand_id' => $this->payload['brand_id'],
                'category_id' => $this->payload['category_id'],
                'product_id' => $this->payload['product_id'],
                'model_id' => $this->payload['model_id'],

                'product_name' => $this->payload['product_name'],
                'brand_name' => $this->payload['brand_name'],
                'category_name' => $this->payload['category_name'],
                'model' => $this->payload['model'],

                'available_claim' => $this->payload['available_claim'],
                'expiry_date' => $this->payload['expiry_date'],

                'w_customer_id' => $this->payload['w_customer_id'],
                'retailer_id' => $this->payload['retailer_id'],
                'company_id' => $this->payload['company_id'],
                'agent_id' => $this->payload['agent_id'] ?? null,
                'created_by' => $this->payload['created_by'] ?? null,

                'document_url' => $this->payload['document_url'] ?? null,
                'link1' => $this->payload['link1'] ?? null,
                'link2' => $this->payload['link2'] ?? null,

                'device_price' => $this->payload['device_price'],
                'product_price' => $this->payload['product_price'],
                'product_mrp' => $product_mrp,

                'retailer_payout' => $this->payload['retailer_payout'],
                'employee_payout' => $this->payload['employee_payout'],
                'other_payout' => $this->payload['other_payout'],
                'company_payout' => $this->payload['company_payout'],

                'is_approved' => 0,
                'status' => 0,
                'is_pay_later' => $this->payload['is_pay_later'] ?? 0
            ]);

            $device->update([
                'w_code' => 'WRT-' . $device->id . '-' . strtoupper(Str::random(6))
            ]);

            /*
            |--------------------------------------------------------------------------
            | CREATE ZOHO INVOICE
            |--------------------------------------------------------------------------
            */

            $controller = app(WarrantyPaymentFlowController::class);

            $invoiceResult = $controller->createWarrantyInvoice(
                $device,
                $this->payload['company_id'],
                $this->payload['retailer_id'],
                $this->payload['product_id'],
                'AUTO-CREDIT',
                $this->payload['product_price']
            );

            if (!$invoiceResult['success']) {
                throw new \Exception($invoiceResult['message']);
            }

            $invoice = $invoiceResult['invoice'];
            $invoiceId = $invoice['invoice_id'];

            $device->update([
                'invoice_id' => $invoiceId,
                'invoice_json' => json_encode($invoice),
                'invoice_status' => $invoice['status']
            ]);

            /*
            |--------------------------------------------------------------------------
            | FETCH UNUSED CREDIT NOTES
            |--------------------------------------------------------------------------
            */

            $client = new Client();

            $creditResponse = $client->get(
                "https://www.zohoapis.in/books/v3/creditnotes",
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
                    ],
                    'query' => [
                        'organization_id' => $company->zoho_org_id,
                        'filter_by' => 'Status.Unused'
                    ]
                ]
            );

            $creditBody = json_decode($creditResponse->getBody(), true);
            $availableCredits = $creditBody['creditnotes'] ?? [];

          
            $remainingBalance = $invoice['balance'] ?? $invoice['total'] ?? 0;

            /*
            |--------------------------------------------------------------------------
            | APPLY CREDIT SAFELY (MULTI-CREDIT SUPPORT)
            |--------------------------------------------------------------------------
            */

            foreach ($availableCredits as $credit) {

                if ($remainingBalance <= 0) break;

                if ($credit['balance'] <= 0) continue;

                $applyAmount = min($credit['balance'], $remainingBalance);

                $client->post(
                    "https://www.zohoapis.in/books/v3/creditnotes/{$credit['creditnote_id']}/apply",
                    [
                        'headers' => [
                            'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
                        ],
                        'query' => [
                            'organization_id' => $company->zoho_org_id
                        ],
                        'json' => [
                            "invoices" => [
                                [
                                    "invoice_id" => $invoiceId,
                                    "amount_applied" => $applyAmount
                                ]
                            ]
                        ]
                    ]
                );

                $remainingBalance -= $applyAmount;
            }

            /*
            |--------------------------------------------------------------------------
            | REFRESH FINAL INVOICE STATUS
            |--------------------------------------------------------------------------
            */

            $finalResponse = $client->get(
                "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}",
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
                    ],
                    'query' => [
                        'organization_id' => $company->zoho_org_id
                    ]
                ]
            );

            $finalInvoice = json_decode($finalResponse->getBody(), true)['invoice'];

            /*
            |--------------------------------------------------------------------------
            | UPDATE DEVICE + COMPANY
            |--------------------------------------------------------------------------
            */

            $device->update([
                'invoice_status' => $finalInvoice['status'],
            //    'invoice_json' => json_encode($finalInvoice),
                'status' => 1,
                'is_from_wallet' =>1
            ]);

            $company->update([
                'last_credit_applied_at' => now()
            ]);

            WarrantyFlowLog::create([
                'device_id' => $device->id,
                'step' => 'AUTO_CREDIT_COMPLETED',
                'status' => 1
            ]);

            DB::commit();
            
            try {

    $retailerCompany = Company::find($this->payload['retailer_id']);

                if ($retailerCompany && $device) {
            
                    app(WhatsappService::class)
                        ->sendRetailerPaymentSuccess(
                            $retailerCompany,
                            $device
                        );
                }
            
            } catch (\Throwable $e) {
            
                Log::error('Retailer Payment WhatsApp Failed', [
                    'retailer_id' => $this->payload['retailer_id'] ?? null,
                    'device_id'   => $device->id ?? null,
                    'error'       => $e->getMessage()
                ]);
            
            }


        } catch (\Exception $e) {

            DB::rollBack();

            WarrantyFlowLog::create([
                'step' => 'AUTO_CREDIT_FAILED',
                'status' => 0,
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}