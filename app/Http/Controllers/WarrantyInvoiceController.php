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
use App\Models\ZohoInvoiceLog;


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
                                'invoice_status'       => $body['invoice']['status'] ?? 'draft',
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
    
    //
    
    public function createBulkInvoicesCompanyOnlyWise(Request $request)
    {
        // 1️⃣ Fetch pending devices
        $devicesGroupedByCompany = WDevice::with('product')
            ->whereNull('invoice_id_parent')
            ->get()
            ->groupBy('company_id');
    
        if ($devicesGroupedByCompany->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'No pending invoices'
            ]);
        }
    
        // 2️⃣ ADMIN company
        $adminCompany = Company::where('id', 1)
            ->where('role', 1)
            ->first();
    
        if (
            !$adminCompany ||
            !$adminCompany->zoho_access_token ||
            !$adminCompany->zoho_org_id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Admin Zoho credentials missing'
            ]);
        }
    
        $client = new \GuzzleHttp\Client();
        $createdInvoices = [];
    
        foreach ($devicesGroupedByCompany as $companyId => $companyDevices) {
    
            $groupedProducts = $companyDevices->groupBy('product_id');
            $lineItems = [];
    
            foreach ($groupedProducts as $productDevices) {
    
                $product = $productDevices->first()->product;
                if (!$product || !$product->zoho_id) {
                    continue;
                }
    
                $amount = $productDevices->sum('company_payout');
                if ($amount <= 0) {
                    continue;
                }
    
                $lineItems[] = [
                    'item_id'  => $product->zoho_id, // ADMIN ITEM
                    'name'     => $product->name,
                    'rate'     => $amount,
                    'quantity' => 1,
                ];
            }
    
            if (empty($lineItems)) {
                continue;
            }
    
            $company = Company::find($companyId);
            if (!$company || !$company->zoho_id) {
                continue;
            }
    
            $payload = [
                'customer_id'       => $company->zoho_id,
                'date'              => now()->format('Y-m-d'),
                'line_items'        => $lineItems,
                'is_inclusive_tax'  => true
            ];
    
            try {
                // 3️⃣ Create invoice
                $response = $client->post(
                    'https://www.zohoapis.in/books/v3/invoices',
                    [
                        'headers' => [
                            'Authorization' => 'Zoho-oauthtoken ' . $adminCompany->zoho_access_token,
                            'Content-Type'  => 'application/json',
                        ],
                        'query' => [
                            'organization_id' => $adminCompany->zoho_org_id,
                        ],
                        'json' => $payload,
                    ]
                );
    
                $body = json_decode($response->getBody(), true);
    
                if (!empty($body['invoice']['invoice_id'])) {
    
                    // 4️⃣ Update devices (PARENT)
                    foreach ($companyDevices as $device) {
                        $device->update([
                            'invoice_id_parent'           => $body['invoice']['invoice_id'],
                            'invoice_created_date_parent' => now(),
                            'invoice_status_parent'       => $body['invoice']['status'] ?? 'draft',
                            'invoice_json_parent'         => json_encode($body['invoice']),
                        ]);
                    }
    
                    // 5️⃣ INSERT LOG
                    $log = ZohoInvoiceLog::create([
                        'company_id'        => $companyId,
                        'invoice_id_parent' => $body['invoice']['invoice_id'],
                        'invoice_number'    => $body['invoice']['invoice_number'] ?? null,
                        'zoho_invoice_id'   => $body['invoice']['invoice_id'],
                        'zoho_status'       => $body['invoice']['status'] ?? 'draft',
                        'request_payload'   => json_encode($payload),
                        'response_payload'  => json_encode($body),
                    ]);
    
                    // 6️⃣ MARK AS SENT
                    try {
                        $client->post(
                            "https://www.zohoapis.in/books/v3/invoices/{$body['invoice']['invoice_id']}/status/sent",
                            [
                                'headers' => [
                                    'Authorization' => 'Zoho-oauthtoken ' . $adminCompany->zoho_access_token,
                                ],
                                'query' => [
                                    'organization_id' => $adminCompany->zoho_org_id,
                                ],
                            ]
                        );
    
                        $log->update([
                            'is_sent'     => 1,
                            'sent_at'     => now(),
                            'zoho_status' => 'sent'
                        ]);
    
                    } catch (\Exception $e) {
                        \Log::error('Mark as sent failed', [
                            'invoice_id' => $body['invoice']['invoice_id'],
                            'error' => $e->getMessage()
                        ]);
                    }
    
                    $createdInvoices[] = [
                        'company_id'        => $companyId,
                        'invoice_id_parent' => $body['invoice']['invoice_id'],
                        'invoice_no_parent' => $body['invoice']['invoice_number'] ?? null,
                        'invoice_status'    => $body['invoice']['status'] ?? 'draft',
                        'total_amount'      => $companyDevices->sum('company_payout'),
                    ];
                }
    
            } catch (\Exception $e) {
                \Log::error('Parent invoice creation failed', [
                    'company_id' => $companyId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    
        return response()->json([
            'status'  => true,
            'message' => 'Company-wise parent invoices created successfully',
            'data'    => $createdInvoices
        ]);
    }
    public function getZohoInvoiceLogs(Request $request)
    {
        $perPage = $request->get('per_page', 20);
    
        $logs = ZohoInvoiceLog::query()
            ->when($request->company_id, fn ($q) =>
                $q->where('company_id', $request->company_id)
            )
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    
        return response()->json([
            'status' => true,
            'data'   => $logs
        ]);
    }
    
    
    public function syncRetailerInvoicesFromZoho(Request $request)
    {
    $perPage = 200;
    $client  = new \GuzzleHttp\Client();

    // Get companies who create retailer invoices (role = 2)
    $companies = Company::where('role', 2)
        ->whereNotNull('zoho_access_token')
        ->whereNotNull('zoho_org_id')
        ->get();

    foreach ($companies as $company) {

        $page = 1;

        do {
            try {
                $response = $client->get(
                    'https://www.zohoapis.in/books/v3/invoices',
                    [
                        'headers' => [
                            'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token,
                            'Content-Type'  => 'application/json',
                        ],
                        'query' => [
                            'organization_id' => $company->zoho_org_id,
                            'per_page'        => $perPage,
                            'page'            => $page,
                        ],
                    ]
                );

                $body = json_decode($response->getBody(), true);

                $invoices = $body['invoices'] ?? [];

                foreach ($invoices as $invoice) {

                    if (empty($invoice['invoice_id'])) {
                        continue;
                    }

                    // Update matching devices
                    WDevice::where('invoice_id', $invoice['invoice_id'])
                        ->update([
                            'invoice_status' => $invoice['status'] ?? null,
                            'invoice_json'   => json_encode($invoice),
                        ]);
                }

                $hasMorePages = $body['page_context']['has_more_page'] ?? false;
                $page++;

            } catch (\Exception $e) {

                \Log::error('Zoho invoice sync failed', [
                    'company_id' => $company->id,
                    'error'      => $e->getMessage(),
                ]);

                break;
            }

        } while ($hasMorePages);
    }

    return response()->json([
        'status'  => true,
        'message' => 'Retailer invoice status synced from Zoho successfully'
    ]);
}
public function syncParentInvoicesFromZoho(Request $request)
{
    $client  = new \GuzzleHttp\Client();
    $perPage = 200;
    $page    = 1;

    // ADMIN company (parent invoices owner)
    $adminCompany = Company::where('id', 1)
        ->where('role', 1)
        ->first();

    if (
        !$adminCompany ||
        !$adminCompany->zoho_access_token ||
        !$adminCompany->zoho_org_id
    ) {
        return response()->json([
            'status' => false,
            'message' => 'Admin Zoho credentials missing'
        ]);
    }

    do {
        try {
            // 1️⃣ Fetch invoices from Zoho (ADMIN ORG)
            $response = $client->get(
                'https://www.zohoapis.in/books/v3/invoices',
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $adminCompany->zoho_access_token,
                        'Content-Type'  => 'application/json',
                    ],
                    'query' => [
                        'organization_id' => $adminCompany->zoho_org_id,
                        'per_page'        => $perPage,
                        'page'            => $page,
                    ],
                ]
            );

            $body = json_decode($response->getBody(), true);

            $invoices = $body['invoices'] ?? [];

            // 2️⃣ Update matching parent invoices
            foreach ($invoices as $invoice) {

                if (empty($invoice['invoice_id'])) {
                    continue;
                }

                WDevice::where('invoice_id_parent', $invoice['invoice_id'])
                    ->update([
                        'invoice_status_parent' => $invoice['status'] ?? null,
                        'invoice_json_parent'   => json_encode($invoice),
                    ]);
            }

            // Pagination control
            $hasMorePages = $body['page_context']['has_more_page'] ?? false;
            $page++;

        } catch (\Exception $e) {

            \Log::error('Parent invoice sync failed', [
                'error' => $e->getMessage(),
            ]);

            break;
        }

    } while ($hasMorePages);

    return response()->json([
        'status'  => true,
        'message' => 'Parent invoice status synced from Zoho successfully'
    ]);
}

}