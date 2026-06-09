<?php

namespace App\Http\Controllers;

use App\Models\SubscribedPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use App\Models\Company;
use App\Models\ZohoInvoice;
use App\Models\WarrantyProduct;
use App\Mail\InvoiceCreatedMail;
use App\Models\WarrantyFlowLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class SubscribedPackageController extends Controller
{
    public function index(Request $request)
    {
        $query = SubscribedPackage::with(['company', 'package']);

        if ($request->filled('retailer_id')) {
            $query->where('retailer_id', $request->retailer_id);
        }

        if ($request->filled('package_id')) {
            $query->where('package_id', $request->package_id);
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->payment_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('start_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('end_date', '<=', $request->end_date);
        }

        $limit = $request->get('limit', 20);

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('id')->paginate($limit)
        ]);
    }

    // =========================================================


public function buyPackageWithCredit(Request $request)
{
    $validator = Validator::make($request->all(), [

        'company_package_id' => 'required',

        'company_id'         => 'required|exists:companies,id',

        'retailer_id'        => 'required|exists:companies,id',

        'package_id'         => 'required',

        'amount'             => 'required|numeric|min:1',
    ]);

    if ($validator->fails()) {

        return response()->json([

            'success' => false,

            'errors'  => $validator->errors()

        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | UNIQUE TRANSACTION REF
    |--------------------------------------------------------------------------
    */

    $transactionRef =
        'WALLET-' . md5(

            $request->retailer_id .
            '-' .
            $request->package_id .
            '-' .
            microtime(true)
        );

    try {

        /*
        |--------------------------------------------------------------------------
        | CACHE LOCK (PREVENT DOUBLE CLICK / API RETRY)
        |--------------------------------------------------------------------------
        */

        return Cache::lock(

            'wallet-buy-package-' .
            $request->retailer_id .
            '-' .
            $request->package_id,

            30

        )->block(10, function () use (
            $request,
            $transactionRef
        ) {

            DB::beginTransaction();

            try {

                /*
                |--------------------------------------------------------------------------
                | LOCK RETAILER ROW
                |--------------------------------------------------------------------------
                */

                $company = Company::where(
                        'id',
                        $request->retailer_id
                    )
                    ->lockForUpdate()
                    ->first();

                if (!$company) {

                    throw new \Exception(
                        'Retailer not found'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | DUPLICATE ACTIVE SUBSCRIPTION CHECK
                |--------------------------------------------------------------------------
                */

                $exists = SubscribedPackage::where(
                        'retailer_id',
                        $request->retailer_id
                    )
                    ->where(
                        'package_id',
                        $request->package_id
                    )
                    ->where('status', 1)
                    ->whereDate(
                        'end_date',
                        '>=',
                        Carbon::today()
                    )
                    ->exists();

                if ($exists) {

                    throw new \Exception(
                        'Package already active for this retailer.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | FETCH PRODUCT
                |--------------------------------------------------------------------------
                */

                $product = WarrantyProduct::findOrFail(
                    $request->package_id
                );

                if (!$product->zoho_id) {

                    throw new \Exception(
                        'Zoho product mapping not found'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | WALLET BALANCE CHECK
                |--------------------------------------------------------------------------
                */

                $currentWalletBalance =
                    (float) (
                        $company->wallet_balance
                        ?? 0
                    );

                if (
                    $currentWalletBalance <
                    (float) $request->amount
                ) {

                    throw new \Exception(
                        'Insufficient wallet balance'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | CREATE / LOAD SUBSCRIPTION
                |--------------------------------------------------------------------------
                */

                $subscription =
                    SubscribedPackage::updateOrCreate(

                        [
                            'transaction_ref' =>
                                $transactionRef
                        ],

                        [

                            'package_id' =>
                                $request->package_id,

                            'package_name' =>
                                $product->name
                                ?? 'Subscription Package',

                            'company_package_id' =>
                                $request->company_package_id,

                            'company_id' =>
                                $company->company_id,

                            'retailer_id' =>
                                $request->retailer_id,

                            'payment_id' => 0,

                            'purchase_source' =>
                                'wallet',

                            'status' => 0,

                            'enroll_max' =>
                                $product->enroll_max,

                            'balance' =>
                                $product->enroll_max,

                            'validity_days' =>
                                $product->sub_val_days,

                            'start_date' =>
                                now()->toDateString(),

                            'end_date' =>
                                now()
                                    ->addDays(
                                        $product->sub_val_days
                                    )
                                    ->toDateString(),

                            'amount' =>
                                $request->amount,

                            'payment_mode' =>
                                'wallet'
                        ]
                    );

                /*
                |--------------------------------------------------------------------------
                | SUBSCRIPTION CODE
                |--------------------------------------------------------------------------
                */

                if (
                    empty(
                        $subscription->subscription_code
                    )
                ) {

                    $subscriptionCode = strtoupper(

                        ($company->company_code ?? 'CMP')
                        . '-'
                        . now()->format('ymd')
                        . '-'
                        . str_pad(
                            $subscription->id,
                            5,
                            '0',
                            STR_PAD_LEFT
                        )
                    );

                    $subscription->update([

                        'subscription_code' =>
                            $subscriptionCode
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | DEDUCT WALLET INSIDE TRANSACTION
                |--------------------------------------------------------------------------
                */

                $newWalletBalance =

                    $currentWalletBalance
                    - (float) $request->amount;

                if ($newWalletBalance < 0) {

                    $newWalletBalance = 0;
                }

                $company->wallet_balance =
                    $newWalletBalance;

                $company->is_subscribed = 1;

                $company->last_update_balance_at =
                    now();

                $company->save();

                /*
                |--------------------------------------------------------------------------
                | FLOW LOG
                |--------------------------------------------------------------------------
                */

                WarrantyFlowLog::firstOrCreate(

                    [

                        'payment_id' => 0,

                        'step' =>
                            'SUBSCRIPTION_WALLET_STARTED',

                        'device_id' =>
                            $subscription->id
                    ],

                    [

                        'status' => 1,

                        'response_data' =>
                            json_encode([

                                'transaction_ref' =>
                                    $transactionRef
                            ])
                    ]
                );

                DB::commit();

            } catch (\Throwable $e) {

                DB::rollBack();

                throw $e;
            }

            /*
            |--------------------------------------------------------------------------
            | REFRESH
            |--------------------------------------------------------------------------
            */

            $subscription->refresh();

            $company->refresh();

            /*
            |--------------------------------------------------------------------------
            | CREATE INVOICE + APPLY CREDIT
            |--------------------------------------------------------------------------
            */

            $controller =
                app(
                    WarrantyPaymentFlowController::class
                );

            $invoiceResult =
                $controller
                    ->createSubscriptionInvoiceWithWallet(

                        $subscription,

                        $request->company_id,

                        $request->retailer_id,

                        $request->package_id,

                        0,

                        $request->amount
                    );

            if (
                empty(
                    $invoiceResult['success']
                )
            ) {

                throw new \Exception(

                    $invoiceResult['message']
                    ?? 'Invoice creation failed'
                );
            }

            $zohoInvoice =
                $invoiceResult['invoice'];

            /*
            |--------------------------------------------------------------------------
            | UPDATE SUBSCRIPTION
            |--------------------------------------------------------------------------
            */

            $subscription->update([

                'zoho_invoice_id' =>
                    $zohoInvoice['invoice_id'],

                'invoice_created_date' =>
                    $zohoInvoice['date']
                    ?? now()->toDateString(),

                'invoice_status' =>
                    'paid',

                'invoice_json' =>
                    json_encode($zohoInvoice),

                'payment_json' =>
                    json_encode([

                        'payment_mode' =>
                            'wallet',

                        'amount' =>
                            $request->amount,

                        'credited' => true
                    ]),

                'status' => 1
            ]);

            /*
            |--------------------------------------------------------------------------
            | APPROVE INVOICE
            |--------------------------------------------------------------------------
            */

            $controller->approveZohoInvoice(

                $request->company_id,

                $zohoInvoice['invoice_id'],

                $company->contact_email
            );

            /*
            |--------------------------------------------------------------------------
            | WHATSAPP
            |--------------------------------------------------------------------------
            */

            try {

                $invoiceNumber =
                    $zohoInvoice['invoice_number']
                    ?? '-';

                $invoiceDate =
                    $zohoInvoice['date']
                    ?? now()->toDateString();

                $invoiceAmount =
                    $zohoInvoice['total']
                    ?? $request->amount;

                $invoiceUrl =
                    $zohoInvoice['invoice_url']
                    ?? (
                        $zohoInvoice['customer_view_url']
                        ?? ''
                    );

                $whatsappService =
                    app(
                        \App\Services\WhatsappService::class
                    );

                /*
                |--------------------------------------------------------------------------
                | INVOICE WHATSAPP
                |--------------------------------------------------------------------------
                */

                $whatsappService
                    ->invoiceWhatsapp(

                        $company,

                        $invoiceNumber,

                        $invoiceDate,

                        $invoiceAmount,

                        $invoiceUrl
                    );

                /*
                |--------------------------------------------------------------------------
                | SUBSCRIPTION ACTIVATED
                |--------------------------------------------------------------------------
                */

                $whatsappService
                    ->sendSubscriptionActivatedWhatsapp(

                        $company,

                        $subscription
                    );

                /*
                |--------------------------------------------------------------------------
                | WALLET DEDUCTED
                |--------------------------------------------------------------------------
                */

                $whatsappService
                    ->sendWalletDeductedWhatsapp(

                        $company,

                        'WALLET-' .
                        $subscription->id,

                        $request->amount,

                        $subscription->subscription_code
                            ?? 'WM001',

                        now()->format('d-m-Y'),

                        $company->wallet_balance
                            ?? 0
                    );

            } catch (\Throwable $e) {

                \Log::error(

                    'WhatsApp Failed',

                    [

                        'subscription_id' =>
                            $subscription->id,

                        'error' =>
                            $e->getMessage()
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | FINAL FLOW LOG
            |--------------------------------------------------------------------------
            */

            WarrantyFlowLog::firstOrCreate([

                'payment_id' => 0,

                'device_id' =>
                    $subscription->id,

                'invoice_id' =>
                    $subscription->zoho_invoice_id,

                'step' =>
                    'SUBSCRIPTION_WALLET_CREDIT_APPLIED',

            ], [

                'zoho_payment_id' => null,

                'status' => 1,

                'response_data' =>
                    json_encode([

                        'payment_mode' =>
                            'wallet',

                        'credited' => true,

                        'amount' =>
                            $request->amount
                    ])
            ]);

            return response()->json([

                'success' => true,

                'message' =>
                    'Subscription completed successfully.',

                'data' => [

                    'subscription_id' =>
                        $subscription->id,

                    'subscription_code' =>
                        $subscription->subscription_code,

                    'invoice_id' =>
                        $subscription->zoho_invoice_id,

                    'payment_mode' =>
                        'wallet',

                    'wallet_balance' =>
                        $company->wallet_balance
                ]
            ]);
        });

    } catch (\Throwable $e) {

        \Log::error(

            'SUBSCRIPTION FLOW FAILED',

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


  public function checkActivePlan(Request $request)
{
    $validator = Validator::make($request->all(), [
        'retailer_id' => 'required|exists:companies,id',
        'package_id'  => 'nullable'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors()
        ], 422);
    }

    try {

        $today = Carbon::today();

        // ==========================================
        // BASE QUERY
        // ==========================================
        $query = SubscribedPackage::with(['package', 'company'])
            ->where('retailer_id', $request->retailer_id)
            ->where('status', 1)
            ->whereDate('end_date', '>=', $today);

        // ==========================================
        // 🔥 FILTER BY PACKAGE IF PROVIDED
        // ==========================================
        if ($request->filled('package_id')) {
            $query->where('package_id', $request->package_id);
        }

        $subscription = $query->orderByDesc('id')->first();

        // ==========================================
        // NO PLAN FOUND
        // ==========================================
        if (!$subscription) {
            return response()->json([
                'success' => true,
                'has_active_plan' => false,
                'message' => 'No active subscription found'
            ]);
        }

        // ==========================================
        // RESPONSE
        // ==========================================
        return response()->json([
            'success' => true,
            'has_active_plan' => true,
            'data' => [
                'subscription_id'   => $subscription->id,
                'subscription_code' => $subscription->subscription_code,

                'package_id'   => $subscription->package_id,
                'package_name' => $subscription->package->name ?? null,

                'start_date' => $subscription->start_date,
                'end_date'   => $subscription->end_date,
                'validity_days' => $subscription->validity_days,

                'balance'    => $subscription->balance,
                'enroll_max' => $subscription->enroll_max,

                'amount' => $subscription->amount,
                'payment_mode' => $subscription->payment_mode,

                'invoice_id' => $subscription->zoho_invoice_id,
                'payment_id' => $subscription->zoho_payment_id,

                'status' => $subscription->status
            ]
        ]);

    } catch (\Exception $e) {

        \Log::error('CHECK ACTIVE PLAN FAILED', [
            'error' => $e->getMessage(),
            'request' => $request->all()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Something went wrong',
            'error'   => $e->getMessage()
        ], 500);
    }
}


    // with offer
    
     public function buyPackageWithoffer(Request $request)
    {
        $validator = Validator::make($request->all(), [
    
            'company_package_id' => 'required',
    
            'company_id' => 'required|exists:companies,id',
    
            'retailer_id' => 'required|exists:companies,id',
    
            'package_id' => 'required|exists:w_products,id',
        ]);
    
        if ($validator->fails()) {
    
            return response()->json([
    
                'success' => false,
    
                'errors' => $validator->errors()
    
            ], 422);
        }
    
        try {
    
            return Cache::lock(
    
                'offer-buy-package-' .
                $request->retailer_id .
                '-' .
                $request->package_id,
    
                30
    
            )->block(10, function () use ($request) {
    
                DB::beginTransaction();
    
                try {
    
                    /*
                    |--------------------------------------------------------------------------
                    | LOCK RETAILER
                    |--------------------------------------------------------------------------
                    */
    
                    $company = Company::where(
                            'id',
                            $request->retailer_id
                        )
                        ->lockForUpdate()
                        ->first();
    
                    if (!$company) {
    
                        throw new \Exception(
                            'Retailer not found'
                        );
                    }
    
                    /*
                    |--------------------------------------------------------------------------
                    | DUPLICATE ACTIVE PACKAGE CHECK
                    |--------------------------------------------------------------------------
                    */
    
                    $alreadySubscribed = SubscribedPackage::where(
                            'retailer_id',
                            $request->retailer_id
                        )
                        ->where(
                            'package_id',
                            $request->package_id
                        )
                        ->where('status', 1)
                        ->whereDate(
                            'end_date',
                            '>=',
                            Carbon::today()
                        )
                        ->exists();
    
                    if ($alreadySubscribed) {
    
                        throw new \Exception(
                            'Package already active for this retailer.'
                        );
                    }
    
                    /*
                    |--------------------------------------------------------------------------
                    | PRODUCT
                    |--------------------------------------------------------------------------
                    */
    
                    $product = WarrantyProduct::findOrFail(
                        $request->package_id
                    );
    
                    if (!$product->zoho_id) {
    
                        throw new \Exception(
                            'Zoho product mapping not found'
                        );
                    }
    
                    /*
                    |--------------------------------------------------------------------------
                    | FINAL AMOUNT
                    |--------------------------------------------------------------------------
                    */
    
                    $finalAmount = (float) (
                        $product->discount_price ?? 0
                    );
    
                    if ($finalAmount < 0) {
                        $finalAmount = 0;
                    }
    
                    /*
                    |--------------------------------------------------------------------------
                    | WALLET CHECK
                    |--------------------------------------------------------------------------
                    */
    
                    $walletBalance = (float) (
                        $company->wallet_balance ?? 0
                    );
    
                    if ($walletBalance < $finalAmount) {
    
                        throw new \Exception(
                            'Insufficient wallet balance'
                        );
                    }
    
                    /*
                    |--------------------------------------------------------------------------
                    | TRANSACTION REF
                    |--------------------------------------------------------------------------
                    */
    
                    $transactionRef =
                        'WALLET-' .
                        strtoupper(
                            uniqid()
                        );
    
                    /*
                    |--------------------------------------------------------------------------
                    | CREATE SUBSCRIPTION
                    |--------------------------------------------------------------------------
                    */
    
                    $subscription =
                        SubscribedPackage::create([
    
                            'transaction_ref' =>
                                $transactionRef,
    
                            'package_id' =>
                                $product->id,
    
                            'package_name' =>
                                $product->name
                                ?? 'Subscription Package',
    
                            'company_package_id' =>
                                $request->company_package_id,
    
                            'company_id' =>
                                $company->company_id,
    
                            'retailer_id' =>
                                $request->retailer_id,
    
                            'payment_id' => 0,
    
                            'purchase_source' =>
                                'wallet',
    
                            'status' => 0,
    
                            'enroll_max' =>
                                $product->enroll_max,
    
                            'balance' =>
                                $product->enroll_max,
    
                            'validity_days' =>
                                $product->sub_val_days,
    
                            'start_date' =>
                                now()->toDateString(),
    
                            'end_date' =>
                                now()
                                    ->addDays(
                                        $product->sub_val_days
                                    )
                                    ->toDateString(),
    
                            'amount' =>
                                $finalAmount,
    
                            'payment_mode' =>
                                'wallet'
                        ]);
    
                    /*
                    |--------------------------------------------------------------------------
                    | SUBSCRIPTION CODE
                    |--------------------------------------------------------------------------
                    */
    
                    $subscriptionCode = strtoupper(
    
                        ($company->company_code ?? 'CMP')
                        . '-'
                        . now()->format('ymd')
                        . '-'
                        . str_pad(
                            $subscription->id,
                            5,
                            '0',
                            STR_PAD_LEFT
                        )
                    );
    
                    $subscription->subscription_code =
                        $subscriptionCode;
    
                    /*
                    |--------------------------------------------------------------------------
                    | WALLET DEDUCTION
                    |--------------------------------------------------------------------------
                    */
    
                    $company->wallet_balance =
                        max(
                            0,
                            $walletBalance - $finalAmount
                        );
    
                    $company->is_subscribed = 1;
    
                    $company->last_update_balance_at =
                        now();
    
                        
                        if ((float)$finalAmount <= 0) {
                    
                        $company->is_free_subscribed = 1;
                    
                        $company->free_subscribe_date =
                    
                            now();
                    
                    }
                    

                    $company->save();
    
                    /*
                    |--------------------------------------------------------------------------
                    | SAVE SUBSCRIPTION
                    |--------------------------------------------------------------------------
                    */
    
                    $subscription->save();
    
                    /*
                    |--------------------------------------------------------------------------
                    | FLOW LOG
                    |--------------------------------------------------------------------------
                    */
    
                    WarrantyFlowLog::create([
    
                        'payment_id' => 0,
    
                        'step' =>
                            'SUBSCRIPTION_WALLET_STARTED',
    
                        'device_id' =>
                            $subscription->id,
    
                        'status' => 1,
    
                        'response_data' =>
                            json_encode([
    
                                'transaction_ref' =>
                                    $transactionRef,
    
                                'amount' =>
                                    $finalAmount
                            ])
                    ]);
    
                    DB::commit();
    
                } catch (\Throwable $e) {
    
                    DB::rollBack();
    
                    throw $e;
                }
    
                /*
                |--------------------------------------------------------------------------
                | REFRESH MODELS
                |--------------------------------------------------------------------------
                */
    
                $subscription->refresh();
    
                $company->refresh();
    
                /*
                |--------------------------------------------------------------------------
                | CREATE ZOHO INVOICE
                |--------------------------------------------------------------------------
                */
    
                $controller = app(
                    WarrantyPaymentFlowController::class
                );
    
                $invoiceResult =
                    $controller
                        ->createSubscriptionInvoiceWithOffer(
    
                            $subscription,
    
                            $request->company_id,
    
                            $request->retailer_id,
    
                            $request->package_id,
    
                            0,
    
                            $finalAmount
                        );
    
                if (
                    empty(
                        $invoiceResult['success']
                    )
                ) {
    
                    throw new \Exception(
    
                        $invoiceResult['message']
                        ?? 'Invoice creation failed'
                    );
                }
    
                $zohoInvoice =
                    $invoiceResult['invoice'];
    
                /*
                |--------------------------------------------------------------------------
                | UPDATE SUBSCRIPTION
                |--------------------------------------------------------------------------
                */
    
                $subscription->update([
    
                    'zoho_invoice_id' =>
                        $zohoInvoice['invoice_id'] ?? null,
    
                    'invoice_created_date' =>
                        $zohoInvoice['date']
                        ?? now()->toDateString(),
    
                    'invoice_status' =>
                        'paid',
    
                    'invoice_json' =>
                        json_encode($zohoInvoice),
    
                    'payment_json' =>
                        json_encode([
    
                            'payment_mode' =>
                                'wallet',
    
                            'amount' =>
                                $finalAmount,
    
                            'credited' => true
                        ]),
    
                    'status' => 1
                ]);
    
                /*
                |--------------------------------------------------------------------------
                | APPROVE INVOICE
                |--------------------------------------------------------------------------
                */
    
                if (!empty($zohoInvoice['invoice_id'])) {
    
                    $controller->approveZohoInvoice(
    
                        $request->company_id,
    
                        $zohoInvoice['invoice_id'],
    
                        $company->contact_email
                    );
                }
    
                /*
                |--------------------------------------------------------------------------
                | WHATSAPP
                |--------------------------------------------------------------------------
                */
    
                try {
    
                    $invoiceNumber =
                        $zohoInvoice['invoice_number']
                        ?? '-';
    
                    $invoiceDate =
                        $zohoInvoice['date']
                        ?? now()->toDateString();
    
                    $invoiceAmount =
                        $zohoInvoice['total']
                        ?? $finalAmount;
    
                    $invoiceUrl =
                        $zohoInvoice['invoice_url']
                        ?? (
                            $zohoInvoice['customer_view_url']
                            ?? ''
                        );
    
                    $whatsappService =
                        app(
                            \App\Services\WhatsappService::class
                        );
    
                    $whatsappService
                        ->invoiceWhatsapp(
    
                            $company,
    
                            $invoiceNumber,
    
                            $invoiceDate,
    
                            $invoiceAmount,
    
                            $invoiceUrl
                        );
    
                    $whatsappService
                        ->sendSubscriptionActivatedWhatsapp(
    
                            $company,
    
                            $subscription
                        );
    
                   
    
                } catch (\Throwable $e) {
    
                    \Log::error(
    
                        'WhatsApp Failed',
    
                        [
    
                            'subscription_id' =>
                                $subscription->id,
    
                            'error' =>
                                $e->getMessage()
                        ]
                    );
                }
    
                /*
                |--------------------------------------------------------------------------
                | FINAL FLOW LOG
                |--------------------------------------------------------------------------
                */
    
                WarrantyFlowLog::create([
    
                    'payment_id' => 0,
    
                    'device_id' =>
                        $subscription->id,
    
                    'invoice_id' =>
                        $subscription->zoho_invoice_id,
    
                    'step' =>
                        'SUBSCRIPTION_WALLET_CREDIT_APPLIED',
    
                    'zoho_payment_id' => null,
    
                    'status' => 1,
    
                    'response_data' =>
                        json_encode([
    
                            'payment_mode' =>
                                'wallet',
    
                            'credited' => true,
    
                            'amount' =>
                                $finalAmount
                        ])
                ]);
    
                return response()->json([
    
                    'success' => true,
    
                    'message' =>
                        'Subscription completed successfully.',
    
                    'data' => [
    
                        'subscription_id' =>
                            $subscription->id,
    
                        'subscription_code' =>
                            $subscription->subscription_code,
    
                        'invoice_id' =>
                            $subscription->zoho_invoice_id,
    
                        'payment_mode' =>
                            'wallet',
    
                        'paid_amount' =>
                            $finalAmount,
    
                        'wallet_balance' =>
                            $company->wallet_balance
                    ]
                ]);
            });
    
        } catch (\Throwable $e) {
    
            \Log::error(
    
                'SUBSCRIPTION FLOW FAILED',
    
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

}