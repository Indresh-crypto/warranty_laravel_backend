<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;
use App\Models\ZohoPayment;
use App\Models\OnlinePayment;
use App\Models\Company;
use App\Models\OtherApiLog;
use DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Events\PaymentSuccessful;

class ZohoPaymentController extends Controller
{
    public function createPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'customer_id' => 'required|string',
            'payment_mode' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'reference_number' => 'nullable|string',
            'description' => 'nullable|string',
            'invoices' => 'nullable|array|min:1',
            'invoices.*.invoice_id' => 'nullable|string',
            'invoices.*.amount_applied' => 'nullable|numeric|min:0.01',
            'company_id' => 'nullable|integer',
            'role' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $orgUser = Company::find($request->user_id);
        if (!$orgUser) {
            return response()->json(['error' => 'Organization user not found'], 404);
        }

        $accessToken = $orgUser->zoho_access_token;
        $orgId = $orgUser->zoho_org_id;

       $customerData = Company::findOrFail($request->customer_id);

        $paymentData = [
            "customer_id" => $customerData->zoho_id,
            "payment_mode" => $request->payment_mode,
            "amount" => $request->amount,
            "date" => $request->date,
            "reference_number" => $request->reference_number,
            "description" => $request->description,
            "is_advance_payment"=>true
        ];

        try {
            $client = new Client();
            $response = $client->post("https://www.zohoapis.in/books/v3/customerpayments", [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'organization_id' => $orgId,
                ],
                'json' => $paymentData,
            ]);

            $responseBody = json_decode($response->getBody(), true);

            if (isset($responseBody['payment'])) {
                ZohoPayment::create([
                    'z_json' => json_encode($responseBody['payment']),
                    'org_id' => $orgUser->zoho_org_id,
                    'invoice_id' => $request->invoices[0]['invoice_id'] ?? null,
                    'contact_id' => $request->customer_id,
                    'company_id' => $request->company_id ?? null,
                    'user_id' => $request->user_id,
                    'role' => $request->role ?? null,
                ]);

                return response()->json(['message' => 'Payment created successfully'], 201);
            } else {
                return response()->json(['error' => 'Payment creation failed. Invalid response from Zoho.'], 500);
            }

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getPayments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'org_id' => 'nullable',
            'payment_id' => 'nullable|string',
            'contact_id' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1'
        ]);
    
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $perPage = $request->get('per_page', 10);
    
        $query = ZohoPayment::query();
    
        // Fixed filters (optional)
        if ($request->org_id) {
            $query->where('org_id', $request->org_id);
        }
    
        if ($request->payment_id) {
            $query->where('payment_id', 'like', "%{$request->payment_id}%");
        }
    
        if ($request->contact_id) {
            $query->where('contact_id', 'like', "%{$request->contact_id}%");
        }
    
        // Dynamic filter (loop through fillable fields)
        $searchableFields = (new ZohoPayment())->getFillable();
    
        foreach ($request->all() as $key => $value) {
            if (in_array($key, $searchableFields) && !empty($value)) {
                $query->where($key, 'like', "%{$value}%");
            }
        }
    
        // Pagination
        $paginated = $query->paginate($perPage);
    
        $paginated->getCollection()->transform(function ($item) {
            $item->z_json = json_decode($item->z_json); // decode JSON payload
            return $item;
        });
    
        return response()->json($paginated);
    }
 
  public function createOnlinePayment(Request $request)
  {
    $validator = Validator::make($request->all(), [
        'user_id'        => 'nullable|integer',
        'company_id'     => 'nullable|integer',
        'payment_id'     => 'nullable|string',
        'amount'         => 'nullable|numeric|min:0.01',
        'payment_date'   => 'nullable',
        'invoice_id'     => 'nullable',
        'invoice_number' => 'nullable',
        'customer_id'    => 'required',
        'payment_from'   => 'nullable'
    ]);

    if ($validator->fails()) {
        return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
    }

    $data = $request->only([
        'company_id','user_id','payment_id','amount',
        'payment_date','invoice_id','invoice_number',
        'customer_id','payment_from'
    ]);

    $data['status'] = 1;
    $data['is_captured'] = 0;
    $data['zoho_status'] = 0;

    DB::beginTransaction();

    try {
        // 1️⃣ Create payment (FAST)
        $payment = OnlinePayment::create($data);

        DB::commit(); // ✅ COMMIT EARLY (IMPORTANT)

        /**
         * ===============================
         * 🔽 NON-BLOCKING OPERATIONS
         * ===============================
         */

        // Razorpay Capture
        $razorClient = new \GuzzleHttp\Client();
        $razorResponse = $razorClient->post(
            "https://api.razorpay.com/v1/payments/{$data['payment_id']}/capture",
            [
               'auth' => [
                config('services.razorpay.razorpay_key'),
                config('services.razorpay.razorpay_secret'),
            ],
                'json' => [
                    'amount'   => $data['amount'] * 100,
                    'currency' => 'INR',
                ],
            ]
        );

        $razorBody = json_decode($razorResponse->getBody(), true);
        $isCaptured = ($razorBody['status'] ?? '') === 'captured';

        $payment->update([
            'is_captured' => $isCaptured ? 1 : 0,
            'capture_response' => $razorBody
        ]);

        // Zoho Payment
        $orgUser = Company::find($data['company_id']);
        if ($isCaptured && $orgUser) {

            $paymentData = [
                "customer_id"       => $data['customer_id'],
                "payment_mode"     => "WARRANTY",
                "amount"           => $data['amount'],
                "date"             => date('Y-m-d', strtotime($data['payment_date'])),
                "reference_number" => $data['payment_id'],
                "description"      => "Warranty Payment"
            ];

            $client = new \GuzzleHttp\Client();
            $response = $client->post(
                "https://www.zohoapis.in/books/v3/customerpayments",
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
                        'Content-Type'  => 'application/json',
                    ],
                    'query' => ['organization_id' => $orgUser->zoho_org_id],
                    'json'  => $paymentData,
                ]
            );

            $responseBody = json_decode($response->getBody(), true);
            $zohoPayment = $responseBody['payment'] ?? null;

            $payment->update([
                'zoho_response' => json_encode($responseBody),
                'zoho_status'   => $zohoPayment ? 1 : 0
            ]);

            // 🔥 WhatsApp event AFTER success
            if ($zohoPayment) {
                event(new PaymentSuccessful($payment));
            }
        }

        // ✅ FAST RESPONSE TO FRONTEND
        return response()->json([
            'status' => true,
            'data' => $payment
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'status' => false,
            'error' => $e->getMessage()
        ], 500);
    }
}

    public function syncAllPayments(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer',
        'user_id'    => 'required|integer',
        'role'       => 'nullable|string'
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $orgUser = Company::find($request->company_id);
    if (!$orgUser || !$orgUser->zoho_access_token || !$orgUser->zoho_org_id) {
        return response()->json(['error' => 'Invalid Zoho credentials'], 400);
    }

    $client   = new \GuzzleHttp\Client();
    $page     = 1;
    $perPage  = 200; 
    $allPayments = [];

    try {
        do {
            $response = $client->get("https://www.zohoapis.in/books/v3/customerpayments", [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
                    'Content-Type'  => 'application/json',
                ],
                'query' => [
                    'organization_id' => $orgUser->zoho_org_id,
                    'per_page'        => $perPage,
                    'page'            => $page,
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            if (!isset($body['customerpayments']) || empty($body['customerpayments'])) {
                break;
            }

            foreach ($body['customerpayments'] as $payment) {
                
                $retailer = Company::where('zoho_contact_id', $payment['customer_id'])->first();
                
                $Retailer = Retailers::where('contact_id', $payment['customer_id'])->first();
                 
             
                ZohoPayment::updateOrCreate(
                    ['payment_id' => $payment['payment_id']],
                    [
                        'payment_number'  => $payment['payment_number'] ?? null,
                        'z_json'          => json_encode($payment),
                        'org_id'          => $orgUser->zoho_org_id,
                        'user_id'         => $request->user_id,
                        'org_code'        => $retailer->org_code ?? '',
                        'org_name'        => $retailer->business_name ?? '',
                        'role'            => $retailer->role ?? 0,
                        'company_id'      => $request->company_id,
                        'contact_id'      => $payment['customer_id'] ?? null,
                        'amount'          => $payment['amount'] ?? null,
                        'date'            => $payment['date'] ?? null,
                        'created_by'      => $payment['created_by']['name'] ?? null,
                        'customer_name'   => $payment['customer_name'] ?? null,
                        'description'     => $payment['description'] ?? null,
                        'payment_mode'    => $payment['payment_mode'] ?? null,
                        'reference_number'=> $payment['reference_number'] ?? null,
                        'level1'          => $Retailer->level1 ?? 0,
                        'level2'          => $Retailer->level2 ?? 0,
                        'level3'          => $Retailer->level3 ?? 0,
                        'level4'          => $Retailer->level4 ?? 0,
                        'level5'          => $Retailer->level5 ?? 0,
                        
                        'level1_name'   => $Retailer->level1_name ?? "",
                        'level2_name'   => $Retailer->level2_name ?? "",
                        'level3_name'   => $Retailer->level3_name ?? "",
                        'level4_name'   => $Retailer->level4_name ?? "",
                        'level5_name'   => $Retailer->level5_name ?? "",
                    ]
                );
            }

            $allPayments = array_merge($allPayments, $body['customerpayments']);
            $hasMore = $body['page_context']['has_more_page'] ?? false;
            $page++;

        } while ($hasMore && $page <= 50); // limit 10k payments max

        return response()->json([
            'status'  => true,
            'message' => 'Payments synced successfully from Zoho.',
            'count'   => count($allPayments),
        ]);

    }catch (\GuzzleHttp\Exception\ClientException $e) {
    $statusCode = $e->getResponse()->getStatusCode();
    $errorBody  = json_decode($e->getResponse()->getBody()->getContents(), true);

    // Log validation errors
    DB::table('ama_error_logs')->insert([
        'api_id'         => null,
        'method_name'    => 'syncAllPayments',
        'error_message'  => 'Zoho payments sync failed',
        'additional_info'=> json_encode($errorBody, JSON_UNESCAPED_UNICODE),
        'status_code'    => $statusCode,
        'is_solved'      => 0,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    return response()->json([
        'status' => false,
        'error'  => $errorBody['message'] ?? $e->getMessage(),
    ], $statusCode);

} catch (\Exception $e) {
    // Log unexpected errors too (optional)
    DB::table('ama_error_logs')->insert([
        'api_id'         => null,
        'method_name'    => 'syncAllPayments',
        'error_message'  => 'Unexpected error in Zoho payments sync',
        'additional_info'=> $e->getMessage(),
        'status_code'    => 500,
        'is_solved'      => 0,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    return response()->json([
        'status' => false,
        'error'  => $e->getMessage(),
    ], 500);
}
}
    
    public function getPaymentSummary(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'org_id' => 'nullable',
        'payment_id' => 'nullable|string',
        'contact_id' => 'nullable|string',
        'date_from' => 'nullable|date',
        'date_to'   => 'nullable|date',
        'per_page' => 'nullable|integer|min:1|max:100',
        'page' => 'nullable|integer|min:1'
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $perPage = $request->get('per_page', 10);

    $query = ZohoPayment::query();

    // Fixed filters
    if ($request->org_id) {
        $query->where('org_id', $request->org_id);
    }
    if ($request->payment_id) {
        $query->where('payment_id', 'like', "%{$request->payment_id}%");
    }
    if ($request->contact_id) {
        $query->where('contact_id', 'like', "%{$request->contact_id}%");
    }

    // Dynamic filters (loop through fillable)
    $searchableFields = (new ZohoPayment())->getFillable();
    foreach ($request->all() as $key => $value) {
        if (in_array($key, $searchableFields) && !empty($value)) {
            if ($key === 'date') {
                // Special handling for date range
                continue;
            }
            $query->where($key, 'like', "%{$value}%");
        }
    }

    // Date filter (since stored as varchar YYYY-MM-DD)
    if ($request->date_from && $request->date_to) {
        $query->whereBetween(
            DB::raw("STR_TO_DATE(date, '%Y-%m-%d')"),
            [$request->date_from, $request->date_to]
        );
    } elseif ($request->date_from) {
        $query->where(DB::raw("STR_TO_DATE(date, '%Y-%m-%d')"), '>=', $request->date_from);
    } elseif ($request->date_to) {
        $query->where(DB::raw("STR_TO_DATE(date, '%Y-%m-%d')"), '<=', $request->date_to);
    }

    // Clone query for summary (before pagination)
    $summaryQuery = clone $query;

    $summary = [
        'total_payments' => $summaryQuery->count(),
        'total_amount'   => $summaryQuery->sum('amount'),
    ];

    // Paginated data
    $paginated = $query->paginate($perPage);

    $paginated->getCollection()->transform(function ($item) {
        $item->z_json = json_decode($item->z_json);
        return $item;
    });

    return response()->json([
        'summary' => $summary,
        'data'    => $paginated
    ]);
}

    public function optInAndSendMessage(Request $request)
    {
        $templateid = $request->input('templateid');
        $phone = $request->input('phone');
        $orgCode = $request->input('org_code');
        $params = $request->input('params', []);
    
        $apiKey  = env('GUPSHUP_API_KEY');
        $appName = "Goexrt";
        $source  = env('GUPSHUP_WHATSAPP_NUMBER'); // e.g. 919372011028
    
        // 1️⃣ Opt-in user before sending message
        $optinResponse = $this->optInUser($apiKey, $appName, $phone);
    
        if (!$optinResponse) {
            return response()->json(['error' => 'Failed to opt-in user'], 400);
        }
    
        // 2️⃣ Send WhatsApp template message
        return $this->sendMessage($apiKey, $source, $phone, $orgCode, $appName, $templateid, $params);
    }

    private function optInUser($apiKey, $appName, $phone)
    {
        $response = Http::withHeaders([
            'apikey' => $apiKey,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->post("https://api.gupshup.io/sm/api/v1/app/opt/in/{$appName}", [
            'user' => $phone,
        ]);

        
        if ($response->successful()) {
            sleep(3); 
            return true;
        }
        return false;
    }

    private function sendMessage($apiKey, $source, $phone, $orgCode, $appName, $templateid, array $params)
    {
        if (strpos($phone, '91') !== 0) {
            $phone = '91' . $phone;
        }
    
        $templateData = [
            "id" => $templateid,
            "params" => [
                $params[0] ." | ". $orgCode,
                $params[1],
                $params[2] ,
                $params[3],
                $params[4]
            ]
        ];
        
            // Create Guzzle client
            $client = new Client();
        
            try {
                $response = $client->post('https://api.gupshup.io/wa/api/v1/template/msg', [
                    'headers' => [
                        'apikey' => $apiKey,
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'form_params' => [
                        'channel'     => 'whatsapp',
                        'source'      => $source,
                        'destination' => $phone,
                        'src.name'    => 'Goelectronix', // must match your Gupshup app name
                        'template'    => json_encode($templateData),
                    ],
                ]);
        
                // Parse response
                $body = json_decode($response->getBody(), true);
                $this->logApiError('sendMessage: success', $response->getBody(), $templateData);
                return response()->json([
                    'ApiResponse' => $body,
                ]);
            } catch (\Exception $e) {
                
                $this->logApiError('sendMessage: Exception', $e->getMessage(), $templateData);
                return response()->json([
                    'error' => $e->getMessage(),
                ], 500);
            }
        
    
     
        return response()->json([
            'status' => $response->successful(),
            'api_response' => $response->json(),
        ]);
    }
    protected function logApiError($method, $errorMessage, $payload = null)
    {
        try {
            OtherApiLog::create([
                'method_name'   => $method,
                'error_message' => $errorMessage,
                'payload'       => $payload ? json_encode($payload) : null,
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to log API error: " . $e->getMessage());
        }
    }
}