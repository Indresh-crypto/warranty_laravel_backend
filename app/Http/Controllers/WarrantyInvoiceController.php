<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Company;
use App\Models\WarrantyProduct;
use App\Models\UploadFile;
use App\Models\CompanyProduct;
use App\Models\PriceTemplate;
use App\Models\WCustomer;
use App\Models\Companies;
use App\Models\WDevice;
use App\Models\Wclaim;
use App\Models\ZohoInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Barryvdh\DomPDF\Facade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;

class WarrantyInvoiceController extends Controller
{
    public function createBulkInvoicesRetailerWise(Request $request)
    {

        $devices = WDevice::with(['product', 'promoter'])
            ->whereNull('invoice_id')
            ->get()
            ->groupBy(['company_id', 'retailer_id']);
    
        if ($devices->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'No pending invoices'
            ]);
        }
    

        $client = new \GuzzleHttp\Client();
        $createdInvoices = [];
    
        foreach ($devices as $companyId => $retailers) {
    
            // 🔐 Company Zoho credentials
           $company = Company::where('id', $companyId)
                  ->where('role', 2)
                  ->first();
    
 
            if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
                continue;
            }
    
            foreach ($retailers as $retailerId => $retailerDevices) {
    
                // 🏪 Retailer must have Zoho contact
                $retailer = Company::find($retailerId);
    
    
                if (!$retailer || !$retailer->zoho_id) {
                    continue;
                }
    
                // 🧾 Merge products into line items
                $lineItems = [];
                $groupedProducts = $retailerDevices->groupBy('product_id');
    
                foreach ($groupedProducts as $productId => $productDevices) {
                    $product = $productDevices->first()->product;

                    $lineItems[] = [
                        "item_id"  => optional(
                            $product->companyProducts()
                                ->where('company_id', $companyId)
                                ->first()
                        )->zoho_item_id,
                        "name"     => $product->name,
                        "rate"     => $productDevices->sum('product_price'),
                        "quantity" => 1,
                    ];
                }
    
                if (empty($lineItems)) {
                    continue;
                }
    
                $payload = [
                    "customer_id" => $retailer->zoho_id,
                    "date"        => now()->format('Y-m-d'),
                    "line_items"  => $lineItems,
                ];
    
                try {
                    $response = $client->post(
                        "https://www.zohoapis.in/books/v3/invoices",
                        [
                            'headers' => [
                                'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token,
                                'Content-Type'  => 'application/json',
                            ],
                            'query' => [
                                'organization_id' => $company->zoho_org_id,
                            ],
                            'json' => $payload,
                        ]
                    );
    
                    $body = json_decode($response->getBody(), true);
    
                    if (!empty($body['invoice']['invoice_id'])) {
    
                        foreach ($retailerDevices as $device) {
                            $device->update([
                                'invoice_id'           => $body['invoice']['invoice_id'],
                                'invoice_created_date' => now(),
                                'invoice_json'         => json_encode($body['invoice']),
                            ]);
                        }
    
                        $createdInvoices[] = [
                            'company_id'  => $companyId,
                            'retailer_id' => $retailerId,
                            'invoice_id'  => $body['invoice']['invoice_id'],
                            'amount'      => $retailerDevices->sum('product_price'),
                        ];
                    }
    
                } catch (\Exception $e) {
                    \Log::error('Invoice creation failed', [
                        'company_id' => $companyId,
                        'retailer_id' => $retailerId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    
        return response()->json([
            'status' => true,
            'message' => 'Bulk invoices created successfully',
            'data' => $createdInvoices
        ]);
    }
    //
    public function cancelWarrantyAndCreateCreditNote(Request $request)
    {
    $request->validate([
        'w_device_id' => 'required|exists:w_devices,id',
        'reason'      => 'nullable|string'
    ]);

    $device = WDevice::find($request->w_device_id);

    // 🚫 Already credited
    if ($device->credit_note) {
        return response()->json([
            'status' => false,
            'message' => 'Credit note already issued for this device'
        ], 409);
    }

    if (!$device->invoice_id) {
        return response()->json([
            'status' => false,
            'message' => 'No invoice found for this device'
        ], 400);
    }

    // 🔐 Company (Zoho org)
    $company = Company::where('id', $device->company_id)
        ->where('role', 2)
        ->first();

    if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
        return response()->json([
            'status' => false,
            'message' => 'Zoho credentials not found'
        ], 400);
    }

    // 🏪 Retailer (Zoho customer)
    $retailer = Company::find($device->retailer_id);

    if (!$retailer || !$retailer->zoho_id) {
        return response()->json([
            'status' => false,
            'message' => 'Retailer Zoho contact not found'
        ], 400);
    }

    // 🧾 Credit note payload
    $payload = [
        "customer_id" => $retailer->zoho_id,
        "invoice_id"  => $device->invoice_id,
        "date"        => now()->format('Y-m-d'),
        "line_items"  => [
            [
                "name"        => $device->product_name ?? 'Warranty Cancellation',
                "description" => "Warranty cancelled for device ID {$device->id}",
                "rate"        => $device->product_price,
                "quantity"    => 1,
            ]
        ],
        "notes" => $request->reason ?? 'Warranty cancelled',
        "status" => 4
    ];

    $client = new \GuzzleHttp\Client();

    try {
        $response = $client->post(
            "https://www.zohoapis.in/books/v3/creditnotes",
            [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token,
                    'Content-Type'  => 'application/json',
                ],
                'query' => [
                    'organization_id' => $company->zoho_org_id,
                ],
                'json' => $payload,
            ]
        );

        $body = json_decode($response->getBody(), true);

        if (empty($body['creditnote']['creditnote_id'])) {
            return response()->json([
                'status' => false,
                'message' => 'Credit note creation failed'
            ], 500);
        }

        // ✅ Update device
        $device->update([
            'credit_note'   => $body['creditnote']['creditnote_id'],
            'cd_issued_date'=> now(),
            'status_remark' => 'Warranty cancelled',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Warranty cancelled and credit note created',
            'credit_note_id' => $body['creditnote']['creditnote_id']
        ], 200);

    } catch (\GuzzleHttp\Exception\ClientException $e) {
        $errorBody = json_decode(
            $e->getResponse()->getBody()->getContents(),
            true
        );

        return response()->json([
            'status' => false,
            'error' => $errorBody['message'] ?? $e->getMessage()
        ], $e->getResponse()->getStatusCode());
    }
}
}