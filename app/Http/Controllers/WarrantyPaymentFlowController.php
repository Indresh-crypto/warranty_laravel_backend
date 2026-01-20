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


    public function createWarrantyInvoice(
        $device,
        $company_id,
        $retailer_id,
        $product_id,
        $payment_id,
        $amount
    ) {
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

}