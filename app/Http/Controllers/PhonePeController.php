<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PhonePeController extends Controller
{
    private $merchantId;
    private $saltKey;
    private $saltIndex;
    private $baseUrl;

    public function __construct()
    {
        $this->merchantId = config('services.phonepe.merchant_id');
        $this->saltKey    = config('services.phonepe.salt_key');
        $this->saltIndex  = config('services.phonepe.salt_index');
        $this->baseUrl    = config('services.phonepe.base_url');
    }

    /**
     * Create Payment
     */
   
   public function createPayment(Request $request)
   {
    $amount = $request->amount * 100;
    $txnId  = "TXN" . time();

    $payload = [
        "merchantId" => env('PHONEPE_MERCHANT_ID'),
        "merchantTransactionId" => $txnId,
        "merchantUserId" => "USER001",
        "amount" => $amount,
        "redirectUrl" => url('/payment-success'),
        "redirectMode" => "POST",
        "callbackUrl" => url('/api/phonepe/callback'),
        "mobileNumber" => "9999999999",
        "paymentInstrument" => [
            "type" => "PAY_PAGE"
        ]
    ];

    $base64Payload = base64_encode(json_encode($payload));

    $checksum = hash(
        'sha256',
        $base64Payload . "/pg/v1/pay" . env('PHONEPE_SALT_KEY')
    ) . "###" . env('PHONEPE_SALT_INDEX');

    $response = Http::withHeaders([
        "Content-Type" => "application/json",
        "X-VERIFY" => $checksum
    ])->post(
        env('PHONEPE_BASE_URL') . "/pg/v1/pay",
        ["request" => $base64Payload]
    );



    return response()->json($response->json());
}

    /**
     * PhonePe Callback
     */
    public function callback(Request $request)
    {
        // Log callback response
        \Log::info('PhonePe Callback:', $request->all());

        return response()->json(['status' => 'OK']);
    }

    /**
     * Check Payment Status
     */
    public function checkStatus($txnId)
    {
        $path = "/pg/v1/status/{$this->merchantId}/{$txnId}";

        $checksum = hash(
            'sha256',
            $path . $this->saltKey
        ) . "###" . $this->saltIndex;

        $response = Http::withHeaders([
            "X-VERIFY" => $checksum,
            "X-MERCHANT-ID" => $this->merchantId
        ])->get($this->baseUrl . $path);

        return response()->json($response->json());
    }
}