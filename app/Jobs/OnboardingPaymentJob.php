<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\AdvancePayment;
use App\Models\WarrantyFlowLog;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

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

    public $tries = 3;

    public $timeout = 180;

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {
        /*
        |--------------------------------------------------------------------------
        | PAYMENT ID
        |--------------------------------------------------------------------------
        */

        $paymentId =
            $this->payload['payment_id'] ?? null;

        if (!$paymentId) {

            Log::error(
                'ONBOARDING PAYMENT ID MISSING',
                [
                    'payload' => $this->payload
                ]
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | CACHE LOCK
        |--------------------------------------------------------------------------
        */

        $lock = Cache::lock(
            'onboarding_payment_' . $paymentId,
            120
        );

        if (!$lock->get()) {

            Log::warning(
                'ONBOARDING PAYMENT LOCKED',
                [
                    'payment_id' => $paymentId
                ]
            );

            return;
        }

        try {

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
                    $this->payload[$field] === null
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

            $amount =
                (float) $this->payload['amount'];

            $companyId = 1;

            $retailerId =
                $this->payload['retailer_id'];

            Log::info(
                'ONBOARDING PAYMENT JOB STARTED',
                [
                    'payment_id' => $paymentId,
                    'retailer_id' => $retailerId,
                    'amount' => $amount
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | DUPLICATE CHECK
            |--------------------------------------------------------------------------
            */

            $existingPayment = AdvancePayment::where(
                'payment_id',
                $paymentId
            )->first();

            if ($existingPayment) {

                Log::warning(
                    'ONBOARDING PAYMENT ALREADY EXISTS',
                    [
                        'payment_id' => $paymentId
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | START DB TRANSACTION
            |--------------------------------------------------------------------------
            */

            DB::beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | LOAD COMPANY
            |--------------------------------------------------------------------------
            */

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
            | LOAD RETAILER WITH DB LOCK
            |--------------------------------------------------------------------------
            */

            $retailer = Company::where(
                'id',
                $retailerId
            )
            ->lockForUpdate()
            ->first();

            if (!$retailer) {

                throw new \Exception(
                    'Retailer not found'
                );
            }

            if (!$retailer->zoho_id) {

                throw new \Exception(
                    'Retailer Zoho contact missing'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | HTTP CLIENT
            |--------------------------------------------------------------------------
            */

            $client = new Client([

                'timeout' => 60,

                'connect_timeout' => 20,

                'http_errors' => true
            ]);

            /*
            |--------------------------------------------------------------------------
            | CHECK EXISTING PAYMENT IN ZOHO
            |--------------------------------------------------------------------------
            */

            $existingZohoPayment = null;

            try {

                $searchResponse = $client->get(
                    'https://www.zohoapis.in/books/v3/customerpayments',
                    [

                        'headers' => [

                            'Authorization' =>
                                'Zoho-oauthtoken ' .
                                $company->zoho_access_token
                        ],

                        'query' => [

                            'organization_id' =>
                                $company->zoho_org_id,

                            'reference_number' =>
                                $paymentId
                        ]
                    ]
                );

                $searchBody = json_decode(
                    $searchResponse->getBody(),
                    true
                );

                if (
                    isset($searchBody['customerpayments']) &&
                    count($searchBody['customerpayments']) > 0
                ) {

                    $existingZohoPayment =
                        $searchBody['customerpayments'][0];

                    Log::info(
                        'ZOHO PAYMENT ALREADY EXISTS',
                        [
                            'payment_id' => $paymentId
                        ]
                    );
                }

            } catch (\Throwable $e) {

                Log::warning(
                    'ZOHO PAYMENT SEARCH FAILED',
                    [
                        'payment_id' => $paymentId,
                        'message' => $e->getMessage()
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | CREATE ZOHO PAYMENT
            |--------------------------------------------------------------------------
            */

            if (!$existingZohoPayment) {

                $paymentData = [
                    
                    'is_advance_payment' =>true,
                    
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

                Log::info(
                    'CREATING ZOHO PAYMENT',
                    [
                        'payment_id' => $paymentId,
                        'payload' => $paymentData
                    ]
                );

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

                Log::info(
                    'ZOHO PAYMENT RESPONSE',
                    [
                        'payment_id' => $paymentId,
                        'response' => $body
                    ]
                );

                if (!isset($body['payment'])) {

                    throw new \Exception(
                        'Zoho payment creation failed'
                    );
                }

                $zohoPayment = $body['payment'];

            } else {

                $zohoPayment =
                    $existingZohoPayment;
            }

            /*
            |--------------------------------------------------------------------------
            | PREVENT DOUBLE WALLET UPDATE
            |--------------------------------------------------------------------------
            */

            $existingWalletUpdate =
                WarrantyFlowLog::where(
                    'payment_id',
                    $paymentId
                )
                ->where(
                    'step',
                    'ONBOARDING_WALLET_UPDATED'
                )
                ->exists();

            if (!$existingWalletUpdate) {

                $oldWallet =
                    (float) ($retailer->wallet_balance ?? 0);

                $newWallet =
                    $oldWallet + $amount;

                $retailer->wallet_balance =
                    $newWallet;

                /*
                |--------------------------------------------------------------------------
                | PAYMENT SUCCESS STATUS
                |--------------------------------------------------------------------------
                */

                $retailer->is_payment_success = 1;

                $retailer->last_update_balance_at =
                    now();

                $retailer->save();

                WarrantyFlowLog::create([

                    'payment_id' =>
                        $paymentId,

                    'step' =>
                        'ONBOARDING_WALLET_UPDATED',

                    'status' =>
                        1,

                    'response_data' =>
                        json_encode([

                            'old_wallet_balance' =>
                                $oldWallet,

                            'added_amount' =>
                                $amount,

                            'updated_wallet_balance' =>
                                $newWallet
                        ])
                ]);

                Log::info(
                    'RETAILER WALLET UPDATED',
                    [

                        'retailer_id' =>
                            $retailer->id,

                        'old_wallet_balance' =>
                            $oldWallet,

                        'added_amount' =>
                            $amount,

                        'updated_wallet_balance' =>
                            $newWallet
                    ]
                );

            } else {

                Log::warning(
                    'WALLET ALREADY UPDATED',
                    [
                        'payment_id' => $paymentId
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | REFRESH RETAILER
            |--------------------------------------------------------------------------
            */

            $retailer->refresh();

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
            | FLOW LOG SUCCESS
            |--------------------------------------------------------------------------
            */

            WarrantyFlowLog::create([

                'payment_id' =>
                    $paymentId,

                'step' =>
                    'ONBOARDING_PAYMENT_SUCCESS',

                'status' =>
                    1,

                'response_data' =>
                    json_encode([

                        'zoho_payment_id' =>
                            $zohoPayment['payment_id']
                            ?? null,

                        'wallet_balance' =>
                            $retailer->wallet_balance,

                        'is_payment_success' =>
                            $retailer->is_payment_success
                    ])
            ]);

            /*
            |--------------------------------------------------------------------------
            | COMMIT
            |--------------------------------------------------------------------------
            */

            DB::commit();

            Log::info(
                'ONBOARDING PAYMENT SUCCESS',
                [

                    'payment_id' =>
                        $paymentId,

                    'retailer_id' =>
                        $retailer->id,

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

                $whatsappService =
                    app(WhatsappService::class);

                $whatsappService
                    ->paymentSuccessWhatsapp(
                        $retailer,
                        $zohoPayment,
                        $amount,
                        $paymentId
                    );

                $whatsappService
                    ->sendPaymentReceiptWhatsapp(

                        $retailer,

                        $retailer->company_code
                            ?? 'ARP001',

                        $zohoPayment['payment_id']
                            ?? $paymentId,

                        $amount,

                        now()->format('d-m-Y'),

                        (float) $retailer->wallet_balance
                    );

            } catch (\Throwable $e) {

                Log::error(
                    'WHATSAPP FAILED',
                    [
                        'payment_id' => $paymentId,
                        'message' => $e->getMessage()
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
                    'EMAIL FAILED',
                    [
                        'payment_id' => $paymentId,
                        'message' => $e->getMessage()
                    ]
                );
            }

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error(
                'ONBOARDING PAYMENT FAILED',
                [

                    'payment_id' =>
                        $paymentId,

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

            try {

                WarrantyFlowLog::create([

                    'payment_id' =>
                        $paymentId,

                    'step' =>
                        'ONBOARDING_PAYMENT_FAILED',

                    'status' =>
                        0,

                    'error_message' =>
                        $e->getMessage()
                ]);

            } catch (\Throwable $logError) {

                Log::error(
                    'FLOW LOG FAILED',
                    [
                        'message' =>
                            $logError->getMessage()
                    ]
                );
            }

            throw $e;

        } finally {

            try {

                optional($lock)->release();

            } catch (\Throwable $e) {

                Log::warning(
                    'LOCK RELEASE FAILED',
                    [
                        'payment_id' => $paymentId
                    ]
                );
            }
        }
    }
}