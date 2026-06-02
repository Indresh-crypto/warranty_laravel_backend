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
use App\Models\WarrantyClaim;
use App\Models\CompanyEmployee;
use App\Models\PriceTemplate;
use App\Models\WCustomer;
use App\Models\Companies;
use App\Models\WDevice;
use App\Models\Wclaim;
use App\Models\ZohoInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; 
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\WarrantyProductCoverage;
use DB;
use App\Events\WarrantyRegistered;
use App\Events\WarrantyRegisterWhatsapp;
use App\Events\WarrantyRegisteredProvision;

use App\Services\WarrantyPricingService;

use App\Models\DeviceModel;
use Illuminate\Validation\Rule;

use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceCreatedMail;
use App\Mail\WarrantyActivationMail;
use App\Jobs\SyncZohoInvoicesJob;

use App\Models\SubscribedPackage;

use App\Models\WarrantyFlowLog;
use App\Models\IndiaPincode;

class WarrantyController extends Controller
{
    public function getBrands(Request $request)
    {
       $brands = Brand::with(['categories:id,name'])->get();

        return response()->json([
            'message' => 'Brands with categories fetched successfully.',
            'data' => $brands
        ], Response::HTTP_OK);
    }
    public function getCategories(Request $request)
    {
        $data = Category::get();
        return response()->json($data, Response::HTTP_OK);
    }
    public function assignCategoriesToBrand(Request $request)
    {   
        $brand = Brand::findOrFail($request->brand_id);
        $brand->categories()->sync($request->category_ids);

        return response()->json([
            'message' => 'Categories assigned successfully',
            'brand' => $brand->load('categories'),
        ]);
    }

    public function getBrandsWithCategories()
    {
        $brands = Brand::with('categories')->get(); 

        return response()->json([
            'message' => 'Brands with categories retrieved successfully',
            'brands' => $brands,
        ]);
    }

    public function getMatchingPriceTemplates(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */
            $validator = Validator::make($request->all(), [
                'company_id'    => 'required|integer|exists:companies,id',
                'category_id'   => 'required|integer|exists:category,id',
                'product_price' => 'required|numeric|min:0',
                'retailer_id'   => 'required|integer|exists:companies,id',
            ]);
        
            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors(),
                ], 422);
            }
        
            try {
        
                /*
                |--------------------------------------------------------------------------
                | MAIN QUERY (FINAL LOGIC)
                |--------------------------------------------------------------------------
                */
                $today = now()->toDateString();
        
            $matchingTemplates = PriceTemplate::with([
            'warrantyProduct',
            'warrantyProduct.subscribedPackages' => function ($q) use ($request, $today) {
                $q->where('retailer_id', $request->retailer_id)
                  ->where('status', 1)
                  ->whereDate('end_date', '>=', $today);
            }
        ])
        ->where('company_id', $request->company_id)
    
        ->where(function ($query) use ($request, $today) {
    
            // NORMAL PRODUCTS
            $query->whereHas('warrantyProduct', function ($q) {
                $q->where('is_offer', 0);
            });
    
            // SUBSCRIPTION PRODUCTS
            $query->orWhereHas('warrantyProduct', function ($q) use ($request, $today) {
                $q->where('is_offer', 1)
                  ->whereHas('subscribedPackages', function ($sub) use ($request, $today) {
                      $sub->where('retailer_id', $request->retailer_id)
                          ->where('status', 1)
                          ->whereDate('end_date', '>=', $today);
                  });
            });
    
        })
    
        ->whereHas('warrantyProduct.categories', function ($cat) use ($request) {
            $cat->where('category_id', $request->category_id);
        })
    
        ->where('min_price', '<=', $request->product_price)
        ->where('max_price', '>=', $request->product_price)
    
        ->get();
        
                /*
                |--------------------------------------------------------------------------
                | EMPTY CHECK
                |--------------------------------------------------------------------------
                */
                if ($matchingTemplates->isEmpty()) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'No matching price templates found',
                        'data'    => [],
                    ], 404);
                }
        
                /*
                |--------------------------------------------------------------------------
                | SUCCESS RESPONSE
                |--------------------------------------------------------------------------
                */
                return response()->json([
                    'status'  => true,
                    'message' => 'Matching price templates retrieved successfully',
                    'data'    => $matchingTemplates,
                ], 200);
        
            } catch (\Exception $e) {
    
            \Log::error('PRICE TEMPLATE MATCH FAILED', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
    
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
     public function getProductsWithCategories(Request $request)
    {
        $query = WarrantyProduct::with('categories');
    
        // Optional filter
        if ($request->has('is_offer')) {
            $query->where('is_offer', $request->is_offer);
        }
    
        $products = $query->get();
    
        return response()->json([
            'message' => 'Products with categories retrieved successfully',
            'products' => $products,
        ]);
    }

  public function addPriceTemplate(Request $request)
  {
    $validator = Validator::make($request->all(), [
        'warranty_product_id' => 'required|exists:w_products,id',
        'emp_payout'          => 'required|numeric|min:0',
        'retailer_payout'     => 'required|numeric|min:0',
        'other_payout'        => 'required|numeric|min:0',
        'company_payout'      => 'required|numeric|min:0',
        'company_id'          => 'required|exists:companies,id',
        'min_price'           => 'required|numeric|min:0',
        'max_price'           => 'required|numeric|min:0|gte:min_price',
        'is_fixed'            => 'required|boolean',
        'is_percent'          => 'required|boolean',
        'product_price'       => 'required'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422);
    }

    // Fetch warranty product
    $product = WarrantyProduct::find($request->warranty_product_id);

    if (!$product) {
        return response()->json([
            'status' => false,
            'message' => 'Warranty product not found.',
        ], 404);
    }

    //Ensure min/max is inside product range
    if ($product->min_value && $request->min_price < $product->min_value) {
        return response()->json([
            'status' => false,
            'message' => "Minimum price should not be less than product's allowed min_value ({$product->min_value})."
        ], 422);
    }

    if ($product->max_value && $request->max_price > $product->max_value) {
        return response()->json([
            'status' => false,
            'message' => "Maximum price should not exceed product's allowed max_value ({$product->max_value})."
        ], 422);
    }

    // Ensure is_fixed / is_percent matches product
    if ($product->is_fixed != $request->is_fixed) {
        return response()->json([
            'status' => false,
            'message' => "Price template 'is_fixed' must match product setting."
        ], 422);
    }

    if ($product->is_percent != $request->is_percent) {
        return response()->json([
            'status' => false,
            'message' => "Price template 'is_percent' must match product setting."
        ], 422);
    }

    // ✅ Check overlapping price range
    $exists = PriceTemplate::where('warranty_product_id', $request->warranty_product_id)
        ->where('company_id', $request->company_id)
        ->where(function ($query) use ($request) {
            $query->whereBetween('min_price', [$request->min_price, $request->max_price])
                  ->orWhereBetween('max_price', [$request->min_price, $request->max_price])
                  ->orWhere(function ($q) use ($request) {
                      $q->where('min_price', '<=', $request->min_price)
                        ->where('max_price', '>=', $request->max_price);
                  });
        })
        ->exists();

    if ($exists) {
        return response()->json([
            'status' => false,
            'message' => 'Price range already exists for this product.',
        ], 409);
    }

    // Create price template
    $priceTemplate = PriceTemplate::create($request->all());

    return response()->json([
        'status' => true,
        'message' => 'Price template added successfully',
        'price_template' => $priceTemplate,
    ], 201);
}
  

    public function createWarrantyInvoice(WDevice $device, WCustomer $customer, $company_id, $zoho_product_id)
    {
        try {
    
            $orgUser = Company::find(1);
            if (!$orgUser) {
                return [
                    'success' => false,
                    'message' => 'Organization user not found.',
                ];
            }
    
            $accessToken = $orgUser->zoho_access_token;
            $orgId = $orgUser->zoho_org_id;
    
            $retailer = Company::find($device->retailer_id);
    
            if (!$retailer || !$retailer->zoho_id) {
                return [
                    'success' => false,
                    'message' => 'Retailer or Zoho customer ID not found.',
                ];
            }
    
            $invoicePayload = [
                'customer_id' => $retailer->zoho_id,
                'reference_number' => "WTY" . $device->id,
                'is_inclusive_tax' => true,
                'location_id'      => $orgUser->location_id,
                'notes' => $customer->name .
                    ' | Mobile: ' . $customer->mobile .
                    ' | IMEI: ' . $device->imei1 .
                    ' | Device Price: ₹' . number_format($device->device_price, 2) .
                    ' | WTY ID: ' . $device->id .
                    ' | Retailer Payout: ₹' . number_format($device->retailer_payout, 2) .
                    ' | Employee Payout: ₹' . number_format($device->employee_payout, 2),
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'payment_terms_label' => "You need to clear the invoice within 7 days.",
                'line_items' => [
                    [
                        'item_id' => $zoho_product_id,
                        'name' => $device->product_name,
                        'rate' => $device->product_price,
                        'quantity' => 1,
                        'description' => $device->brand_name . ' | ' .
                            $device->model . ' | Device Price: ₹' . number_format($device->device_price, 2) .
                            ' | ' . $device->imei1,
                    ],
                ],
            ];
    
            $client = new \GuzzleHttp\Client();
    
            $response = $client->post('https://www.zohoapis.in/books/v3/invoices', [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'organization_id' => $orgId,
                ],
                'json' => $invoicePayload,
            ]);
    
            $responseBody = json_decode($response->getBody(), true);
    
            
                Mail::to($retailer->contact_email)
                ->queue(new InvoiceCreatedMail(
                    $responseBody['invoice'] ?? [],
                    $responseBody['invoice']['invoice_url'] ?? '#'
                ));
                    
                    
            if (!isset($responseBody['invoice'])) {
                return [
                    'success' => false,
                    'message' => 'Invoice data missing in Zoho response.',
                    'response' => $responseBody,
                ];
            }
    
            $invoice = $responseBody['invoice'];
    
            ZohoInvoice::create([
                'invoice_id' => $invoice['invoice_id'],
                'contact_id' => $customer->retailer_id,
                'org_id' => $orgId,
                'company_id' => $company_id,
                'user_id' => $customer->retailer_id,
                'role' => 0,
                'zoho_json' => json_encode($invoice),
                'created_by_id' => $customer->retailer_id,
                'created_by_name' => $customer->name,
                'invoice_status' => 'paid',
                'due_date' => now()->addDays(7)->toDateString(),
                'payment_terms_label' => "You need to clear the invoice within 7 days.",
                'payment_date' => null,
                'invoice_amount' => $invoice['total'],
                'balance_amount' => $invoice['balance'],
                'product_type' => 'service',
                'quotation_id' => 0
            ]);
    
            // Update device
            $device->update([
                'zoho_invoice_id' => $invoice['invoice_id'],
                'invoice_id' => $invoice['invoice_number'] ?? null,
                'invoice_status' => $invoice['status'] ?? 'paid',
                'invoice_json' => json_encode($invoice),
                'invoice_created_date' => now(),
            ]);
    
            return [
                'success' => true,
                'message' => 'Invoice created and recorded successfully.',
                'invoice' => $invoice,
            ];
    
        } catch (\Exception $e) {
    
            return [
                'success' => false,
                'message' => 'Exception occurred while creating invoice.',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function updateCustomer(Request $request, $id)
    {
        $customer = WCustomer::find($id);
        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $customer->update($request->all());

        return response()->json(['message' => 'Customer updated successfully', 'customer' => $customer], 200);
    }


    public function createDevice(Request $request)
    {
        
           $exists = WDevice::where('product_id', $request->product_id)
            ->where(function ($query) use ($request) {
                $query->where('imei1', $request->imei1)
                      ->orWhere('imei2', $request->imei2)
                      ->orWhere('serial', $request->serial);
            })
            ->exists();
       
            if ($exists) {
                return response()->json([
                    'message' => 'Device with the same IMEI or Serial already exists.'
                ], 409);
            }
        
            $wproduct = WarrantyProduct::find($request->product_id);
            
            $product_mrp = 0;
            
            if ($wproduct && $wproduct->is_percent == 1) {
                $product_mrp = ($request->product_mrp / 100) * $request->device_price;
            } else {
                $product_mrp = $request->product_mrp;
            }
            
             $pricing = WarrantyPricingService::calculate(
                $request->product_id,
                $request->company_id,
                $request->device_price
            );
            
            $device = WDevice::create([
                
                'imei1' => $request->imei1,
                'imei2' => $request->imei2,
                'serial' => $request->serial,
                'brand_id' => $request->brand_id,
                'category_id' => $request->category_id,
                'product_id' => $request->product_id,
                'product_name' => $request->product_name,
                'brand_name' => $request->brand_name,
                'model' => $request->model,
                'category_name' => $request->category_name,
                'available_claim' => $request->available_claim,
                'expiry_date' => $request->expiry_date,
                'w_customer_id' => $request->w_customer_id,
                'retailer_id' => $request->retailer_id,
                'document_url' => $request->document_url,
                'link1' => $request->link1,
                'link2' => $request->link2,
                'device_price' => $request->device_price,
                'retailer_payout' => $pricing['retailer_payout'],
                'employee_payout' => $pricing['employee_payout'],
                'other_payout'    => $pricing['other_payout'],
                'company_payout'  => $pricing['company_payout'],
                'company_id' => $request->company_id,
                'product_price' => $request->product_price,
                'product_mrp' => $product_mrp,
                'agent_id' => $request->agent_id,
                'created_by' => $request->created_by,
                'promoter_id' => $request->promoter_id,
                'is_approved' => 0,
                'status'=>0,
                'is_pay_later'=>$request->is_pay_later,
                'model_id' => $request->model_id,
                'name' => $request->name,
                'is_from_wallet' => $request->is_from_wallet ?? 0
            ]);
  
            // ✅ Step 2: Generate WRT code using primary key
            $random = strtoupper(Str::random(6)); // A9F3XQ
            $wCode = "WRT-{$device->id}-{$random}";
        
            // ✅ Step 3: Update device with w_code
            $device->update([
                'w_code' => $wCode
            ]);
        
            $device->load([
            'customer',
                'product.coverages'
            ]);
            

           
           event(new WarrantyRegisteredProvision($device));
           
           try {
                  //  app(\App\Services\WhatsappService::class)
                   //     ->sendWarrantyProvision($device);
                } catch (\Exception $e) {
                    \Log::error('Provision WhatsApp failed', [
                        'device_id' => $device->id,
                        'error' => $e->getMessage()
                    ]);
                }
                
            try {

                $company = \App\Models\Company::find(
                    $request->retailer_id
                );
            
                if (!$company) {
            
                    throw new \Exception(
                        'Retailer company not found'
                    );
                }
            
                $invoiceNumber = $device->invoice_id ?? $device->w_code;
            
                $invoiceDate = now()->format('d-m-Y');
            
                $invoiceAmount =
                    $device->product_price
                    ?? $device->product_mrp
                    ?? 0;
            
                $invoiceUrl =
                    $device->certificate_link
                    ?? $device->document_url
                    ?? '';
            
                app(\App\Services\WhatsappService::class)

                        ->invoiceWhatsapp(
                
                            $company,
                
                            $invoiceNumber,
                
                            $invoiceDate,
                
                            $invoiceAmount,
                
                            $invoiceUrl
                        );
                
                } catch (\Throwable $e) {
                
                    \Log::error('Create device Invoice WhatsApp Failed', [
                
                        'device_id'   => $device->id,
                
                        'retailer_id' => $request->retailer_id,
                
                        'error'       => $e->getMessage(),
                
                        'line'        => $e->getLine()
                    ]);
                }
                



            return response()->json([
                'message' => 'Device created successfully',
                'device' => $device
            ], 201);
    }


    public function updateProduct(Request $request, $id)
    {
        DB::beginTransaction();
    
        try {
    
            // =========================
            // ✅ FIND PRODUCT
            // =========================
            $product = WarrantyProduct::findOrFail($id);
    
            // =========================
            // GENERATE NAME
            // =========================
             $productName = !empty($request->name)
                ? $request->name
                : collect([
                    $request->plan_type,
                    $request->category_name,
                    $request->validity ? '(' . $request->validity . ' Days)' : null,
                    ($request->min_value !== null && $request->max_value !== null)
                        ? $request->min_value . ' to ' . $request->max_value
                        : null
                ])->filter()->implode(' ');
    
    
            // =========================
            // GET COMPANY (ZOHO)
            // =========================
                    $orgUser = Company::where('id', 1)
                          ->whereNotNull('zoho_access_token')
                          ->whereNotNull('zoho_org_id')
                          ->first();
    
            if (!$orgUser) {
                return response()->json([
                    'status' => false,
                    'error' => 'Zoho credentials missing'
                ], 404);
            }
    
            // =========================
            // ZOHO UPDATE
            // =========================
            if ($product->zoho_id) {
    


                $client = new \GuzzleHttp\Client();
    
                $itemData = [
                    "name" => $productName,
                    "rate" => $request->mrp ?? 0,
                    "hsn_or_sac" => $request->hsn_or_sac,
                    "description" => $request->features ?? '',
                    "product_type" => 'service',
                    "is_taxable" => $request->is_taxable ?? true,
                ];
    
                $response = $client->request(
                    'PUT',
                    "https://www.zohoapis.in/books/v3/items/{$product->zoho_id}",
                    [
                        'headers' => [
                            'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
                            'Content-Type'  => 'application/json',
                        ],
                        'query' => [
                            'organization_id' => $orgUser->zoho_org_id,
                        ],
                        'json' => $itemData,
                    ]
                );
    
                $zohoResponse = json_decode($response->getBody(), true);
    
                \Log::info('Zoho Update Response', [
                    'product_id' => $product->id,
                    'zoho_id' => $product->zoho_id,
                    'response' => $zohoResponse
                ]);
            }
    
            // =========================
            // ✅ LOCAL UPDATE
            // =========================
            $product->update([
                'name'          => $productName,
                'image'         => $request->image,
                'hsn_code'      => $request->hsn_or_sac,
                'validity'      => $request->validity,
                'claims'        => $request->claims,
                'product_value' => $request->product_value,
                'cover_value'   => $request->cover_value,
                'features'      => $request->features,
                'min_value'     => $request->min_value,
                'max_value'     => $request->max_value,
                'status'        => $request->status,
                'coverage'      => $request->coverage,
                'exclusions'    => $request->exclusions,
                'margin'        => $request->margin,
                'mrp'           => $request->mrp,
                'product_type'  => $request->plan_type,
                'is_fixed'      => $request->is_fixed ?? false,
                'is_percent'    => $request->is_percent ?? false,
                'is_regular'    => $request->is_regular ?? false,
                'is_offer'      => $request->is_offer ?? false,
                'plan_type'     => $request->plan_type,
                'enroll_max'    => $request->enroll_max ?? 0,
                'sub_val_days'  => $request->sub_val_days ?? 0,
                'retailer_benifits' => $request->retailer_benifits
            ]);
    
            // =========================
            // ✅ SYNC CATEGORIES
            // =========================
            if ($request->has('category_ids') && is_array($request->category_ids)) {
                $product->categories()->sync($request->category_ids);
            }
    
            // =========================
            // ✅ SYNC COVERAGES
            // =========================
            if (!empty($request->coverage)) {
    
                WarrantyProductCoverage::where(
                    'warranty_product_id',
                    $product->id
                )->delete();
    
                $coverages = array_map(
                    'trim',
                    preg_split('/[.|]/', $request->coverage)
                );
    
                foreach ($coverages as $coverage) {
    
                    if ($coverage === '') continue;
    
                    WarrantyProductCoverage::create([
                        'warranty_product_id' => $product->id,
                        'title'               => $coverage,
                        'description'         => null,
                        'status'              => 1
                    ]);
                }
            }
    
            DB::commit();
    
            return response()->json([
                'status'  => true,
                'message' => 'Product updated successfully (Zoho + Local)',
                'product' => $product->load(['categories', 'coverages'])
            ], 200);
    
        } catch (\GuzzleHttp\Exception\ClientException $e) {
    
            DB::rollBack();
    
            $errorBody = json_decode($e->getResponse()->getBody(), true);
    
            return response()->json([
                'status' => false,
                'error' => $errorBody['message'] ?? $e->getMessage()
            ], $e->getResponse()->getStatusCode());
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            \Log::error('Product Update Failed', [
                'error' => $e->getMessage()
            ]);
    
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update product',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function createBrand(Request $request)
    {
        $validated = $request->validate([
        'name' => 'required|string|max:255',
        'image' => 'nullable',
        'description' => 'nullable|string',
        'category_ids' => 'nullable|array',
        'category_ids.*' => 'exists:category,id',
        'status'        =>'nullable'
        ]);

        $brand = Brand::create([
            'name' => $validated['name'],
            'image' => $validated['image'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
        ]);

        if (!empty($validated['category_ids'])) {
            $brand->categories()->attach($validated['category_ids']);
        }

        return response()->json([
            'message' => 'Brand created successfully',
            'brand' => $brand->load('categories'),
        ], 201);
    }
    
   public function updateBrand(Request $request, $id)
   {
    // Find the brand
    $brand = Brand::find($id);
    if (!$brand) {
        return response()->json([
            'status' => false,
            'message' => 'Brand not found',
        ], 404);
    }

    // Validate input using Validator class
    $validator = Validator::make($request->all(), [
        'name'          => 'sometimes|required|string|max:255',
        'image'         => 'nullable',
        'description'   => 'nullable|string',
        'category_ids'  => 'nullable|array',
        'category_ids.*'=> 'exists:category,id',
        'status'        => 'nullable'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => 'Validation errors',
            'errors'  => $validator->errors(),
        ], 422);
    }

    $validated = $validator->validated();

    // Update brand fields
    if (isset($validated['name'])) {
        $brand->name = $validated['name'];
    }
    if (array_key_exists('image', $validated)) {
        $brand->image = $validated['image'];
    }
    if (array_key_exists('description', $validated)) {
        $brand->description = $validated['description'];
    }
    if (array_key_exists('status', $validated)) {
        $brand->status = $validated['status'];
    }

    $brand->save();

    // Update category associations if provided
    if (isset($validated['category_ids'])) {
        $brand->categories()->sync($validated['category_ids']);
    }

    return response()->json([
        'status'  => true,
        'message' => 'Brand updated successfully',
        'brand'   => $brand->load('categories'),
    ], 200);
}
    public function createCategory(Request $request)
    {
        $validated = $request->validate([
        'name' => 'required|string|max:255',
        'image' => 'nullable',
        'description' => 'nullable|string',
        'status'=>'nullable'
        ]);

        $brand = Category::create([
            'name' => $validated['name'],
            'image' => $validated['image'] ?? null,
            'description' => $validated['description'] ?? null,
            'status'=>$validated['status']
        ]);

     
        return response()->json([
            'message' => 'Category created successfully',
        ], 201);
    }
    
    
public function getCompanyProduct(Request $request)
{
    $query = CompanyProduct::with('product.categories', 'company');

    // Filter by company
    if ($request->filled('company_id')) {
        $query->where('company_id', $request->company_id);
    }

    // ✅ New filter: by company_product_id
    if ($request->filled('company_product_id')) {
        $query->where('id', $request->company_product_id);
    }

    // Filter by product.is_offer
    if ($request->filled('is_offer')) {
        $query->whereHas('product', function ($q) use ($request) {
            $q->where('is_offer', (int) $request->is_offer);
        });
    }

    $companiesWithProducts = $query->get()
        ->groupBy('company_id')
        ->map(function ($items, $companyId) {
            return [
                'company_id' => $companyId,
                'company' => $items->first()->company,

                'products' => $items->map(function ($item) {
                    return [
                        'company_product_id' => $item->id,
                        'product_id' => $item->product_id,
                        'company_id' => $item->company_id,
                        'margin' => $item->margin,
                        'p_status' => $item->p_status,
                        'product' => $item->product
                    ];
                }),
            ];
        })
        ->values();

    return response()->json([
        'message' => 'Company products retrieved successfully.',
        'data' => $companiesWithProducts
    ]);
}
    public function UploadFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:jpeg,png,jpg,pdf,docx,webp|max:5048',
            'tag'  => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Store file in storage/app/public/images
        $path = $request->file('file')->store('images', 'public');

        // Public URL for the file
        $url = asset('storage/' . $path);

        // Save to DB (store only the relative path or full URL as needed)
        $document = UploadFile::create([
            'file_url' => 'storage/' . $path, // OR use $url if you want full URL in DB
            'tag'      => $request->tag
        ]);

        return response()->json([
            'message' => 'File uploaded successfully.',
            'data' => [
                'document' => $document,
                'file_url' => $url
            ]
        ]);
    }

    public function toggleBrandStatus($id)
    {
        $brand = Brand::findOrFail($id);
        $brand->status = !$brand->status; // toggle
        $brand->save();

        return response()->json([
            'message' => 'Brand status updated',
            'status' => $brand->status ? 'active' : 'inactive'
        ], Response::HTTP_OK);
    }

    public function toggleCategoryStatus($id)
    {
        $category = Category::findOrFail($id);
        $category->status = !$category->status;
        $category->save();

        return response()->json([
            'message' => 'Category status updated',
            'status' => $category->status ? 'active' : 'inactive'
        ], Response::HTTP_OK);
    }


    public function getSoldSummery(Request $request)
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

        $query = WDevice::query();

        if ($request->has('retailer_id') && !empty($request->retailer_id)) {
            $query->where('retailer_id', $request->retailer_id);
        }

        $monthlyQuery = (clone $query)->whereBetween('created_at', [$startOfMonth, $endOfMonth]);

        $monthDevices = $monthlyQuery->count();
        $monthClaims = $monthlyQuery->sum('available_claim');

        $totalDevices = $query->count();
        $totalClaims = $query->sum('available_claim');

     //   $claimQuery = Wclaim::query();
         $claimQuery;

        if ($request->has('retailer_id') && !empty($request->retailer_id)) {
          //  $claimQuery->where('retailer_id', $request->retailer_id);
        }

          return response()->json([
            'retailer_id'        => $request->retailer_id ?? null,
            'total_devices'      => $totalDevices,
            'total_claims'       => 0,
            'total_pending'      => 0,
            'total_approved'     => 0,
            'total_rejected'     => 0,
            'this_month_devices' => 0,
            'this_month_claims'  => 0,
        ]);
    }

    public function createProduct(Request $request)
    {
        // Define validation rules
       $rules = [
            'image' => 'nullable|url',
            'category_ids' => 'nullable|array',
            'validity' => 'required|integer',
            'claims' => 'required|integer',
            'product_value' => 'nullable|numeric',
            'cover_value' => 'nullable|numeric',
            'features' => 'nullable|string',
            'min_value' => 'nullable|numeric',
            'max_value' => 'nullable|numeric',
            'is_fixed' => 'nullable|boolean',
            'is_percent' => 'nullable|boolean',
            'is_regular' => 'nullable|boolean',
            'is_offer' => 'nullable|boolean',
            'product_type' => 'nullable|string',
            'is_taxable' => 'nullable|boolean',
            'company_id' => 'required|integer',
            'hsn_or_sac' => 'required|string',
            'status' => 'required',
            'margin' => 'required|numeric',
            'coverage' => 'nullable|string',
            'exclusions' => 'nullable|string',
            'category_name' => 'nullable|string',
            'enroll_max' => 'nullable|integer',
            'sub_val_days' => 'nullable|integer',
            'retailer_benifits' => 'nullable',
        
            'plan_type' => [
                'required',
                Rule::in([
                    'Screen Damage',
                    'Total Protection',
                    'Extended Warranty'
                ])
            ],
        ];
    
        $validator = Validator::make($request->all(), $rules);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
     
       $productName = !empty($request->name)
        ? $request->name
        : collect([
            $request->plan_type,
            $request->category_name,
            $request->validity ? '(' . $request->validity . ' Days)' : null,
            ($request->min_value !== null && $request->max_value !== null)
                ? $request->min_value . ' to ' . $request->max_value
                : null
        ])->filter()->implode(' ');
    
        // Find organization user
        $orgUser = Company::where('id', 1)
                          ->whereNotNull('zoho_access_token')
                          ->whereNotNull('zoho_org_id')
                          ->first();
    
        if (!$orgUser) {
            return response()->json(['status' => false, 'error' => 'Organization user not found or Zoho credentials missing.'], 404);
        }
    
        $itemData = [
            "name" => $productName,
            "rate" => $request->mrp ?? 0,
            "hsn_or_sac" => $request->hsn_or_sac,
            "description" => $request->features ?? 'Item Description',
            "product_type" => $request->product_type ?? 'service',
            "is_taxable" => $request->is_taxable ?? true,
        ];
    
        $client = new \GuzzleHttp\Client();
    
        try {
            $response = $client->post('https://www.zohoapis.in/books/v3/items', [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'organization_id' => $orgUser->zoho_org_id,
                ],
                'json' => $itemData,
            ]);
    
            $body = json_decode($response->getBody(), true);
            $zohoItem = $body['item'] ?? null;
    
            if (!$zohoItem) {
                return response()->json(['status' => false, 'error' => 'Zoho item creation failed'], 500);
            }
    
            $product = WarrantyProduct::create([
                'name' => $productName,
                'image' => $request->image ?? null,
                'zoho_id' => $zohoItem['item_id'], 
                'hsn_code' => $request->hsn_or_sac,
                'validity' => $request->validity,
                'claims' => $request->claims,
                'features' => $request->features,
                'min_value' => $request->min_value,
                'max_value' => $request->max_value,
                'is_fixed' => $request->is_fixed ?? false,
                'is_percent' => $request->is_percent ?? false,
                'is_regular' => $request->is_regular ?? false,
                'is_offer' => $request->is_offer ?? false,
                'mrp' => $request->mrp,
                'status' => $request->status,
                'margin' => $request->margin,
                'coverage'=> $request->coverage,
                'exclusions' => $request->exclusions,
                'product_type' => $request->plan_type,
                'enroll_max' => $request->enroll_max ?? 0,
                'sub_val_days' => $request->sub_val_days ?? 0,
                'retailer_benifits' => $request->retailer_benifits
            ]);
    
    
                if (!empty($request->coverage)) {
            
                // Convert string to array
                $coverages = array_map(
                    'trim',
                    explode('.', $request->coverage)
                );
            
                foreach ($coverages as $coverage) {
            
                    if ($coverage === '') {
                        continue;
                    }
            
                    WarrantyProductCoverage::create([
                        'warranty_product_id' => $product->id,
                        'title'               => $coverage,
                        'description'         => null,
                        'status'              => 1
                    ]);
                }
            }

           if ($request->has('category_ids') && is_array($request->category_ids)) {
                $product->categories()->sync($request->category_ids);
            }
            
    
            return response()->json([
                'status' => true,
                'message' => 'Item created successfully in Zoho and stored locally.',
                'product' => $product->load('categories'),
                'zoho_data' => $zohoItem
            ], 201);
    
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = json_decode($e->getResponse()->getBody(), true);
            return response()->json([
                'status' => false,
                'error' => $errorBody['message'] ?? $e->getMessage()
            ], $e->getResponse()->getStatusCode());
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function updateCategory(Request $request, $id)
    {
        // Find the category
        $category = Category::find($id);
        if (!$category) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found',
            ], 404);
        }
    
        // Validate input (all fields optional for update)
        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'image'       => 'nullable',
            'description' => 'nullable|string',
            'status'      => 'nullable'
        ]);
    
        // Update category fields
        if (isset($validated['name'])) {
            $category->name = $validated['name'];
        }
        if (array_key_exists('image', $validated)) {
            $category->image = $validated['image'];
        }
        if (array_key_exists('description', $validated)) {
            $category->description = $validated['description'];
        }
        if (array_key_exists('status', $validated)) {
            $category->status = $validated['status'];
        }
    
        $category->save();
    
        return response()->json([
            'status'  => true,
            'message' => 'Category updated successfully',
            'data'    => $category,
        ], 200);
    }
    public function toggleStatusProduct($id)
    {
        $product = WarrantyProduct::findOrFail($id);
        $product->status = !$product->status; // toggle
        $product->save();

        return response()->json([
            'message' => 'Product status updated',
            'status' => $product->status ? 'active' : 'inactive'
        ], Response::HTTP_OK);
    }
    public function getPriceTemplates(Request $request)
    {
        $query = PriceTemplate::with('warrantyProduct.categories');
    
        // Filter by company_id if provided
        if ($request->has('company_id') && !empty($request->company_id)) {
            $query->where('company_id', $request->company_id);
        }
    
        $priceTemplates = $query->get();
    
        return response()->json([
            'message' => 'Price templates retrieved successfully',
            'price_templates' => $priceTemplates,
        ]);
    }
    public function updateWarrantyStatus(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:w_devices,id',
            'is_approved' => 'required|in:0,1,2',
        ], [
            'id.required' => 'Device ID is required.',
            'id.exists' => 'Device not found.',
            'is_approved.required' => 'is_approved is required.',
            'is_approved.in' => 'must be 0, 1 or 2.',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    
        $device = WDevice::findOrFail($request->id);
    
        // Set status explicitly
        $device->is_approved =        $request->is_approved;
        $device->status_remark = $request->status_remark;
        $device->save();
    
        return response()->json([
            'message' => 'Warranty status updated',
            'status' => $device->status == 1 ? 'active' : 'inactive'
        ], Response::HTTP_OK);
    }
       
     public function getWarrantyCustomerDetails(Request $request)
    {
        $query = WCustomer::with('devices', 'retailer', 'devices.product');
    
        // If 'id' is provided, return that customer
        if ($request->filled('id')) {
            $customer = $query->where('id', $request->id)->first();
            if (!$customer) {
                return response()->json(['message' => 'Customer not found'], 404);
            }
            return response()->json($customer, 200);
        }
    
        // Otherwise, return all customers ordered by created_at
        $customers = $query->orderBy('created_at', 'desc')->get();
    
        return response()->json($customers, 200);
    }
        
    public function optInAndSendMessage(Request $request)
    {
        $templateId = $request->input('templateid');
        $title = $request->input('title');
        $phone = $request->input('phone');
    
        $apiKey = env('GUPSHUP_API_KEY');
        $appName = "Goexrt";
        $source = env('GUPSHUP_WHATSAPP_NUMBER');
    
        // Step 1: Opt-in user
        $optinResponse = $this->optInUser($apiKey, $appName, $phone);
    
   
        if (!$optinResponse) {
            return response()->json(['error' => 'Failed to opt-in user'], 400);
        }
    
        // Step 2: Prepare message parameters
        $params = [
            'channel' => 'whatsapp',
            'source' => $source,
            'destination' => $phone,
            'src.name' => $appName,
            'template' => json_encode([
                'id' => $templateId,
                'params' => [
                    $request->input('customer_name'),
                    $request->input('product_name'),
                    $request->input('product_category'),
                    $request->input('purchase_date'),
                    $request->input('warranty_id'),
                    $request->input('retailer_name'),
                    $request->input('district'),
                    $request->input('retailer_code'),
                    $request->input('phone_no'),
                ]
            ]),
            'message' => json_encode([
                'document' => [
                    'link' => $request->input('file_link'),
                    'filename' => $request->input('file_name')
                ],
                'type' => 'document'
            ])
        ];
    
        // Step 3: Send message
        return $this->sendMessage($apiKey, $params);
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

  private function sendMessage($apiKey, $params)
  {
    $url = 'https://api.gupshup.io/wa/api/v1/template/msg';
    $source = env('GUPSHUP_WHATSAPP_NUMBER', '918369719004');
    $appName = 'Goexrt';

    // Extract dynamic values
    $phone = '91' . preg_replace('/\D/', '', $params['phone']);
    $templateId = $params['templateid'];
    $fileLink = $params['file_link'] ?? null;
    $fileName = $params['file_name'] ?? null;

    // ✅ Build template parameters
    $templateData = [
        "id" => $templateId,
        "params" => [
            $params['customer_name'] ?? '',
            $params['product_name'] ?? '',
            $params['product_category'] ?? '',
            $params['purchase_date'] ?? '',
            $params['warranty_id'] ?? '',
            $params['retailer_name'] ?? '',
            $params['district'] ?? '',
            $params['retailer_code'] ?? '',
            $params['phone_no'] ?? ''
        ]
    ];

    // ✅ Build message payload (document type)
    $messageData = [
        "type" => "document",
        "document" => [
            "link" => $fileLink,
            "filename" => $fileName
        ]
    ];

    // ✅ Make API call
    $response = Http::asForm()->withHeaders([
        'apikey' => $apiKey,
        'Cache-Control' => 'no-cache',
        'Content-Type' => 'application/x-www-form-urlencoded'
    ])->post($url, [
        'channel' => 'whatsapp',
        'source' => $source,
        'destination' => $phone,
        'src.name' => $appName,
        'template' => json_encode($templateData),
        'message' => json_encode($messageData)
    ]);

    // ✅ Handle API response
    if ($response->failed()) {
        Log::error('Gupshup API error: ' . $response->body());
        return response()->json([
            'success' => false,
            'message' => 'Failed to send WhatsApp message',
            'error' => $response->body()
        ], 400);
    }

    return response()->json([
        'success' => true,
        'message' => 'WhatsApp message sent successfully',
        'response' => $response->json()
    ]);
}
public function dashboardCounts(Request $request)
{
    $companyId = $request->input('company_id');
    $agentId   = $request->input('agent_id');

    /*
    |--------------------------------------------------------------------------
    | Base Query Builders (Reusable)
    |--------------------------------------------------------------------------
    */
    $deviceQuery = WDevice::query();
    $claimQuery  = WarrantyClaim::query();

    if ($companyId) {
        $deviceQuery->where('company_id', $companyId);
        $claimQuery->where('company_id', $companyId);
    }

    if ($agentId) {
        $deviceQuery->where('agent_id', $agentId);
        $claimQuery->where('agent_id', $agentId);
    }

    /*
    |--------------------------------------------------------------------------
    | COMMISSION CALCULATION
    |--------------------------------------------------------------------------
    */
    $approvedCommission = (clone $deviceQuery)
        ->whereNotNull('invoice_id')
        ->where('invoice_id', '!=', '')
        ->sum('company_payout');

    $pendingCommission = (clone $deviceQuery)
        ->where(function ($q) {
            $q->whereNull('invoice_id')
              ->orWhere('invoice_id', '');
        })
        ->sum('company_payout');

    /*
    |--------------------------------------------------------------------------
    | COMPANY DASHBOARD
    |--------------------------------------------------------------------------
    */
    if (!empty($companyId)) {

        if (!Company::where('id', $companyId)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid company ID'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'type'   => 'company',
            'data' => [
                'brand_count'     => Brand::count(),
                'category_count'  => Category::count(),

                'product_count' =>
                    CompanyProduct::where('company_id', $companyId)->count(),

                'price_templates_count' =>
                    PriceTemplate::where('company_id', $companyId)->count(),

                
                'connected_retailers_count' => Company::query()
                ->where('role', 5)
                ->where('company_id', $companyId)
                ->whereNotNull('last_connected_date')
                ->where('last_connected_date', '>=', Carbon::now()->subDays(7))
                ->count(),
    
                
                 'active_retailers_count' => WDevice::where('company_id', $companyId)
                    ->where('created_at', '>=', Carbon::now()->subDays(7))
                    ->distinct('retailer_id')
                    ->count('retailer_id'),
                    
                    

                'open_claims_count' =>
                    (clone $claimQuery)
                        ->where('status', 'pending')
                        ->count(),

                'active_warranties_count' =>
                    (clone $deviceQuery)
                        ->where('is_approved',1)
                        ->count(),
                        
                'total_warranties_count' =>
                    (clone $deviceQuery)
                        ->count(),
                'agent_count'     => Company::where('role', 4)->where('company_id', $companyId)->count(),
                'company_employee_count'     => CompanyEmployee::where('company_id', $companyId)->count(),
                'retailer_count'  => Company::where('role', 5)->where('company_id', $companyId)->count(),
                'approved_commission' => $approvedCommission,
                'pending_commission'  => $pendingCommission,
            ]
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | ADMIN / GLOBAL DASHBOARD
    |--------------------------------------------------------------------------
    */
    return response()->json([
        'status' => true,
        'type'   => 'admin',
        'data' => [
            'brand_count'     => Brand::count(),
            'category_count'  => Category::count(),
            'product_count'   => WarrantyProduct::count(),

            'company_count'   => Company::where('role', 2)->count(),
            'agent_count'     => Company::where('role', 4)->count(),
            'retailer_count'  => Company::where('role', 5)->count(),

            'price_templates_count' =>
                PriceTemplate::count(),

            'connected_retailers_count' =>
                Company::where('role', 5)->count(),

            'open_claims_count' =>
                (clone $claimQuery)
                    ->where('status', 'pending')
                    ->count(),

            'active_warranties_count' =>
                (clone $deviceQuery)
                    ->where('status', 'active')
                    ->count(),

            'approved_commission' => $approvedCommission,
            'pending_commission'  => $pendingCommission,
        ]
    ], 200);
}
    
    public function updateProductStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'p_status' => 'required|in:0,1',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
    
        $record = CompanyProduct::find($id);
    
        if (!$record) {
            return response()->json([
                'message' => 'Company product not found.',
            ], 404);
        }
    
        $record->update([
            'p_status' => $request->p_status
        ]);
    
        return response()->json([
            'message' => 'Product status updated successfully.',
            'data' => $record
        ]);
    }

 /*
 public function generateDeviceCertificate(Request $request)
{
   
      $validator = Validator::make($request->all(), [
        'imei1' => 'required'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors()
        ], 422);
    }



    // Get device by IMEI
    $device = WDevice::with('customer')
        ->where('imei1', $request->imei1)
        ->first();

    if (!$device) {
        return response()->json([
            'message' => 'Device not found'
        ], 404);
    }

    // Relations
    $customer = $device->customer; // relation
    $retailer = Company::find($device->retailer_id);
    $product  = WarrantyProduct::find($device->product_id);

    if (!$customer || !$retailer || !$product) {
        return response()->json([
            'message' => 'Related data missing for certificate generation'
        ], 422);
    }


    $certificateId = 'GX-WNTY-' . now()->year . '-' . str_pad($device->id, 5, '0', STR_PAD_LEFT);
    $verifyUrl = "https://verify.goelectronix.in/cert/{$certificateId}";
    $qrCode = "1";


    $pdf = Pdf::loadView('certificate', [
        'certificateId'   => $certificateId,
        'startDate'       => now()->toDateString(),
        'endDate'         => Carbon::parse($device->expiry_date)->toDateString(),
        'customerName'    => $customer->name,
        'customerPhone'   => $customer->mobile,
        'brand'           => $device->brand_name,
        'model'           => $device->model,
        'category'        => $device->category_name,
        'imei1'           => $device->imei1,
        'serial'          => $device->serial,
        'purchaseDate'    => now()->toDateString(),
        'planName'        => $product->name,
        'planSummary'     => $product->features,
        'maxClaims'       => $device->available_claim,
        'coverageLimit'   => number_format($device->device_price, 2),
        'retailerName'    => $retailer->business_name,
        'retailerCode'    => $retailer->company_code,
        'retailerAddress' => $retailer->address_line1,
        'retailerContact' => $retailer->contact_phone,
        'issuedOn'        => now()->toDateString(),
        'qrCode'          => $qrCode,
        'verifyUrl'       => $verifyUrl,
    ])->setPaper('a4', 'portrait');


    $pdfPath = "warranty_pdfs/{$certificateId}.pdf";
    Storage::disk('public')->put($pdfPath, $pdf->output());

    $certificateLink = Storage::disk('public')->url($pdfPath);

    
    $device->update([
        'certificate_link' => $certificateLink
    ]);

  //  event(new WarrantyRegisterWhatsapp($device->fresh()));

    return response()->json([
        'success'         => true,
        'message'         => 'Certificate generated successfully',
        'certificate_id'  => $certificateId,
        'certificate_url' => $certificateLink
    ]);
}
*/
/*
   public function assignProduct(Request $request)
  {
    $validator = Validator::make($request->all(), [
        'product_ids'   => 'required|array|min:1',
        'product_ids.*' => 'exists:w_products,id',
        'company_id'    => 'required|exists:companies,id',
        'margin'        => 'required|numeric|min:0',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation error',
            'errors'  => $validator->errors()
        ], 422);
    }

    $company = Company::find($request->company_id);

    if (!$company->zoho_access_token || !$company->zoho_org_id) {
        return response()->json([
            'status' => false,
            'message' => 'Zoho credentials not found for company'
        ], 400);
    }

    $client = new \GuzzleHttp\Client();

    $assigned = [];
    $skipped  = [];
    $failed   = [];

    foreach ($request->product_ids as $productId) {

        if (
            CompanyProduct::where('product_id', $productId)
                ->where('company_id', $company->id)
                ->exists()
        ) {
            $skipped[] = $productId;
            continue;
        }

        $product = WarrantyProduct::find($productId);

        if (!$product) {
            $failed[] = [
                'product_id' => $productId,
                'error' => 'Product not found'
            ];
            continue;
        }

        $itemPayload = [
            "name"         => $product->name,
            "rate"         => $product->mrp,
            "hsn_or_sac"   => $product->hsn_code,
            "product_type" => "service",
            "description" => $product->features,
        ];

        try {

            $response = $client->post(
                'https://www.zohoapis.in/books/v3/items',
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token,
                        'Content-Type'  => 'application/json',
                    ],
                    'query' => [
                        'organization_id' => $company->zoho_org_id,
                    ],
                    'json' => $itemPayload,
                ]
            );

            $body = json_decode($response->getBody(), true);

            if (empty($body['item']['item_id'])) {
                $failed[] = [
                    'product_id' => $productId,
                    'error' => 'Zoho item_id missing',
                    'zoho_response' => $body
                ];
                continue;
            }

            $assigned[] = CompanyProduct::create([
                'product_id'   => $product->id,
                'company_id'   => $company->id,
                'margin'       => $request->margin,
                'p_status'     => 1,
                'zoho_item_id' => $body['item']['item_id'],
                'zoho_json'    => json_encode($body['item']),
            ]);

        } catch (\GuzzleHttp\Exception\ClientException $e) {

            $zohoError = json_decode(
                $e->getResponse()->getBody()->getContents(),
                true
            );

            $failed[] = [
                'product_id' => $productId,
                'zoho_error' => $zohoError['message'] ?? 'Zoho API error',
                'zoho_code'  => $zohoError['code'] ?? null,
                'details'    => $zohoError
            ];

            \Log::error('Zoho Item Creation Failed', [
                'product_id' => $productId,
                'company_id' => $company->id,
                'zoho_error' => $zohoError
            ]);
        }
    }

    return response()->json([
        'status'   => true,
        'message'  => 'Product assignment completed',
        'assigned' => count($assigned),
        'skipped'  => $skipped,
        'failed'   => $failed
    ], 201);
}
*/

    public function assignProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_ids'   => 'required|array|min:1',
            'product_ids.*' => 'exists:w_products,id',
            'company_id'    => 'required|exists:companies,id',
            'margin'        => 'required|numeric|min:0',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }
    
        $assigned = [];
        $skipped  = [];
        $failed   = [];
    
        foreach ($request->product_ids as $productId) {
    
            // Skip if already assigned
            if (
                CompanyProduct::where('product_id', $productId)
                    ->where('company_id', $request->company_id)
                    ->exists()
            ) {
                $skipped[] = $productId;
                continue;
            }
    
            $product = WarrantyProduct::find($productId);
    
            if (!$product) {
                $failed[] = [
                    'product_id' => $productId,
                    'error' => 'Product not found'
                ];
                continue;
            }
    
            /**
             * STEP 1: Get zoho_item_id
             */
    
            // Option 1: Directly from product table
            $zohoItemId = $product->zoho_id ?? null;
    
            // Option 2: If not in product, fetch from any existing company mapping (parent)
            if (!$zohoItemId) {
                $parentMapping = CompanyProduct::where('product_id', $productId)
                    ->whereNotNull('zoho_item_id')
                    ->first();
    
                if ($parentMapping) {
                    $zohoItemId = $parentMapping->zoho_item_id;
                }
            }
    
            // If still not found → fail
            if (!$zohoItemId) {
                $failed[] = [
                    'product_id' => $productId,
                    'error' => 'zoho item id not found in parent/product'
                ];
                continue;
            }
    
            /**
             * STEP 2: Create mapping
             */
            try {
    
                $assigned[] = CompanyProduct::create([
                    'product_id'   => $product->id,
                    'company_id'   => $request->company_id,
                    'margin'       => $request->margin,
                    'p_status'     => 1,
                    'zoho_item_id' => $zohoItemId,
                    'zoho_json'    => null, // optional
                ]);
    
            } catch (\Exception $e) {
    
                $failed[] = [
                    'product_id' => $productId,
                    'error' => $e->getMessage()
                ];
    
                \Log::error('Product Assignment Failed', [
                    'product_id' => $productId,
                    'company_id' => $request->company_id,
                    'error'      => $e->getMessage()
                ]);
            }
        }
    
        return response()->json([
            'status'   => true,
            'message'  => 'Product assignment completed',
            'assigned' => count($assigned),
            'skipped'  => $skipped,
            'failed'   => $failed
        ], 201);
    }
    public function agentDashboard(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'agent_id' => 'required|integer|exists:companies,id'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $agentId = $request->agent_id;
    
        $now = now();
        $monthStart = now()->startOfMonth();
        $monthEnd   = now()->endOfMonth();
    
        /* ================= TOTAL RETAILERS ================= */
        $totalRetailers = Company::where('agent_id', $agentId)
            ->where('role', 5)
            ->count();
    
        /* ================= SELLING RETAILERS (LAST 7 DAYS) ================= */
        $sellingRetailers = WDevice::where('agent_id', $agentId)
            ->where('created_at', '>=', now()->subDays(7))
            ->distinct('retailer_id')
            ->count('retailer_id');
    
        /* ================= THIS MONTH SALES ================= */
        $thisMonthSales = WDevice::where('agent_id', $agentId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('product_mrp');
    
        /* ================= THIS MONTH COMMISSION ================= */
        $thisMonthCommission = WDevice::where('agent_id', $agentId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('other_payout');
    
        /* ================= LIFETIME COMMISSION ================= */
        $lifetimeCommission = WDevice::where('agent_id', $agentId)
            ->sum('other_payout');
    
        /* ================= AVG SALES / RETAILER (CURRENT MONTH) ================= */
        $avgSalesPerRetailer = WDevice::where('agent_id', $agentId)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw('AVG(product_mrp) as avg_sales')
            ->value('avg_sales');
    
        /* ================= RESPONSE ================= */
        return response()->json([
            'status' => true,
            'data' => [
                'onboarded_retailers' => [
                    'total' => $totalRetailers,
                ],
    
                'selling_retailers' => [
                    'last_7_days' => $sellingRetailers,
                ],
    
                'this_month_sales' => [
                    'amount'   => round($thisMonthSales, 2),
                ],
    
                'this_month_commission' => [
                    'amount' => round($thisMonthCommission, 2),
                ],
    
                'lifetime_commission' => [
                    'amount' => round($lifetimeCommission, 2),
                ],
    
                'avg_sales_per_retailer' => [
                    'amount' => round($avgSalesPerRetailer ?? 0, 2),
                ]
            ]
        ], 200);
    }
/*
    public function generateDeviceCertificate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'imei1' => 'required'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors()
            ], 422);
        }
    
        $device = WDevice::with('customer')
            ->where('imei1', $request->imei1)
            ->first();
    
        if (!$device) {
            return response()->json([
                'message' => 'Device not found'
            ], 404);
        }
    
        $customer = $device->customer;
        $retailer = Company::find($device->retailer_id);
        $product  = WarrantyProduct::find($device->product_id);
    
        if (!$customer || !$retailer || !$product) {
            return response()->json([
                'message' => 'Related data missing for certificate generation'
            ], 422);
        }
    
      
        $certificateId = 'GX-WNTY-' . now()->year . '-' . str_pad($device->id, 5, '0', STR_PAD_LEFT);
        $verifyUrl = "https://verify.goelectronix.in/cert/{$certificateId}";
    
             $templatePath = storage_path('app/template/WarrantyCertificate.docx');
    
        $templatePath = storage_path('app/template/WarrantyCertificate.docx');
        $templateProcessor = new TemplateProcessor($templatePath);
    
       
        $templateProcessor->setValue('certificateId', $certificateId);
        $templateProcessor->setValue('customerName', $customer->name);
        $templateProcessor->setValue('customerPhone', $customer->mobile);
        $templateProcessor->setValue('brand', $device->brand_name);
        $templateProcessor->setValue('model', $device->model);
        $templateProcessor->setValue('category', $device->category_name);
        $templateProcessor->setValue('imei1', $device->imei1);
        $templateProcessor->setValue('serial', $device->serial ?? '');
        $templateProcessor->setValue('planName', $product->name);
        $templateProcessor->setValue('planSummary', strip_tags($product->features));
        $templateProcessor->setValue('maxClaims', $device->available_claim);
        $templateProcessor->setValue('coverageLimit', number_format($device->device_price, 2));
        $templateProcessor->setValue('retailerName', $retailer->business_name);
        $templateProcessor->setValue('retailerCode', $retailer->company_code);
        $templateProcessor->setValue('retailerAddress', $retailer->address_line1);
        $templateProcessor->setValue('retailerContact', $retailer->contact_phone);
        $templateProcessor->setValue('startDate', now()->toDateString());
        $templateProcessor->setValue('endDate', Carbon::parse($device->expiry_date)->toDateString());
        $templateProcessor->setValue('issuedOn', now()->toDateString());
        $templateProcessor->setValue('verifyUrl', $verifyUrl);
    
      
        $folderPath = storage_path('app/public/warranty_pdfs');
        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0777, true);
        }
    
    
        $docxFile = "{$folderPath}/{$certificateId}.docx";
        $templateProcessor->saveAs($docxFile);
    
    
        $command = "libreoffice --headless --convert-to pdf --outdir {$folderPath} {$docxFile}";
        exec($command);
    
        $pdfFileName = "{$certificateId}.pdf";
        $pdfPath = "warranty_pdfs/{$pdfFileName}";
        $certificateLink = Storage::disk('public')->url($pdfPath);
    
       
        $device->update([
            'certificate_link' => $certificateLink
        ]);
    
        return response()->json([
            'success'         => true,
            'message'         => 'Certificate generated successfully',
            'certificate_id'  => $certificateId,
            'certificate_url' => $certificateLink
        ]);
    }
*/

    public function generateDeviceCertificate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'imei1' => 'required'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors()
            ], 422);
        }
    
        $device = WDevice::with(['customer','product.coverages'])
            ->where(function ($q) use ($request) {
                $q->where('imei1', $request->imei1)
                  ->orWhere('imei2', $request->imei1);
            })
            ->first();
    
        if (!$device) {
            return response()->json([
                'message' => 'Device not found'
            ], 404);
        }
    
        $customer = $device->customer;
        $retailer = Company::find($device->retailer_id);
        $product  = $device->product;
    
        if (!$customer || !$retailer || !$product) {
            return response()->json([
                'message' => 'Related data missing for certificate generation'
            ], 422);
        }
    
        /** Certificate ID */
        $certificateId = 'WM-WNTY-' . now()->year . '-' . str_pad($device->id, 5, '0', STR_PAD_LEFT);
    
        /** Generate PDF */
        $pdf = Pdf::loadView('certificate', [
            'device' => $device,
            'customer' => $customer,
            'product' => $product,
            'retailer' => $retailer,
            'certificateId' => $certificateId
        ])->setPaper('A4');
    
        /** Save */
        Storage::disk('public')->makeDirectory('warranty_pdfs');
    
        $pdfFileName = 'warranty_pdfs/'.$certificateId.'.pdf';
    
        Storage::disk('public')->put($pdfFileName, $pdf->output());
    
        $certificateLink = Storage::disk('public')->url($pdfFileName);
    
        /** Update */
        $device->update([
            'certificate_link' => $certificateLink
        ]);
    
        return response()->json([
            'success'         => true,
            'message'         => 'Certificate generated successfully',
            'certificate_id'  => $certificateId,
            'certificate_url' => $certificateLink
        ]);
    }

    public function getMatchingPriceTemplateforDevice($devicePrice, $productType, $companyId)
    {
        return PriceTemplate::with('product.categories')
            ->where('company_id', $companyId)
            ->where('product_type', $productType)
            ->where('min_price', '<=', $devicePrice)
            ->where('max_price', '>=', $devicePrice)
            ->orderBy('id', 'asc')
            ->get();   // MUST BE get()
    }
    
    
    public function priceReport(Request $request)
    {
        try {
    
            $companyId  = $request->company_id;
            $brandId    = $request->brand_id;
            $categoryId = $request->category_id;
    
            $devicesQuery = DeviceModel::with(['brand','category'])
                ->where('status',1);
    
            if (!empty($brandId)) {
                $devicesQuery->where('brand_id',$brandId);
            }
    
            if (!empty($categoryId)) {
                $devicesQuery->where('category_id',$categoryId);
            }
    
            $devices = $devicesQuery->get();
    
            $productTypes = [
                'Screen Damage',
                'Total Protection',
                'Extended Warranty'
            ];
    
            $finalReport = [];
    
            foreach ($devices as $device) {
    
                $modelData = [
                    'brand'        => $device->brand->name ?? null,
                    'model'        => $device->name,
                    'device_price' => $device->price,
                    'category'     => $device->category->name ?? null,
                    'packages'     => []
                ];
    
                foreach ($productTypes as $type) {
    
                   
                    $templates = PriceTemplate::with(['product.categories'])
                    ->where('company_id', $companyId)
                    ->where('product_type', $type)
                    ->where('min_price', '<=', $device->price)
                    ->where('max_price', '>=', $device->price)
                    ->whereHas('product', function ($q) {
                        $q->where('is_regular', 1);
                    })
                    ->get();
                    
    
                    $matched = false;
    
                    foreach ($templates as $template) {
    
                        if (!$template->product) {
                            continue;
                        }
    
                       $productCategories = $template->product?->categories?->pluck('id')->toArray() ?? [];
    
                       
                       if (!empty($productCategories) && !in_array($device->category_id,$productCategories)) {
                                continue;
                            }
    
                        $productPrice = $template->product->mrp ?? 0;
    
                        if ($template->is_percent) {
    
                            $companyPayout  = ($device->price * $template->company_payout)/100;
                            $agentPayout    = ($device->price * $template->emp_payout)/100;
                            $otherPayout    = ($device->price * $template->other_payout)/100;
                            $retailerPayout = ($device->price * $template->retailer_payout)/100;
    
                            $productMrp = ($device->price * $productPrice)/100;
    
                        } else {
    
                            $companyPayout  = $template->company_payout;
                            $agentPayout    = $template->emp_payout;
                            $otherPayout    = $template->other_payout;
                            $retailerPayout = $template->retailer_payout;
    
                            $productMrp = $productPrice;
                        }
    
                        $modelData['packages'][] = [
                            'product_type'=>$type,
                            'product_name'=>$template->product->name ?? null,
                            'claims'=>$template->product->claims ?? null,
                            'validity_days'=>$template->product->validity ?? null,
                            'product_price'=>$productMrp,
                            'company_payout'=>$companyPayout,
                            'agent_payout'=>$agentPayout,
                            'other_payout'=>$otherPayout,
                            'retailer_payout'=>$retailerPayout,
                            'is_matched'=>true
                        ];
    
                        $matched = true;
                    }
    
                    // IMPORTANT: keep frontend structure same
                    if (!$matched) {
    
                        $modelData['packages'][] = [
                            'product_type'=>$type,
                            'product_name'=>null,
                            'claims'=>null,
                            'validity_days'=>null,
                            'product_price'=>null,
                            'company_payout'=>null,
                            'agent_payout'=>null,
                            'other_payout'=>null,
                            'retailer_payout'=>null,
                            'is_matched'=>false
                        ];
                    }
                }
    
                $finalReport[] = $modelData;
            }
    
            return response()->json([
                'success'=>true,
                'data'=>$finalReport
            ]);
    
        } catch (\Exception $e) {
    
            return response()->json([
                'success'=>false,
                'error'=>$e->getMessage(),
                'line'=>$e->getLine()
            ]);
        }
    }

    public function createDeviceWithInvoiceAndCredit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'imei1'         => 'required',
            'product_id'    => 'required|exists:w_products,id',
            'company_id'    => 'required|exists:companies,id',
            'retailer_id'   => 'required|exists:companies,id',
            'w_customer_id' => 'required|exists:w_customers,id'
        ]);
    
        if ($validator->fails()) {
    
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }
    
        DB::beginTransaction();
    
        try {
    
            // ===============================
            // DUPLICATE CHECK
            // ===============================
    
            $exists = WDevice::where(
                    'product_id',
                    $request->product_id
                )
                ->where(function ($query) use ($request) {
    
                    $query->where(
                        'imei1',
                        $request->imei1
                    );
    
                    if ($request->imei2) {
    
                        $query->orWhere(
                            'imei2',
                            $request->imei2
                        );
                    }
    
                    if ($request->serial) {
    
                        $query->orWhere(
                            'serial',
                            $request->serial
                        );
                    }
                })
                ->exists();
    
            if ($exists) {
    
                throw new \Exception(
                    'Device with the same IMEI or Serial already exists.'
                );
            }
    
            // ===============================
            // CALCULATE MRP
            // ===============================
    
            $wproduct = WarrantyProduct::find(
                $request->product_id
            );
    
            $product_mrp = 0;
    
            if (
                $wproduct &&
                $wproduct->is_percent == 1
            ) {
    
                $product_mrp =
                    ($request->product_mrp / 100)
                    * $request->device_price;
    
            } else {
    
                $product_mrp =
                    $request->product_mrp;
            }
    
            $pricing = WarrantyPricingService::calculate(
    
                $request->product_id,
    
                $request->company_id,
    
                $request->device_price
            );
    
            // ===============================
            // CREATE DEVICE
            // ===============================
    
            $device = WDevice::create([
    
                'imei1' =>
                    $request->imei1,
    
                'imei2' =>
                    $request->imei2,
    
                'serial' =>
                    $request->serial,
    
                'brand_id' =>
                    $request->brand_id,
    
                'category_id' =>
                    $request->category_id,
    
                'product_id' =>
                    $request->product_id,
    
                'product_name' =>
                    $request->product_name,
    
                'brand_name' =>
                    $request->brand_name,
    
                'model' =>
                    $request->model,
    
                'model_id' =>
                    $request->model_id,
    
                'category_name' =>
                    $request->category_name,
    
                'available_claim' =>
                    $request->available_claim,
    
                'expiry_date' =>
                    $request->expiry_date,
    
                'document_url' =>
                    $request->document_url,
    
                'link1' =>
                    $request->link1,
    
                'link2' =>
                    $request->link2,
    
                'device_price' =>
                    $request->device_price,
    
                'product_price' =>
                    $request->product_price,
    
                'product_mrp' =>
                    $product_mrp,
    
                'retailer_payout' =>
                    $pricing['retailer_payout'],
    
                'employee_payout' =>
                    $pricing['employee_payout'],
    
                'other_payout' =>
                    $pricing['other_payout'],
    
                'company_payout' =>
                    $pricing['company_payout'],
    
                'company_id' =>
                    $request->company_id,
    
                'retailer_id' =>
                    $request->retailer_id,
    
                'promoter_id' =>
                    $request->promoter_id,
    
                'name' =>
                    $request->name,
    
                'w_customer_id' =>
                    $request->w_customer_id,
    
                'agent_id' =>
                    $request->agent_id,
    
                'created_by' =>
                    $request->created_by,
    
                'is_approved' => 1,
    
                'is_pay_later' =>
                    $request->is_pay_later,
    
                'is_from_wallet' =>
                    $request->is_from_wallet ?? 0,
    
                'status' => 1
            ]);
    
            // ===============================
            // GENERATE WARRANTY CODE
            // ===============================
    
            $device->w_code =
                'WRT-' .
                $device->id .
                '-' .
                strtoupper(Str::random(6));
    
            $device->save();
    
            // ===============================
            // GET CUSTOMER
            // ===============================
    
            $customer = WCustomer::findOrFail(
                $device->w_customer_id
            );
    
            // ===============================
            // GET PRODUCT
            // ===============================
    
            $product = WarrantyProduct::findOrFail(
                $request->product_id
            );
    
            if (!$product->zoho_id) {
    
                throw new \Exception(
                    'Zoho product mapping not found'
                );
            }
    
            // ===============================
            // CREATE INVOICE
            // ===============================
    
            $invoiceResult = $this->createWarrantyInvoice(
    
                $device,
    
                $customer,
    
                $request->company_id,
    
                $product->zoho_id
            );
    
            if (!$invoiceResult['success']) {
    
                throw new \Exception(
                    $invoiceResult['message']
                );
            }
    
            $invoiceId =
                $invoiceResult['invoice']['invoice_id'];
    
    
    // ===============================
// APPROVE INVOICE
// ===============================

try {

    $this->approveZohoInvoice(

        $request->company_id,

        $invoiceId,

        $customer->email ?? null
    );

    \Log::info(
        'Invoice approved successfully',
        [

            'invoice_id' =>
                $invoiceId,

            'device_id' =>
                $device->id
        ]
    );

} catch (\Exception $e) {

    \Log::error(
        'Invoice approval failed',
        [

            'invoice_id' =>
                $invoiceId,

            'device_id' =>
                $device->id,

            'error' =>
                $e->getMessage()
        ]
    );

    throw $e;
}
            \Log::info(
                'Invoice created',
                [
    
                    'device_id' =>
                        $device->id,
    
                    'invoice_id' =>
                        $invoiceId
                ]
            );
    
            // ===============================
            // SEND MAIL
            // ===============================
    
            Mail::to($customer->email)
                ->queue(
                    new WarrantyActivationMail($device)
                );
    
            // ===============================
            // APPLY CREDIT
            // ===============================
    
            if (
                !empty($request->credit_amount)
                &&
                $request->credit_amount > 0
            ) {
    
                $creditAmount = round(
                    $request->credit_amount,
                    2
                );
    
                \Log::info(
                    'Applying credit',
                    [
    
                        'invoice_id' =>
                            $invoiceId,
    
                        'amount' =>
                            $creditAmount
                    ]
                );
    
                $creditResult =
                    $this->applyCreditToInvoice(
    
                        $request->company_id,
    
                        $request->retailer_id,
    
                        $invoiceId,
    
                        $creditAmount
                    );
    
                if (!$creditResult['success']) {
    
                    throw new \Exception(
                        $creditResult['message']
                    );
                }
            }
    
            // ===============================
            // SAVE INVOICE ID
            // ===============================
    
            $device->update([
    
                'invoice_id' =>
                    $invoiceId
            ]);
            
            
            // ===============================
// DEDUCT WALLET BALANCE
// ===============================

if (
    !empty($request->credit_amount)
    &&
    $request->credit_amount > 0
) {

    $retailerCompany = Company::find(
        $request->retailer_id
    );

    if ($retailerCompany) {

        $currentWalletBalance =
            (float) (
                $retailerCompany->wallet_balance ?? 0
            );

        $newWalletBalance =
            $currentWalletBalance
            - (float) $request->credit_amount;

        if ($newWalletBalance < 0) {

            $newWalletBalance = 0;
        }

        $retailerCompany->wallet_balance =
            $newWalletBalance;

        $retailerCompany->save();
    }
}
    
            DB::commit();
    
            // ===============================
            // CUSTOMER WHATSAPP
            // ===============================
    
            try {
    
                \Log::info(
                    'Sending customer WhatsApp',
                    [
    
                        'device_id' =>
                            $device->id
                    ]
                );
    
             //   app(\App\Services\WhatsappService::class)
             //       ->sendWarranty($device);
    
            } catch (\Exception $e) {
    
                \Log::error(
                    'Customer WhatsApp failed',
                    [
    
                        'device_id' =>
                            $device->id,
    
                        'error' =>
                            $e->getMessage()
                    ]
                );
            }
    
            // ===============================
            // WARRANTY EVENT
            // ===============================
    
            event(
                new WarrantyRegisterWhatsapp($device)
            );
    
            WarrantyFlowLog::create([
    
                'payment_id' => 0,
    
                'device_id' =>
                    $device->id,
    
                'step' =>
                    'WHATSAPP_SENT',
    
                'status' => 1
            ]);
    
            // ===============================
            // RETAILER WHATSAPP
            // ===============================
    
            try {
    
                $company = Company::find(
                    $device->retailer_id
                );
    
                \Log::info(
                    'Retailer lookup',
                    [
    
                        'retailer_id' =>
                            $device->retailer_id,
    
                        'company_found' =>
                            $company ? true : false
                    ]
                );
    
                if (!$company) {
    
                    throw new \Exception(
                        'Retailer company not found'
                    );
                }
    
                if (empty($company->contact_phone)) {
    
                    throw new \Exception(
                        'Retailer phone missing'
                    );
                }
    
                /*
                |--------------------------------------------------------------------------
                | PAYMENT SUCCESS WHATSAPP
                |--------------------------------------------------------------------------
                */
    
               $invoiceNumber =
                    $invoiceResult['invoice']['invoice_number']
                    ?? '-';
                
                $invoiceDate =
                    $invoiceResult['invoice']['date']
                    ?? now()->toDateString();
                
                $invoiceAmount =
                    $request->credit_amount
                    ?? $device->product_price
                    ?? 0;
                
                $invoiceUrl =
                    $invoiceResult['invoice']['invoice_url']
                    ?? (
                        $invoiceResult['invoice']['customer_view_url']
                        ?? ''
                    );
                
                app(\App\Services\WhatsappService::class)
                    ->invoiceWhatsapp(
                
                        $company,
                
                        $invoiceNumber,
                
                        $invoiceDate,
                
                        $invoiceAmount,
                
                        $invoiceUrl
                    );
    
                /*
                |--------------------------------------------------------------------------
                | WALLET DEDUCTED WHATSAPP
                |--------------------------------------------------------------------------
                */
                 $company->refresh();
                app(\App\Services\WhatsappService::class)
                    ->sendWalletDeductedWhatsapp(
    
                        $company,
    
                        // Transaction ID
                        'WARRANTY-' . $device->id,
    
                        // Amount
                        $request->credit_amount
                            ?? $device->product_price
                            ?? 0,
    
                        // Warranty ID
                        $device->w_code
                            ?? 'WM001',
    
                        // Date
                        now()->format('d-m-Y'),
    
                        // Closing Balance
                        $company->wallet_balance
                            ?? 0
                    );
    
                \Log::info(
                    'Retailer WhatsApp sent',
                    [
    
                        'device_id' =>
                            $device->id,
    
                        'retailer_id' =>
                            $company->id
                    ]
                );
    
            } catch (\Exception $e) {
    
                \Log::error(
                    'Retailer WhatsApp failed',
                    [
    
                        'device_id' =>
                            $device->id,
    
                        'retailer_id' =>
                            $device->retailer_id,
    
                        'error' =>
                            $e->getMessage()
                    ]
                );
            }
    
            return response()->json([
    
                'success' => true,
    
                'message' =>
                    'Device created, invoice generated, credit applied.',
    
                'invoice_id' =>
                    $invoiceId
            ]);
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            \Log::error(
                'DEVICE CREATE FLOW FAILED',
                [
    
                    'error' =>
                        $e->getMessage(),
    
                    'request' =>
                        $request->all()
                ]
            );
    
            return response()->json([
    
                'success' => false,
    
                'message' =>
                    $e->getMessage()
    
            ], 500);
        }
    }
    public function applyCreditToInvoice($company_id, $retailer_id, $invoiceId, $amount)
    {
        try {
    
            $company = Company::find(1);
            $retailer = Company::find($retailer_id);
    
            if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
                return [
                    'success' => false,
                    'message' => 'Zoho credentials missing'
                ];
            }
    
            if (!$retailer || !$retailer->zoho_id) {
                return [
                    'success' => false,
                    'message' => 'Retailer Zoho contact id missing'
                ];
            }
    
            $client = new \GuzzleHttp\Client();
    
            /*
            |--------------------------------------------------------------------------
            | STEP 1 — GET CUSTOMER PAYMENTS
            |--------------------------------------------------------------------------
            */
    
            $response = $client->get(
                "https://www.zohoapis.in/books/v3/customerpayments",
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
                    ],
                    'query' => [
                        'organization_id' => $company->zoho_org_id,
                        'customer_id' => $retailer->zoho_id
                    ]
                ]
            );
    
            $body = json_decode($response->getBody(), true);
            $payments = $body['customerpayments'] ?? [];
    
            if (empty($payments)) {
                return [
                    'success' => false,
                    'message' => 'No payments found'
                ];
            }
    
            /*
            |--------------------------------------------------------------------------
            | STEP 2 — FILTER UNUSED PAYMENTS
            |--------------------------------------------------------------------------
            */
    
            $invoicePayments = [];
            $remainingAmount = $amount;
    
            foreach ($payments as $payment) {
    
                if ($payment['unused_amount'] <= 0) {
                    continue;
                }
    
                $applyAmount = min($payment['unused_amount'], $remainingAmount);
    
                $invoicePayments[] = [
                    "payment_id" => $payment['payment_id'],
                    "amount_applied" => $applyAmount
                ];
    
                $remainingAmount -= $applyAmount;
    
                if ($remainingAmount <= 0) {
                    break;
                }
            }
    
            if (empty($invoicePayments)) {
                return [
                    'success' => false,
                    'message' => 'No unused payment credits available'
                ];
            }
    
            /*
            |--------------------------------------------------------------------------
            | STEP 3 — APPLY CREDITS
            |--------------------------------------------------------------------------
            */
    
            $payload = [
                "invoice_payments" => $invoicePayments,
                "apply_creditnotes" => []
            ];
    
            $response = $client->post(
                "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}/credits",
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token,
                        'content-type'  => 'application/json'
                    ],
                    'query' => [
                        'organization_id' => $company->zoho_org_id
                    ],
                    'json' => $payload
                ]
            );
    
    
            return [
                'success' => true,
                'applied_payments' => $invoicePayments,
                'response' => json_decode($response->getBody(), true)
            ];
    
        } catch (\Exception $e) {
    
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    public function getCoverageByType(Request $request)
    {
        $request->validate([
            'product_type' => 'required'
        ]);
    
        $products = WarrantyProduct::with('coverages')
            ->where('product_type', $request->product_type)
            ->where('status', 1)
            ->get();
    
        if ($products->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No products found for this type'
            ], 404);
        }
    
        $response = $products->map(function ($product) {
            return [
                'coverage'     => $product->coverage,
                'exclusions'  => $product->exclusions,
                'features'    => $product->features
            ];
        });
    
        return response()->json([
            'status' => true,
            'data'   => $response
        ]);
    }
    
    public function updatePriceTemplate(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'warranty_product_id' => 'required|exists:w_products,id',
            'emp_payout'          => 'required|numeric|min:0',
            'retailer_payout'     => 'required|numeric|min:0',
            'other_payout'        => 'required|numeric|min:0',
            'company_payout'      => 'required|numeric|min:0',
            'company_id'          => 'required|exists:companies,id',
            'min_price'           => 'required|numeric|min:0',
            'max_price'           => 'required|numeric|min:0|gte:min_price',
            'is_fixed'            => 'required|boolean',
            'is_percent'          => 'required|boolean',
            'product_price'       => 'required'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        // ✅ Find template
        $priceTemplate = PriceTemplate::find($id);
    
        if (!$priceTemplate) {
            return response()->json([
                'status' => false,
                'message' => 'Price template not found',
            ], 404);
        }
    
        // ✅ Fetch warranty product
        $product = WarrantyProduct::find($request->warranty_product_id);
    
        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Warranty product not found.',
            ], 404);
        }
    
        // ✅ Check product min/max rules
        if ($product->min_value && $request->min_price < $product->min_value) {
            return response()->json([
                'status' => false,
                'message' => "Minimum price should not be less than product's allowed min_value ({$product->min_value})."
            ], 422);
        }
    
        if ($product->max_value && $request->max_price > $product->max_value) {
            return response()->json([
                'status' => false,
                'message' => "Maximum price should not exceed product's allowed max_value ({$product->max_value})."
            ], 422);
        }
    
        // ✅ Match product price type
        if ($product->is_fixed != $request->is_fixed) {
            return response()->json([
                'status' => false,
                'message' => "Price template 'is_fixed' must match product setting."
            ], 422);
        }
    
        if ($product->is_percent != $request->is_percent) {
            return response()->json([
                'status' => false,
                'message' => "Price template 'is_percent' must match product setting."
            ], 422);
        }
    
        // ✅ Check overlapping price range (exclude current record)
        $exists = PriceTemplate::where('warranty_product_id', $request->warranty_product_id)
            ->where('company_id', $request->company_id)
            ->where('id', '!=', $id)
            ->where(function ($query) use ($request) {
                $query->whereBetween('min_price', [$request->min_price, $request->max_price])
                    ->orWhereBetween('max_price', [$request->min_price, $request->max_price])
                    ->orWhere(function ($q) use ($request) {
                        $q->where('min_price', '<=', $request->min_price)
                          ->where('max_price', '>=', $request->max_price);
                    });
            })
            ->exists();
    
        if ($exists) {
            return response()->json([
                'status' => false,
                'message' => 'Price range already exists for this product.',
            ], 409);
        }
    
        // ✅ Update template
        $priceTemplate->update($request->all());
    
        return response()->json([
            'status' => true,
            'message' => 'Price template updated successfully',
            'price_template' => $priceTemplate,
        ]);
    }
    
public function getRetailerTransactions($company_id, $retailer_id)
{
    try {

        $company = Company::find(1);
        $retailer = Company::find($retailer_id);

        if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
            return response()->json([
                'success' => false,
                'message' => 'Zoho credentials missing'
            ], 400);
        }

        if (!$retailer || !$retailer->zoho_id) {
            return response()->json([
                'success' => false,
                'message' => 'Retailer Zoho contact id missing'
            ], 400);
        }

        $client = new \GuzzleHttp\Client();

        /*
        |--------------------------------------------------------------------------
        | FETCH CUSTOMER PAYMENTS
        |--------------------------------------------------------------------------
        */

        $response = $client->get(
            "https://www.zohoapis.in/books/v3/customerpayments",
            [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
                ],
                'query' => [
                    'organization_id' => $company->zoho_org_id,
                    'customer_id' => $retailer->zoho_id
                ]
            ]
        );

        $body = json_decode($response->getBody(), true);
        $payments = $body['customerpayments'] ?? [];

       


        return response()->json([
            'success' => true,
            'retailer_id' => $retailer_id,
            'transactions' => $payments
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

public function getInvoicesAgainstPayment($company_id, $payment_id)
{
    try {

        $company = Company::find(1);

        if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
            return response()->json([
                'success' => false,
                'message' => 'Zoho credentials missing'
            ], 400);
        }

        $client = new \GuzzleHttp\Client();

        $response = $client->get(
            "https://www.zohoapis.in/books/v3/customerpayments/{$payment_id}",
            [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
                ],
                'query' => [
                    'organization_id' => $company->zoho_org_id
                ]
            ]
        );

        $body = json_decode($response->getBody(), true);

        $payment = $body['payment'] ?? [];

        $invoices = $payment['invoices'] ?? [];

        $invoiceList = [];

        foreach ($invoices as $invoice) {

            $invoiceList[] = [
                'invoice_id' => $invoice['invoice_id'],
                'invoice_number' => $invoice['invoice_number'],
                'invoice_amount' => $invoice['invoice_amount'] ?? 0,
                'amount_applied' => $invoice['amount_applied'] ?? 0,
                'date' => $invoice['date'] ?? null
            ];
        }

        return response()->json([
            'success' => true,
            'payment_id' => $payment_id,
            'payment_amount' => $payment['amount'] ?? 0,
            'unused_amount' => $payment['unused_amount'] ?? 0,
            'invoices' => $invoiceList
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

    public function createSubscriptionInvoice(SubscribedPackage $package, $company_id, $zoho_product_id)
    {
        try {
    
            $orgUser = Company::find(1);
            if (!$orgUser) {
                return ['success' => false, 'message' => 'Organization not found'];
            }
    
            if (empty($orgUser->zoho_access_token) || empty($orgUser->zoho_org_id)) {
                return ['success' => false, 'message' => 'Zoho credentials missing'];
            }
    
            $accessToken = $orgUser->zoho_access_token;
            $orgId = $orgUser->zoho_org_id;
    
            $retailer = Company::find($package->retailer_id);
    
            if (!$retailer || !$retailer->zoho_id) {
                return ['success' => false, 'message' => 'Retailer Zoho ID missing'];
            }
    
            $invoicePayload = [
                'customer_id' => $retailer->zoho_id,
                'reference_number' => $package->subscription_code,
                'is_inclusive_tax' => true,
                'notes' => $package->product_name,
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'line_items' => [
                    [
                        'item_id' => $zoho_product_id,
                        'name' => $package->product_name,
                        'rate' => $package->amount ?? 0,
                    ],
                ],
            ];
    
            $client = new \GuzzleHttp\Client();
    
            $response = $client->post('https://www.zohoapis.in/books/v3/invoices', [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'query' => ['organization_id' => $orgId],
                'json' => $invoicePayload,
            ]);
    
            $responseBody = json_decode($response->getBody(), true);
    
            if (!isset($responseBody['invoice'])) {
                return [
                    'success' => false,
                    'message' => 'Invoice not created in Zoho',
                    'response' => $responseBody
                ];
            }
    
            $invoice = $responseBody['invoice'];
    
            // Save invoice record
            ZohoInvoice::create([
                'invoice_id' => $invoice['invoice_id'],
                'contact_id' => $package->retailer_id,
                'org_id' => $orgId,
                'company_id' => $company_id,
                'zoho_json' => json_encode($invoice),
                'invoice_status' => $invoice['status'] ?? 'created',
                'invoice_amount' => $invoice['total'] ?? 0,
                'balance_amount' => $invoice['balance'] ?? 0,
            ]);
    
            // Update subscription (NOT $device)
            $package->update([
                'zoho_invoice_id' => $invoice['invoice_id'],
                'invoice_status' => $invoice['status'] ?? 'created',
                'invoice_json' => json_encode($invoice),
                'invoice_created_date' => now(),
                'status'=>1
            ]);
    
            // Send email AFTER success
            Mail::to($retailer->contact_email)->queue(
                new InvoiceCreatedMail($invoice, $invoice['invoice_url'] ?? '#')
            );
    
            return [
                'success' => true,
                'invoice' => $invoice
            ];
    
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    //create customer with subscription

   public function createDeviceWithInvoiceAndSubscription(Request $request)
    {
        $validator = Validator::make($request->all(), [
    
            'imei1'         => 'required',
    
            'product_id'    => 'required|exists:w_products,id',
    
            'company_id'    => 'required|exists:companies,id',
    
            'retailer_id'   => 'required|exists:companies,id',
    
            'w_customer_id' => 'required|exists:w_customers,id',
    
            'subscription_id' => 'required|exists:subscribed_packages,id'
        ]);
    
        if ($validator->fails()) {
    
            return response()->json([
    
                'success' => false,
    
                'errors'  => $validator->errors()
    
            ], 422);
        }
    
        DB::beginTransaction();
    
        try {
    
            // ===============================
            // VALIDATE SUBSCRIPTION
            // ===============================
    
            $subscriptionPackage = SubscribedPackage::find(
                $request->subscription_id
            );
    
            if (!$subscriptionPackage) {
    
                return response()->json([
    
                    'success' => false,
    
                    'message' =>
                        'Subscription package not found.'
    
                ], 404);
            }
    
            /*
            |--------------------------------------------------------------------------
            | CHECK SUBSCRIPTION STATUS
            |--------------------------------------------------------------------------
            */
    
            if ((int) $subscriptionPackage->status !== 1) {
    
                return response()->json([
    
                    'success' => false,
    
                    'message' =>
                        'Subscription is inactive.'
    
                ], 422);
            }
    
            /*
            |--------------------------------------------------------------------------
            | CHECK SUBSCRIPTION BALANCE
            |--------------------------------------------------------------------------
            */
    
            if ((int) $subscriptionPackage->balance <= 0) {
    
                return response()->json([
    
                    'success' => false,
    
                    'message' =>
                        'Subscription balance exhausted.'
    
                ], 422);
            }
    
            /*
            |--------------------------------------------------------------------------
            | CHECK SUBSCRIPTION EXPIRY
            |--------------------------------------------------------------------------
            */
    
            if (
                !empty($subscriptionPackage->end_date)
                &&
                Carbon::parse(
                    $subscriptionPackage->end_date
                )->endOfDay()->lt(now())
            ) {
    
                return response()->json([
    
                    'success' => false,
    
                    'message' =>
                        'Subscription expired.'
    
                ], 422);
            }
    
            // ===============================
            // DUPLICATE CHECK
            // ===============================
    
            $exists = WDevice::where(
                    'product_id',
                    $request->product_id
                )
                ->where(function ($query) use ($request) {
    
                    $query->where(
                        'imei1',
                        $request->imei1
                    );
    
                    if ($request->imei2) {
    
                        $query->orWhere(
                            'imei2',
                            $request->imei2
                        );
                    }
    
                    if ($request->serial) {
    
                        $query->orWhere(
                            'serial',
                            $request->serial
                        );
                    }
                })
                ->exists();
    
            if ($exists) {
    
                throw new \Exception(
                    'Device with the same IMEI or Serial already exists.'
                );
            }
    
            // ===============================
            // GET PRODUCT
            // ===============================
    
            $wproduct = WarrantyProduct::find(
                $request->product_id
            );
    
            // ===============================
            // CREATE DEVICE
            // ===============================
    
            $product_mrp = $wproduct->per_device_product_mrp;
            $product_price = $wproduct->per_device_price;
    
            $productType = $request->product_type ?? "Extended Warranty";
            $companyId = $request->company_id;
    
            $payouts= $this->getMatchingPriceTemplateforDevice($request->device_price, $productType, $companyId);
            $priceTemplate = $payouts->first();
            $device = WDevice::create([
    
                'imei1' =>
                    $request->imei1,
    
                'imei2' =>
                    $request->imei2,
    
                'serial' =>
                    $request->serial,
    
                'brand_id' =>
                    $request->brand_id,
    
                'category_id' =>
                    $request->category_id,
    
                'product_id' =>
                    $request->product_id,
    
                'product_name' =>
                    $request->product_name,
    
                'brand_name' =>
                    $request->brand_name,
    
                'model' =>
                    $request->model,
    
                'model_id' =>
                    $request->model_id,
    
                'category_name' =>
                    $request->category_name,
    
                'available_claim' =>
                    $request->available_claim,
    
                'expiry_date' =>
                    $request->expiry_date,
    
                'document_url' =>
                    $request->document_url,
    
                'link1' =>
                    $request->link1,
    
                'link2' =>
                    $request->link2,
    
                'device_price' =>
                    $request->device_price,
    
                'product_price' =>
                    $product_price,
    
                'product_mrp' =>
                    $product_mrp,
    
               'retailer_payout' => $priceTemplate->retailer_payout ?? 0,

    'employee_payout' => $priceTemplate->emp_payout ?? 0,

    'other_payout' => $priceTemplate->other_payout ?? 0,

    'company_payout' => $priceTemplate->company_payout ?? 0,
    
                'company_id' =>
                    $request->company_id,
    
                'retailer_id' =>
                    $request->retailer_id,
    
                'promoter_id' =>
                    $request->promoter_id,
    
                'name' =>
                    $request->name,
    
                'w_customer_id' =>
                    $request->w_customer_id,
    
                'agent_id' =>
                    $request->agent_id,
    
                'created_by' =>
                    $request->created_by,
    
                'is_approved' => 1,
    
                'is_pay_later' =>
                    $request->is_pay_later,
    
                'is_from_wallet' =>
                    $request->is_from_wallet ?? 0,
    
                'subscription_id' =>
                    $subscriptionPackage->id,
    
                'status' => 1
            ]);
    
            // ===============================
            // GENERATE WARRANTY CODE
            // ===============================
    
            $device->w_code =
                'WRT-' .
                $device->id .
                '-' .
                strtoupper(Str::random(6));
    
            $device->save();
    
            // ===============================
            // GET CUSTOMER
            // ===============================
    
            $customer = WCustomer::findOrFail(
                $device->w_customer_id
            );
    
            // ===============================
            // VALIDATE PRODUCT
            // ===============================
    
            $product = WarrantyProduct::findOrFail(
                $request->product_id
            );
    
            if (!$product->zoho_id) {
    
                throw new \Exception(
                    'Zoho product mapping not found'
                );
            }
    
            // ===============================
            // SEND MAIL
            // ===============================
    
            Mail::to($customer->email)
                ->queue(
                    new WarrantyActivationMail($device)
                );
    
            // ===============================
            // SAVE INVOICE DETAILS
            // ===============================
    
            $device->update([
    
                'subscription_id' =>
                    $subscriptionPackage->id,
    
                'invoice_id' =>
                    $subscriptionPackage->zoho_invoice_id,
    
                'invoice_status' =>
                    $subscriptionPackage->invoice_status
            ]);
    
            // ===============================
            // DEDUCT SUBSCRIPTION BALANCE
            // ===============================
    
            $subscriptionPackage->refresh();
    
            if ((int) $subscriptionPackage->balance <= 0) {
    
                throw new \Exception(
                    'Subscription balance exhausted.'
                );
            }
    
            $subscriptionPackage->decrement('balance');
    
            DB::commit();
    
            // ===============================
            // CUSTOMER WHATSAPP
            // ===============================
    
            try {
    
                \Log::info(
                    'Sending customer WhatsApp',
                    [
    
                        'device_id' =>
                            $device->id
                    ]
                );
    
                app(\App\Services\WhatsappService::class)
                    ->sendWarranty($device);
    
                event(
                    new WarrantyRegisterWhatsapp($device)
                );
    
                WarrantyFlowLog::create([
    
                    'payment_id' => 0,
    
                    'device_id' =>
                        $device->id,
    
                    'step' =>
                        'WHATSAPP_SENT',
    
                    'status' => 1
                ]);
    
            } catch (\Exception $e) {
    
                \Log::error(
                    'Customer WhatsApp failed',
                    [
    
                        'device_id' =>
                            $device->id,
    
                        'error' =>
                            $e->getMessage()
                    ]
                );
            }
    
            // ===============================
            // RETAILER WHATSAPP
            // ===============================
    
            try {
    
                $company = Company::find(
                    $device->retailer_id
                );
    
                \Log::info(
                    'Retailer lookup',
                    [
    
                        'retailer_id' =>
                            $device->retailer_id,
    
                        'company_found' =>
                            $company ? true : false
                    ]
                );
    
                if (!$company) {
    
                    throw new \Exception(
                        'Retailer company not found'
                    );
                }
    
                if (empty($company->contact_phone)) {
    
                    throw new \Exception(
                        'Retailer phone missing'
                    );
                }
    
                \Log::info(
                    'Sending retailer invoice WhatsApp',
                    [
    
                        'retailer_id' =>
                            $company->id,
    
                        'phone' =>
                            $company->contact_phone
                    ]
                );
    
                $invoiceNumber =
                    $subscriptionPackage->zoho_invoice_number
                    ?? '-';
    
                $invoiceDate =
                    $subscriptionPackage->created_at
                    ?? now();
    
                $invoiceAmount =
                    $subscriptionPackage->amount
                    ?? 0;
    
                $invoiceUrl =
                    $subscriptionPackage->invoice_url
                    ?? '';
    
                app(\App\Services\WhatsappService::class)
                    ->invoiceWhatsapp(
    
                        $company,
    
                        $invoiceNumber,
    
                        $invoiceDate,
    
                        $invoiceAmount,
    
                        $invoiceUrl
                    );
    
                \Log::info(
                    'Retailer invoice WhatsApp sent',
                    [
    
                        'device_id' =>
                            $device->id,
    
                        'retailer_id' =>
                            $company->id
                    ]
                );
    
            } catch (\Exception $e) {
    
                \Log::error(
                    'Retailer invoice WhatsApp failed',
                    [
    
                        'device_id' =>
                            $device->id,
    
                        'retailer_id' =>
                            $device->retailer_id,
    
                        'error' =>
                            $e->getMessage()
                    ]
                );
            }
    
            return response()->json([
    
                'success' => true,
    
                'message' =>
                    'Device created successfully using subscription.',
    
                'device_id' =>
                    $device->id,
    
                'warranty_code' =>
                    $device->w_code,
    
                'subscription_id' =>
                    $subscriptionPackage->id,
    
                'remaining_balance' =>
                    $subscriptionPackage->fresh()->balance,
    
                'invoice_id' =>
                    $subscriptionPackage->zoho_invoice_id
            ]);
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            \Log::error(
                'DEVICE CREATE FLOW FAILED',
                [
    
                    'error' =>
                        $e->getMessage(),
    
                    'line' =>
                        $e->getLine(),
    
                    'file' =>
                        $e->getFile(),
    
                    'request' =>
                        $request->all()
                ]
            );
    
            return response()->json([
    
                'success' => false,
    
                'message' =>
                    $e->getMessage()
    
            ], 500);
        }
    }
  //
public function createDeviceByCustomer(Request $request)
{
    $validator = Validator::make($request->all(), [
        'imei1'         => 'required',
        'product_id'    => 'required|exists:w_products,id',
        'company_id'    => 'required|exists:companies,id',
        'retailer_id'   => 'required|exists:companies,id',

        // Customer fields (required now)
        'name'          => 'required|string|max:255',
        'mobile'        => 'required|string|max:20',
        'email'         => 'nullable|email',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();

    try {


        $pincodeData = IndiaPincode::where('pincode', $request->pincode)->first();
        
        if (!$pincodeData) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid pincode'
            ], 422);
        }

        $customer = WCustomer::where('mobile', $request->mobile)
            ->orWhere(function ($q) use ($request) {
                if ($request->email) {
                    $q->where('email', $request->email);
                }
            })
            ->first();

       if (!$customer) {
        
            $customer = WCustomer::create([
        
                'name'        => $request->name,
                'mobile'      => $request->mobile,
                'email'       => $request->email ?? null,
                'pincode'     => $request->pincode,
                'state'       => $pincodeData->state,
                'city'        => $pincodeData->district,
                'company_id'  => $request->company_id,
                'retailer_id' => $request->retailer_id,
        
            ]);
        
        }

        // ==========================================
        // 2. DUPLICATE DEVICE CHECK
        // ==========================================

        $exists = WDevice::where('product_id', $request->product_id)
            ->where(function ($query) use ($request) {

                $query->where('imei1', $request->imei1);

                if ($request->imei2) {
                    $query->orWhere('imei2', $request->imei2);
                }

                if ($request->serial) {
                    $query->orWhere('serial', $request->serial);
                }
            })
            ->exists();

        if ($exists) {
            throw new \Exception('Device already registered with IMEI/Serial.');
        }

        // ==========================================
        // 3. CREATE DEVICE
        // ==========================================

        $device = WDevice::create([
            'imei1' => $request->imei1,
            'imei2' => $request->imei2,
            'serial' => $request->serial,

            'brand_id' => $request->brand_id,
            'category_id' => $request->category_id,
            'product_id' => $request->product_id,

            'product_name' => $request->product_name,
            'brand_name' => $request->brand_name,
            'model' => $request->model,
            'model_id' => $request->model_id,
            'category_name' => $request->category_name,

            'available_claim' => $request->available_claim,
            'expiry_date' => $request->expiry_date,

            'device_price' => $request->device_price,
            'product_price' => $request->product_price,
            'product_mrp' => 0,

            'company_id' => $request->company_id,
            'retailer_id' => $request->retailer_id,

            'name' => $customer->name,
            'w_customer_id' => $customer->id,

            'is_approved' => 1,
            'status' => 1
        ]);

        // ==========================================
        // 4. WARRANTY CODE
        // ==========================================

        $device->w_code = 'WRT-' . $device->id . '-' . strtoupper(Str::random(6));
        $device->save();

        // ==========================================
        // 5. EMAIL + WHATSAPP (UNCHANGED)
        // ==========================================

        if ($customer->email) {
            Mail::to($customer->email)
                ->queue(new WarrantyActivationMail($device));
        }

        DB::commit();

        // WhatsApp (outside transaction)
     //   app(\App\Services\WhatsappService::class)
      //      ->sendWarranty($device);

        return response()->json([
            'success' => true,
            'message' => 'Customer + Device created successfully',
            'customer_id' => $customer->id,
            'device_id' => $device->id
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        \Log::error('DEVICE CREATE FAILED', [
            'error' => $e->getMessage(),
            'request' => $request->all()
        ]);

        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}
    public function productSalesReport(Request $request)
    
    {
    
        $query = WDevice::query();
    
        // Filters (same logic as before)
    
        if ($request->filled('agent_id')) {
    
            $query->where('agent_id', $request->agent_id);
    
        }
    
        if ($request->filled('company_id')) {
    
            $query->where('company_id', $request->company_id);
    
        }
    
        if ($request->filled('created_by')) {
    
            $query->where('created_by', $request->created_by);
    
        }
    
        if ($request->filled('retailer_id')) {
    
            $query->where('retailer_id', $request->retailer_id);
    
        }
    
        // Optional date range
    
        if ($request->filled('start_date')) {
    
            $query->whereDate('created_at', '>=', $request->start_date);
    
        }
    
        if ($request->filled('end_date')) {
    
            $query->whereDate('created_at', '<=', $request->end_date);
    
        }
    
        //  Get grouped raw data
    
        $rows = $query->selectRaw("
    
                DATE(created_at) as date,
    
                product_name,
    
                COUNT(*) as total
    
            ")
    
            ->whereNotNull('product_name')
    
            ->groupBy('date', 'product_name')
    
            ->orderBy('date')
    
            ->get();
    
        //  Transform to required format
    
        $result = [];
    
        foreach ($rows as $row) {
    
            $date = $row->date;
    
            $product = strtolower($row->product_name); // match your frontend format
    
            if (!isset($result[$date])) {
    
                $result[$date] = [
    
                    'date' => $date
    
                ];
    
            }
    
            $result[$date][$product] = (int) $row->total;
    
        }
    
        // Re-index array
    
        $final = array_values($result);
    
        return response()->json($final);
    
    }
    
    public function approveZohoInvoice(
    $company_id,
    $invoiceId,
    $email = null
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
                'Zoho credentials missing'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | API CLIENT
        |--------------------------------------------------------------------------
        */

        $client = new \GuzzleHttp\Client();

        /*
        |--------------------------------------------------------------------------
        | PAYLOAD
        |--------------------------------------------------------------------------
        */

        $payload = [

            'send_from_org_email_id' => false
        ];

        /*
        |--------------------------------------------------------------------------
        | OPTIONAL EMAIL
        |--------------------------------------------------------------------------
        */

        if (!empty($email)) {

            $payload['to_mail_ids'] = [
                $email
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | API CALL
        |--------------------------------------------------------------------------
        */

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

                'json' => $payload
            ]
        );

        $body = json_decode(
            $response->getBody(),
            true
        );

        \Log::info(
            'ZOHO INVOICE APPROVED',
            [

                'invoice_id' =>
                    $invoiceId,

                'response' =>
                    $body
            ]
        );

        return [

            'success' => true,

            'response' => $body
        ];

    } catch (\Exception $e) {

        \Log::error(
            'ZOHO INVOICE APPROVAL FAILED',
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

            'message' =>
                $e->getMessage()
        ];
    }
}
}