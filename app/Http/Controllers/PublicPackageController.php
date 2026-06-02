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



class PublicPackageController extends Controller
{
    
    
    public function getPublicPriceTemplates(Request $request)
    {
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
    
            $matchingTemplates = PriceTemplate::with([
                'warrantyProduct',
                'warrantyProduct.subscribedPackages'
            ])
            ->where('company_id', $request->company_id)
    
            //  ONLY ZERO PRODUCT PRICE
            ->where('product_price', 0)
    
            ->whereHas('warrantyProduct', function ($q) {
                // no condition
            })
    
            ->whereHas('warrantyProduct.categories', function ($cat) use ($request) {
                $cat->where('category_id', $request->category_id);
            })
    
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


}