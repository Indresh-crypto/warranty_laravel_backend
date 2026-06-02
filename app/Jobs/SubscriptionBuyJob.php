<?php

namespace App\Jobs;

use App\Models\WarrantyFlowLog;
use App\Models\WarrantyProduct;
use App\Http\Controllers\WarrantyPaymentFlowController;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

use App\Mail\InvoiceCreatedMail;
use App\Models\Company;
use App\Models\SubscribedPackage;

class SubscriptionBuyJob implements ShouldQueue
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
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        $required = [
            'payment_id',
            'package_id',
            'company_package_id',
            'company_id',
            'retailer_id',
            'amount',
        ];

        foreach ($required as $field) {

            if (
                !isset($this->payload[$field]) ||
                $this->payload[$field] === ''
            ) {

                throw new \Exception(
                    $field . ' missing in subscription payload'
                );
            }
        }

        $paymentId = $this->payload['payment_id'];

        /*
        |--------------------------------------------------------------------------
        | CACHE LOCK
        |--------------------------------------------------------------------------
        */

        return Cache::lock(

            'subscription-buy-' . $paymentId,

            120

        )->block(10, function () use ($paymentId) {

            try {

                /*
                |--------------------------------------------------------------------------
                | IDEMPOTENT CHECK
                |--------------------------------------------------------------------------
                */

                $alreadyCompleted = WarrantyFlowLog::where(
                        'payment_id',
                        $paymentId
                    )
                    ->where(
                        'step',
                        'SUBSCRIPTION_JOB_COMPLETED'
                    )
                    ->exists();

                if ($alreadyCompleted) {

                    \Log::warning(
                        'SUBSCRIPTION JOB ALREADY COMPLETED',
                        [
                            'payment_id' => $paymentId
                        ]
                    );

                    return;
                }

                /*
                |--------------------------------------------------------------------------
                | START LOG
                |--------------------------------------------------------------------------
                */

                WarrantyFlowLog::firstOrCreate(

                    [
                        'payment_id' => $paymentId,
                        'step'       => 'SUBSCRIPTION_JOB_STARTED'
                    ],

                    [
                        'status' => 1
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | LOAD PRODUCT
                |--------------------------------------------------------------------------
                */

                $product = WarrantyProduct::find(
                    $this->payload['package_id']
                );

                if (!$product) {

                    throw new \Exception(
                        'Subscription package not found'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | LOAD RETAILER
                |--------------------------------------------------------------------------
                */

                $company = Company::find(
                    $this->payload['retailer_id']
                );

                if (!$company) {

                    throw new \Exception(
                        'Retailer company not found'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | CREATE / LOAD SUBSCRIPTION
                |--------------------------------------------------------------------------
                */

                DB::transaction(function () use (
                    &$subscription,
                    $paymentId,
                    $product,
                    $company
                ) {

                    $subscription =
                        SubscribedPackage::updateOrCreate(

                            [
                                'payment_id' => $paymentId
                            ],

                            [

                                'package_id' =>
                                    $this->payload['package_id'],

                                'package_name' =>
                                    $product->name
                                    ?? 'Subscription Package',

                                'company_package_id' =>
                                    $this->payload['company_package_id'],

                                'company_id' =>
                                    $this->payload['company_id'],

                                'retailer_id' =>
                                    $this->payload['retailer_id'],

                                'status' => 0,

                                'enroll_max' =>
                                    $product->enroll_max,

                                'balance' =>
                                    $product->enroll_max,

                                'validity_days' =>
                                    $product->sub_val_days,

                                'payment_id' =>
                                    $paymentId,

                                'start_date' =>
                                    now()->toDateString(),

                                'end_date' =>
                                    now()
                                        ->addDays(
                                            $product->sub_val_days
                                        )
                                        ->toDateString(),

                                'amount' =>
                                    $this->payload['amount']
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
                    | COMPANY UPDATE
                    |--------------------------------------------------------------------------
                    */

                    if (!$company->is_subscribed) {

                        $company->update([

                            'is_subscribed' => 1
                        ]);
                    }

                    WarrantyFlowLog::firstOrCreate(

                        [
                            'payment_id' => $paymentId,
                            'step'       => 'SUBSCRIPTION_CREATED'
                        ],

                        [
                            'device_id' => $subscription->id,
                            'status'    => 1
                        ]
                    );

                }, 3);

                /*
                |--------------------------------------------------------------------------
                | REFRESH
                |--------------------------------------------------------------------------
                */

                $subscription = SubscribedPackage::find(
                    $subscription->id
                );

                /*
                |--------------------------------------------------------------------------
                | CREATE INVOICE
                |--------------------------------------------------------------------------
                */

                if (empty($subscription->zoho_invoice_id)) {

                    try {

                        $controller = app(
                            WarrantyPaymentFlowController::class
                        );

                        $invoiceResult =
                            $controller->createSubscriptionInvoice(

                                $subscription,

                                $this->payload['company_id'],

                                $this->payload['retailer_id'],

                                $this->payload['package_id'],

                                $paymentId,

                                $this->payload['amount']
                            );

                        if (
                            empty($invoiceResult['success'])
                        ) {

                            throw new \Exception(
                                $invoiceResult['message']
                                ?? 'Invoice creation failed'
                            );
                        }

                        $zohoInvoice =
                            $invoiceResult['invoice'];

                        DB::transaction(function () use (
                            $subscription,
                            $zohoInvoice,
                            $paymentId
                        ) {

                            $subscription->update([

                                'zoho_invoice_id' =>
                                    $zohoInvoice['invoice_id'],

                                'invoice_created_date' =>
                                    $zohoInvoice['date']
                                    ?? now()->toDateString(),

                                'invoice_status' =>
                                    $zohoInvoice['status']
                                    ?? 'created',

                                'invoice_json' =>
                                    json_encode($zohoInvoice)
                            ]);

                            WarrantyFlowLog::firstOrCreate(

                                [
                                    'payment_id' => $paymentId,
                                    'step'       => 'SUBSCRIPTION_INVOICE_CREATED'
                                ],

                                [
                                    'device_id' => $subscription->id,
                                    'invoice_id' =>
                                        $zohoInvoice['invoice_id'],
                                    'status' => 1
                                ]
                            );

                        }, 3);

                    } catch (\Throwable $e) {

                        WarrantyFlowLog::firstOrCreate([

                            'payment_id' =>
                                $paymentId,

                            'device_id' =>
                                $subscription->id ?? null,

                            'step' =>
                                'SUBSCRIPTION_INVOICE_FAILED',

                            'status' => 0,

                            'error_message' =>
                                $e->getMessage()
                        ]);

                        throw $e;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | SEND INVOICE
                |--------------------------------------------------------------------------
                */

                try {

                    $alreadySent =
                        WarrantyFlowLog::where(
                            'payment_id',
                            $paymentId
                        )
                        ->where(
                            'step',
                            'SUBSCRIPTION_INVOICE_SENT'
                        )
                        ->exists();

                    if (!$alreadySent) {

                        if (
                            empty(
                                $subscription->zoho_invoice_id
                            )
                        ) {

                            throw new \Exception(
                                'Zoho invoice missing'
                            );
                        }

                        $controller = app(
                            WarrantyPaymentFlowController::class
                        );

                        $sendResponse =
                            $controller->sendZohoInvoice(

                                $this->payload['company_id'],

                                $subscription->zoho_invoice_id
                            );

                        $subscription->update([

                            'invoice_status' => 'sent',

                            'status' => 1
                        ]);

                        WarrantyFlowLog::firstOrCreate([

                            'payment_id' =>
                                $paymentId,

                            'device_id' =>
                                $subscription->id,

                            'invoice_id' =>
                                $subscription->zoho_invoice_id,

                            'step' =>
                                'SUBSCRIPTION_INVOICE_SENT',

                            'status' => 1,

                            'response_data' =>
                                json_encode($sendResponse)
                        ]);
                    }

                } catch (\Throwable $e) {

                    WarrantyFlowLog::firstOrCreate([

                        'payment_id' =>
                            $paymentId,

                        'device_id' =>
                            $subscription->id,

                        'step' =>
                            'SUBSCRIPTION_INVOICE_SEND_FAILED',

                        'status' => 0,

                        'error_message' =>
                            $e->getMessage()
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | CREATE PAYMENT
                |--------------------------------------------------------------------------
                */

                if (empty($subscription->zoho_payment_id)) {

                    try {

                        if (
                            empty(
                                $subscription->zoho_invoice_id
                            )
                        ) {

                            throw new \Exception(
                                'Invoice ID missing before payment'
                            );
                        }

                        $zohoResponse =
                            app(
                                WarrantyPaymentFlowController::class
                            )->createZohoPayment(

                                $this->payload['company_id'],

                                $this->payload['retailer_id'],

                                $paymentId,

                                $this->payload['amount'],

                                $subscription->zoho_invoice_id
                            );

                        $zohoPayment =
                            $zohoResponse['payment']
                            ?? null;

                        if (!$zohoPayment) {

                            throw new \Exception(
                                'Zoho payment creation failed'
                            );
                        }

                        DB::transaction(function () use (
                            $subscription,
                            $zohoPayment,
                            $zohoResponse,
                            $paymentId
                        ) {

                            $subscription->update([

                                'zoho_payment_id' =>
                                    $zohoPayment['payment_id'],

                                'payment_json' =>
                                    json_encode($zohoPayment),

                                'invoice_status' =>
                                    'paid',

                                'status' => 1
                            ]);

                            WarrantyFlowLog::firstOrCreate(

                                [
                                    'payment_id' => $paymentId,
                                    'step' =>
                                        'SUBSCRIPTION_PAYMENT_CREATED'
                                ],

                                [
                                    'device_id' =>
                                        $subscription->id,

                                    'invoice_id' =>
                                        $subscription->zoho_invoice_id,

                                    'zoho_payment_id' =>
                                        $zohoPayment['payment_id'],

                                    'status' => 1,

                                    'response_data' =>
                                        json_encode(
                                            $zohoResponse
                                        )
                                ]
                            );

                        }, 3);

                    } catch (\Throwable $e) {

                        WarrantyFlowLog::firstOrCreate([

                            'payment_id' =>
                                $paymentId,

                            'device_id' =>
                                $subscription->id,

                            'step' =>
                                'SUBSCRIPTION_PAYMENT_FAILED',

                            'status' => 0,

                            'error_message' =>
                                $e->getMessage()
                        ]);

                        throw $e;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | PAYMENT SUCCESS WHATSAPP
                |--------------------------------------------------------------------------
                */

                try {

                    $waLog =
                        WarrantyFlowLog::firstOrCreate(

                            [
                                'payment_id' => $paymentId,
                                'step' =>
                                    'SUBSCRIPTION_PAYMENT_WA_SENT'
                            ],

                            [
                                'device_id' =>
                                    $subscription->id,

                                'status' => 1
                            ]
                        );

                    if ($waLog->wasRecentlyCreated) {

                        app(
                            \App\Services\WhatsappService::class
                        )->paymentSuccessWhatsapp(

                            $company,

                            json_decode(
                                $subscription->payment_json,
                                true
                            ),

                            $subscription->amount,

                            $paymentId
                        );

                        $invoiceJson = json_decode(
                            $subscription->invoice_json,
                            true
                        );

                        $invoiceNumber =
                            $invoiceJson['invoice_number']
                            ?? (
                                $invoiceJson['invoice_id']
                                ?? '-'
                            );

                        $invoiceDate =
                            $invoiceJson['date']
                            ?? now()->toDateString();

                        $invoiceAmount =
                            $invoiceJson['total']
                            ?? $subscription->amount;

                        $invoiceUrl =
                            $invoiceJson['invoice_url']
                            ?? (
                                $invoiceJson['customer_view_url']
                                ?? '#'
                            );

                        app(
                            \App\Services\WhatsappService::class
                        )->invoiceWhatsapp(

                            $company,

                            $invoiceNumber,

                            $invoiceDate,

                            $invoiceAmount,

                            $invoiceUrl
                        );
                    }

                } catch (\Throwable $e) {

                    \Log::error(
                        'SUBSCRIPTION PAYMENT WA FAILED',
                        [
                            'error' =>
                                $e->getMessage()
                        ]
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | SUBSCRIPTION ACTIVATED WHATSAPP
                |--------------------------------------------------------------------------
                */

                try {

                    $waActivate =
                        WarrantyFlowLog::firstOrCreate(

                            [
                                'payment_id' => $paymentId,
                                'step' =>
                                    'SUBSCRIPTION_ACTIVATED_WA_SENT'
                            ],

                            [
                                'device_id' =>
                                    $subscription->id,

                                'status' => 1
                            ]
                        );

                    if (
                        $waActivate->wasRecentlyCreated
                    ) {

                        app(
                            \App\Services\WhatsappService::class
                        )->sendSubscriptionActivatedWhatsapp(

                            $company,

                            $subscription
                        );
                    }

                } catch (\Throwable $e) {

                    \Log::error(
                        'SUBSCRIPTION ACTIVATION WA FAILED',
                        [
                            'error' =>
                                $e->getMessage()
                        ]
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | MAIL
                |--------------------------------------------------------------------------
                */

                try {

                    $mailLog =
                        WarrantyFlowLog::firstOrCreate(

                            [
                                'payment_id' => $paymentId,
                                'step' =>
                                    'SUBSCRIPTION_MAIL_SENT'
                            ],

                            [
                                'device_id' =>
                                    $subscription->id,

                                'status' => 1
                            ]
                        );

                    if (
                        $mailLog->wasRecentlyCreated
                    ) {

                        Mail::to(
                            $company->contact_email
                        )->queue(

                            new InvoiceCreatedMail(

                                json_decode(
                                    $subscription->invoice_json,
                                    true
                                ),

                                json_decode(
                                    $subscription->invoice_json,
                                    true
                                )['invoice_url']
                                ?? '#'
                            )
                        );
                    }

                } catch (\Throwable $e) {

                    \Log::error(
                        'SUBSCRIPTION MAIL FAILED',
                        [
                            'error' =>
                                $e->getMessage()
                        ]
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | JOB COMPLETED
                |--------------------------------------------------------------------------
                */

                WarrantyFlowLog::firstOrCreate(

                    [
                        'payment_id' => $paymentId,
                        'step'       => 'SUBSCRIPTION_JOB_COMPLETED'
                    ],

                    [
                        'device_id' =>
                            $subscription->id,

                        'status' => 1
                    ]
                );

            } catch (\Throwable $e) {

                if (
                    str_contains(
                        strtolower($e->getMessage()),
                        'duplicate'
                    )
                ) {

                    \Log::warning(
                        'Duplicate subscription prevented',
                        [
                            'payment_id' => $paymentId
                        ]
                    );

                    return;
                }

                \Log::error(
                    'SUBSCRIPTION JOB FAILED',
                    [

                        'payment_id' =>
                            $paymentId,

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
                            )
                    ]
                );

                WarrantyFlowLog::firstOrCreate(

                    [

                        'payment_id' =>
                            $paymentId,

                        'step' =>
                            'SUBSCRIPTION_FINAL_FAILED'

                    ],

                    [

                        'status' => 0,

                        'error_message' =>
                            $e->getMessage()
                    ]
                );

                throw $e;
            }

        });
    }

    /*
    |--------------------------------------------------------------------------
    | FINAL FAILURE CALLBACK
    |--------------------------------------------------------------------------
    */

    public function failed(
        \Throwable $exception
    ) {

        WarrantyFlowLog::firstOrCreate([

            'payment_id' =>
                $this->payload['payment_id']
                ?? null,

            'step' =>
                'FINAL_FAILED',

            'status' => 0,

            'error_message' =>
                $exception->getMessage()
        ]);
    }
}