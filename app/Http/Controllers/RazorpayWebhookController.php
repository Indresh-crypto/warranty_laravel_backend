<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Jobs\WarrantyPaymentFlowJob;
use GuzzleHttp\Client;

class RazorpayWebhookController extends Controller
{
   public function handle(Request $request)
   {
    $payload   = $request->getContent();
    $signature = $request->header('X-Razorpay-Signature');
    $secret    = config('services.razorpay.webhook_secret');

    // =============================
    // SIGNATURE VERIFICATION
    // =============================

    if (!$signature || !$secret) {
        Log::warning('Razorpay webhook invalid config');
        return response()->json(['status' => 'invalid'], 400);
    }

    $expectedSignature = hash_hmac('sha256', $payload, $secret);

    if (!hash_equals($expectedSignature, $signature)) {
        Log::warning('Razorpay webhook signature mismatch');
        return response()->json(['status' => 'invalid signature'], 400);
    }

    // =============================
    // PAYLOAD PARSE
    // =============================

    $data  = json_decode($payload, true);
    $event = $data['event'] ?? null;

    $payment =
        $data['payload']['payment']['entity']
        ?? null;

    if (!$payment) {
        return response()->json(['status' => 'no payment'], 200);
    }

    // =============================
    // RAW WEBHOOK LOG (AUDIT)
    // =============================

    DB::table('razorpay_webhook_logs')->insert([
        'event'       => $event,
        'entity_type' => 'payment',
        'entity_id'   => $payment['id'],
        'order_id'    => $payment['order_id'] ?? null,
        'amount'      => $payment['amount'] / 100,
        'currency'    => $payment['currency'],
        'status'      => $payment['status'],
        'payload'     => json_encode($data),
        'created_at'  => now()
    ]);

    // ==================================================
    // STEP 1 — PAYMENT AUTHORIZED → CAPTURE
    // ==================================================

    if ($event === 'payment.authorized') {

        if ($payment['captured'] == true) {
            return response()->json(['status' => 'already captured'], 200);
        }

        try {

            (new \GuzzleHttp\Client())->post(
                "https://api.razorpay.com/v1/payments/{$payment['id']}/capture",
                [
                    'auth' => [
                        config('services.razorpay.razorpay_key'),
                        config('services.razorpay.razorpay_secret'),
                    ],
                    'json' => [
                        'amount' => $payment['amount'],
                        'currency' => 'INR'
                    ]
                ]
            );

            return response()->json(['status' => 'capture triggered'], 200);

        } catch (\Exception $e) {

            Log::error('Capture failed', [
                'payment_id' => $payment['id'],
                'error' => $e->getMessage()
            ]);

            return response()->json(['status' => 'capture failed'], 500);
        }
    }

    // ==================================================
    // IGNORE NON CAPTURE EVENTS
    // ==================================================

    if ($event !== 'payment.captured') {
        return response()->json(['status' => 'ignored'], 200);
    }

    // ==================================================
    // PAYMENT MASTER IDEMPOTENCY
    // ==================================================

    $alreadyProcessed = DB::table('payments_master')
        ->where('payment_id', $payment['id'])
        ->exists();

    if ($alreadyProcessed) {
        return response()->json(['status' => 'duplicate'], 200);
    }

    // ==================================================
    // SAVE PAYMENT MASTER FIRST (LOCK)
    // ==================================================

    DB::table('payments_master')->insert([
        'payment_id' => $payment['id'],
        'order_id' => $payment['order_id'],
        'project' => $payment['notes']['project'] ?? null,
        'service' => $payment['notes']['service'] ?? null,
        'amount' => $payment['amount'] / 100,
        'currency' => $payment['currency'],
        'status' => 'captured',
        'meta' => json_encode($payment['notes']),
        'raw_payload' => json_encode($payment),
        'paid_at' => now(),
        'created_at' => now(),
        'updated_at' => now()
    ]);

    $project = $payment['notes']['project'] ?? null;
    $service = $payment['notes']['service'] ?? null;


    // ==================================================
    // UPDATE WARRANTY PAYMENT FLOW
    // ==================================================

    if ($project === 'warranty' && $service === 'update') {

        \App\Jobs\UpdateWarrantyPaymentJob::dispatch([
            'payment_id'  => $payment['id'],
            'device_id'   => $payment['notes']['device_id'],
            'company_id'  => $payment['notes']['company_id'],
            'retailer_id' => $payment['notes']['retailer_id'],
            'amount'      => $payment['amount'] / 100
        ]);

        return response()->json(['status' => 'update queued'], 200);
    }

   if ($project === 'warranty' && $service === 'update_pay_later') {
    
        \App\Jobs\UpdatePayLaterInvoiceJob::dispatch([
            'payment_id'  => $payment['id'],
            'invoice_id'  => $payment['notes']['invoice_id'],
            'company_id'  => $payment['notes']['company_id'],
            'retailer_id' => $payment['notes']['retailer_id'],
            'amount'      => $payment['amount'] / 100
        ]);
    
        return response()->json(['status' => 'update pay later queued'], 200);
    }
        
    // ==================================================
    // CREATE WARRANTY FLOW
    // ==================================================

    if ($project === 'warranty' && $service === 'activation') {

        $jobPayload = [

            'payment_id' => $payment['id'],
            'amount' => $payment['amount'] / 100,

            'device_price' => $payment['notes']['device_price'] ?? null,

            'imei1' => $payment['notes']['imei1'],
            'imei2' => $payment['notes']['imei2'] ?? null,
            'serial' => $payment['notes']['serial'] ?? null,

            'product_id' => $payment['notes']['product_id'],
            'model_id' => $payment['notes']['model_id'] ?? null,
            'company_id' => $payment['notes']['company_id'],
            'retailer_id' => $payment['notes']['retailer_id'],
            'agent_id' => $payment['notes']['agent_id'] ?? null,
            'w_customer_id' => $payment['notes']['w_customer_id'],

            'created_by' => $payment['notes']['created_by'] ?? null,

            'link1' => $payment['notes']['link1'] ?? null,
            'document_url' => $payment['notes']['document_url'] ?? null
        ];

        WarrantyPaymentFlowJob::dispatch($jobPayload);

        return response()->json(['status' => 'activation queued'], 200);
    }

    return response()->json(['status' => 'processed'], 200);
}
}