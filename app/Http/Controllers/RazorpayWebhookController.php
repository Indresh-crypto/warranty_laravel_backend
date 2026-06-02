<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;

class RazorpayWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload   = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');
        $secret    = "abc123";

        /*
        |--------------------------------------------------------------------------
        | SIGNATURE VERIFICATION
        |--------------------------------------------------------------------------
        */

        if (!$signature || !$secret) {
            Log::warning('Razorpay webhook invalid config');
            return response()->json(['status' => 'invalid'], 400);
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Razorpay webhook signature mismatch');
            return response()->json(['status' => 'invalid signature'], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | PARSE PAYLOAD
        |--------------------------------------------------------------------------
        */

        $data  = json_decode($payload, true);
        $event = $data['event'] ?? null;

        $payment = $data['payload']['payment']['entity'] ?? null;

        if (!$payment) {
            return response()->json(['status' => 'no payment'], 200);
        }

        $notes = $payment['notes'] ?? [];

        /*
        |--------------------------------------------------------------------------
        | LOG RAW WEBHOOK
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | STEP 1 — CAPTURE AUTHORIZED PAYMENT
        |--------------------------------------------------------------------------
        */

        if ($event === 'payment.authorized') {

            if ($payment['captured'] === true) {
                return response()->json(['status' => 'already captured'], 200);
            }

            try {

                (new Client())->post(
                    "https://api.razorpay.com/v1/payments/{$payment['id']}/capture",
                    [
                        'auth' => [
                            config('services.razorpay.razorpay_key'),
                            config('services.razorpay.razorpay_secret')
                        ],
                        'json' => [
                            'amount' => $payment['amount'],
                            'currency' => 'INR'
                        ]
                    ]
                );

                Log::info('Payment capture triggered', [
                    'payment_id' => $payment['id']
                ]);

                return response()->json(['status' => 'capture triggered'], 200);

            } catch (\Exception $e) {

                Log::error('Capture failed', [
                    'payment_id' => $payment['id'],
                    'error' => $e->getMessage()
                ]);

                return response()->json(['status' => 'capture failed'], 500);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | IGNORE EVENTS OTHER THAN CAPTURED
        |--------------------------------------------------------------------------
        */

        if ($event !== 'payment.captured') {
            return response()->json(['status' => 'ignored'], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | IDEMPOTENCY CHECK (VERY IMPORTANT)
        |--------------------------------------------------------------------------
        */

        $alreadyProcessed = DB::table('payments_master')
            ->where('payment_id', $payment['id'])
            ->exists();

        if ($alreadyProcessed) {

            Log::warning('Duplicate payment webhook ignored', [
                'payment_id' => $payment['id']
            ]);

            return response()->json(['status' => 'duplicate'], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | SAVE PAYMENT MASTER
        |--------------------------------------------------------------------------
        */

        DB::table('payments_master')->insert([
            'payment_id' => $payment['id'],
            'order_id' => $payment['order_id'] ?? null,
            'project' => $notes['project'] ?? null,
            'service' => $notes['service'] ?? null,
            'amount' => $payment['amount'] / 100,
            'currency' => $payment['currency'],
            'status' => 'captured',
            'meta' => json_encode($notes),
            'raw_payload' => json_encode($payment),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        /*
        |--------------------------------------------------------------------------
        | DETERMINE PROJECT + SERVICE
        |--------------------------------------------------------------------------
        */

        $project = "warranty";
        $service = $notes['service'] ?? null;

        /*
        |--------------------------------------------------------------------------
        | UPDATE WARRANTY FLOW
        |--------------------------------------------------------------------------
        */

        if ($project === 'warranty' && $service === 'update') {

            \App\Jobs\UpdateWarrantyPaymentJob::dispatch([
                'payment_id'  => $payment['id'],
                'device_id'   => $notes['device_id'] ?? null,
                'company_id'  => $notes['company_id'] ?? null,
                'retailer_id' => $notes['retailer_id'] ?? null,
                'amount'      => $payment['amount'] / 100
            ]);

            return response()->json(['status' => 'update queued'], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE PAY LATER
        |--------------------------------------------------------------------------
        */

        if ($project === 'warranty' && $service === 'update_pay_later') {

            \App\Jobs\UpdatePayLaterInvoiceJob::dispatch([
                'payment_id'  => $payment['id'],
                'invoice_id'  => $notes['invoice_id'] ?? null,
                'company_id'  => $notes['company_id'] ?? null,
                'retailer_id' => $notes['retailer_id'] ?? null,
                'amount'      => $payment['amount'] / 100
            ]);

            return response()->json(['status' => 'update pay later queued'], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | CREATE WARRANTY ACTIVATION
        |--------------------------------------------------------------------------
        */

        if ($project === 'warranty' && $service === 'activation') {

            $jobPayload = [

                'payment_id' => $payment['id'],
                'amount' => $payment['amount'] / 100,

                'device_price' => $notes['device_price'] ?? null,

                'imei1' => $notes['imei1'] ?? null,
                'imei2' => $notes['imei2'] ?? null,
                'serial' => $notes['serial'] ?? null,

                'product_id' => $notes['product_id'] ?? null,
                'model_id' => $notes['model_id'] ?? null,
                'company_id' => $notes['company_id'] ?? null,
                'retailer_id' => $notes['retailer_id'] ?? null,
                'agent_id' => $notes['agent_id'] ?? null,
                'w_customer_id' => $notes['w_customer_id'] ?? null,

                'created_by' => $notes['created_by'] ?? null,

                'link1' => $notes['link1'] ?? null,
                'document_url' => $notes['document_url'] ?? null
            ];

            \App\Jobs\WarrantyPaymentFlowJob::dispatch($jobPayload);

            return response()->json(['status' => 'activation queued'], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | ADVANCE WALLET PAYMENT
        |--------------------------------------------------------------------------
        */

        if ($project === 'warranty' && $service === 'advance_payment') {

            \App\Jobs\AdvancePaymentJob::dispatch([
                'company_id'  => $notes['company_id'] ?? null,
                'retailer_id' => $notes['retailer_id'] ?? ($notes['user_id'] ?? null),
                'amount'      => $payment['amount'] / 100,
                'payment_id'  => $payment['id']
            ]);

            return response()->json(['status' => 'advance payment queued'], 200);
        }
        
        
         if ($project === 'warranty' && $service === 'onboarding') {

            \App\Jobs\OnboardingPaymentJob::dispatch([
                'company_id'  => $notes['company_id'] ?? null,
                'retailer_id' => $notes['retailer_id'] ?? ($notes['user_id'] ?? null),
                'amount'      => $payment['amount'] / 100,
                'payment_id'  => $payment['id']
            ]);

            return response()->json(['status' => 'onboarding payment queued'], 200);
        }
        
        
        if ($project === 'warranty' && $service === 'subscription') {

            $jobPayload = [

                'company_id'  => $notes['company_id'] ?? null,
                'company_package_id'  => $notes['company_package_id'] ?? null,
                'package_id'  => $notes['package_id'] ?? null,
                'retailer_id' => $notes['retailer_id'] ?? ($notes['user_id'] ?? null),
                'amount'      => $payment['amount'] / 100,
                'payment_id'  => $payment['id']
            ];

            \App\Jobs\SubscriptionBuyJob::dispatch($jobPayload);

            return response()->json(['status' => 'activation queued'], 200);
        }
        
         if ($project === 'warranty' && $service === 'advance_payment') {

            \App\Jobs\AdvancePaymentJob::dispatch([
                'company_id'  => $notes['company_id'] ?? null,
                'retailer_id' => $notes['retailer_id'] ?? ($notes['user_id'] ?? null),
                'amount'      => $payment['amount'] / 100,
                'payment_id'  => $payment['id']
            ]);

            return response()->json(['status' => 'advance payment queued'], 200);
        }
        

        return response()->json(['status' => 'processed'], 200);
    }
}