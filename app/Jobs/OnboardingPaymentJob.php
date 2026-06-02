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

class OnboardingPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;

    public $tries = 5;

    public $timeout = 180;

    public function __construct($payload)
    {
        $this->payload = $payload;
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

            if (!isset($this->payload[$field])) {

                throw new \Exception(
                    $field . ' missing in payload'
                );
            }
        }

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | VARIABLES
            |--------------------------------------------------------------------------
            */

            $paymentId =
                $this->payload['payment_id'];

            $amount =
                (float) $this->payload['amount'];

            $companyId =1;
                

            $retailerId =
                $this->payload['retailer_id'];

            Log::info(
                'ONBOARDING PAYMENT JOB STARTED',
                [
                    'payload' => $this->payload
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | DUPLICATE CHECK
            |--------------------------------------------------------------------------
            */

            $exists = AdvancePayment::where(
                'payment_id',
                $paymentId
            )->exists();

            if ($exists) {

                Log::warning(
                    'ONBOARDING PAYMENT ALREADY PROCESSED',
                    [
                        'payment_id' => $paymentId
                    ]
                );

                DB::rollBack();

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | LOAD COMPANY
            |--------------------------------------------------------------------------
            */

            $companyId = 1;
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
                    'Onboarding Retailer Zoho contact missing'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | CREATE ZOHO PAYMENT
            |--------------------------------------------------------------------------
            */

            $paymentData = [

                'location_id' =>
                   $company->location_id,

                'location_name' =>
                    'Warranty Mitra',

                'customer_id' =>
                    $retailer->zoho_id,

                'amount' =>
                    $amount,

                'reference_number' =>
                    $paymentId,

                'payment_mode' =>
                    'RZ WM',

                'description' =>
                    'Onboarding recharge',

                'invoices' => []
            ];

            $client = new Client([
                'timeout' => 60,
                'connect_timeout' => 20
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
                    'Zoho onboarding payment creation failed'
                );
            }

            $zohoPayment = $body['payment'];

            /*
            |--------------------------------------------------------------------------
            | UPDATE WALLET BALANCE
            |--------------------------------------------------------------------------
            */

            $currentWalletBalance =
                (float) ($retailer->wallet_balance ?? 0);

            $newWalletBalance =
                $currentWalletBalance + $amount;

            $retailer->wallet_balance =
                $newWalletBalance;

            $retailer->save();

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            | RELOAD FRESH DATA FROM DB
            |--------------------------------------------------------------------------
            */

            $retailer->refresh();

            Log::info(
                'RETAILER WALLET UPDATED',
                [

                    'retailer_id' =>
                        $retailer->id,

                    'old_wallet_balance' =>
                        $currentWalletBalance,

                    'added_amount' =>
                        $amount,

                    'updated_wallet_balance' =>
                        $retailer->wallet_balance
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | SAVE ADVANCE PAYMENT
            |--------------------------------------------------------------------------
            */

            AdvancePayment::create([

                'retailer_id' =>
                    $retailer->id,

                'payment_id' =>
                    $paymentId,

                'payment_json' =>
                    json_encode($zohoPayment),

                'amount' =>
                    $amount
            ]);

            /*
            |--------------------------------------------------------------------------
            | FLOW LOG
            |--------------------------------------------------------------------------
            */

            WarrantyFlowLog::create([

                'payment_id' =>
                    $paymentId,

                'step' =>
                    'ONBOARDING_PAYMENT_CREATED',

                'status' =>
                    1,

                'response_data' =>
                    json_encode($zohoPayment)
            ]);

            /*
            |--------------------------------------------------------------------------
            | COMMIT DB
            |--------------------------------------------------------------------------
            */

            DB::commit();

            Log::info(
                'ONBOARDING PAYMENT SUCCESS',
                [

                    'payment_id' =>
                        $paymentId,

                    'zoho_payment_id' =>
                        $zohoPayment['payment_id']
                        ?? null,

                    'amount' =>
                        $amount,

                    'wallet_balance' =>
                        $retailer->wallet_balance
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | WHATSAPP
            |--------------------------------------------------------------------------
            */

            try {

                Log::info(
                    'WHATSAPP PROCESS STARTED'
                );

                $razorpayId = $paymentId;

                $whatsappService =
                    app(WhatsappService::class);

                /*
                |--------------------------------------------------------------------------
                | PAYMENT SUCCESS WHATSAPP
                |--------------------------------------------------------------------------
                */

                $paymentWhatsappResponse =
                    $whatsappService
                        ->paymentSuccessWhatsapp(
                            $retailer,
                            $zohoPayment,
                            $amount,
                            $razorpayId
                        );

                Log::info(
                    'PAYMENT WHATSAPP RESPONSE',
                    [
                        'response' =>
                            $paymentWhatsappResponse
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | REFRESH AGAIN BEFORE RECEIPT
                |--------------------------------------------------------------------------
                */

                $retailer->refresh();

                /*
                |--------------------------------------------------------------------------
                | PAYMENT RECEIPT WHATSAPP
                |--------------------------------------------------------------------------
                */

                $receiptWhatsappResponse =
                    $whatsappService
                        ->sendPaymentReceiptWhatsapp(

                            $retailer,

                            // ARP ID
                            $retailer->company_code
                                ?? 'ARP001',

                            // RECEIPT ID
                            $zohoPayment['payment_id']
                                ?? $paymentId,

                            // AMOUNT
                            $amount,

                            // DATE
                            now()->format('d-m-Y'),

                            // UPDATED WALLET BALANCE
                            (float) $retailer->wallet_balance
                        );

                Log::info(
                    'PAYMENT RECEIPT WHATSAPP RESPONSE',
                    [
                        'wallet_balance' =>
                            $retailer->wallet_balance,

                        'response' =>
                            $receiptWhatsappResponse
                    ]
                );

            } catch (\Throwable $e) {

                Log::error(
                    'ONBOARDING PAYMENT WHATSAPP FAILED',
                    [

                        'retailer_id' =>
                            $retailer->id ?? null,

                        'message' =>
                            $e->getMessage(),

                        'line' =>
                            $e->getLine(),

                        'file' =>
                            $e->getFile(),

                        'trace' =>
                            $e->getTraceAsString()
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | EMAIL
            |--------------------------------------------------------------------------
            */

            try {

                if ($retailer->contact_email) {

                    Mail::to(
                        $retailer->contact_email
                    )
                    ->queue(
                        new RetailerPaymentDoneMail(
                            $retailer,
                            $paymentId,
                            $amount
                        )
                    );

                    Log::info(
                        'PAYMENT EMAIL QUEUED',
                        [

                            'retailer_id' =>
                                $retailer->id,

                            'email' =>
                                $retailer->contact_email
                        ]
                    );
                }

            } catch (\Throwable $e) {

                Log::error(
                    'PAYMENT EMAIL FAILED',
                    [

                        'retailer_id' =>
                            $retailer->id ?? null,

                        'message' =>
                            $e->getMessage()
                    ]
                );
            }

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error(
                'ONBOARDING PAYMENT FAILED',
                [

                    'message' =>
                        $e->getMessage(),

                    'line' =>
                        $e->getLine(),

                    'file' =>
                        $e->getFile(),

                    'payload' =>
                        $this->payload
                ]
            );

            WarrantyFlowLog::create([

                'payment_id' =>
                    $this->payload['payment_id']
                    ?? null,

                'step' =>
                    'ONBOARDING_PAYMENT_FAILED',

                'status' =>
                    0,

                'error_message' =>
                    $e->getMessage()
            ]);

            throw $e;
        }
    }
}