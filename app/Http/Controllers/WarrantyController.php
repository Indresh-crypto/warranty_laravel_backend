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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // ✅ MOVE HERE
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\WarrantyProductCoverage;
use DB;

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
        'category_id'    => 'required|integer|exists:category,id', // ✅ table name 'category'
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
        $query->where('category.id', $request->category_id); // ✅ use 'category.id'
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

            $retailer = Company::find($customer->retailer_id);
            if (!$retailer || !$retailer->zoho_contact_id) {
                return [
                    'success' => false,
                    'message' => 'Retailer or Zoho customer ID not found.',
                ];
            }

            $invoicePayload = [
                'customer_id' => $retailer->zoho_contact_id,
                'reference_number' => "WTY" . $device->id,
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

            if (isset($responseBody['invoice'])) {
                $invoice = $responseBody['invoice'];

               ZohoInvoice::create([
                    'invoice_id'       => $responseBody['invoice']['invoice_id'],
                    'contact_id'       => $customer->retailer_id,
                    'org_id'           => $orgId,
                    'company_id'       => $company_id,
                    'user_id'          => $customer->retailer_id,
                    'role'             => 'sales',
                    'zoho_json'        => json_encode($responseBody['invoice']),
                    'created_by_id'    => $customer->retailer_id,
                    'created_by_name'  => $customer->name,
                    'invoice_status'   => $responseBody['invoice']['status'] ?? 'sent',
                    'due_date' => now()->addDays(7)->toDateString(),
                    'payment_terms_label' => "You need to clear the invoice within 7 days.",
                    'payment_date'     => null,
                    'invoice_amount'   => $responseBody['invoice']['total'],
                    'balance_amount'   => $responseBody['invoice']['balance'],
                    'product_type'     => $quotation->product_type ?? 'goods',
                    'quotation_id'     => 0
                    
                ]);

                return [
                    'success' => true,
                    'message' => 'Invoice created and recorded successfully.',
                    'invoice' => $invoice,
                ];
            }

            return [
                'success' => false,
                'message' => 'Invoice data missing in Zoho response.',
                'response' => $responseBody,
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
    // 🔒 Check duplicate device
    $exists = WDevice::where('imei1', $request->imei1)
        ->orWhere('imei2', $request->imei2)
        ->orWhere('serial', $request->serial)
        ->exists();

    if ($exists) {
        return response()->json([
            'message' => 'Device with the same IMEI or Serial already exists.'
        ], 409);
    }

    // ✅ Step 1: Create device
    $device = WDevice::create([
        'name' => $request->name,
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
        'retailer_payout' => $request->retailer_payout,
        'employee_payout' => $request->employee_payout,
        'other_payout' => $request->other_payout,
        'company_payout' => $request->company_payout,
        'product_price' => $request->product_price,
        'agent_id' => $request->agent_id,
        'created_by' => $request->created_by,
        'is_approved' => 1
    ]);

    // ✅ Step 2: Generate WRT code using primary key
    $random = strtoupper(Str::random(6)); // A9F3XQ
    $wCode = "WRT-{$device->id}-{$random}";

    // ✅ Step 3: Update device with w_code
    $device->update([
        'w_code' => $wCode
    ]);

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

        // 1️⃣ Update product
        $product->update([
            'name'          => $request->name,
            'image'         => $request->image,
            'zoho_id'       => $request->zoho_id,
            'hsn_code'      => $request->hsn_code,
            'validity'      => $request->validity,
            'claims'        => $request->claims,
            'product_value' => $request->product_value,
            'cover_value'   => $request->cover_value,
            'features'      => $request->features,
            'min_value'     => $request->min_value,
            'max_value'     => $request->max_value,
            'status'        => $request->status,
            'coverage'      => $request->coverage, // optional to keep
            'exclustions'   => $request->exclustions
        ]);

        // 2️⃣ Sync categories (existing logic)
        if ($request->has('category_ids')) {
            $product->categories()->sync($request->category_ids);
        }

        // 3️⃣ SYNC COVERAGES (IMPORTANT PART)
        if (!empty($request->coverage)) {

            // 🔴 Delete old coverages
            WarrantyProductCoverage::where(
                'warranty_product_id',
                $product->id
            )->delete();

            // 🔵 Split coverage string
            $coverages = array_map(
                'trim',
                preg_split('/[.|]/', $request->coverage)
            );

            // 🟢 Insert new coverages
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
            'name' => 'required|string|max:255',
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
            'status'    => 'required',
            'margin'    => 'required',
            'coverage' => 'nullable',
            'exclustions' => 'nullable'
        ];
    
        $validator = Validator::make($request->all(), $rules);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        // Find organization user
        $orgUser = Company::where('id', $request->company_id)
                          ->whereNotNull('zoho_access_token')
                          ->whereNotNull('zoho_org_id')
                          ->first();
    
        if (!$orgUser) {
            return response()->json(['status' => false, 'error' => 'Organization user not found or Zoho credentials missing.'], 404);
        }
    
        $itemData = [
            "name" => $request->name,
            "rate" => $request->mrp ?? 0,
            "hsn_or_sac" => $request->hsn_or_sac,
            "description" => $request->features ?? 'Default Item Description',
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
                'name' => $request->name,
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
                'exclustions' => $request->exclustions
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
        $query = WCustomer::with('devices', 'retailer');
    
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
        return response()->json([
            'status' => true,
            'data' => [
                'brand_count'     => Brand::count(),
                'category_count'  => Category::count(),
                'product_count'   => WarrantyProduct::count(),
                'company_count'    => Company::where('role', 2)->count(),
                'agent_count'    => Company::where('role', 4)->count(),
                'retailer_count'    => Company::where('role', 5)->count(),
                'price_templates_count' =>0,
                'connected_retailers_count'=>0,
                'open_claims_count' =>0,
                'active_warranties_count' =>0
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

    public function generateDeviceCertificate(Request $request)
    {
    
        $device = WDevice::with(['customer'])->find($request->w_id);
    
        if (!$device) {
            return response()->json([
                'message' => 'Device not found'
            ], 404);
        }
    
        $customer = WCustomer::find($device->w_customer_id);
        $retailer = Company::where('id', $device->retailer_id)->first();
        $product  = WarrantyProduct::find($device->product_id);
    
        if (!$customer || !$retailer || !$product) {
            return response()->json([
                'message' => 'Related data missing for certificate generation'
            ], 422);
        }
    
        /** -------------------------
         * Certificate Details
         * ------------------------*/
        $certificateId = 'GX-WNTY-' . now()->year . '-' . str_pad($device->id, 5, '0', STR_PAD_LEFT);
        $verifyUrl = "https://verify.goelectronix.in/cert/{$certificateId}";
        $qrCode = "1";
    
        /** -------------------------
         * PDF Generation
         * ------------------------*/
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
            'retailerName'    => $retailer->Shop_Name,
            'retailerCode'    => $retailer->RetailerCode,
            'retailerAddress' => $retailer->Address,
            'issuedOn'        => now()->toDateString(),
            'qrCode'          => $qrCode,
            'verifyUrl'       => $verifyUrl,
        ])->setPaper('a4', 'portrait');
    
        /** -------------------------
         * Store PDF
         * ------------------------*/
        $pdfPath = "warranty_pdfs/{$certificateId}.pdf";
        Storage::disk('public')->put($pdfPath, $pdf->output());
    
        $certificateLink = Storage::disk('public')->url($pdfPath);
    
        /** -------------------------
         * Update Device
         * ------------------------*/
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
}