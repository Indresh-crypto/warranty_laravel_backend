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
        $validator = Validator::make($request->all(), [
            'company_id'     => 'required|integer|exists:companies,id',
            'category_id'    => 'required|integer|exists:category,id',
            'product_price'  => 'required|numeric|min:0',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }
    
        // ✅ Step 1: Get all product IDs linked to this category
        $productIds = WarrantyProduct::whereHas('categories', function ($query) use ($request) {
            $query->where('category.id', $request->category_id); 
        })->pluck('id');
    
        if ($productIds->isEmpty()) {
            return response()->json([
                'status'  => false,
                'message' => 'No products found under the given category',
                'data'    => [],
            ], 404);
        }
    
        // ✅ Step 2: Find matching templates
        $matchingTemplates = PriceTemplate::with('warrantyProduct.categories')
            ->where('company_id', $request->company_id)
            ->whereIn('warranty_product_id', $productIds)
            ->where('min_price', '<=', $request->product_price)
            ->where('max_price', '>=', $request->product_price)
            ->get();
    
        if ($matchingTemplates->isEmpty()) {
            return response()->json([
                'status'  => false,
                'message' => 'No matching price templates found',
                'data'    => [],
            ], 404);
        }
    
        return response()->json([
            'status'  => true,
            'message' => 'Matching price templates retrieved successfully',
            'data'    => $matchingTemplates,
        ], 200);
    }

    public function getProductsWithCategories()
    {
        $products = WarrantyProduct::with('categories')->get(); 

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

    // ✅ Fetch warranty product
    $product = WarrantyProduct::find($request->warranty_product_id);

    if (!$product) {
        return response()->json([
            'status' => false,
            'message' => 'Warranty product not found.',
        ], 404);
    }

    // ✅ Ensure min/max is inside product range
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

    // ✅ Ensure is_fixed / is_percent matches product
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

    // ✅ Create price template
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

        $orgUser = Company::find($company_id);
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

        // ✅ Update device
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
                'is_approved' => 0,
                'status'=>0,
                'is_pay_later'=>$request->is_pay_later,
                'model_id' => $request->model_id
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
                    app(\App\Services\WhatsappService::class)
                        ->sendWarrantyProvision($device);
                } catch (\Exception $e) {
                    \Log::error('Provision WhatsApp failed', [
                        'device_id' => $device->id,
                        'error' => $e->getMessage()
                    ]);
                }


            return response()->json([
                'message' => 'Device created successfully',
                'device' => $device
            ], 201);
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

    if ($request->has('company_id')) {
        $query->where('company_id', $request->company_id);
    }

    $companiesWithProducts = $query->get()
        ->groupBy('company_id')
        ->map(function ($items, $companyId) {
            return [
                'company_id' => $companyId,
                'company' => $items->first()->company,

                // Return ALL company_product fields + product details
                'products' => $items->map(function ($item) {
                    return [
                        'company_product_id' => $item->id,
                        'product_id' => $item->product_id,
                        'company_id' => $item->company_id,
                        'margin' => $item->margin,
                        'p_status' => $item->p_status,
                        'product' => $item->product // full product object
                    ];
                }),
            ];
        })
        ->values();

    return response()->json([
        'message' => 'Company products grouped by company retrieved successfully.',
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

    public function updateProduct(Request $request, $id)
    {
        DB::beginTransaction();
    
        try {
    
            $product = WarrantyProduct::findOrFail($id);
    
            // ✅ Generate same product name format as createProduct
            $productName = collect([
                $request->plan_type,
                $request->category_name,
                $request->validity ? '(' . $request->validity . ' Days)' : null,
                ($request->min_value !== null && $request->max_value !== null)
                    ? $request->min_value . ' to ' . $request->max_value
                    : null
            ])->filter()->implode(' ');
    
            // 1️⃣ Update product
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
                'plan_type'     => $request->plan_type
            ]);
    
            // 2️⃣ Sync categories
            if ($request->has('category_ids')) {
                $product->categories()->sync($request->category_ids);
            }
    
            // 3️⃣ Sync coverages
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
                'message' => 'Product updated successfully',
                'product' => $product->load(['categories', 'coverages'])
            ], 200);
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update product',
                'error'   => $e->getMessage()
            ], 500);
        }
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
    
     
         $productName = collect([
                $request->plan_type,
                $request->category_name,
                $request->validity ? '(' . $request->validity . ' Days)' : null,
                ($request->min_value !== null && $request->max_value !== null)
                    ? $request->min_value . ' to ' . $request->max_value
                    : null
            ])->filter()->implode(' ');

        // Find organization user
        $orgUser = Company::where('id', $request->company_id)
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
                'product_type' => $request->plan_type
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

        // 🚫 Skip if already assigned
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
        ->where('imei1', $request->imei1)
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
    $certificateId = 'GX-WNTY-' . now()->year . '-' . str_pad($device->id, 5, '0', STR_PAD_LEFT);

    /** Generate PDF from Blade */
    $pdf = Pdf::loadView('certificate', [
        'device' => $device,
        'customer' => $customer,
        'product' => $product,
        'retailer' => $retailer,
        'certificateId' => $certificateId
    ])->setPaper('A4');

    /** Folder */
    $folderPath = storage_path('app/public/warranty_pdfs');
    if (!file_exists($folderPath)) {
        mkdir($folderPath, 0777, true);
    }

    /** Save PDF */
    $pdfFileName = $certificateId . '.pdf';
    $pdf->save($folderPath . '/' . $pdfFileName);

    $certificateLink = Storage::disk('public')->url('warranty_pdfs/'.$pdfFileName);

    /** Update device */
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

public function getMatchingPriceTemplateforDevice($devicePrice, $productType, $companyId, $categoryId)
    {
        return PriceTemplate::with('product')
            ->where('company_id', $companyId)
            ->where('product_type', $productType)
            ->where('min_price', '<=', $devicePrice)
            ->where('max_price', '>=', $devicePrice)
            // ✅ Filter by the product's category using whereHas
            ->whereHas('product.categories', function ($query) use ($categoryId) {
                // 'category.id' because your Category model has protected $table = 'category';
                $query->where('category.id', $categoryId); 
            })
            ->orderBy('id', 'asc')
            ->get();   // 🔥 MUST BE get()
    }
    
public function priceReport(Request $request)
    {
        $companyId  = $request->company_id;
        $brandId    = $request->brand_id;      // optional
        $categoryId = $request->category_id;   // optional

        $devicesQuery = DeviceModel::select('device_models.*')
            ->join('brands', 'brands.id', '=', 'device_models.brand_id')
            ->with(['brand', 'category'])
            ->where('device_models.status', 1);

        // ✅ Filter by brand if provided
        if (!empty($brandId)) {
            $devicesQuery->where('device_models.brand_id', $brandId);
        }

        // ✅ Filter by category if provided
        if (!empty($categoryId)) {
            $devicesQuery->where('device_models.category_id', $categoryId);
        }

        // ✅ Keep sorting
        $devices = $devicesQuery
            ->orderBy('brands.name', 'asc')
            ->orderBy('device_models.price', 'asc')
            ->get();

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

                // ✅ Pass $device->category_id as the 4th parameter
                $templates = $this->getMatchingPriceTemplateforDevice(
                    $device->price,
                    $type,
                    $companyId,
                    $device->category_id 
                );

                if ($templates->isEmpty()) {
                    $modelData['packages'][] = [
                        'product_type'    => $type,
                        'product_name'    => null,
                        'claims'          => null,
                        'validity_days'   => null,
                        'product_price'   => null,
                        'company_payout'  => null,
                        'agent_payout'    => null,
                        'other_payout'    => null,
                        'retailer_payout' => null,
                        'is_matched'      => false,
                    ];
                    continue;
                }

                foreach ($templates as $template) {

                   $productPrice = $template->product?->mrp ?? 0;

                   if ($template->is_percent) {
                       $companyPayout  = ($device->price * $template->company_payout) / 100;
                       $agentPayout    = ($device->price * $template->emp_payout) / 100;
                       $otherPayout    = ($device->price * $template->other_payout) / 100;
                       $retailerPayout = ($device->price * $template->retailer_payout) / 100;
                       $productMrp = ($device->price * $productPrice) / 100;
                   } else {
                       $companyPayout  = $template->company_payout;
                       $agentPayout    = $template->emp_payout;
                       $otherPayout    = $template->other_payout;
                       $retailerPayout = $template->retailer_payout;
                       $productMrp = $productPrice; 
                   }
                       
                   $modelData['packages'][] = [
                       'product_type'    => $type,
                       'product_name'    => $template->product?->name,
                       'claims'          => $template->product?->claims,
                       'validity_days'   => $template->product?->validity,
                       'product_price'   => $productMrp,
                       'company_payout'  => $companyPayout,
                       'agent_payout'    => $agentPayout,
                       'other_payout'    => $otherPayout,
                       'retailer_payout' => $retailerPayout,
                       'is_matched'      => true,
                   ];
                }
            }

            $finalReport[] = $modelData;
        }

        return response()->json([
            'success' => true,
            'data'    => $finalReport
        ]);
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
                throw new \Exception('Device with the same IMEI or Serial already exists.');
            }
    
            // ===============================
            // CALCULATE MRP
            // ===============================
    
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
    
            // ===============================
            // CREATE DEVICE
            // ===============================
    
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
    
                'document_url' => $request->document_url,
                'link1' => $request->link1,
                'link2' => $request->link2,
    
                'device_price' => $request->device_price,
                'product_price' => $request->product_price,
                'product_mrp' => $product_mrp,
    
               'retailer_payout' => $pricing['retailer_payout'],
                'employee_payout' => $pricing['employee_payout'],
                'other_payout'    => $pricing['other_payout'],
                'company_payout'  => $pricing['company_payout'],
    
                'company_id' => $request->company_id,
                'retailer_id' => $request->retailer_id,
                'w_customer_id' => $request->w_customer_id,
    
                'agent_id' => $request->agent_id,
                'created_by' => $request->created_by,
    
                'is_approved' => 1,
                'is_pay_later' => $request->is_pay_later,
    
                'status' => 1
            ]);
    
            // ===============================
            // GENERATE WARRANTY CODE
            // ===============================
    
            $device->w_code = 'WRT-' . $device->id . '-' . strtoupper(Str::random(6));
            $device->save();
    
            // ===============================
            // GET CUSTOMER
            // ===============================
    
            $customer = WCustomer::findOrFail($device->w_customer_id);
    
            // ===============================
            // GET ZOHO PRODUCT
            // ===============================
    
            $product = WarrantyProduct::findOrFail($request->product_id);
    
            if (!$product->zoho_id) {
                throw new \Exception('Zoho product mapping not found');
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
                throw new \Exception($invoiceResult['message']);
            }
    
            $invoiceId = $invoiceResult['invoice']['invoice_id'];
    
            // ===============================
            // APPLY CREDIT
            // ===============================
    
            if (!empty($request->credit_amount) && $request->credit_amount > 0) {
            
                $creditAmount = round($request->credit_amount, 2);
            
                $creditResult = $this->applyCreditToInvoice(
                    $request->company_id,
                    $request->retailer_id,
                    $invoiceId,
                    $creditAmount
                );
            
                if (!$creditResult['success']) {
                    throw new \Exception($creditResult['message']);
                }
            }
    
            // ===============================
            // SAVE INVOICE
            // ===============================
    
            $device->update([
                'invoice_id' => $invoiceId
            ]);
    
            DB::commit();
    
            event(new WarrantyRegisterWhatsapp($device->fresh()));
            
            
             try {
                    app(\App\Services\WhatsappService::class)
                        ->sendWarranty($device);
                } catch (\Exception $e) {
                    \Log::error('WhatsApp failed', [
                        'device_id' => $device->id,
                        'error' => $e->getMessage()
                    ]);
                }


            return response()->json([
                'success' => true,
                'message' => 'Device created, invoice generated, credit applied.',
                'invoice_id' => $invoiceId
            ]);
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function applyCreditToInvoice($company_id, $retailer_id, $invoiceId, $amount)
    {
        try {
    
            $company = Company::find($company_id);
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

        $company = Company::find($company_id);
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

        $company = Company::find($company_id);

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
}