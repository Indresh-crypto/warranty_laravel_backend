<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');
        $webhookSecret = env('RAZORPAY_WEBHOOK_SECRET'); // Set this in .env

        if (!$this->verifySignature($payload, $signature, $webhookSecret)) {
            Log::warning("⚠️ Invalid Razorpay webhook signature.");
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $data = $request->all();
        $event = $data['event'];

        switch ($event) {
            case 'payment.captured':
                $paymentId = $data['payload']['payment']['entity']['id'];
                $amount = $data['payload']['payment']['entity']['amount'] / 100;
                $email = $data['payload']['payment']['entity']['email'];
                
                // ✅ Find and update invoice/payment status here
                // Example:
                // $invoice = Invoice::where('payment_id', $paymentId)->first();
                // if ($invoice) {
                //     $invoice->status = 'paid';
                //     $invoice->save();
                // }

                Log::info("✅ Payment captured: $paymentId | ₹$amount | $email");
                break;

            case 'payment.failed':
                Log::info("❌ Payment failed: " . $data['payload']['payment']['entity']['id']);
                break;

            default:
                Log::info("ℹ️ Razorpay event received: " . $event);
                break;
        }

        return response()->json(['status' => 'ok']);
    }

    private function verifySignature($payload, $actualSignature, $secret)
    {
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $actualSignature);
    }
}