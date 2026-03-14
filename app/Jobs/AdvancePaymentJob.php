<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\AdvancePayment;
use App\Models\WarrantyFlowLog;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

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

    public function handle()
    {

        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        $required = ['company_id','retailer_id','amount'];

        foreach ($required as $field) {
            if (empty($this->payload[$field])) {
                throw new \Exception($field . ' missing in payload');
            }
        }

        DB::beginTransaction();

        try {

            $paymentId = $this->payload['payment_id'] ?? 'ADV-' . time();
            $amount    = (float) $this->payload['amount'];

            Log::info('ADVANCE PAYMENT JOB STARTED', [
                'payment_id' => $paymentId,
                'amount' => $amount
            ]);

            /*
            |--------------------------------------------------------------------------
            | LOAD COMPANY
            |--------------------------------------------------------------------------
            */

            $company = Company::find($this->payload['company_id']);

            if (!$company || !$company->zoho_access_token || !$company->zoho_org_id) {
                throw new \Exception('Company Zoho credentials missing');
            }

            /*
            |--------------------------------------------------------------------------
            | LOAD RETAILER
            |--------------------------------------------------------------------------
            */

            $retailer = Company::find($this->payload['retailer_id']);

            if (!$retailer || !$retailer->zoho_id) {
                throw new \Exception('Retailer Zoho contact missing');
            }

            /*
            |--------------------------------------------------------------------------
            | CREATE ZOHO CUSTOMER PAYMENT (ADVANCE)
            |--------------------------------------------------------------------------
            */

            $paymentData = [

                "customer_id" => $retailer->zoho_id,

                "amount" => $amount,

                "reference_number" => $paymentId,

                "payment_mode" => "cash",

                "description" => "Advance wallet recharge",

                "invoices" => [] // Empty means advance payment
            ];

            $client = new Client();

            $response = $client->post(
                "https://www.zohoapis.in/books/v3/customerpayments",
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $company->zoho_access_token
                    ],
                    'query' => [
                        'organization_id' => $company->zoho_org_id
                    ],
                    'json' => $paymentData
                ]
            );

            $body = json_decode($response->getBody(), true);

            if (empty($body['payment'])) {
                throw new \Exception('Zoho payment creation failed');
            }

            $zohoPayment = $body['payment'];

            /*
            |--------------------------------------------------------------------------
            | SAVE ADVANCE PAYMENT IN DB
            |--------------------------------------------------------------------------
            */

            AdvancePayment::create([
                'retailer_id'  => $retailer->id,
                'payment_id'   => $zohoPayment['payment_id'],
                'payment_json' => json_encode($zohoPayment),
                'amount'       => $amount
            ]);

            /*
            |--------------------------------------------------------------------------
            | LOG SUCCESS
            |--------------------------------------------------------------------------
            */

            WarrantyFlowLog::create([
                'payment_id' => $paymentId,
                'step'       => 'ADVANCE_PAYMENT_CREATED',
                'status'     => 1,
                'response_data' => json_encode($zohoPayment)
            ]);

            DB::commit();

            Log::info('ADVANCE PAYMENT SUCCESS', [
                'zoho_payment_id' => $zohoPayment['payment_id'],
                'amount' => $amount
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('ADVANCE PAYMENT FAILED', [
                'error' => $e->getMessage(),
                'payload' => $this->payload
            ]);

            WarrantyFlowLog::create([
                'payment_id'    => $this->payload['payment_id'] ?? null,
                'step'          => 'ADVANCE_PAYMENT_FAILED',
                'status'        => 0,
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}