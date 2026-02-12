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

class WarrantyPaymentFlowController extends Controller
{


    public function processWarrantyPayment(Request $request)
    {
    // ✅ Validate required fields (add more if needed)
    $request->validate([
        'payment_id' => 'required',
        'imei1' => 'required',
        'brand_id' => 'required',
        'category_id' => 'required',
        'product_id' => 'required',
        'company_id' => 'required',
        'amount' => 'required|numeric'
    ]);

    // ✅ Log callback
    WarrantyFlowLog::create([
        'payment_id' => $request->payment_id,
        'step' => 'CALLBACK_RECEIVED',
        'status' => 1,
        'request_data' => json_encode($request->all())
    ]);

    // ✅ Send ALL required device fields to Job
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

    // ✅ Dispatch Queue Job
    WarrantyPaymentFlowJob::dispatch($payload);

    return response()->json([
        'status' => true,
        'message' => 'Processing started'
    ]);
}

/*
    public function createWarrantyInvoice($device,$company_id,$retailer_id,$product_id,$payment_id,$amount) 
    {
        try {
    
            // ==========================
            // SELLER COMPANY (ZOHO ORG)
            // ==========================
    
            $company = Company::find($company_id);
    
            if (!$company) {
                throw new \Exception('Company not found');
            }
    
            if (!$company->zoho_access_token || !$company->zoho_org_id) {
                throw new \Exception('Company Zoho credentials missing');
            }
    
            // ==========================
            // RETAILER (ZOHO CUSTOMER)
            // ==========================
    
            $retailer = Company::find($retailer_id);
    
            if (!$retailer || !$retailer->zoho_id) {
                throw new \Exception('Retailer Zoho contact id missing');
            }
    
            // ==========================
            // PRODUCT ITEM MAPPING
            // ==========================
    
            $companyProduct = CompanyProduct::where('company_id', $company_id)
                ->where('product_id', $product_id)
                ->first();
    
            if (!$companyProduct || !$companyProduct->zoho_item_id) {
                throw new \Exception('Zoho item id missing for company product mapping');
            }
    
            // ==========================
            // BUILD INVOICE
            // ==========================
    
            $payload = [
    
                // CUSTOMER = RETAILER
                'customer_id' => $retailer->zoho_id,
    
                'reference_number' => 'WTY-' . $device->id . '-' . $payment_id,
    
                'date' => now()->toDateString(),
    
                'line_items' => [
                    [
                        'item_id' => $companyProduct->zoho_item_id,
    
                        'name' => $device->product_name ?? 'Warranty Activation',
    
                        'rate' => $device->product_price > 0
                            ? $device->product_price
                            : $amount,
    
                        'quantity' => 1
                    ]
                ]
            ];
    
            // ==========================
            // SEND TO ZOHO
            // ==========================
    
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
    
            if (empty($body['invoice'])) {
                throw new \Exception(json_encode($body));
            }
    
            return [
                'success' => true,
                'invoice' => $body['invoice']
            ];
    
        } catch (\Exception $e) {
    
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
*/
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
    
            $company = Company::find($company_id);
    
            if (!$company) {
                throw new \Exception('Company not found');
            }
    
            if (!$company->zoho_access_token || !$company->zoho_org_id) {
                throw new \Exception('Company Zoho credentials missing');
            }
    
            $retailer = Company::find($retailer_id);
    
            if (!$retailer || !$retailer->zoho_id) {
                throw new \Exception('Retailer Zoho contact id missing');
            }
    
            $customer = \App\Models\WCustomer::find($device->w_customer_id);
    
            if (!$customer) {
                throw new \Exception('Warranty customer not found');
            }
    
         $customerDetails =
            ($customer->name ?? '-') . "\n"
            . 'Price: ' . ($device->product_price ?? '-') . "\n"
            . 'Warranty ID: ' . ($device->w_code ?? '-');
    
            $companyProduct = CompanyProduct::where('company_id', $company_id)
                ->where('product_id', $product_id)
                ->first();
    
            if (!$companyProduct || !$companyProduct->zoho_item_id) {
                throw new \Exception('Zoho item id missing for company product mapping');
            }
    
            $payload = [
    
                'customer_id' => $retailer->zoho_id,
    
                'reference_number' => 'WTY-' . $device->id . '-' . $payment_id,
    
                'date' => now()->toDateString(),
    
                // 👇 Appears in invoice footer
                'notes' => $customerDetails,
                
                'is_inclusive_tax' =>true,
    
                'line_items' => [
                    [
                        'item_id' => $companyProduct->zoho_item_id,
    
                        'name' => $device->product_name ?? 'Warranty Activation',
    
                        // 👇 NOW FULL DETAILS INSIDE LINE ITEM
                        'description' => $customerDetails,
    
                        'rate' => $device->product_price > 0
                            ? $device->product_price
                            : $amount,
    
                        'quantity' => 1
                    ]
                ]
            ];
    
            $client = new \GuzzleHttp\Client();
    
            $response = $client->post(
                'https://www.zohoapis.in/books/v3/invoices',
                [
                    'headers' => [
                        'Authorization' =>
                            'Zoho-oauthtoken ' . $company->zoho_access_token
                    ],
                    'query' => [
                        'organization_id' => $company->zoho_org_id
                    ],
                    'json' => $payload
                ]
            );
    
            $body = json_decode($response->getBody(), true);
    
            if (empty($body['invoice'])) {
                throw new \Exception(json_encode($body));
            }
    
            return [
                'success' => true,
                'invoice' => $body['invoice']
            ];
    
        } catch (\Exception $e) {
    
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function createZohoPayment($company_id,$retailer_id,$payment_id,$amount,$invoiceId)
    {
        $company = \App\Models\Company::find($company_id);

        if (!$company || !$company->zoho_access_token) {
            throw new \Exception('Zoho org credentials missing');
        }
        
        // Retailer is Zoho customer
        $retailer = \App\Models\Company::find($retailer_id);
        
        if (!$retailer || !$retailer->zoho_id) {
            throw new \Exception('Retailer Zoho contact id missing');
        }
        
        $paymentData = [
            "customer_id" => $retailer->zoho_id,
            "amount" => $amount,
            "reference_number" => $payment_id,
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

   
    return json_decode($response->getBody(), true);
    }
    
   public function sendZohoInvoice($company_id, $invoiceId)
   {
        $company = \App\Models\Company::find($company_id);
    
        if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
            throw new \Exception('Zoho org credentials missing');
        }
    
        $client = new \GuzzleHttp\Client();
    
        $response = $client->post(
            "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}/status/sent",
            [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
                ],
                'query' => [
                    'organization_id' => $company->zoho_org_id
                ]
            ]
        );
    
        return json_decode($response->getBody(), true);
    }
    
    public function updateExistingWarrantyPayment(Request $request)
{
    // ==========================
    // VALIDATION
    // ==========================

    $request->validate([
        'payment_id'   => 'required',
        'device_id'    => 'required|exists:w_devices,id',
        'amount'       => 'required|numeric|min:1',
        'company_id'   => 'required',
        'retailer_id'  => 'required'
    ]);

    DB::beginTransaction();

    try {

        // ==========================
        // STEP 1: FETCH DEVICE
        // ==========================

        $device = WDevice::lockForUpdate()->find($request->device_id);

        if (!$device) {
            throw new \Exception('Device not found');
        }

        // Prevent duplicate payment
        if ($device->payment_status == 1) {
            return response()->json([
                'status' => true,
                'message' => 'Payment already completed'
            ]);
        }

        // ==========================
        // STEP 2: LOG REQUEST
        // ==========================

        WarrantyFlowLog::create([
            'payment_id' => $request->payment_id,
            'device_id' => $device->id,
            'step' => 'UPDATE_PAYMENT_CALLBACK',
            'status' => 1,
            'request_data' => json_encode($request->all())
        ]);

        // ==========================
        // STEP 3: CREATE / UPDATE ZOHO INVOICE
        // ==========================

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

        WarrantyFlowLog::create([
            'payment_id' => $request->payment_id,
            'device_id' => $device->id,
            'invoice_id' => $invoiceId,
            'step' => 'UPDATE_INVOICE_CREATED',
            'status' => 1,
            'response_data' => json_encode($invoiceResponse)
        ]);

        // ==========================
        // STEP 4: CAPTURE RAZORPAY
        // ==========================

        $razorClient = new \GuzzleHttp\Client();

        $razorResponse = $razorClient->post(
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

        $razorBody = json_decode($razorResponse->getBody(), true);

        WarrantyFlowLog::create([
            'payment_id' => $request->payment_id,
            'step' => 'UPDATE_RAZORPAY_CAPTURED',
            'status' => 1,
            'response_data' => json_encode($razorBody)
        ]);

        // ==========================
        // STEP 5: CREATE ZOHO PAYMENT
        // ==========================

        $zohoPayment = $this->createZohoPayment(
            $request->company_id,
            $request->retailer_id,
            $request->payment_id,
            $request->amount,
            $invoiceId
        );

        WarrantyFlowLog::create([
            'payment_id' => $request->payment_id,
            'invoice_id' => $invoiceId,
            'zoho_payment_id' => $zohoPayment['payment']['payment_id'] ?? null,
            'step' => 'UPDATE_ZOHO_PAYMENT_CREATED',
            'status' => 1,
            'response_data' => json_encode($zohoPayment)
        ]);

        // ==========================
        // STEP 6: UPDATE DEVICE RECORD
        // ==========================

        $device->update([
            'payment_status' => 1,
            'zoho_invoice_id' => $invoiceId,
            'zoho_payment_id' => $zohoPayment['payment']['payment_id'] ?? null,
            'razorpay_payment_id' => $request->payment_id,
            'paid_at' => now()
        ]);

        DB::commit();

        // ==========================
        // STEP 7: EVENT
        // ==========================

        event(new PaymentSuccessful($device));

        return response()->json([
            'status' => true,
            'message' => 'Warranty payment updated successfully'
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

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
    $company = Company::find($company_id);

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

}