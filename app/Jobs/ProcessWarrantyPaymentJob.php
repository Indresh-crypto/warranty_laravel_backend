<?php

namespace App\Jobs;

use App\Models\WDevice;
use App\Models\WCustomer;
use App\Models\WarrantyPaymentLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use DB;
class ProcessWarrantyPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {
        DB::beginTransaction();

        try {

            // -----------------------
            // STEP 1: CREATE DEVICE
            // -----------------------

            $device = WDevice::create([
                'imei1' => $this->payload['imei'],
                'product_id' => $this->payload['product_id'],
                'company_id' => $this->payload['company_id'],
                'is_approved' => 1
            ]);

            WarrantyPaymentLog::create([
                'payment_id' => $this->payload['payment_id'],
                'device_id' => $device->id,
                'step' => 'DEVICE_CREATED',
                'status' => 1
            ]);

            // -----------------------
            // STEP 2: CREATE ZOHO INVOICE
            // -----------------------

            $customer = WCustomer::find($device->w_customer_id);

            $invoiceResult = app(ZohoInvoiceService::class)
                ->createWarrantyInvoice(
                    $device,
                    $customer,
                    $this->payload['company_id'],
                    $this->payload['zoho_product_id'],
                    $this->payload['payment_id'] // pass gateway id
                );

            if (!$invoiceResult['success']) {
                throw new Exception($invoiceResult['message']);
            }

            $invoiceId = $invoiceResult['invoice']['invoice_id'];

            WarrantyPaymentLog::create([
                'payment_id' => $this->payload['payment_id'],
                'device_id' => $device->id,
                'invoice_id' => $invoiceId,
                'step' => 'INVOICE_CREATED',
                'status' => 1,
                'response_payload' => json_encode($invoiceResult)
            ]);

            // -----------------------
            // STEP 3: CAPTURE RAZORPAY
            // -----------------------

            $razor = new \GuzzleHttp\Client();

            $capture = $razor->post(
                "https://api.razorpay.com/v1/payments/{$this->payload['payment_id']}/capture",
                [
                    'auth' => [
                        config('services.razorpay.razorpay_key'),
                        config('services.razorpay.razorpay_secret'),
                    ],
                    'json' => [
                        'amount' => $this->payload['amount'] * 100,
                        'currency' => 'INR'
                    ]
                ]
            );

            $captureBody = json_decode($capture->getBody(), true);

            WarrantyPaymentLog::create([
                'payment_id' => $this->payload['payment_id'],
                'step' => 'RAZORPAY_CAPTURED',
                'status' => 1,
                'response_payload' => json_encode($captureBody)
            ]);

            // -----------------------
            // STEP 4: CREATE ZOHO PAYMENT
            // -----------------------

            $paymentResult = app(ZohoPaymentService::class)
                ->createPayment(
                    $invoiceId,
                    $this->payload
                );

            WarrantyPaymentLog::create([
                'payment_id' => $this->payload['payment_id'],
                'invoice_id' => $invoiceId,
                'zoho_payment_id' => $paymentResult['payment_id'] ?? null,
                'step' => 'ZOHO_PAYMENT_CREATED',
                'status' => 1,
                'response_payload' => json_encode($paymentResult)
            ]);

            DB::commit();

            // EVENT
            event(new WarrantyPaymentCompleted($device));

        } catch (\Exception $e) {

            DB::rollBack();

            WarrantyPaymentLog::create([
                'payment_id' => $this->payload['payment_id'],
                'step' => 'FAILED',
                'status' => 0,
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}
