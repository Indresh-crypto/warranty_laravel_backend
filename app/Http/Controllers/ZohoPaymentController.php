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
use Illuminate\Support\Facades\Log;

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
    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */
    $validator = Validator::make($request->all(), [

        'org_id'        => 'nullable|string',

        'location_id'   => 'nullable|string',

        'payment_id'    => 'nullable|string',

        'customer_id'   => 'nullable|string',

        'invoice_id'    => 'nullable|string',

        'payment_mode'  => 'nullable|string',

        'per_page'      => 'nullable|integer|min:1|max:100',

        'page'          => 'nullable|integer|min:1',

        /*
            flags:
            received_today
            pending
            failed
        */
        'flag'          => 'nullable|string|in:received_today,pending,failed',
    ]);

    if ($validator->fails()) {

        return response()->json([

            'status' => false,

            'errors' => $validator->errors()

        ], 422);
    }

    try {

        /*
        |--------------------------------------------------------------------------
        | DEFAULT PARAMS
        |--------------------------------------------------------------------------
        */
        $params = array_merge(

            $request->all()
        );

        /*
        |--------------------------------------------------------------------------
        | SYNC LATEST PAYMENTS IF LOCATION PROVIDED
        |--------------------------------------------------------------------------
        */
        if ($request->filled('location_id')) {

            \Log::info('PAYMENT FETCH REQUEST', $request->input());

            try {

                Http::timeout(60)

                    ->acceptJson()

                    ->get(

                        'https://goelectronix.in/api/v1/zoho/sync-payments',

                        [

                            'org_id'      => $params['org_id'] ?? null,

                            'location_id' => $request->location_id,
                        ]
                    );

            } catch (\Throwable $syncError) {

                \Log::error('PAYMENT SYNC FAILED', [

                    'message' =>
                        $syncError->getMessage(),

                    'line' =>
                        $syncError->getLine(),

                    'file' =>
                        $syncError->getFile(),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | FETCH PAYMENTS
        |--------------------------------------------------------------------------
        */
        $response = Http::timeout(30)

            ->acceptJson()

            ->get(

                'https://goelectronix.in/api/v1/zoho/get-payments',

                $params
            );

        /*
        |--------------------------------------------------------------------------
        | API FAILURE
        |--------------------------------------------------------------------------
        */
        if (!$response->successful()) {

            return response()->json([

                'status' => false,

                'message' =>
                    'Unable to fetch payments',

                'error' => $response->json()

            ], $response->status());
        }

        /*
        |--------------------------------------------------------------------------
        | RETURN SAME RESPONSE
        |--------------------------------------------------------------------------
        */
        return response()->json(

            $response->json(),

            $response->status()
        );

    } catch (\Throwable $e) {

        \Log::error('MASTER PAYMENT API FAILED', [

            'message' => $e->getMessage(),

            'line'    => $e->getLine(),

            'file'    => $e->getFile(),
        ]);

        return response()->json([

            'status' => false,

            'message' =>
                'Payment service unavailable',

            'error' => $e->getMessage()

        ], 500);
    }
}

   /* 
    public function createOnlinePayment(Request $request)
    {
        Log::channel('payment')->info('Payment API called', [
            'payload' => $request->all()
        ]);
    
        $validator = Validator::make($request->all(), [
            'user_id'        => 'nullable|integer',
            'company_id'     => 'nullable|integer',
            'payment_id'     => 'required|string',
            'amount'         => 'required|numeric|min:0.01',
            'payment_date'   => 'nullable',
            'invoice_id'     => 'nullable',
            'invoice_number' => 'nullable',
            'customer_id'    => 'required|string',
            'payment_from'   => 'nullable|string'
        ]);
    
        if ($validator->fails()) {
            Log::channel('payment')->error('Validation failed', [
                'errors' => $validator->errors()
            ]);
    
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $data = $request->only([
            'company_id',
            'user_id',
            'payment_id',
            'amount',
            'payment_date',
            'invoice_id',
            'invoice_number',
            'customer_id',
            'payment_from'
        ]);
    
        $data['status']      = 1;
        $data['is_captured'] = 0;
        $data['zoho_status'] = 0;
    
        DB::beginTransaction();
    
        try {
  
            $payment = OnlinePayment::create($data);
            DB::commit();
    
            Log::channel('payment')->info('Payment record created', [
                'payment_id' => $payment->id,
                'razorpay_payment_id' => $data['payment_id']
            ]);
    
           
            $razorClient = new Client([
                'auth' => [
                    config('services.razorpay.razorpay_key'),
                    config('services.razorpay.razorpay_secret'),
                ],
            ]);
    
            $isCaptured = false;
            $razorpayResponseData = null;
    
            try {
                $fetchResponse = $razorClient->get(
                    "https://api.razorpay.com/v1/payments/{$data['payment_id']}"
                );
    
                $razorpayResponseData = json_decode($fetchResponse->getBody(), true);
    
                Log::channel('payment')->info('Razorpay payment fetched', [
                    'status' => $razorpayResponseData['status'] ?? null
                ]);
    
                if (($razorpayResponseData['status'] ?? '') === 'captured') {
                    $isCaptured = true;
                }
    
            } catch (RequestException $e) {
                Log::channel('payment')->error('Failed to fetch Razorpay payment', [
                    'error' => $e->getMessage()
                ]);
            }
            if($request->w_type =="111")
            {
          
                if (!$isCaptured) {
                    try {
                        Log::channel('payment')->info('Razorpay capture started', [
                            'payment_id' => $data['payment_id']
                        ]);
        
                        $captureResponse = $razorClient->post(
                            "https://api.razorpay.com/v1/payments/{$data['payment_id']}/capture",
                            [
                                'json' => [
                                    'amount'   => $data['amount'] * 100,
                                    'currency' => 'INR',
                                ]
                            ]
                        );
        
                        $razorpayResponseData = json_decode($captureResponse->getBody(), true);
                        $isCaptured = ($razorpayResponseData['status'] ?? '') === 'captured';
        
                    } catch (RequestException $e) {
        
                        $responseBody = optional($e->getResponse())->getBody()->getContents();
        
                        if (str_contains($responseBody, 'already been captured')) {
                            Log::channel('payment')->warning(
                                'Razorpay already captured – treating as success',
                                ['payment_id' => $data['payment_id']]
                            );
                            $isCaptured = true;
                        } else {
                            Log::channel('payment')->error('Razorpay capture failed', [
                                'error' => $e->getMessage(),
                                'response' => $responseBody
                            ]);
                        }
                    }
                } 
        
                
                $payment->update([
                    'is_captured'       => $isCaptured ? 1 : 0,
                    'capture_response' => $razorpayResponseData
                        ? json_encode($razorpayResponseData)
                        : null
                ]);
        
              
                if ($isCaptured && $data['company_id']) {
        
                    $company = Company::find($data['company_id']);
        
                    if ($company) {
                        try {
                            $zohoPayload = [
                                "customer_id"       => $data['customer_id'],
                                "payment_mode"      => "WARRANTY",
                                "amount"            => $data['amount'],
                                "date"              => date('Y-m-d', strtotime($data['payment_date'])),
                                "reference_number"  => $data['payment_id'],
                                "description"       => "Warranty Payment"
                            ];
        
                            Log::channel('payment')->info('Zoho payment started', [
                                'payload' => $zohoPayload
                            ]);
        
                            $zohoClient = new Client();
                            $zohoResponse = $zohoClient->post(
                                "https://www.zohoapis.in/books/v3/customerpayments",
                                [
                                    'headers' => [
                                        'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token,
                                        'Content-Type'  => 'application/json',
                                    ],
                                    'query' => [
                                        'organization_id' => $company->zoho_org_id
                                    ],
                                    'json' => $zohoPayload
                                ]
                            );
        
                            $zohoBody = json_decode($zohoResponse->getBody(), true);
        
                            $payment->update([
                                'zoho_response' => json_encode($zohoBody),
                                'zoho_status'   => isset($zohoBody['payment']) ? 1 : 0
                            ]);
        
                            if (isset($zohoBody['payment'])) {
                                event(new PaymentSuccessful($payment));
                            }
        
                        } catch (RequestException $e) {
                            Log::channel('payment')->error('Zoho payment failed', [
                                'error' => $e->getMessage(),
                                'response' => optional($e->getResponse())->getBody()->getContents()
                            ]);
                        }
                    }
                }
            }
        
            return response()->json([
                'status' => true,
                'message' => 'Payment processed successfully',
                'data' => $payment
            ], 200);
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            Log::channel('payment')->critical('Payment API crashed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
    
            return response()->json([
                'status' => false,
                'error' => 'Payment processing failed'
            ], 500);
        }
    }
*/

public function createOnlinePayment(Request $request)
{

    try {
        $request->validate([

            'payment_id' => 'required|string'

        ]);

        $paymentId = $request->payment_id;



        $payment = DB::table('payments_master')

                    ->where('payment_id', $paymentId)

                    ->first();

        if (!$payment) {

            return response()->json([

                'status'  => false,

                'message' => 'Payment not found.'

            ], 404);

        }

      

        $payload = json_decode($payment->raw_payload, true);

        if (!$payload) {

            return response()->json([

                'status'  => false,

                'message' => 'Invalid payment payload.'

            ], 400);

        }

       
        $notes = $payload['notes'] ?? [];

        $retailerId = $notes['retailer_id'] ?? null;

       

        if ($retailerId) {

            $company = Company::find($retailerId);

            if ($company) {

                $company->update([

                    'is_payment_success' => 1

                ]);

                return response()->json([

                    'status'  => true,

                    'message' => 'Company payment status updated successfully.',

                    'company' => $company

                ]);

            }

        }

        return response()->json([

            'status'  => false,

            'message' => 'Retailer company not found.'

        ], 404);

    } catch (\Exception $e) {

        Log::error('ONLINE PAYMENT VERIFY FAILED', [

            'error'      => $e->getMessage(),

            'payment_id' => $request->payment_id

        ]);

        return response()->json([

            'status'  => false,

            'message' => $e->getMessage()

        ], 500);

    }

}
public function syncAllPayments(Request $request)
{
    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    $validator = Validator::make($request->all(), [

        'company_id' => 'required|integer',

        'user_id'    => 'required|integer',

        'role'       => 'nullable|string'
    ]);

    if ($validator->fails()) {

        return response()->json([

            'status' => false,

            'errors' => $validator->errors()

        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD COMPANY
    |--------------------------------------------------------------------------
    */

    $orgUser = Company::find(
        $request->company_id
    );

    if (
        !$orgUser ||
        !$orgUser->zoho_access_token ||
        !$orgUser->zoho_org_id
    ) {

        return response()->json([

            'status' => false,

            'error' =>
                'Invalid Zoho credentials'

        ], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP CLIENT
    |--------------------------------------------------------------------------
    */

    $client = new \GuzzleHttp\Client([

        'timeout'         => 60,

        'connect_timeout' => 20,

        'http_errors'     => true,
    ]);

    /*
    |--------------------------------------------------------------------------
    | VARIABLES
    |--------------------------------------------------------------------------
    */

    $page        = 1;

    $perPage     = 200;

    $totalSynced = 0;

    $totalFailed = 0;

    $maxPages    = 500;

    try {

        do {

            Log::info(
                'ZOHO PAYMENT SYNC PAGE START',
                [
                    'page' => $page
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | FETCH PAYMENTS
            |--------------------------------------------------------------------------
            */

            $response = $client->get(

                'https://www.zohoapis.in/books/v3/customerpayments',

                [

                    'headers' => [

                        'Authorization' =>

                            'Zoho-oauthtoken ' .
                            $orgUser->zoho_access_token,

                        'Content-Type' =>
                            'application/json',
                    ],

                    'query' => [

                        'organization_id' =>
                            $orgUser->zoho_org_id,

                        /*
                        |--------------------------------------------------------------------------
                        | LOCATION FILTER
                        |--------------------------------------------------------------------------
                        */

                        'location_id' =>
                            $orgUser->location_id,

                        'per_page' =>
                            $perPage,

                        'page' =>
                            $page,
                    ],
                ]
            );

            $body = json_decode(
                (string) $response->getBody(),
                true
            );

            $payments =
                $body['customerpayments']
                ?? [];

            /*
            |--------------------------------------------------------------------------
            | STOP IF EMPTY
            |--------------------------------------------------------------------------
            */

            if (empty($payments)) {

                Log::info(
                    'NO MORE PAYMENTS FOUND',
                    [
                        'page' => $page
                    ]
                );

                break;
            }

            /*
            |--------------------------------------------------------------------------
            | PRELOAD RETAILERS
            |--------------------------------------------------------------------------
            */

            $customerIds = collect($payments)
                ->pluck('customer_id')
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            $retailers = Company::whereIn(
                    'zoho_id',
                    $customerIds
                )
                ->get()
                ->keyBy('zoho_id');

            /*
            |--------------------------------------------------------------------------
            | PROCESS PAYMENTS
            |--------------------------------------------------------------------------
            */

            foreach ($payments as $payment) {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | PAYMENT ID
                    |--------------------------------------------------------------------------
                    */

                    $paymentId =
                        $payment['payment_id']
                        ?? null;

                    if (!$paymentId) {

                        $totalFailed++;

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | RETAILER
                    |--------------------------------------------------------------------------
                    */

                    $retailer =
                        $retailers[
                            $payment['customer_id']
                            ?? ''
                        ] ?? null;

                    /*
                    |--------------------------------------------------------------------------
                    | SAFE VALUES
                    |--------------------------------------------------------------------------
                    */

                    $paymentNumber =
                        $payment['payment_number']
                        ?? null;

                    $contactId =
                        $payment['customer_id']
                        ?? null;

                    $locationId =
                        $payment['location_id']
                        ?? null;
                        
                    $locationName =
                        $payment['location_name']
                        ?? null;

                    $customerName =
                        $payment['customer_name']
                        ?? null;

                    $paymentMode =
                        $payment['payment_mode']
                        ?? null;

                    $referenceNumber =
                        $payment['reference_number']
                        ?? null;

                    $paymentDate =
                        $payment['date']
                        ?? null;

                    $amount =
                        (float) (
                            $payment['amount']
                            ?? 0
                        );

                    $unusedAmount =
                        (float) (
                            $payment['unused_amount']
                            ?? 0
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | UPSERT
                    |--------------------------------------------------------------------------
                    */

                    ZohoPayment::updateOrCreate(

                        [
                            'payment_id' =>
                                $paymentId
                        ],

                        [

                            'payment_number' =>
                                $paymentNumber,

                            'z_json' =>
                                json_encode(
                                    $payment,
                                    JSON_UNESCAPED_UNICODE
                                ),

                            'org_id' =>
                                $orgUser->zoho_org_id,

                            'user_id' =>
                                $request->user_id,

                            'company_id' =>
                                $orgUser->id,

                            'contact_id' =>
                                $contactId,

                            /*
                            |--------------------------------------------------------------------------
                            | LOCATION ID SAVED
                            |--------------------------------------------------------------------------
                            */

                            'location_id' =>
                                $locationId,
                                
                            'location_name' =>
                                $locationName,

                            'amount' =>
                                $amount,

                            'unused_amount' =>
                                $unusedAmount,

                            'date' =>
                                $paymentDate,

                            'customer_name' =>
                                $customerName,

                            'description' =>
                                $payment['description']
                                ?? null,

                            'payment_mode' =>
                                $paymentMode,

                            'reference_number' =>
                                $referenceNumber,

                            'created_by' =>
                                $payment['created_by']['name']
                                ?? null,

                            /*
                            |--------------------------------------------------------------------------
                            | RETAILER DATA
                            |--------------------------------------------------------------------------
                            */

                            'org_code' =>
                                $retailer->org_code
                                ?? '',

                            'org_name' =>
                                $retailer->business_name
                                ?? '',

                            'role' =>
                                $retailer->role
                                ?? 0,

                            'level1' =>
                                $retailer->level1
                                ?? 0,

                            'level2' =>
                                $retailer->level2
                                ?? 0,

                            'level3' =>
                                $retailer->level3
                                ?? 0,

                            'level4' =>
                                $retailer->level4
                                ?? 0,

                            'level5' =>
                                $retailer->level5
                                ?? 0,

                            'level1_name' =>
                                $retailer->level1_name
                                ?? '',

                            'level2_name' =>
                                $retailer->level2_name
                                ?? '',

                            'level3_name' =>
                                $retailer->level3_name
                                ?? '',

                            'level4_name' =>
                                $retailer->level4_name
                                ?? '',

                            'level5_name' =>
                                $retailer->level5_name
                                ?? '',
                        ]
                    );

                    $totalSynced++;

                } catch (\Throwable $e) {

                    $totalFailed++;

                    Log::error(

                        'PAYMENT SYNC FAILED',

                        [

                            'payment_id' =>
                                $payment['payment_id']
                                ?? null,

                            'message' =>
                                $e->getMessage(),

                            'line' =>
                                $e->getLine()
                        ]
                    );

                    continue;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | PAGINATION
            |--------------------------------------------------------------------------
            */

            $hasMore =
                $body['page_context']['has_more_page']
                ?? false;

            Log::info(
                'ZOHO PAYMENT PAGE COMPLETED',
                [

                    'page'         => $page,

                    'synced_count' => $totalSynced,

                    'failed_count' => $totalFailed,

                    'has_more'     => $hasMore
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | MEMORY CLEANUP
            |--------------------------------------------------------------------------
            */

            unset($payments);
            unset($body);

            gc_collect_cycles();

            /*
            |--------------------------------------------------------------------------
            | NEXT PAGE
            |--------------------------------------------------------------------------
            */

            $page++;

            /*
            |--------------------------------------------------------------------------
            | SMALL DELAY
            |--------------------------------------------------------------------------
            */

            usleep(150000);

        } while (
            $hasMore &&
            $page <= $maxPages
        );

        /*
        |--------------------------------------------------------------------------
        | SUCCESS
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'status' => true,

            'message' =>
                'Payments synced successfully.',

            'total_synced' =>
                $totalSynced,

            'total_failed' =>
                $totalFailed,

            'pages_processed' =>
                $page - 1,
        ]);

    } catch (\GuzzleHttp\Exception\ClientException $e) {

        $statusCode =
            $e->getResponse()
                ->getStatusCode();

        $errorBody = json_decode(

            $e->getResponse()
                ->getBody()
                ->getContents(),

            true
        );

        Log::error(

            'ZOHO PAYMENT SYNC CLIENT ERROR',

            [

                'message' =>

                    $errorBody['message']
                    ?? $e->getMessage(),

                'status_code' =>
                    $statusCode
            ]
        );

        return response()->json([

            'status' => false,

            'error'  =>

                $errorBody['message']
                ?? $e->getMessage(),

        ], $statusCode);

    } catch (\Throwable $e) {

        Log::error(

            'SYNC ALL PAYMENTS FAILED',

            [

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile(),

                'trace' =>
                    substr(
                        $e->getTraceAsString(),
                        0,
                        3000
                    )
            ]
        );

        return response()->json([

            'status' => false,

            'error' =>
                $e->getMessage(),

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