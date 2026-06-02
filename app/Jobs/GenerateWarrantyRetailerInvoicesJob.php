<?php

namespace App\Jobs;

use App\Models\WDevice;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\WarrantyBulkInvoiceLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceCreatedMail;

class GenerateWarrantyRetailerInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    public function handle()
    {
        /*
        |--------------------------------------------------------------------------
        | LOAD ELIGIBLE DEVICES
        |--------------------------------------------------------------------------
        */
    
        $rawDevices = WDevice::with('customer')
    
            ->where('is_pay_later', 1)
    
            ->where(function ($q) {
    
                $q->whereNull('invoice_status')
                  ->orWhereNull('invoice_id');
            })
    
            ->orderBy('company_id')
    
            ->orderBy('retailer_id')
    
            ->orderBy('product_id')
    
            ->get();
    
        Log::info('BULK INVOICE JOB STARTED', [
    
            'device_count' =>
                $rawDevices->count()
        ]);
    
        if ($rawDevices->isEmpty()) {
    
            Log::info('NO ELIGIBLE DEVICES FOUND');
    
            return;
        }
    
        /*
        |--------------------------------------------------------------------------
        | GROUPING
        |--------------------------------------------------------------------------
        */
    
        $devices = $rawDevices->groupBy([
    
            'company_id',
    
            'retailer_id',
    
            'product_id'
        ]);
    
        /*
        |--------------------------------------------------------------------------
        | LOOP COMPANIES
        |--------------------------------------------------------------------------
        */
    
        foreach ($devices as $companyId => $retailers) {
    
            try {
    
                /*
                |--------------------------------------------------------------------------
                | COMPANY
                |--------------------------------------------------------------------------
                */
    
                $company = Company::find($companyId);
    
                if (
                    !$company ||
                    !$company->zoho_access_token ||
                    !$company->zoho_org_id
                ) {
    
                    Log::warning(
                        'COMPANY SKIPPED',
                        [
                            'company_id' =>
                                $companyId
                        ]
                    );
    
                    continue;
                }
    
                /*
                |--------------------------------------------------------------------------
                | COMPANY PRODUCTS
                |--------------------------------------------------------------------------
                */
    
                $companyProducts =
                    CompanyProduct::where(
                        'company_id',
                        $companyId
                    )
                    ->pluck(
                        'zoho_item_id',
                        'product_id'
                    );
    
                /*
                |--------------------------------------------------------------------------
                | RETAILERS
                |--------------------------------------------------------------------------
                */
    
                foreach ($retailers as $retailerId => $products) {
    
                    foreach ($products as $productId => $retailerDevices) {
    
                        /*
                        |--------------------------------------------------------------------------
                        | DUPLICATE CHECK
                        |--------------------------------------------------------------------------
                        */
    
                        $existingInvoice =
                            $retailerDevices
                                ->whereNotNull('invoice_id')
                                ->first();
    
                        if ($existingInvoice) {
    
                            Log::warning(
                                'DEVICES ALREADY INVOICED',
                                [
                                    'invoice_id' =>
                                        $existingInvoice->invoice_id,
    
                                    'retailer_id' =>
                                        $retailerId
                                ]
                            );
    
                            continue;
                        }
    
                        /*
                        |--------------------------------------------------------------------------
                        | RETAILER
                        |--------------------------------------------------------------------------
                        */
    
                        $retailer =
                            Company::find($retailerId);
    
                        if (
                            !$retailer ||
                            !$retailer->zoho_id
                        ) {
    
                            Log::warning(
                                'RETAILER ZOHO ID MISSING',
                                [
                                    'retailer_id' =>
                                        $retailerId
                                ]
                            );
    
                            continue;
                        }
    
                        /*
                        |--------------------------------------------------------------------------
                        | BULK LOG
                        |--------------------------------------------------------------------------
                        */
    
                        $bulkLog =
                            WarrantyBulkInvoiceLog::create([
    
                                'company_id' =>
                                    $companyId,
    
                                'retailer_id' =>
                                    $retailerId,
    
                                'status' =>
                                    'started',
    
                                'device_count' =>
                                    $retailerDevices->count(),
    
                                'total_amount' =>
                                    $retailerDevices->sum(
                                        'product_price'
                                    )
                            ]);
    
                        /*
                        |--------------------------------------------------------------------------
                        | BUILD LINE ITEMS
                        |--------------------------------------------------------------------------
                        */
    
                        $lineItems = [];
    
                        try {
    
                            foreach ($retailerDevices as $device) {
    
                                /*
                                |--------------------------------------------------------------------------
                                | PRODUCT ITEM
                                |--------------------------------------------------------------------------
                                */
    
                                $zohoItemId =
                                    $companyProducts[
                                        $device->product_id
                                    ] ?? null;
    
                                if (!$zohoItemId) {
    
                                    throw new \Exception(
    
                                        'Zoho item missing for product ID: '
                                        . $device->product_id
                                    );
                                }
    
                                /*
                                |--------------------------------------------------------------------------
                                | DESCRIPTION
                                |--------------------------------------------------------------------------
                                */
    
                                $customer =
                                    $device->customer;
    
                                $description =
                                    ($device->w_code ?? '-')
                                    . "\n"
                                    . ($customer->name ?? '-')
                                    . "\n"
                                    . ($device->model ?? '-');
    
                                $lineItems[] = [
    
                                    'item_id' =>
                                        $zohoItemId,
    
                                    'name' =>
                                        $device->product_name,
    
                                    'rate' =>
                                        $device->product_price,
    
                                    'quantity' => 1,
    
                                    'description' =>
                                        $description
                                ];
                            }
    
                        } catch (\Throwable $e) {
    
                            $bulkLog->update([
    
                                'status' =>
                                    'failed',
    
                                'error_message' =>
                                    $e->getMessage()
                            ]);
    
                            Log::error(
                                'LINE ITEM BUILD FAILED',
                                [
                                    'retailer_id' =>
                                        $retailerId,
    
                                    'message' =>
                                        $e->getMessage()
                                ]
                            );
    
                            continue;
                        }
    
                        /*
                        |--------------------------------------------------------------------------
                        | ZOHO INVOICE CREATE
                        |--------------------------------------------------------------------------
                        */
    
                        try {
    
                            $payload = [
    
                                'customer_id' =>
                                    $retailer->zoho_id,
    
                                'reference_number' =>
    
                                    'WTY-BLK-'
                                    . now()->format('Ym')
                                    . '-'
                                    . $retailerId
                                    . '-'
                                    . time(),
    
                                'date' =>
                                    now()->toDateString(),
    
                                'line_items' =>
                                    $lineItems,
    
                                'is_inclusive_tax' =>
                                    true,
    
                                'location_id' =>
                                    $company->location_id
                            ];
    
                            $client = new Client();
    
                            /*
                            |--------------------------------------------------------------------------
                            | CREATE INVOICE
                            |--------------------------------------------------------------------------
                            */
    
                            $response = $client->post(
    
                                'https://www.zohoapis.in/books/v3/invoices',
    
                                [
    
                                    'headers' => [
    
                                        'Authorization' =>
    
                                            'Zoho-oauthtoken '
                                            . $company->zoho_access_token
                                    ],
    
                                    'query' => [
    
                                        'organization_id' =>
                                            $company->zoho_org_id
                                    ],
    
                                    'json' => $payload
                                ]
                            );
    
                            $body = json_decode(
                                $response->getBody(),
                                true
                            );
    
                            if (
                                empty($body['invoice'])
                            ) {
    
                                throw new \Exception(
                                    'Zoho invoice create failed'
                                );
                            }
    
                            $invoice =
                                $body['invoice'];
    
                            /*
                            |--------------------------------------------------------------------------
                            | SEND INVOICE
                            |--------------------------------------------------------------------------
                            */
    
                            $client->post(
    
                                "https://www.zohoapis.in/books/v3/invoices/{$invoice['invoice_id']}/status/sent",
    
                                [
    
                                    'headers' => [
    
                                        'Authorization' =>
    
                                            'Zoho-oauthtoken '
                                            . $company->zoho_access_token
                                    ],
    
                                    'query' => [
    
                                        'organization_id' =>
                                            $company->zoho_org_id
                                    ]
                                ]
                            );
    
                            /*
                            |--------------------------------------------------------------------------
                            | FETCH UPDATED INVOICE
                            |--------------------------------------------------------------------------
                            */
    
                            $invoiceResponse =
                                $client->get(
    
                                    "https://www.zohoapis.in/books/v3/invoices/{$invoice['invoice_id']}",
    
                                    [
    
                                        'headers' => [
    
                                            'Authorization' =>
    
                                                'Zoho-oauthtoken '
                                                . $company->zoho_access_token
                                        ],
    
                                        'query' => [
    
                                            'organization_id' =>
                                                $company->zoho_org_id
                                        ]
                                    ]
                                );
    
                            $invoiceBody =
                                json_decode(
                                    $invoiceResponse->getBody(),
                                    true
                                );
    
                            if (
                                empty(
                                    $invoiceBody['invoice']
                                )
                            ) {
    
                                throw new \Exception(
                                    'Unable to fetch updated invoice'
                                );
                            }
    
                            $updatedInvoice =
                                $invoiceBody['invoice'];
    
                        } catch (\Throwable $e) {
    
                            $bulkLog->update([
    
                                'status' =>
                                    'failed',
    
                                'error_message' =>
                                    $e->getMessage()
                            ]);
    
                            Log::error(
                                'ZOHO BULK INVOICE FAILED',
                                [
    
                                    'company_id' =>
                                        $companyId,
    
                                    'retailer_id' =>
                                        $retailerId,
    
                                    'message' =>
                                        $e->getMessage(),
    
                                    'line' =>
                                        $e->getLine()
                                ]
                            );
    
                            continue;
                        }
    
                        /*
                        |--------------------------------------------------------------------------
                        | UPDATE DEVICES
                        |--------------------------------------------------------------------------
                        */
    
                        try {
    
                            DB::transaction(function () use (
    
                                $retailerDevices,
    
                                $updatedInvoice,
    
                                $bulkLog
                            ) {
    
                                WDevice::whereIn(
    
                                    'id',
    
                                    $retailerDevices
                                        ->pluck('id')
                                )
                                ->update([
    
                                    'invoice_id' =>
                                        $updatedInvoice['invoice_id'],
    
                                    'invoice_status' =>
                                        $updatedInvoice['status']
                                        ?? 'sent',
    
                                    'invoice_created_date' =>
                                        $updatedInvoice['date']
                                        ?? now()->toDateString(),
    
                                    'invoice_json' =>
                                        json_encode(
                                            $updatedInvoice
                                        ),
    
                                    'status' => 1,
    
                                    'is_approved' => 0
                                ]);
    
                                $bulkLog->update([
    
                                    'invoice_id' =>
                                        $updatedInvoice['invoice_id'],
    
                                    'status' =>
                                        'success',
    
                                    'response_json' =>
                                        json_encode(
                                            $updatedInvoice
                                        )
                                ]);
    
                            }, 3);
    
                        } catch (\Throwable $e) {
    
                            Log::error(
                                'DEVICE UPDATE FAILED',
                                [
    
                                    'invoice_id' =>
                                        $updatedInvoice['invoice_id']
                                        ?? null,
    
                                    'message' =>
                                        $e->getMessage()
                                ]
                            );
    
                            continue;
                        }
    
                        /*
                        |--------------------------------------------------------------------------
                        | REFRESH DEVICE
                        |--------------------------------------------------------------------------
                        */
    
                        $deviceForWhatsapp =
                            WDevice::where(
                                'invoice_id',
                                $updatedInvoice['invoice_id']
                            )
                            ->whereNotNull('invoice_json')
                            ->first();
    
                        /*
                        |--------------------------------------------------------------------------
                        | MAIL
                        |--------------------------------------------------------------------------
                        */
    
                        try {
    
                            if (
                                !empty(
                                    $retailer->contact_email
                                )
                            ) {
    
                                Mail::to(
                                    $retailer->contact_email
                                )->queue(
    
                                    new InvoiceCreatedMail(
    
                                        $updatedInvoice,
    
                                        $updatedInvoice['invoice_url']
                                        ?? '#'
                                    )
                                );
                            }
    
                        } catch (\Throwable $e) {
    
                            Log::error(
                                'INVOICE MAIL FAILED',
                                [
    
                                    'retailer_id' =>
                                        $retailerId,
    
                                    'message' =>
                                        $e->getMessage()
                                ]
                            );
                        }
    
                        /*
                        |--------------------------------------------------------------------------
                        | WHATSAPP
                        |--------------------------------------------------------------------------
                        */
    
                        try {
    
                            if (
                                $deviceForWhatsapp &&
                                !empty(
                                    $deviceForWhatsapp->invoice_json
                                )
                            ) {
    
                                app(
                                    \App\Services\WhatsappService::class
                                )->sendRetailerInvoiceSuccess(
    
                                    $retailer,
    
                                    $deviceForWhatsapp
                                );
                            }
    
                        } catch (\Throwable $e) {
    
                            Log::error(
                                'RETAILER INVOICE WHATSAPP FAILED',
                                [
    
                                    'retailer_id' =>
                                        $retailerId,
    
                                    'device_id' =>
                                        $deviceForWhatsapp->id ?? null,
    
                                    'message' =>
                                        $e->getMessage()
                                ]
                            );
                        }
    
                        Log::info(
                            'BULK INVOICE SUCCESS',
                            [
    
                                'invoice_id' =>
                                    $updatedInvoice['invoice_id'],
    
                                'retailer_id' =>
                                    $retailerId,
    
                                'devices' =>
                                    $retailerDevices
                                        ->pluck('id')
                                        ->toArray()
                            ]
                        );
                    }
                }
    
            } catch (\Throwable $e) {
    
                Log::error(
                    'COMPANY BULK PROCESS FAILED',
                    [
    
                        'company_id' =>
                            $companyId,
    
                        'message' =>
                            $e->getMessage(),
    
                        'line' =>
                            $e->getLine(),
    
                        'trace' =>
                            substr(
                                $e->getTraceAsString(),
                                0,
                                3000
                            )
                    ]
                );
            }
        }
    
        Log::info('BULK INVOICE JOB COMPLETED');
    }
}