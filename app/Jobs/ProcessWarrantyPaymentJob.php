<?php

namespace App\Jobs;

use App\Models\WDevice;
use App\Models\WCustomer;
use App\Models\WarrantyPaymentLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use GuzzleHttp\Client;

use App\Mail\InvoiceCreatedMail;
use App\Mail\PaymentCompletedMail;
use App\Events\WarrantyPaymentCompleted;

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

            /* =====================================================
             | STEP 1: CREATE DEVICE
             ===================================================== */
            $device = WDevice::create([
                'imei1'        => $this->payload['imei'],
                'product_id'   => $this->payload['product_id'],
                'company_id'   => $this->payload['company_id'],
                'w_customer_id'=> $this->payload['customer_id'] ?? null,
                'is_approved'  => 1,
                'status'       => 1
            ]);

            WarrantyPaymentLog::create([
                'payment_id' => $this->payload['payment_id'],
                'device_id'  => $device->id,
                'step'       => 'DEVICE_CREATED',
                'status'     => 1
            ]);

            /* =====================================================
             | STEP 2: CREATE ZOHO INVOICE
             ===================================================== */
            $customer = WCustomer::find($device->w_customer_id);

            $invoiceResult = app(\App\Services\ZohoInvoiceService::class)
                ->createWarrantyInvoice(
                    $device,
                    $customer,
                    $this->payload['company_id'],
                    $this->payload['zoho_product_id'],
                    $this->payload['payment_id']
                );

            if (empty($invoiceResult['success'])) {
                throw new \Exception($invoiceResult['message'] ?? 'Invoice creation failed');
            }

            $invoice     = $invoiceResult['invoice'];
            $invoiceId   = $invoice['invoice_id'];

            $device->update([
                'invoice_id'   => $invoiceId,
                'invoice_json' => json_encode($invoice)
            ]);

            WarrantyPaymentLog::create([
                'payment_id'       => $this->payload['payment_id'],
                'device_id'        => $device->id,
                'invoice_id'       => $invoiceId,
                'step'             => 'INVOICE_CREATED',
                'status'           => 1,
                'response_payload' => json_encode($invoiceResult)
            ]);

            /* =====================================================
             | STEP 3: CAPTURE RAZORPAY PAYMENT
             ===================================================== */
            $razor = new Client();

            $capture = $razor->post(
                "https://api.razorpay.com/v1/payments/{$this->payload['payment_id']}/capture",
                [
                    'auth' => [
                        config('services.razorpay.razorpay_key'),
                        config('services.razorpay.razorpay_secret'),
                    ],
                    'json' => [
                        'amount'   => $this->payload['amount'] * 100,
                        'currency' => 'INR'
                    ]
                ]
            );

            $captureBody = json_decode($capture->getBody(), true);

            WarrantyPaymentLog::create([
                'payment_id'       => $this->payload['payment_id'],
                'step'             => 'RAZORPAY_CAPTURED',
                'status'           => 1,
                'response_payload' => json_encode($captureBody)
            ]);

            /* =====================================================
             | STEP 4: CREATE ZOHO PAYMENT
             ===================================================== */
            $paymentResult = app(\App\Services\ZohoPaymentService::class)
                ->createPayment(
                    $invoiceId,
                    $this->payload
                );

            WarrantyPaymentLog::create([
                'payment_id'       => $this->payload['payment_id'],
                'invoice_id'       => $invoiceId,
                'zoho_payment_id'  => $paymentResult['payment_id'] ?? null,
                'step'             => 'ZOHO_PAYMENT_CREATED',
                'status'           => 1,
                'response_payload' => json_encode($paymentResult)
            ]);

            /* =====================================================
             | FINALIZE DEVICE
             ===================================================== */
            $device->update([
                'payment_status'      => 1,
                'razorpay_payment_id' => $this->payload['payment_id'],
                'zoho_payment_id'     => $paymentResult['payment_id'] ?? null,
                'paid_at'             => now()
            ]);

            DB::commit();

            Log::info('PROCESS WARRANTY PAYMENT COMMITTED', [
                'device_id' => $device->id,
                'invoice_id'=> $invoiceId
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            WarrantyPaymentLog::create([
                'payment_id'    => $this->payload['payment_id'],
                'step'          => 'FAILED',
                'status'        => 0,
                'error_message' => $e->getMessage()
            ]);

            Log::error('PROCESS WARRANTY PAYMENT FAILED', [
                'error'   => $e->getMessage(),
                'payload' => $this->payload
            ]);

            throw $e;
        }

        /* =====================================================
         | SEND INVOICE EMAIL (ONCE)
         ===================================================== */
        if (
            !WarrantyPaymentLog::where('payment_id', $this->payload['payment_id'])
                ->where('step', 'INVOICE_MAIL_SENT')
                ->exists()
        ) {
            Mail::to($customer->email)
                ->queue(new InvoiceCreatedMail(
                    $invoice,
                    $invoice['invoice_url'] ?? '#'
                ));

            WarrantyPaymentLog::create([
                'payment_id' => $this->payload['payment_id'],
                'step'       => 'INVOICE_MAIL_SENT',
                'status'     => 1
            ]);
        }

        /* =====================================================
         | SEND PAYMENT COMPLETED EMAIL
         ===================================================== */
        if ($customer && !empty($customer->email)) {

            Mail::to($customer->email)
                ->queue(new PaymentCompletedMail(
                    $device->fresh(['customer'])
                ));

            WarrantyPaymentLog::create([
                'payment_id' => $this->payload['payment_id'],
                'device_id'  => $device->id,
                'step'       => 'PAYMENT_MAIL_SENT',
                'status'     => 1
            ]);
        }

        /* =====================================================
         | EVENT (OPTIONAL)
         ===================================================== */
        event(new WarrantyPaymentCompleted($device));
    }
}