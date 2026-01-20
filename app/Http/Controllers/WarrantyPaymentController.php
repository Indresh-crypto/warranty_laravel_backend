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
use App\Models\WDevice;
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
use DB;
use App\Models\WarrantyPaymentLog;
use App\Models\WarrantyFlowLog;

class WarrantyPaymentController extends Controller
{
    public function paymentCallback(Request $request)
    {
        // Save RAW log
        WarrantyPaymentLog::create([
            'payment_id' => $request->payment_id,
            'step' => 'CALLBACK_RECEIVED',
            'status' => 1,
            'request_payload' => json_encode($request->all())
        ]);

        // Dispatch async job
        ProcessWarrantyPaymentJob::dispatch($request->all());

        return response()->json([
            'status' => true,
            'message' => 'Payment received. Processing started.'
        ]);
    }
}