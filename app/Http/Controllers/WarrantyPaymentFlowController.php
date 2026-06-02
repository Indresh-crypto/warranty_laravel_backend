<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\WDevice;
use App\Models\WCustomer;
use App\Models\CompanyProduct;
use App\Models\Company;
use App\Models\WarrantyFlowLog;
use App\Models\ZohoInvoice;
use App\Models\OnlinePayment;
use App\Events\PaymentSuccessful;
use App\Jobs\WarrantyPaymentFlowJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Services\WhatsappService;
use App\Mail\InvoiceCreatedMail;
use App\Mail\PaymentCompletedMail;
use GuzzleHttp\Client;

class WarrantyPaymentFlowController extends Controller
{


    public function processWarrantyPayment(Request $request)
    {
    // Validate required fields (add more if needed)
    $request->validate([
        'payment_id' => 'required',
        'imei1' => 'required',
        'brand_id' => 'required',
        'category_id' => 'required',
        'product_id' => 'required',
        'company_id' => 'required',
        'amount' => 'required|numeric'
    ]);

    // Log callback
    WarrantyFlowLog::create([
        'payment_id' => $request->payment_id,
        'step' => 'CALLBACK_RECEIVED',
        'status' => 1,
        'request_data' => json_encode($request->all())
    ]);

    // Send ALL required device fields to Job
    $payload = $request->only([

        // Payment
        'payment_id',
        'amount',

        // Device info
        'name',
        'imei1',
        'imei2',
        'serial',

        // Product mapping
        'brand_id',
        'category_id',
        'product_id',

        // Names
        'product_name',
        'brand_name',
        'category_name',
        'model',

        // Warranty
        'available_claim',
        'expiry_date',

        // Relations
        'w_customer_id',
        'retailer_id',
        'agent_id',

        // Files & links
        'document_url',
        'link1',
        'link2',

        // Pricing
        'device_price',
        'product_price',
        'product_mrp',

        // Payouts
        'retailer_payout',
        'employee_payout',
        'other_payout',
        'company_payout',

        // Company
        'company_id',

        // Meta
        'created_by',

        // Zoho
        'zoho_product_id'
    ]);

    WarrantyPaymentFlowJob::dispatch($payload);

    return response()->json([
        'status' => true,
        'message' => 'Processing started'
    ]);
}

    
    public function createWarrantyInvoice(
    $device,
    $company_id,
    $retailer_id,
    $product_id,
    $payment_id,
    $amount
    ) 
    {
        try {
    
    
            // -------------------------------
            // COMPANY
            // -------------------------------
            $company = Company::find(1);
    
            if (!$company) {
                throw new \Exception('Company not found');
            }
    
            if (!$company->zoho_access_token || !$company->zoho_org_id) {
                throw new \Exception('Company Zoho credentials missing');
            }
    
   
            $retailer = Company::where('id', $retailer_id)->first();
    
            if (!$retailer) {
                throw new \Exception('Retailer not found');
            }
    
            if (!$retailer->zoho_id) {
                throw new \Exception('Retailer Zoho contact id missing');
            }
    
            // -------------------------------
            // CUSTOMER
            // -------------------------------
            $customer = \App\Models\WCustomer::find($device->w_customer_id);
    
            if (!$customer) {
                throw new \Exception('Warranty customer not found');
            }
    
            $customerDetails =
                ($customer->name ?? '-') . "\n"
                . 'Price: ' . ($device->product_price ?? '-') . "\n"
                . 'Warranty ID: ' . ($device->w_code ?? '-');
    
            // -------------------------------
            // PRODUCT
            // -------------------------------
            $companyProduct = CompanyProduct::where('company_id', $company_id)
                ->where('product_id', $product_id)
                ->first();
    
            if (!$companyProduct || !$companyProduct->zoho_item_id) {
                throw new \Exception('Zoho item id missing');
            }
    
            // -------------------------------
            // ZOHO PAYLOAD
            // -------------------------------
            $payload = [
                'customer_id' => $retailer->zoho_id,
                'reference_number' => 'WTY-' . $device->id . '-' . $payment_id,
                'date' => now()->toDateString(),
                'notes' => $customerDetails,
                'is_inclusive_tax' => true,
                'location_id'=> $company->location_id,
                'line_items' => [
                    [
                        'item_id' => $companyProduct->zoho_item_id,
                        'name' => $device->product_name ?? 'Warranty Activation',
                        'description' => $customerDetails,
                        'rate' => $device->product_price > 0 ? $device->product_price : $amount,
                        'quantity' => 1
                    ]
                ]
            ];
    
            // -------------------------------
            // API CALL
            // -------------------------------
            $client = new \GuzzleHttp\Client();
    
            $response = $client->post(
                'https://www.zohoapis.in/books/v3/invoices',
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
                    ],
                    'query' => [
                        'organization_id' => $company->zoho_org_id
                    ],
                    'json' => $payload
                ]
            );
    
            $body = json_decode($response->getBody(), true);
    
    
            // -------------------------------
            // VALIDATE INVOICE
            // -------------------------------
            if (empty($body['invoice'])) {
                throw new \Exception('Invoice creation failed: ' . json_encode($body));
            }
    
            // -------------------------------
            // SEND MAIL (AFTER SUCCESS)
            // -------------------------------
            if (!empty($retailer->contact_email)) {
    
                try {
    
                    Log::info('SENDING MAIL', [
                        'email' => $retailer->contact_email
                    ]);
    
                    Mail::to($retailer->contact_email)
                        ->queue(
                            (new InvoiceCreatedMail(
                                $body,
                                $body['invoice_url'] ?? '#'
                            ))->onQueue('emails')
                        );
    
                    Mail::to($retailer->contact_email)
                        ->queue(
                            (new PaymentCompletedMail(
                                $device->fresh(['customer'])
                            ))->onQueue('emails')
                        );
    
                    Log::info('MAIL QUEUED SUCCESS');
    
    //sync payment and invoice
    
    
    //end code
                } catch (\Throwable $e) {
    
                    Log::error('MAIL FAILED', [
                        'error' => $e->getMessage()
                    ]);
                }
    
            } else {
    
                Log::warning('NO EMAIL FOUND', [
                    'retailer_id' => $retailer->id
                ]);
            }
    
            return [
                'success' => true,
                'invoice' => $body['invoice']
            ];
    
        } catch (\Exception $e) {
    
            Log::error('INVOICE FLOW FAILED', [
                'error' => $e->getMessage()
            ]);
    
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function createZohoPayment($company_id,$retailer_id,$payment_id,$amount,$invoiceId)
   {
    $company = \App\Models\Company::find(1);

    if (!$company || !$company->zoho_access_token) {
        throw new \Exception('Zoho org credentials missing');
    }

    $retailer = \App\Models\Company::find($retailer_id);

    if (!$retailer || !$retailer->zoho_id) {
        throw new \Exception('Retailer Zoho contact id missing');
    }

        $paymentData = [
            'payment_mode' => 'RZ WM',
            "customer_id" => $retailer->zoho_id,
            "amount" => $amount,
            "reference_number" => $payment_id,
            "location_id"=> $company->location_id,
            "invoices" => [
                [
                    "invoice_id" => $invoiceId,
                    "amount_applied" => $amount
                ]
            ]
        ];
    

    
    $client = new \GuzzleHttp\Client();

    $response = $client->post(
        "https://www.zohoapis.in/books/v3/customerpayments",
        [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
            ],
            'query' => [
                'organization_id' => $company->zoho_org_id
            ],
            'json' => $paymentData
        ]
    );

    $zohoResponse = json_decode($response->getBody(), true);

    $zohoPayment = $zohoResponse['payment'] ?? null;

    if (!$zohoPayment) {
        throw new \Exception('Zoho payment failed');
    }

    /*
    |--------------------------------------------------------------------------
    | SEND WHATSAPP PAYMENT SUCCESS
    |--------------------------------------------------------------------------
    */

    try {
    /*
        app(\App\Services\WhatsappService::class)
            ->paymentSuccessWhatsapp(
                $retailer,
                $zohoPayment,
                $amount,
                $payment_id
            );
    */
    } catch (\Throwable $e) {

        \Log::error('ADVANCE PAYMENT WHATSAPP FAILED', [
            'retailer_id' => $retailer->id,
            'error' => $e->getMessage()
        ]);
    }

    return $zohoResponse;
}
    

    public function sendZohoInvoice($company_id, $invoiceId)
    {
        $company = \App\Models\Company::find(1);
    
        if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
            throw new \Exception('Zoho org credentials missing');
        }
    
        $client = new \GuzzleHttp\Client();
    
        $headers = [
            'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
        ];
    
        $query = [
            'organization_id' => $company->zoho_org_id
        ];
    
        $baseUrl = "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}";
    
        // Step 1: Approve (safe)
        try {
            $client->post("{$baseUrl}/approve", [
                'headers' => $headers,
                'query'   => $query
            ]);
        } catch (\Exception $e) {
            // ignore if already approved
            \Log::info('Zoho approve skipped', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage()
            ]);
        }
    
        // Step 2: Send
        $response = $client->post("{$baseUrl}/status/sent", [
            'headers' => $headers,
            'query'   => $query
        ]);
    
        return json_decode($response->getBody(), true);
    }
    public function updateExistingWarrantyPayment(Request $request)
    {
        $request->validate([
            'payment_id'   => 'required',
            'device_id'    => 'required|exists:w_devices,id',
            'amount'       => 'required|numeric|min:1',
            'company_id'   => 'required',
            'retailer_id'  => 'required'
        ]);
    
        try {
    
            DB::beginTransaction();
    
            $device = WDevice::lockForUpdate()->find($request->device_id);
    
            if (!$device) {
                throw new \Exception('Device not found');
            }
    
            if ($device->payment_status == 1) {
                DB::commit();
                return response()->json([
                    'status' => true,
                    'message' => 'Payment already completed'
                ]);
            }
    
            WarrantyFlowLog::create([
                'payment_id' => $request->payment_id,
                'device_id' => $device->id,
                'step' => 'UPDATE_PAYMENT_CALLBACK',
                'status' => 1,
                'request_data' => json_encode($request->all())
            ]);
    
            DB::commit(); // ✅ COMMIT EARLY
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    
        /*
        |--------------------------------------------------------------------------
        | AFTER COMMIT → DO HEAVY WORK
        |--------------------------------------------------------------------------
        */
    
        try {
    
            // STEP 3: CREATE INVOICE
            $invoiceResponse = $this->createWarrantyInvoice(
                $device,
                $request->company_id,
                $request->retailer_id,
                $device->product_id,
                $request->payment_id,
                $request->amount
            );
    
            if (!$invoiceResponse['success']) {
                throw new \Exception($invoiceResponse['message']);
            }
    
            $invoiceId = $invoiceResponse['invoice']['invoice_id'];
    
            // STEP 4: CAPTURE RAZORPAY
            $razorClient = new \GuzzleHttp\Client();
    
            $razorClient->post(
                "https://api.razorpay.com/v1/payments/{$request->payment_id}/capture",
                [
                    'auth' => [
                        config('services.razorpay.razorpay_key'),
                        config('services.razorpay.razorpay_secret'),
                    ],
                    'json' => [
                        'amount' => $request->amount * 100,
                        'currency' => 'INR'
                    ]
                ]
            );
    
            // STEP 5: CREATE ZOHO PAYMENT
            $zohoPayment = $this->createZohoPayment(
                $request->company_id,
                $request->retailer_id,
                $request->payment_id,
                $request->amount,
                $invoiceId
            );
    
            // STEP 6: UPDATE DEVICE
            $device->update([
                'payment_status' => 1,
                'zoho_invoice_id' => $invoiceId,
                'zoho_payment_id' => $zohoPayment['payment']['payment_id'] ?? null,
                'razorpay_payment_id' => $request->payment_id,
                'paid_at' => now()
            ]);
    
            // STEP 7: EVENT
            event(new PaymentSuccessful($device));
    
            return response()->json([
                'status' => true,
                'message' => 'Warranty payment updated successfully'
            ]);
    
        } catch (\Exception $e) {
    
            WarrantyFlowLog::create([
                'payment_id' => $request->payment_id,
                'device_id' => $request->device_id,
                'step' => 'UPDATE_PAYMENT_FAILED',
                'status' => 0,
                'error_message' => $e->getMessage()
            ]);
    
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

public function updateZohoInvoice($company_id, $invoiceId, $lineItems)
{
    $company = Company::find(1);

    if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
        throw new \Exception('Zoho credentials missing');
    }

    $client = new \GuzzleHttp\Client();

    $response = $client->put(
        "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}",
        [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token,
                'Content-Type'  => 'application/json'
            ],
            'query' => [
                'organization_id' => $company->zoho_org_id
            ],
            'json' => [
                'line_items' => $lineItems
            ]
        ]
    );

    return json_decode($response->getBody(), true);
}

    
    public function registerWarrantyWithAutoCredit(Request $request)
    {
        $request->validate([
    
            // Device identifiers
            'imei1'            => 'required|string',
            'imei2'            => 'nullable|string',
            'serial'           => 'nullable|string',
    
            // Product mapping
            'brand_id'         => 'required|integer',
            'category_id'      => 'required|integer',
            'product_id'       => 'required|integer',
            'model_id'         => 'required|integer',
    
            // Names
            'product_name'     => 'required|string',
            'brand_name'       => 'required|string',
            'category_name'    => 'required|string',
            'model'            => 'required|string',
    
            // Warranty config
            'available_claim'  => 'required|integer',
            'expiry_date'      => 'required|date',
    
            // Relations
            'w_customer_id'    => 'required|integer',
            'retailer_id'      => 'required|integer',
            'company_id'       => 'required|integer',
            'agent_id'         => 'nullable|integer',
            'created_by'       => 'nullable|integer',
    
            // Files
            'document_url'     => 'nullable|string',
            'link1'            => 'nullable|string',
            'link2'            => 'nullable|string',
    
            // Pricing
            'device_price'     => 'required|numeric|min:0',
            'product_price'    => 'required|numeric|min:0',
            'product_mrp'      => 'required|numeric|min:0',
    
            // Payouts
            'retailer_payout'  => 'required|numeric|min:0',
            'employee_payout'  => 'required|numeric|min:0',
            'other_payout'     => 'required|numeric|min:0',
            'company_payout'   => 'required|numeric|min:0',
    
            // Pay later
            'is_pay_later'     => 'required|boolean',
        ]);
    
        $payload = $request->all();
    
        \App\Jobs\WarrantyCreditFlowJob::dispatch($payload);
    
        return response()->json([
            'status'  => true,
            'message' => 'Warranty registration with credit processing started'
        ]);
    }
    public function createSubscriptionInvoice(
        $subscription,
        $company_id,
        $retailer_id,
        $product_id,
        $payment_id,
        $amount
        ) 
    {
        try {
    
    
            // -------------------------------
            // COMPANY
            // -------------------------------
            $company = Company::find(1);
    
            if (!$company) {
                throw new \Exception('Company not found');
            }
    
            if (!$company->zoho_access_token || !$company->zoho_org_id) {
                throw new \Exception('Company Zoho credentials missing');
            }
    
   
            $retailer = Company::where('id', $retailer_id)->first();
    
            if (!$retailer) {
                throw new \Exception('Retailer not found');
            }
    
            if (!$retailer->zoho_id) {
                throw new \Exception('Retailer Zoho contact id missing');
            }
    
            // -------------------------------
            // CUSTOMER
            // -------------------------------
           
            $customerDetails =
                ($customer->name ?? '-') . "\n"
                . 'Price: ' . ($subscription->amount ?? '-') . "\n"
                . 'Sub. ID: ' . ($subscription->id ?? '-');
    
            // -------------------------------
            // PRODUCT
            // -------------------------------
            $companyProduct = CompanyProduct::where('company_id', $company_id)
                ->where('product_id', $product_id)
                ->first();
    
            if (!$companyProduct || !$companyProduct->zoho_item_id) {
                throw new \Exception('Zoho item id missing');
            }
    
            // -------------------------------
            // ZOHO PAYLOAD
            // -------------------------------
            $payload = [
                'location_id'=> $company->location_id,
                'customer_id' => $retailer->zoho_id,
                'reference_number' => 'SUB-' . $subscription->id . '-' . $payment_id,
                'date' => now()->toDateString(),
                'is_inclusive_tax' => true,
                'line_items' => [
                    [
                        'item_id' => $companyProduct->zoho_item_id,
                        'name' => $subscription->package_name ?? 'Subscription',
                        'rate' => $subscription->amount > 0 ? $subscription->amount : $amount,
                        'quantity' => 1
                    ]
                ]
            ];
    
            // -------------------------------
            // API CALL
            // -------------------------------
            $client = new \GuzzleHttp\Client();
    
            $response = $client->post(
                'https://www.zohoapis.in/books/v3/invoices',
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
                    ],
                    'query' => [
                        'organization_id' => $company->zoho_org_id
                    ],
                    'json' => $payload
                ]
            );
    
            $body = json_decode($response->getBody(), true);
    
    
            // -------------------------------
            // VALIDATE INVOICE
            // -------------------------------
            if (empty($body['invoice'])) {
                throw new \Exception('Invoice creation failed: ' . json_encode($body));
            }
    
            // -------------------------------
            // SEND MAIL (AFTER SUCCESS)
            // -------------------------------
            if (!empty($retailer->contact_email)) {
    
                try {
    
                    Log::info('SENDING MAIL', [
                        'email' => $retailer->contact_email
                    ]);
    
                    Mail::to($retailer->contact_email)
                        ->queue(
                            (new InvoiceCreatedMail(
                                $body,
                                $body['invoice_url'] ?? '#'
                            ))->onQueue('emails')
                        );
    /*
                    Mail::to($retailer->contact_email)
                        ->queue(
                            (new PaymentCompletedMail(
                                $device->fresh(['customer'])
                            ))->onQueue('emails')
                        );
    */
                    Log::info('MAIL QUEUED SUCCESS');
    
    //sync payment and invoice
    
    
    //end code
                } catch (\Throwable $e) {
    
                    Log::error('MAIL FAILED', [
                        'error' => $e->getMessage()
                    ]);
                }
    
            } else {
    
                Log::warning('NO EMAIL FOUND', [
                    'retailer_id' => $retailer->id
                ]);
            }
    
            return [
                'success' => true,
                'invoice' => $body['invoice']
            ];
    
        } catch (\Exception $e) {
    
            Log::error('INVOICE FLOW FAILED', [
                'error' => $e->getMessage()
            ]);
    
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function approveZohoInvoice(
    $companyId,
    $invoiceId,
    $customerEmail = null
    ) {

    try {

        // =====================================================
        // COMPANY
        // =====================================================

        $company_id = 1;
        $company = Company::find(1);

        if (
            !$company ||
            !$company->zoho_access_token ||
            !$company->zoho_org_id
        ) {

            throw new \Exception(
                'Company Zoho credentials missing'
            );
        }

        // =====================================================
        // CLIENT
        // =====================================================

        $client = new \GuzzleHttp\Client();

        // =====================================================
        // BODY
        // =====================================================

        $body = [


            'to_mail_ids' => [

                $customerEmail
                    ?? 'support@warrantymitra.com'
            ],

           
        ];

        // =====================================================
        // API CALL
        // =====================================================

        $response = $client->post(

            "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}/approve",

            [

                'headers' => [

                    'Authorization' =>

                        'Zoho-oauthtoken ' .
                        $company->zoho_access_token,

                    'Content-Type' =>
                        'application/json'
                ],

                'query' => [

                    'organization_id' =>
                        $company->zoho_org_id
                ],

                'json' => $body
            ]
        );

        $responseBody = json_decode(
            $response->getBody(),
            true
        );

        \Log::info(
            'ZOHO INVOICE APPROVED',
            [

                'invoice_id' =>
                    $invoiceId,

                'response' =>
                    $responseBody
            ]
        );

        return [

            'success' => true,

            'response' => $responseBody
        ];

    } catch (\Throwable $e) {

        \Log::error(
            'ZOHO INVOICE APPROVE FAILED',
            [

                'invoice_id' =>
                    $invoiceId,

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]
        );

        return [

            'success' => false,

            'message' => $e->getMessage()
        ];
    }
}
//

public function createSubscriptionInvoiceWithWallet(
    $subscription,
    $company_id,
    $retailer_id,
    $product_id,
    $payment_id,
    $amount
  ) {

    try {

        /*
        |--------------------------------------------------------------------------
        | COMPANY
        |--------------------------------------------------------------------------
        */

        $company = Company::find(1);

        if (!$company) {

            throw new \Exception(
                'Company not found'
            );
        }

        if (
            !$company->zoho_access_token ||
            !$company->zoho_org_id
        ) {

            throw new \Exception(
                'Company Zoho credentials missing'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | RETAILER
        |--------------------------------------------------------------------------
        */

        $retailer = Company::find($retailer_id);

        if (!$retailer) {

            throw new \Exception(
                'Retailer not found'
            );
        }

        if (!$retailer->zoho_id) {

            throw new \Exception(
                'Retailer Zoho contact id missing'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PRODUCT
        |--------------------------------------------------------------------------
        */

        $companyProduct = CompanyProduct::where(
                'company_id',
                $company_id
            )
            ->where(
                'product_id',
                $product_id
            )
            ->first();

        if (
            !$companyProduct ||
            !$companyProduct->zoho_item_id
        ) {

            throw new \Exception(
                'Zoho item id missing'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | COMMON CLIENT
        |--------------------------------------------------------------------------
        */

        $client = new \GuzzleHttp\Client();

        /*
        |--------------------------------------------------------------------------
        | INVOICE PAYLOAD
        |--------------------------------------------------------------------------
        */

        $payload = [

            'location_id' =>
                $company->location_id,

            'customer_id' =>
                $retailer->zoho_id,

            'reference_number' =>

                'SUB-' .
                $subscription->id .
                '-' .
                $payment_id,

            'date' =>
                now()->toDateString(),

            'is_inclusive_tax' => true,

            'line_items' => [

                [

                    'item_id' =>
                        $companyProduct->zoho_item_id,

                    'name' =>
                        $subscription->package_name
                        ?? 'Subscription',

                    'rate' =>

                        $subscription->amount > 0
                        ? $subscription->amount
                        : $amount,

                    'quantity' => 1
                ]
            ]
        ];

        /*
        |--------------------------------------------------------------------------
        | CREATE INVOICE
        |--------------------------------------------------------------------------
        */

        $response = $client->post(
            'https://www.zohoapis.in/books/v3/invoices',
            [

                'headers' => [

                    'Authorization' =>

                        'Zoho-oauthtoken ' .
                        $company->zoho_access_token,

                    'Content-Type' =>
                        'application/json'
                ],

                'query' => [

                    'organization_id' =>
                        $company->zoho_org_id
                ],

                'json' => $payload
            ]
        );

        $body = json_decode(
            $response->getBody(),
            true
        );

        /*
        |--------------------------------------------------------------------------
        | VALIDATE INVOICE
        |--------------------------------------------------------------------------
        */

        if (empty($body['invoice'])) {

            throw new \Exception(

                'Invoice creation failed: ' .
                json_encode($body)
            );
        }

        $invoice =
            $body['invoice'];

        $invoiceId =
            $invoice['invoice_id'];

        Log::info(
            'SUBSCRIPTION INVOICE CREATED',
            [

                'invoice_id' =>
                    $invoiceId,

                'response' =>
                    $body
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | APPROVE INVOICE
        |--------------------------------------------------------------------------
        */

        try {

            $approveResponse = $client->post(

                "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}/approve",

                [

                    'headers' => [

                        'Authorization' =>

                            'Zoho-oauthtoken ' .
                            $company->zoho_access_token,

                        'Content-Type' =>
                            'application/json'
                    ],

                    'query' => [

                        'organization_id' =>
                            $company->zoho_org_id
                    ],

                    'json' => [

                        'send_from_org_email_id' => false
                    ]
                ]
            );

            $approveBody = json_decode(
                $approveResponse->getBody(),
                true
            );

            Log::info(
                'INVOICE APPROVED',
                [

                    'invoice_id' =>
                        $invoiceId,

                    'response' =>
                        $approveBody
                ]
            );

        } catch (\Throwable $e) {

            Log::error(
                'INVOICE APPROVAL FAILED',
                [

                    'invoice_id' =>
                        $invoiceId,

                    'message' =>
                        $e->getMessage()
                ]
            );

            throw $e;
        }

       /*
|--------------------------------------------------------------------------
| APPLY CUSTOMER CREDIT TO INVOICE
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | GET CUSTOMER ADVANCE PAYMENTS
    |--------------------------------------------------------------------------
    */

    $paymentListResponse = $client->get(

        'https://www.zohoapis.in/books/v3/customerpayments',

        [

            'headers' => [

                'Authorization' =>

                    'Zoho-oauthtoken ' .
                    $company->zoho_access_token
            ],

            'query' => [

                'organization_id' =>
                    $company->zoho_org_id,

                'customer_id' =>
                    $retailer->zoho_id
            ]
        ]
    );

    $paymentListBody = json_decode(
        $paymentListResponse->getBody(),
        true
    );

    $payments =
        $paymentListBody['customerpayments']
        ?? [];

    if (empty($payments)) {

        throw new \Exception(
            'No customer advance payment found'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIND UNUSED / PARTIAL PAYMENT
    |--------------------------------------------------------------------------
    */

    $paymentId = null;

    foreach ($payments as $payment) {

        $unusedAmount = (float)(
            $payment['unused_amount']
            ?? 0
        );

        if ($unusedAmount >= (float)$amount) {

            $paymentId =
                $payment['payment_id'];

            break;
        }
    }

    if (!$paymentId) {

        throw new \Exception(
            'No sufficient unused wallet credit found'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | APPLY CREDIT
    |--------------------------------------------------------------------------
    */

    $creditPayload = [

        'invoice_payments' => [

            [

                'payment_id' =>
                    $paymentId,

                'amount_applied' =>
                    (float) $amount
            ]
                ],
        
                'apply_creditnotes' => []
            ];
        
            Log::info(
                'APPLYING CREDIT TO INVOICE',
                [
        
                    'invoice_id' =>
                        $invoiceId,
        
                    'payload' =>
                        $creditPayload
                ]
            );
        
            $creditResponse = $client->post(
        
                "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}/credits",
        
                [
        
                    'headers' => [
        
                        'Authorization' =>
        
                            'Zoho-oauthtoken ' .
                            $company->zoho_access_token,
        
                        'Content-Type' =>
                            'application/json'
                    ],
        
                    'query' => [
        
                        'organization_id' =>
                            $company->zoho_org_id
                    ],
        
                    'json' =>
                        $creditPayload
                ]
            );
        
            $creditBody = json_decode(
                $creditResponse->getBody(),
                true
            );
        
            Log::info(
                'INVOICE CREDIT APPLIED SUCCESS',
                [
        
                    'invoice_id' =>
                        $invoiceId,
        
                    'payment_id' =>
                        $paymentId,
        
                    'response' =>
                        $creditBody
                ]
            );
        
            /*
            |--------------------------------------------------------------------------
            | UPDATE LOCAL INVOICE STATUS
            |--------------------------------------------------------------------------
            */
        
            $invoice['status'] = 'paid';
        
            $invoice['wallet_payment_id'] =
                $paymentId;
        
        } catch (\Throwable $e) {
        
            Log::error(
                'INVOICE CREDIT APPLY FAILED',
                [
        
                    'invoice_id' =>
                        $invoiceId,
        
                    'message' =>
                        $e->getMessage(),
        
                    'line' =>
                        $e->getLine(),
        
                    'file' =>
                        $e->getFile()
                ]
            );
        
            throw $e;
        }

        /*
        |--------------------------------------------------------------------------
        | FETCH UPDATED INVOICE
        |--------------------------------------------------------------------------
        */

        try {

            $invoiceFetchResponse = $client->get(

                "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}",

                [

                    'headers' => [

                        'Authorization' =>

                            'Zoho-oauthtoken ' .
                            $company->zoho_access_token
                    ],

                    'query' => [

                        'organization_id' =>
                            $company->zoho_org_id
                    ]
                ]
            );

            $invoiceFetchBody = json_decode(
                $invoiceFetchResponse->getBody(),
                true
            );

            if (!empty($invoiceFetchBody['invoice'])) {

                $invoice =
                    $invoiceFetchBody['invoice'];
            }

        } catch (\Throwable $e) {

            Log::warning(
                'UPDATED INVOICE FETCH FAILED',
                [

                    'invoice_id' =>
                        $invoiceId,

                    'message' =>
                        $e->getMessage()
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | SEND MAIL
        |--------------------------------------------------------------------------
        */

        if (!empty($retailer->contact_email)) {

            try {

                Log::info(
                    'SENDING INVOICE MAIL',
                    [

                        'email' =>
                            $retailer->contact_email
                    ]
                );

                Mail::to(
                    $retailer->contact_email
                )->queue(

                    (new InvoiceCreatedMail(

                        $invoice,

                        $invoice['invoice_url']
                        ?? '#'

                    ))->onQueue('emails')
                );

                Log::info(
                    'MAIL QUEUED SUCCESS'
                );

            } catch (\Throwable $e) {

                Log::error(
                    'MAIL FAILED',
                    [

                        'message' =>
                            $e->getMessage()
                    ]
                );
            }

        } else {

            Log::warning(
                'NO EMAIL FOUND',
                [

                    'retailer_id' =>
                        $retailer->id
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | RETURN
        |--------------------------------------------------------------------------
        */

        return [

            'success' => true,

            'invoice' => $invoice
        ];

    } catch (\Exception $e) {

        Log::error(
            'INVOICE FLOW FAILED',
            [

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]
        );

        return [

            'success' => false,

            'message' =>
                $e->getMessage()
        ];
    }
}
    //
   
}