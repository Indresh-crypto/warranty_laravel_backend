<?php

namespace App\Jobs;

use App\Models\WDevice;
use App\Models\WCustomer;
use App\Models\WarrantyPaymentLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use GuzzleHttp\Client;

use App\Mail\InvoiceCreatedMail;
use App\Mail\PaymentCompletedMail;
use App\Events\WarrantyPaymentCompleted;

class ProcessWarrantyPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;

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
        'imei',
        'product_id',
        'company_id',
        'zoho_product_id',
        'amount'
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

    $paymentId = $this->payload['payment_id'];

    try {

        /*
        |--------------------------------------------------------------------------
        | IDEMPOTENT CHECK
        |--------------------------------------------------------------------------
        */

        $alreadyCompleted = WarrantyPaymentLog::where(
                'payment_id',
                $paymentId
            )
            ->where(
                'step',
                'JOB_COMPLETED'
            )
            ->exists();

        if ($alreadyCompleted) {

            Log::warning(
                'PROCESS WARRANTY PAYMENT ALREADY COMPLETED',
                [
                    'payment_id' => $paymentId
                ]
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | JOB START LOG
        |--------------------------------------------------------------------------
        */

        WarrantyPaymentLog::firstOrCreate(

            [
                'payment_id' => $paymentId,
                'step'       => 'JOB_STARTED'
            ],

            [
                'status' => 1
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | CREATE / LOAD DEVICE
        |--------------------------------------------------------------------------
        */

        DB::transaction(function () use (
            &$device,
            $paymentId
        ) {

            $device = WDevice::where(
                    'imei1',
                    $this->payload['imei']
                )
                ->where(
                    'product_id',
                    $this->payload['product_id']
                )
                ->lockForUpdate()
                ->first();

            if (!$device) {

                $device = WDevice::create([

                    'imei1' =>
                        $this->payload['imei'],

                    'product_id' =>
                        $this->payload['product_id'],

                    'company_id' =>
                        $this->payload['company_id'],

                    'w_customer_id' =>
                        $this->payload['customer_id']
                        ?? null,

                    'is_approved' => 1,

                    'status' => 1
                ]);

                WarrantyPaymentLog::firstOrCreate(

                    [
                        'payment_id' => $paymentId,
                        'step'       => 'DEVICE_CREATED'
                    ],

                    [
                        'device_id' => $device->id,
                        'status'    => 1
                    ]
                );
            }

        }, 3);

        /*
        |--------------------------------------------------------------------------
        | LOAD CUSTOMER
        |--------------------------------------------------------------------------
        */

        $customer = null;

        if (!empty($device->w_customer_id)) {

            $customer = WCustomer::find(
                $device->w_customer_id
            );
        }

        /*
        |--------------------------------------------------------------------------
        | REFRESH DEVICE
        |--------------------------------------------------------------------------
        */

        $device = WDevice::find($device->id);

        /*
        |--------------------------------------------------------------------------
        | CREATE ZOHO INVOICE
        |--------------------------------------------------------------------------
        */

        if (empty($device->invoice_id)) {

            try {

                $invoiceResult = app(
                    \App\Services\ZohoInvoiceService::class
                )->createWarrantyInvoice(

                    $device,

                    $customer,

                    $this->payload['company_id'],

                    $this->payload['zoho_product_id'],

                    $paymentId
                );

                if (
                    empty($invoiceResult['success'])
                ) {

                    throw new \Exception(
                        $invoiceResult['message']
                        ?? 'Invoice creation failed'
                    );
                }

                $invoice =
                    $invoiceResult['invoice'];

                $invoiceId =
                    $invoice['invoice_id'];

                DB::transaction(function () use (
                    $device,
                    $invoice,
                    $invoiceId,
                    $invoiceResult,
                    $paymentId
                ) {

                    $device->update([

                        'invoice_id' =>
                            $invoiceId,

                        'invoice_json' =>
                            json_encode($invoice)
                    ]);

                    WarrantyPaymentLog::firstOrCreate(

                        [
                            'payment_id' => $paymentId,
                            'step'       => 'INVOICE_CREATED'
                        ],

                        [
                            'device_id' =>
                                $device->id,

                            'invoice_id' =>
                                $invoiceId,

                            'status' => 1,

                            'response_payload' =>
                                json_encode(
                                    $invoiceResult
                                )
                        ]
                    );

                }, 3);

            } catch (\Throwable $e) {

                WarrantyPaymentLog::create([

                    'payment_id' =>
                        $paymentId,

                    'device_id' =>
                        $device->id ?? null,

                    'step' =>
                        'INVOICE_FAILED',

                    'status' => 0,

                    'error_message' =>
                        $e->getMessage()
                ]);

                throw $e;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | REFRESH DEVICE
        |--------------------------------------------------------------------------
        */

        $device = WDevice::find($device->id);

        $invoice =
            json_decode(
                $device->invoice_json,
                true
            );

        $invoiceId =
            $device->invoice_id;

        /*
        |--------------------------------------------------------------------------
        | CAPTURE RAZORPAY PAYMENT
        |--------------------------------------------------------------------------
        */

        $captureDone = WarrantyPaymentLog::where(
                'payment_id',
                $paymentId
            )
            ->where(
                'step',
                'RAZORPAY_CAPTURED'
            )
            ->exists();

        if (!$captureDone) {

            try {

                $razor = new Client();

                $capture = $razor->post(

                    "https://api.razorpay.com/v1/payments/{$paymentId}/capture",

                    [

                        'auth' => [

                            config(
                                'services.razorpay.razorpay_key'
                            ),

                            config(
                                'services.razorpay.razorpay_secret'
                            ),
                        ],

                        'json' => [

                            'amount' =>
                                $this->payload['amount'] * 100,

                            'currency' => 'INR'
                        ]
                    ]
                );

                $captureBody = json_decode(
                    $capture->getBody(),
                    true
                );

                WarrantyPaymentLog::firstOrCreate(

                    [
                        'payment_id' => $paymentId,
                        'step'       => 'RAZORPAY_CAPTURED'
                    ],

                    [
                        'device_id' =>
                            $device->id,

                        'invoice_id' =>
                            $invoiceId,

                        'status' => 1,

                        'response_payload' =>
                            json_encode(
                                $captureBody
                            )
                    ]
                );

            } catch (\Throwable $e) {

                WarrantyPaymentLog::create([

                    'payment_id' =>
                        $paymentId,

                    'device_id' =>
                        $device->id,

                    'step' =>
                        'RAZORPAY_CAPTURE_FAILED',

                    'status' => 0,

                    'error_message' =>
                        $e->getMessage()
                ]);

                throw $e;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CREATE ZOHO PAYMENT
        |--------------------------------------------------------------------------
        */

        if (empty($device->zoho_payment_id)) {

            try {

                $paymentResult = app(
                    \App\Services\ZohoPaymentService::class
                )->createPayment(

                    $invoiceId,

                    $this->payload
                );

                DB::transaction(function () use (
                    $device,
                    $paymentResult,
                    $paymentId,
                    $invoiceId
                ) {

                    $device->update([

                        'payment_status' => 1,

                        'razorpay_payment_id' =>
                            $paymentId,

                        'zoho_payment_id' =>
                            $paymentResult['payment_id']
                            ?? null,

                        'paid_at' => now()
                    ]);

                    WarrantyPaymentLog::firstOrCreate(

                        [
                            'payment_id' => $paymentId,
                            'step'       => 'ZOHO_PAYMENT_CREATED'
                        ],

                        [
                            'device_id' =>
                                $device->id,

                            'invoice_id' =>
                                $invoiceId,

                            'zoho_payment_id' =>
                                $paymentResult['payment_id']
                                ?? null,

                            'status' => 1,

                            'response_payload' =>
                                json_encode(
                                    $paymentResult
                                )
                        ]
                    );

                }, 3);

            } catch (\Throwable $e) {

                WarrantyPaymentLog::create([

                    'payment_id' =>
                        $paymentId,

                    'device_id' =>
                        $device->id,

                    'step' =>
                        'ZOHO_PAYMENT_FAILED',

                    'status' => 0,

                    'error_message' =>
                        $e->getMessage()
                ]);

                throw $e;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | INVOICE MAIL
        |--------------------------------------------------------------------------
        */

        try {

            if (
                $customer &&
                !empty($customer->email)
            ) {

                $mailLog =
                    WarrantyPaymentLog::firstOrCreate(

                        [
                            'payment_id' => $paymentId,
                            'step' =>
                                'INVOICE_MAIL_SENT'
                        ],

                        [
                            'device_id' =>
                                $device->id,

                            'status' => 1
                        ]
                    );

                if (
                    $mailLog->wasRecentlyCreated
                ) {

                    Mail::to(
                        $customer->email
                    )->queue(

                        new InvoiceCreatedMail(

                            $invoice,

                            $invoice['invoice_url']
                            ?? '#'
                        )
                    );
                }
            }

        } catch (\Throwable $e) {

            Log::error(
                'INVOICE MAIL FAILED',
                [
                    'payment_id' =>
                        $paymentId,

                    'error' =>
                        $e->getMessage()
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PAYMENT SUCCESS MAIL
        |--------------------------------------------------------------------------
        */

        try {

            if (
                $customer &&
                !empty($customer->email)
            ) {

                $paymentMailLog =
                    WarrantyPaymentLog::firstOrCreate(

                        [
                            'payment_id' => $paymentId,
                            'step' =>
                                'PAYMENT_MAIL_SENT'
                        ],

                        [
                            'device_id' =>
                                $device->id,

                            'status' => 1
                        ]
                    );

                if (
                    $paymentMailLog->wasRecentlyCreated
                ) {

                    Mail::to(
                        $customer->email
                    )->queue(

                        new PaymentCompletedMail(

                            $device->fresh(
                                ['customer']
                            )
                        )
                    );
                }
            }

        } catch (\Throwable $e) {

            Log::error(
                'PAYMENT MAIL FAILED',
                [
                    'payment_id' =>
                        $paymentId,

                    'error' =>
                        $e->getMessage()
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | EVENT
        |--------------------------------------------------------------------------
        */

        try {

            $eventLog =
                WarrantyPaymentLog::firstOrCreate(

                    [
                        'payment_id' => $paymentId,
                        'step' =>
                            'PAYMENT_EVENT_SENT'
                    ],

                    [
                        'device_id' =>
                            $device->id,

                        'status' => 1
                    ]
                );

            if (
                $eventLog->wasRecentlyCreated
            ) {

                event(
                    new WarrantyPaymentCompleted(
                        $device
                    )
                );
            }

        } catch (\Throwable $e) {

            Log::error(
                'PAYMENT EVENT FAILED',
                [
                    'payment_id' =>
                        $paymentId,

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

        WarrantyPaymentLog::firstOrCreate(

            [
                'payment_id' => $paymentId,
                'step'       => 'JOB_COMPLETED'
            ],

            [
                'device_id' =>
                    $device->id,

                'status' => 1
            ]
        );

        Log::info(
            'PROCESS WARRANTY PAYMENT SUCCESS',
            [
                'payment_id' =>
                    $paymentId,

                'device_id' =>
                    $device->id,

                'invoice_id' =>
                    $invoiceId
            ]
        );

    } catch (\Throwable $e) {

        Log::error(
            'PROCESS WARRANTY PAYMENT FAILED',
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

        WarrantyPaymentLog::create([

            'payment_id' =>
                $paymentId,

            'step' =>
                'FINAL_FAILED',

            'status' => 0,

            'error_message' =>
                $e->getMessage()
        ]);

        throw $e;
    }
}
}