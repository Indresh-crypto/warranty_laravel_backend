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

        if (empty($signature)) {
            Log::warning('Razorpay webhook without signature');
            return response()->json(['status' => 'signature missing'], 400);
        }

        if (empty($secret)) {
            Log::error('Razorpay webhook secret not configured');
            return response()->json(['status' => 'server misconfigured'], 500);
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
        $event = $data['event'] ?? 'unknown';

        $entity = $data['payload'] ?? [];

        $payment =
            $entity['payment']['entity']
            ?? $entity['refund']['entity']
            ?? $entity['order']['entity']
            ?? null;

        if (!$payment) {
            Log::warning('Webhook without payment entity');
            return response()->json(['status' => 'no entity'], 200);
        }

        // =============================
        // RAW WEBHOOK LOG (AUDIT)
        // =============================

        DB::table('razorpay_webhook_logs')->insert([
            'event'       => $event,
            'entity_type' => $payment['entity'] ?? null,
            'entity_id'   => $payment['id'] ?? null,
            'order_id'    => $payment['order_id'] ?? null,
            'amount'      => isset($payment['amount']) ? $payment['amount'] / 100 : null,
            'currency'    => $payment['currency'] ?? null,
            'status'      => $payment['status'] ?? null,
            'payload'     => json_encode($data),
            'created_at'  => now(),
        ]);

        // =============================
        // STEP 1 — MANUAL CAPTURE
        // =============================

        if ($event === 'payment.authorized') {

            // Already captured safety
            if ($payment['captured'] === true) {
                return response()->json(['status' => 'already captured'], 200);
            }

            try {

                $client = new Client();

                $client->post(
                    "https://api.razorpay.com/v1/payments/{$payment['id']}/capture",
                    [
                        'auth' => [
                            config('services.razorpay.razorpay_key'),
                            config('services.razorpay.razorpay_secret'),
                        ],
                        'json' => [
                            'amount' => $payment['amount'], // paise
                            'currency' => 'INR'
                        ]
                    ]
                );

                Log::info('Payment captured successfully', [
                    'payment_id' => $payment['id']
                ]);

                return response()->json(['status' => 'capture triggered'], 200);

            } catch (\Exception $e) {

                Log::error('Payment capture failed', [
                    'payment_id' => $payment['id'],
                    'error' => $e->getMessage()
                ]);

                return response()->json(['status' => 'capture failed'], 500);
            }
        }

        // =============================
        // STEP 2 — PROCESS CAPTURED
        // =============================

        if ($event !== 'payment.captured') {
            return response()->json(['status' => 'ignored'], 200);
        }

        $project = $payment['notes']['project'] ?? null;
        $service = $payment['notes']['service'] ?? null;

        if ($project === 'warranty' && $service === 'activation') {

            // Prevent duplicate warranty execution
            $alreadyProcessed = DB::table('payments_master')
                ->where('payment_id', $payment['id'])
                ->exists();

            if ($alreadyProcessed) {
                return response()->json(['status' => 'duplicate'], 200);
            }

            // Build Job Payload
            $jobPayload = [

                'payment_id' => $payment['id'],
                'amount' => $payment['amount'] / 100,
            
                'device_price' => $payment['notes']['device_price'] ?? null,
            
                'imei1' => $payment['notes']['imei1'],
                'serial' => $payment['notes']['serial'],
                'imei2' => $payment['notes']['imei2'],
                'created_by' => $payment['notes']['created_by'],
                'product_id' => $payment['notes']['product_id'],
                'model_id' => $payment['notes']['model_id'],
                'company_id' => $payment['notes']['company_id'],
                'retailer_id' => $payment['notes']['retailer_id'],
                'agent_id' => $payment['notes']['agent_id'],
                'w_customer_id' => $payment['notes']['w_customer_id'],
                'link1' => $payment['notes']['link1'],
                'document_url' => $payment['notes']['document_url']
            ];

             Log::error('WARRANTY JOB DISPATCHING', [
                    'payment_id' => $payment['id'],
                    'notes' => $payment['notes']
                ]);
    
            // Dispatch Warranty Job
            WarrantyPaymentFlowJob::dispatch($jobPayload);

            // Save Payment Master
            DB::table('payments_master')->insert([
                'payment_id' => $payment['id'],
                'order_id' => $payment['order_id'],
                'project' => $project,
                'service' => $service,
                'amount' => $payment['amount'] / 100,
                'currency' => $payment['currency'],
                'status' => 'captured',
                'meta' => json_encode($payment['notes']),
                'raw_payload' => json_encode($payment),
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json(['status' => 'warranty queued'], 200);
        }

        return response()->json(['status' => 'ok'], 200);
    }
}