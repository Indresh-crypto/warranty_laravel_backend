<?php

namespace App\Jobs;

use App\Models\WarrantyFlowLog;
use App\Models\WDevice;
use App\Models\Brand;
use App\Models\Category;
use App\Models\WCustomer;
use App\Models\DeviceModel;
use App\Models\WarrantyProduct;
use App\Services\WarrantyPricingService;
use App\Events\PaymentSuccessful;
use App\Events\WarrantyRegistered;
use App\Events\WarrantyRegisterWhatsapp;
use App\Http\Controllers\WarrantyPaymentFlowController;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Bus\Dispatchable;

use App\Mail\InvoiceCreatedMail;
use App\Mail\PaymentCompletedMail;
use App\Models\Company;
use Illuminate\Support\Facades\Mail;

class WarrantyPaymentFlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;

    public $tries = 5;
    public $timeout = 120;

    public function backoff()
    {
        return [30, 60, 120, 300, 600];
    }

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {

        /*
        |--------------------------------------------------------------------------
        | REQUIRED PAYLOAD VALIDATION (ONLY IDS)
        |--------------------------------------------------------------------------
        */

        $required = [
            'payment_id',
            'imei1',
            'product_id',
            'company_id',
            'retailer_id',
            'amount',
            'w_customer_id',
            'model_id'
        ];

        foreach ($required as $field) {
            if (empty($this->payload[$field])) {
                throw new \Exception($field . ' missing in job payload');
            }
        }

        DB::beginTransaction();

        try {

            $paymentId = $this->payload['payment_id'];

            /*
            |--------------------------------------------------------------------------
            | JOB START LOG
            |--------------------------------------------------------------------------
            */

            WarrantyFlowLog::create([
                'payment_id' => $paymentId,
                'step' => 'JOB_STARTED',
                'status' => 1
            ]);

            /*
            |--------------------------------------------------------------------------
            | LOAD DEVICE MODEL (MASTER SOURCE)
            |--------------------------------------------------------------------------
            */

            $deviceModel = DeviceModel::find($this->payload['model_id']);

            if (!$deviceModel) {
                throw new \Exception('Device model not found');
            }

            $brandId     = $deviceModel->brand_id;
            $categoryId  = $deviceModel->category_id;
            $devicePrice = $deviceModel->price;
            $modelName   = $deviceModel->name;

            /*
            |--------------------------------------------------------------------------
            | STEP 1 : DEVICE CREATE / LOAD (IDEMPOTENT)
            |--------------------------------------------------------------------------
            */

            $device = WDevice::where('imei1', $this->payload['imei1'])
                ->where('product_id', $this->payload['product_id'])
                ->first();

            if (!$device) {

                $device = WDevice::create([

                    // Device identifiers
                    'imei1' => $this->payload['imei1'],
                    'imei2' => $this->payload['imei2'] ?? null,
                    'serial' => $this->payload['serial'] ?? null,

                    // Mapping (filled later)
                    'brand_id' => null,
                    'category_id' => null,
                    'product_id' => $this->payload['product_id'],

                    // Temp warranty data
                    'product_name' => null,
                    'available_claim' => 0,
                    'expiry_date' => null,

                    // Pricing temp
                    'device_price' => 0,
                    'product_price' => 0,
                    'product_mrp' => 0,

                    // Relations
                    'w_customer_id' => $this->payload['w_customer_id'],
                    'retailer_id' => $this->payload['retailer_id'],
                    'agent_id' => $this->payload['agent_id'] ?? null,

                    // Files
                    'document_url' => $this->payload['document_url'] ?? null,
                    'link1' => $this->payload['link1'] ?? null,
                    'link2' => $this->payload['link2'] ?? null,

                    // Payout temp
                    'retailer_payout' => 0,
                    'employee_payout' => 0,
                    'other_payout' => 0,
                    'company_payout' => 0,

                    // Meta
                    'company_id' => $this->payload['company_id'],
                    'created_by' => $this->payload['created_by'] ?? null,

                    'is_approved' => 1
                ]);

                $device->update([
                    'w_code' => 'WRT-' . $device->id . '-' . Str::upper(Str::random(6))
                ]);

                WarrantyFlowLog::create([
                    'payment_id' => $paymentId,
                    'device_id' => $device->id,
                    'step' => 'DEVICE_CREATED',
                    'status' => 1
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | LOAD WARRANTY PRODUCT
            |--------------------------------------------------------------------------
            */

            $product = WarrantyProduct::find($this->payload['product_id']);

            if (!$product) {
                throw new \Exception('Warranty product not found');
            }

            /*
            |--------------------------------------------------------------------------
            | LOAD CUSTOMER
            |--------------------------------------------------------------------------
            */
            
          


            $warrCustomer = WCustomer::find($this->payload['w_customer_id']);
            
            $devicea = WDevice::where('retailer_id', $warrCustomer->retailer_id)->first();
            
            $company = \App\Models\Company::find($devicea->retailer_id);

  \Log::critical('EMAIL SECTION ENTERED', [
                'company' => $company
            ]);
            
            if (!$company) {
                throw new \Exception('Retailer company not found');
            }

            if (!$warrCustomer) {
                throw new \Exception('Warranty customer not found');
            }

            /*
            |--------------------------------------------------------------------------
            | PRICING ENGINE
            |--------------------------------------------------------------------------
            */

            \Log::info('PRICING_CALCULATION_STARTED', [
                'payment_id' => $paymentId,
                'device_price' => $devicePrice
            ]);

            $pricing = WarrantyPricingService::calculate(
                $this->payload['product_id'],
                $this->payload['company_id'],
                $devicePrice
            );

        \Log::info('Prouct MRP', [
                'payment_mrp' => $pricing['product_mrp'],
             
            ]);
            /*
            |--------------------------------------------------------------------------
            | APPLY ALL FINAL VALUES
            |--------------------------------------------------------------------------
            */

            $brand =    Brand::find($brandId);
            $category = Category::find($categoryId);
            
            $device->update([

                // Customer
                'name' => $warrCustomer->name,

                // Device master
                'brand_id'    => $brandId,
                'category_id' => $categoryId,
                'model'       => $modelName,
                'device_price'=> $devicePrice,
                'brand_name'  => $brand->name,
                'category_name'  => $category->name,

                // Warranty product
                'product_name' => $product->name,

                // Pricing
                'product_price' => $pricing['product_price'],
                'product_mrp'   =>$pricing['product_mrp'],

                // Warranty config
                'available_claim' => $product->claims,
                'expiry_date' => now()->addDays($product->validity),

                // Payouts
                'retailer_payout' => $pricing['retailer_payout'],
                'employee_payout' => $pricing['employee_payout'],
                'other_payout'    => $pricing['other_payout'],
                'company_payout'  => $pricing['company_payout'],
            ]);

            WarrantyFlowLog::create([
                'payment_id' => $paymentId,
                'device_id' => $device->id,
                'step' => 'PRICING_LOADED',
                'status' => 1
            ]);

            /*
            |--------------------------------------------------------------------------
            | STEP 3 : CREATE ZOHO INVOICE
            |--------------------------------------------------------------------------
            */

            if (!WarrantyFlowLog::where('payment_id', $paymentId)
                ->where('step', 'INVOICE_CREATED')
                ->exists()) {

                $invoiceResult = app(WarrantyPaymentFlowController::class)
                    ->createWarrantyInvoice(
                        $device,
                        $this->payload['company_id'],
                        $this->payload['retailer_id'],
                        $this->payload['product_id'],
                        $paymentId,
                        $this->payload['amount']
                    );

                if (empty($invoiceResult['success'])) {
                    throw new \Exception($invoiceResult['message'] ?? 'Invoice creation failed');
                }

                    $zohoInvoice = $invoiceResult['invoice'];
                    
                    // ============================
                    // UPDATE DEVICE INVOICE DATA
                    // ============================
                    
                    $device->update([
                    
                        'invoice_id' => $zohoInvoice['invoice_id'],
                    
                        'invoice_created_date' => $zohoInvoice['date'] 
                            ?? now()->toDateString(),
                    
                        'invoice_status' => $zohoInvoice['status'] 
                            ?? 'created',
                    
                        'invoice_json' => json_encode($zohoInvoice)
                    ]);

                WarrantyFlowLog::create([
                    'payment_id' => $paymentId,
                    'device_id' => $device->id,
                    'invoice_id' => $invoiceResult['invoice']['invoice_id'],
                    'step' => 'INVOICE_CREATED',
                    'status' => 1,
                    'response_data' => json_encode($invoiceResult)
                ]);
            }


                /*
                |--------------------------------------------------------------------------
                | STEP 3.5 : SEND ZOHO INVOICE (MARK AS SENT)
                |--------------------------------------------------------------------------
                */
                
                if (!WarrantyFlowLog::where('payment_id', $paymentId)
                    ->where('step', 'INVOICE_PAID')
                    ->exists()) {
                
                    $sendResponse = app(WarrantyPaymentFlowController::class)
                        ->sendZohoInvoice(
                            $this->payload['company_id'],
                            $device->invoice_id
                        );
                
                    // ==========================
                    // UPDATE DEVICE STATUS
                    // ==========================
                
                   $device->update([
                     //   'invoice_status' => $sendResponse['invoice']['status'] ?? 'unknown',
                        'invoice_status' => 'paid',
                        'status' =>1
                    ]);
                
                    WarrantyFlowLog::create([
                        'payment_id' => $paymentId,
                        'device_id' => $device->id,
                        'invoice_id' => $device->invoice_id,
                        'step' => 'INVOICE_PAID',
                        'status' => 1,
                        'response_data' => json_encode($sendResponse)
                    ]);
                }

            /*
            |--------------------------------------------------------------------------
            | STEP 4 : CREATE ZOHO PAYMENT
            |--------------------------------------------------------------------------
            */

           


            if (!WarrantyFlowLog::where('payment_id', $paymentId)
                ->where('step', 'ZOHO_PAYMENT_CREATED')
                ->exists()) {
            
                $invoiceId = WarrantyFlowLog::where('payment_id', $paymentId)
                    ->where('step', 'INVOICE_CREATED')
                    ->value('invoice_id');
            
                $zohoResponse = app(WarrantyPaymentFlowController::class)
                    ->createZohoPayment(
                        $this->payload['company_id'],   // Zoho org
                        $this->payload['retailer_id'],  // Zoho customer
                        $paymentId,
                        $this->payload['amount'],
                        $invoiceId
                    );
            
                $zohoPayment = $zohoResponse['payment'] ?? null;
            
                if (!$zohoPayment) {
                    throw new \Exception('Zoho payment creation failed');
                }
            
                // ==========================
                // UPDATE DEVICE PAYMENT DATA
                // ==========================
                              
                $device->update([
                
                    // Zoho
                    'zoho_payment_id'      => $zohoPayment['payment_id'],
                    'payment_json'         => json_encode($zohoPayment),
                
                    // Razorpay
                    'razorpay_payment_id'  => $paymentId,
                
                    // Generic payment tracking
                    'payment_id'           => $paymentId,
                    'payment_status'       => 1,
                
                    // Invoice
                    'invoice_status'       => 'paid',
                
                    // Meta
                    'paid_at'              => now(),
                    'status'               => 1
                ]);

                /*
                WarrantyFlowLog::create([
                    'payment_id' => $paymentId,
                    'invoice_id' => $invoiceId,
                    'zoho_payment_id' => $zohoPayment['payment_id'],
                    'step' => 'ZOHO_PAYMENT_CREATED',
                    'status' => 1,
                    'response_data' => json_encode($zohoResponse)
                ]);
                */
                    WarrantyFlowLog::create([
                        'payment_id'        => $paymentId,
                        'device_id'         => $device->id,
                        'invoice_id'        => $invoiceId,
                        'zoho_payment_id'   => $zohoPayment['payment_id'],
                        'step'              => 'ZOHO_PAYMENT_CREATED',
                        'status'            => 1,
                        'response_data'     => json_encode($zohoResponse)
                    ]);
            }

            DB::commit();

          

        } catch (\Exception $e) {

            DB::rollBack();

            WarrantyFlowLog::create([
                'payment_id' => $this->payload['payment_id'] ?? null,
                'step' => 'RETRY_FAILED_ATTEMPT',
                'status' => 0,
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
        //
        
        /* ============================================================
         | EMAILS & NOTIFICATIONS (AFTER COMMIT)
         ============================================================ */

        // 1️⃣ Invoice email (once)
        if (!WarrantyFlowLog::where('payment_id',$paymentId)->where('step','INVOICE_MAIL_SENT')->exists()) {

          


            Mail::to($company->contact_email)
                ->queue(new InvoiceCreatedMail(
                    json_decode($device->invoice_json, true),
                    json_decode($device->invoice_json, true)['invoice_url'] ?? '#'
                ));

            WarrantyFlowLog::create([
                'payment_id' => $paymentId,
                'step' => 'INVOICE_MAIL_SENT',
                'status' => 1
            ]);
        }

        /*
        if (!WarrantyFlowLog::where('payment_id',$paymentId)->where('step','PAYMENT_MAIL_SENT')->exists()) {

            Mail::to($company->contact_email)
                ->queue(new PaymentCompletedMail(
                    $device->fresh(['customer'])
                ));

            WarrantyFlowLog::create([
                'payment_id' => $paymentId,
                'device_id'  => $device->id,
                'step' => 'PAYMENT_MAIL_SENT',
                'status' => 1
            ]);
        }

*/
        // 3️⃣ Existing notifications
        if (!WarrantyFlowLog::where('payment_id',$paymentId)->where('step','EMAIL_SENT')->exists()) {
            event(new WarrantyRegistered($device));
            WarrantyFlowLog::create([
                'payment_id' => $paymentId,
                'device_id' => $device->id,
                'step' => 'EMAIL_SENT',
                'status' => 1
            ]);
        }

        if (!WarrantyFlowLog::where('payment_id',$paymentId)->where('step','WHATSAPP_SENT')->exists()) {
            event(new WarrantyRegisterWhatsapp($device));
            WarrantyFlowLog::create([
                'payment_id' => $paymentId,
                'device_id' => $device->id,
                'step' => 'WHATSAPP_SENT',
                'status' => 1
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FINAL FAILURE CALLBACK
    |--------------------------------------------------------------------------
    */

    public function failed(\Throwable $exception)
    {
        WarrantyFlowLog::create([
            'payment_id' => $this->payload['payment_id'] ?? null,
            'step' => 'FINAL_FAILED',
            'status' => 0,
            'error_message' => $exception->getMessage()
        ]);
    }
}