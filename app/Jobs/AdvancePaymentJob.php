<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\AdvancePayment;
use App\Models\WarrantyFlowLog;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use GuzzleHttp\Client;

use App\Services\WhatsappService;
use App\Mail\RetailerPaymentDoneMail;

class AdvancePaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;

    public $tries = 5;

    public $timeout = 180;

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function backoff()
    {
        return [30, 60, 120, 300, 600];
    }
    
   public function handle()
{
    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    $required = [
        'company_id',
        'retailer_id',
        'amount',
        'payment_id'
    ];

    foreach ($required as $field) {

        if (
            !isset($this->payload[$field]) ||
            $this->payload[$field] === ''
        ) {

            throw new \Exception(
                $field . ' missing in payload'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VARIABLES
    |--------------------------------------------------------------------------
    */

    $paymentId =
        trim($this->payload['payment_id']);

    $amount =
        (float) $this->payload['amount'];

    $companyId =1;

    $retailerId =
        (int) $this->payload['retailer_id'];

    try {

        Log::info(
            'ADVANCE PAYMENT JOB STARTED',
            [
                'payment_id' => $paymentId,
                'payload'    => $this->payload
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | FINAL IDEMPOTENT CHECK
        |--------------------------------------------------------------------------
        */

        $alreadyCompleted =
            WarrantyFlowLog::where(
                'payment_id',
                $paymentId
            )
            ->where(
                'step',
                'ADVANCE_PAYMENT_COMPLETED'
            )
            ->exists();

        if ($alreadyCompleted) {

            Log::warning(
                'ADVANCE PAYMENT ALREADY COMPLETED',
                [
                    'payment_id' => $paymentId
                ]
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | LOAD COMPANY
        |--------------------------------------------------------------------------
        */
        $companyId=1;
        $company = Company::find($companyId);

        if (
            !$company ||
            !$company->zoho_access_token ||
            !$company->zoho_org_id
        ) {

            throw new \Exception(
                'Company Zoho credentials missing'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | LOAD RETAILER
        |--------------------------------------------------------------------------
        */

        $retailer = Company::find($retailerId);

        if (
            !$retailer ||
            !$retailer->zoho_id
        ) {

            throw new \Exception(
                'Retailer Zoho contact missing'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | CHECK EXISTING ADVANCE PAYMENT
        |--------------------------------------------------------------------------
        */

        $existingPayment =
            AdvancePayment::where(
                'payment_id',
                $paymentId
            )->first();

        /*
        |--------------------------------------------------------------------------
        | CREATE ZOHO PAYMENT (ONLY ONCE)
        |--------------------------------------------------------------------------
        */

        $zohoPayment = null;

        if ($existingPayment) {

            $zohoPayment =
                json_decode(
                    $existingPayment->payment_json,
                    true
                );

            Log::warning(
                'ADVANCE PAYMENT ALREADY EXISTS',
                [
                    'payment_id' => $paymentId
                ]
            );

        } else {

            $paymentData = [

                'location_id' =>
                    $company->location_id,

                'customer_id' =>
                    $retailer->zoho_id,

                'amount' =>
                    $amount,

                'reference_number' =>
                    $paymentId,

                'description' =>
                    !empty($this->payload['description'])
                        ? $this->payload['description']
                        : 'Advance payment',

                'payment_mode' =>
                    !empty($this->payload['payment_mode'])
                        ? $this->payload['payment_mode']
                        : 'RZ WM'
            ];

            /*
            |--------------------------------------------------------------------------
            | OPTIONAL DATE
            |--------------------------------------------------------------------------
            */

            if (!empty($this->payload['date'])) {

                $paymentData['date'] =
                    $this->payload['date'];
            }

            Log::info(
                'ZOHO PAYMENT PAYLOAD',
                [
                    'payment_id' => $paymentId,
                    'payload'    => $paymentData
                ]
            );

            $client = new Client([
                'timeout'         => 60,
                'connect_timeout' => 30
            ]);

            $response = $client->post(
                'https://www.zohoapis.in/books/v3/customerpayments',
                [

                    'headers' => [

                        'Authorization' =>
                            'Zoho-oauthtoken ' .
                            $company->zoho_access_token
                    ],

                    'query' => [

                        'organization_id' =>
                            $company->zoho_org_id
                    ],

                    'json' => $paymentData
                ]
            );

            $body = json_decode(
                $response->getBody(),
                true
            );

            if (!isset($body['payment'])) {

                throw new \Exception(
                    'Zoho payment creation failed'
                );
            }

            $zohoPayment =
                $body['payment'];

            Log::info(
                'ZOHO ADVANCE PAYMENT CREATED',
                [
                    'payment_id'      => $paymentId,
                    'zoho_payment_id' =>
                        $zohoPayment['payment_id']
                        ?? null
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | DATABASE OPERATIONS
        |--------------------------------------------------------------------------
        */

        DB::transaction(function () use (
            &$retailer,
            $paymentId,
            $retailerId,
            $zohoPayment,
            $amount
        ) {

            /*
            |--------------------------------------------------------------------------
            | LOCK RETAILER
            |--------------------------------------------------------------------------
            */

            $retailer = Company::where(
                    'id',
                    $retailerId
                )
                ->lockForUpdate()
                ->first();

            /*
            |--------------------------------------------------------------------------
            | SAVE ADVANCE PAYMENT
            |--------------------------------------------------------------------------
            */

            AdvancePayment::firstOrCreate(

                [
                    'payment_id' => $paymentId
                ],

                [

                    'retailer_id' =>
                        $retailer->id,

                    'payment_json' =>
                        json_encode(
                            $zohoPayment
                        ),

                    'amount' =>
                        $amount,

                    'url' =>
                        $this->payload['url']
                        ?? null
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | WALLET UPDATE (ONLY ONCE)
            |--------------------------------------------------------------------------
            */

            $walletLog =
                WarrantyFlowLog::firstOrCreate(

                    [
                        'payment_id' => $paymentId,
                        'step'       => 'WALLET_UPDATED'
                    ],

                    [
                        'status' => 1
                    ]
                );

            if ($walletLog->wasRecentlyCreated) {

                $oldWalletBalance =
                    (float) (
                        $retailer->wallet_balance
                        ?? 0
                    );

                $newWalletBalance =
                    $oldWalletBalance + $amount;

                $retailer->wallet_balance =
                    $newWalletBalance;

                $retailer->last_update_balance_at =
                    now();

                $retailer->save();

                $walletLog->update([

                    'response_data' =>
                        json_encode([

                            'old_wallet_balance' =>
                                $oldWalletBalance,

                            'added_amount' =>
                                $amount,

                            'new_wallet_balance' =>
                                $newWalletBalance
                        ])
                ]);

                Log::info(
                    'WALLET UPDATED',
                    [
                        'retailer_id' =>
                            $retailer->id,

                        'old_balance' =>
                            $oldWalletBalance,

                        'added_amount' =>
                            $amount,

                        'new_balance' =>
                            $newWalletBalance
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | FLOW LOGS
            |--------------------------------------------------------------------------
            */

            WarrantyFlowLog::firstOrCreate(

                [
                    'payment_id' => $paymentId,
                    'step'       => 'ADVANCE_PAYMENT_CREATED'
                ],

                [

                    'status' => 1,

                    'response_data' =>
                        json_encode(
                            $zohoPayment
                        )
                ]
            );

            WarrantyFlowLog::firstOrCreate(

                [
                    'payment_id' => $paymentId,
                    'step'       => 'ADVANCE_PAYMENT_COMPLETED'
                ],

                [
                    'status' => 1
                ]
            );

        }, 3);

        /*
        |--------------------------------------------------------------------------
        | WHATSAPP + EMAIL
        |--------------------------------------------------------------------------
        */

        try {

            $whatsappService =
                app(WhatsappService::class);

            /*
            |--------------------------------------------------------------------------
            | PAYMENT SUCCESS WHATSAPP
            |--------------------------------------------------------------------------
            */

            $paymentWaLog =
                WarrantyFlowLog::firstOrCreate(

                    [
                        'payment_id' => $paymentId,
                        'step'       => 'PAYMENT_WHATSAPP_SENT'
                    ],

                    [
                        'status' => 1
                    ]
                );

            if (
                $paymentWaLog->wasRecentlyCreated
            ) {

                $whatsappService
                    ->paymentSuccessWhatsapp(

                        $retailer,

                        $zohoPayment,

                        $amount,

                        $paymentId
                    );
            }

            /*
            |--------------------------------------------------------------------------
            | RECEIPT WHATSAPP
            |--------------------------------------------------------------------------
            */

            $receiptWaLog =
                WarrantyFlowLog::firstOrCreate(

                    [
                        'payment_id' => $paymentId,
                        'step'       => 'RECEIPT_WHATSAPP_SENT'
                    ],

                    [
                        'status' => 1
                    ]
                );

            if (
                $receiptWaLog->wasRecentlyCreated
            ) {

                $whatsappService
                    ->sendPaymentReceiptWhatsapp(

                        $retailer,

                        $retailer->company_code
                            ?? 'ARP001',

                        $zohoPayment['payment_id']
                            ?? $paymentId,

                        $amount,

                        now()->format('d-m-Y'),

                        $retailer->wallet_balance
                            ?? 0
                    );
            }

            /*
            |--------------------------------------------------------------------------
            | EMAIL
            |--------------------------------------------------------------------------
            */

            $mailLog =
                WarrantyFlowLog::firstOrCreate(

                    [
                        'payment_id' => $paymentId,
                        'step'       => 'PAYMENT_MAIL_SENT'
                    ],

                    [
                        'status' => 1
                    ]
                );

            if (
                $mailLog->wasRecentlyCreated &&
                !empty($retailer->contact_email)
            ) {

                Mail::to(
                    $retailer->contact_email
                )->queue(

                    new RetailerPaymentDoneMail(

                        $retailer,

                        $paymentId,

                        $amount
                    )
                );
            }

        } catch (\Throwable $e) {

            Log::error(
                'ADVANCE PAYMENT NOTIFICATION FAILED',
                [

                    'payment_id' =>
                        $paymentId,

                    'message' =>
                        $e->getMessage(),

                    'line' =>
                        $e->getLine(),

                    'file' =>
                        $e->getFile()
                ]
            );
        }

        Log::info(
            'ADVANCE PAYMENT JOB COMPLETED',
            [
                'payment_id' => $paymentId
            ]
        );

    } catch (\Throwable $e) {

        Log::error(
            'ADVANCE PAYMENT FAILED',
            [

                'payment_id' =>
                    $paymentId ?? null,

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile(),

                'trace' =>
                    substr(
                        $e->getTraceAsString(),
                        0,
                        3000
                    ),

                'payload' =>
                    $this->payload
            ]
        );

        WarrantyFlowLog::create([

            'payment_id' =>
                $paymentId ?? null,

            'step' =>
                'ADVANCE_PAYMENT_FAILED',

            'status' => 0,

            'error_message' =>
                $e->getMessage()
        ]);

        throw $e;
    }
}
       
 public function failed(\Throwable $exception)
{
    try {

        WarrantyFlowLog::create([

            'payment_id' =>
                $this->payload['payment_id']
                ?? null,

            'step' =>
                'ADVANCE_PAYMENT_FINAL_FAILED',

            'status' => 0,

            'error_message' =>
                $exception->getMessage()
        ]);

    } catch (\Throwable $e) {

        Log::error(
            'FAILED METHOD ERROR',
            [
                'message' => $e->getMessage()
            ]
        );
    }
} 
}