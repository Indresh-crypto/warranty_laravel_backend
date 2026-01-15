<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RazorpayWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload   = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');
        $secret    = config('services.razorpay.webhook_secret');

        // 🔒 Signature missing
        if (empty($signature)) {
            Log::warning('Razorpay webhook without signature');
            return response()->json(['status' => 'signature missing'], 400);
        }

        // 🔒 Secret missing
        if (empty($secret)) {
            Log::error('Razorpay webhook secret not configured');
            return response()->json(['status' => 'server misconfigured'], 500);
        }

        // ✅ Verify signature
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Razorpay webhook signature mismatch');
            return response()->json(['status' => 'invalid signature'], 400);
        }

        // ✅ Decode payload
        $data  = json_decode($payload, true);
        $event = $data['event'] ?? 'unknown';

        // 🔍 Extract common entity safely
        $entity = $data['payload'] ?? [];

        $payment =
            $entity['payment']['entity']
            ?? $entity['refund']['entity']
            ?? $entity['order']['entity']
            ?? null;

        // 🔁 OPTIONAL: prevent duplicates (for payment events)
        $entityId = $payment['id'] ?? null;

        if ($entityId) {
            $alreadyLogged = DB::table('razorpay_webhook_logs')
                ->where('event', $event)
                ->where('entity_id', $entityId)
                ->exists();

            if ($alreadyLogged) {
                return response()->json(['status' => 'duplicate'], 200);
            }
        }

        // ✅ Save EVERY webhook
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

        // 🔥 BUSINESS LOGIC (OPTIONAL)
        if ($event === 'payment.captured') {
            // mark order paid
        }

        if ($event === 'payment.failed') {
            // mark order failed
        }

        if ($event === 'refund.processed') {
            // mark refund completed
        }

        // 🔁 Razorpay requires 200 OK
        return response()->json(['status' => 'ok'], 200);
    }
}