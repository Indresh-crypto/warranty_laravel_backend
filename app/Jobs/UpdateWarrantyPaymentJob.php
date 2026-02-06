<?php

namespace App\Jobs;

use App\Models\WDevice;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\WarrantyFlowLog;
use App\Http\Controllers\WarrantyPaymentFlowController;
use App\Events\PaymentSuccessful;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;

class UpdateWarrantyPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;
    public $tries = 5;
    public $timeout = 180;

    public function backoff()
    {
        return [30, 60, 120, 300, 600];
    }

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {
        $required = [
            'payment_id',
            'device_id',
            'company_id',
            'retailer_id',
            'amount'
        ];

        foreach ($required as $field) {
            if (empty($this->payload[$field])) {
                throw new \Exception($field . ' missing');
            }
        }

        DB::beginTransaction();

        try {

            $paymentId = $this->payload['payment_id'];

            WarrantyFlowLog::firstOrCreate(
                [
                    'payment_id' => $paymentId,
                    'step'       => 'UPDATE_JOB_STARTED'
                ],
                [
                    'status'     => 1
                ]
            );

             \Log::info('Device', [
                                'payload' => $this->payloa
                            ]);
                
            $device = WDevice::where('id', $this->payload['device_id'])
                ->lockForUpdate()
                ->first();


                \Log::info('Device', [
                    'device' => $device
                ]);
                
                

            if (!$device) {
                throw new \Exception('Device not found');
            }

            // Prevent double payment
            if ($device->payment_status == 1) {
                DB::commit();
                return;
            }

            $controller = app(WarrantyPaymentFlowController::class);
            $company    = Company::find($this->payload['company_id']);

            if (!$company) {
                throw new \Exception('Company not found');
            }

            $existingInvoiceId = $device->invoice_id ?? $device->zoho_invoice_id ?? null;
            $zohoInvoice = null;

            /*
            |--------------------------------------------------------------------------
            | STEP 1 : HANDLE INVOICE SAFELY
            |--------------------------------------------------------------------------
            */

            if ($existingInvoiceId) {

                // Fetch existing invoice from Zoho
                $client = new Client();

                $getResponse = $client->get(
                    "https://www.zohoapis.in/books/v3/invoices/{$existingInvoiceId}",
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
                    throw new \Exception('Failed fetching existing invoice');
                }

                $currentInvoice = $getBody['invoice'];

                // If draft → update
                if ($currentInvoice['status'] === 'draft') {

                    $companyProduct = CompanyProduct::where(
                        'company_id',
                        $this->payload['company_id']
                    )
                    ->where('product_id', $device->product_id)
                    ->first();

                    if (!$companyProduct || !$companyProduct->zoho_item_id) {
                        throw new \Exception('Zoho item mapping missing');
                    }

                    $lineItems = [
                        [
                            'item_id'  => $companyProduct->zoho_item_id,
                            'name'     => $device->product_name,
                            'rate'     => $this->payload['amount'],
                            'quantity' => 1
                        ]
                    ];

                    $updateResponse = $controller->updateZohoInvoice(
                        $this->payload['company_id'],
                        $existingInvoiceId,
                        $lineItems
                    );

                    if (empty($updateResponse['invoice'])) {
                        throw new \Exception('Invoice update failed');
                    }

                    $zohoInvoice = $updateResponse['invoice'];

                } else {
                    // sent / paid → don't update
                    $zohoInvoice = $currentInvoice;
                }

            } else {

                // Create new invoice
                $invoiceResult = $controller->createWarrantyInvoice(
                    $device,
                    $this->payload['company_id'],
                    $this->payload['retailer_id'],
                    $device->product_id,
                    $paymentId,
                    $this->payload['amount']
                );

                if (!$invoiceResult['success']) {
                    throw new \Exception($invoiceResult['message']);
                }

                $zohoInvoice = $invoiceResult['invoice'];

                $device->update([
                    'invoice_id' => $zohoInvoice['invoice_id']
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | STEP 2 : SEND INVOICE IF NOT SENT
            |--------------------------------------------------------------------------
            */

            if ($zohoInvoice['status'] === 'draft') {
                $controller->sendZohoInvoice(
                    $this->payload['company_id'],
                    $zohoInvoice['invoice_id']
                );
            }

            /*
            |--------------------------------------------------------------------------
            | STEP 3 : CREATE ZOHO PAYMENT
            |--------------------------------------------------------------------------
            */

            if (!$device->zoho_payment_id) {

                $zohoPayment = $controller->createZohoPayment(
                    $this->payload['company_id'],
                    $this->payload['retailer_id'],
                    $paymentId,
                    $this->payload['amount'],
                    $zohoInvoice['invoice_id']
                );

                $paymentEntity = $zohoPayment['payment'] ?? null;

                if (!$paymentEntity) {
                    throw new \Exception('Zoho payment failed');
                }

                $device->update([
                    'zoho_payment_id'     => $paymentEntity['payment_id'],
                    'payment_json'        => json_encode($paymentEntity),
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | STEP 4 : FETCH FINAL INVOICE STATE
            |--------------------------------------------------------------------------
            */

            $client = new Client();

            $finalResponse = $client->get(
                "https://www.zohoapis.in/books/v3/invoices/{$zohoInvoice['invoice_id']}",
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

            $finalBody = json_decode($finalResponse->getBody(), true);

            if (empty($finalBody['invoice'])) {
                throw new \Exception('Final invoice fetch failed');
            }

            $finalInvoice = $finalBody['invoice'];

            /*
            |--------------------------------------------------------------------------
            | FINAL DEVICE UPDATE
            |--------------------------------------------------------------------------
            */

            $device->update([
                'invoice_status'      => $finalInvoice['status'],
                'invoice_json'        => json_encode($finalInvoice),
                'payment_status'      => 1,
                'razorpay_payment_id' => $paymentId,
                'payment_id'          => $paymentId,
                'paid_at'             => now(),
                'status'              => 1,
                'is_approved'         => 1
            ]);

            WarrantyFlowLog::create([
                'payment_id' => $paymentId,
                'device_id'  => $device->id,
                'step'       => 'UPDATE_COMPLETED',
                'status'     => 1,
                'response_data' => json_encode([
                    'invoice' => $finalInvoice
                ])
            ]);

            DB::commit();

            event(new PaymentSuccessful($device));

        } catch (\Exception $e) {

            DB::rollBack();

            WarrantyFlowLog::create([
                'payment_id'   => $this->payload['payment_id'] ?? null,
                'step'         => 'UPDATE_FAILED',
                'status'       => 0,
                'error_message'=> $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        WarrantyFlowLog::create([
            'payment_id'   => $this->payload['payment_id'] ?? null,
            'step'         => 'UPDATE_FINAL_FAILED',
            'status'       => 0,
            'error_message'=> $exception->getMessage()
        ]);
    }
}