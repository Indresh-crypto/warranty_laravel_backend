<?php

namespace App\Jobs;

use App\Models\WDevice;
use App\Models\Company;
use App\Models\WarrantyFlowLog;
use App\Http\Controllers\WarrantyPaymentFlowController;
use App\Events\WarrantyRegistered;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use GuzzleHttp\Client;

use App\Mail\InvoiceCreatedMail;
use App\Mail\PaymentCompletedMail;
use App\Services\WhatsappService;
class UpdatePayLaterInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;
    public $tries = 5;
    public $timeout = 180;

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {
        /* ================= VALIDATION ================= */
        $required = ['payment_id','invoice_id','company_id','retailer_id','amount'];

        foreach ($required as $field) {
            if (empty($this->payload[$field])) {
                throw new \Exception($field . ' missing');
            }
        }

        $devicesForMail = [];

        DB::beginTransaction();

        try {

            $paymentId     = $this->payload['payment_id'];
            $invoiceId     = $this->payload['invoice_id'];
            $requestAmount = (float) $this->payload['amount'];
            
            $amount     = (float) $this->payload['amount'];
            $companyId  = $this->payload['company_id'];
            $retailerId = $this->payload['retailer_id'];
            

            Log::info('PAY LATER JOB STARTED', compact('paymentId','invoiceId'));

            /* ================= LOAD COMPANY ================= */
            $company = Company::find($this->payload['company_id']);

            if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
                throw new \Exception('Company Zoho credentials missing');
            }

            /* ================= LOAD RETAILER ================= */
            $retailer = Company::find($this->payload['retailer_id']);

            if (!$retailer) {
                throw new \Exception('Retailer not found');
            }

            /* ================= FETCH DEVICES ================= */
            $devices = WDevice::where('invoice_id', $invoiceId)->get();

            if ($devices->isEmpty()) {
                throw new \Exception('No devices found for invoice');
            }

            /* ================= FETCH INVOICE ================= */
            $client = new Client();

            $response = $client->get(
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

            $invoice = json_decode($response->getBody(), true)['invoice'] ?? null;

            if (!$invoice) {
                throw new \Exception('Invoice fetch failed');
            }

            $balance     = (float) $invoice['balance'];
            $applyAmount = min($balance, $requestAmount);

            Log::info('INVOICE STATUS', [
                'balance' => $balance,
                'applyAmount' => $applyAmount
            ]);

            /* ================= CREATE PAYMENT ================= */
            if ($applyAmount > 0) {
                $controller = app(WarrantyPaymentFlowController::class);

                $zohoPayment = $controller->createZohoPayment(
                    $company->id,
                    $this->payload['retailer_id'],
                    $paymentId,
                    $applyAmount,
                    $invoiceId
                );

            }

            /* ================= FETCH UPDATED INVOICE ================= */
            $response = $client->get(
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

            $finalInvoice = json_decode($response->getBody(), true)['invoice'] ?? null;

            if (!$finalInvoice) {
                throw new \Exception('Final invoice fetch failed');
            }

            /* ================= UPDATE DEVICES ================= */
            foreach ($devices as $device) {

                if ($device->payment_status == 1) {
                    continue;
                }

                $device->update([
                    'payment_status' => 1,
                    'payment_id'     => $paymentId,
                    'paid_at'        => now(),
                    'status'         => 1,
                    'is_approved'    => 1,
                ]);

                WarrantyFlowLog::create([
                    'payment_id' => $paymentId,
                    'device_id'  => $device->id,
                    'step'       => 'PAY_LATER_COMPLETED',
                    'status'     => 1
                ]);

                $devicesForMail[] = $device;
            }

            DB::commit();

            Log::info('DB COMMIT DONE', [
                'devices_for_mail' => count($devicesForMail)
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('PAY LATER JOB FAILED', [
                'error' => $e->getMessage(),
                'payload' => $this->payload
            ]);

            WarrantyFlowLog::create([
                'payment_id'    => $this->payload['payment_id'] ?? null,
                'step'          => 'PAY_LATER_FAILED',
                'status'        => 0,
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }

        /* ============================================================
         |  SEND INVOICE EMAIL (ONCE, AFTER COMMIT)
         ============================================================ */

        Log::info('INVOICE MAIL TO RETAILER', [
            'email' => $retailer->contact_email
        ]);

        if (
            !WarrantyFlowLog::where('payment_id', $paymentId)
                ->where('step', 'INVOICE_MAIL_SENT')
                ->exists()
        ) {

            if (!empty($retailer->contact_email)) {

                Mail::to($retailer->contact_email)
                    ->queue(new InvoiceCreatedMail(
                        $finalInvoice,
                        $finalInvoice['invoice_url'] ?? '#'
                    ));
            }

            WarrantyFlowLog::create([
                'payment_id' => $paymentId,
                'step'       => 'INVOICE_MAIL_SENT',
                'status'     => 1
            ]);
        }

        /* ============================================================
         |  PER-DEVICE EVENTS + PAYMENT EMAIL
         ============================================================ */

        foreach ($devicesForMail as $device) {

            event(new WarrantyRegistered($device));

            WarrantyFlowLog::firstOrCreate([
                'payment_id' => $paymentId,
                'device_id'  => $device->id,
                'step'       => 'EMAIL_SENT'
            ], [
                'status' => 1
            ]);

            if (
                $device->customer &&
                !empty($device->customer->email) &&
                !WarrantyFlowLog::where('payment_id', $paymentId)
                    ->where('device_id', $device->id)
                    ->where('step', 'PAYMENT_MAIL_SENT')
                    ->exists()
            ) {

                Mail::to($device->customer->email)
                    ->queue(new PaymentCompletedMail(
                        $device->fresh(['customer'])
                    ));
                Mail::to($retailer->contact_email)
                    ->queue(new PaymentCompletedMail(
                        $device->fresh(['customer'])
                    ));

                WarrantyFlowLog::create([
                    'payment_id' => $paymentId,
                    'device_id'  => $device->id,
                    'step'       => 'PAYMENT_MAIL_SENT',
                    'status'     => 1
                ]);
            }
        }
    }
}