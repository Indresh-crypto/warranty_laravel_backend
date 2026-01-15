<?php

namespace App\Listeners;

use App\Events\PaymentSuccessful;
use GuzzleHttp\Client;
use App\Models\Company;
use Illuminate\Support\Facades\Log;

class SendWhatsappPaymentReceipt
{
    public function handle(PaymentSuccessful $event)
    {
        $payment = $event->payment;

        // Destination company
        $company = Company::find($payment->company_id);

        if (!$company || empty($company->contact_phone)) {
            Log::warning('WhatsApp skipped: No phone number');
            return;
        }

        $destination = '91' . ltrim($company->contact_phone, '0');

        try {
            $client = new Client();

            $client->post(
                'https://api.gupshup.io/wa/api/v1/template/msg',
                [
                    'headers' => [
                        'apikey'        => config('services.gupshup.apikey'),
                        'Content-Type'  => 'application/x-www-form-urlencoded',
                        'Cache-Control' => 'no-cache',
                    ],

                    'form_params' => [

                        // REQUIRED FIELDS
                        'channel'     => 'whatsapp',
                        'source'      => '15557661628',
                        'destination' => $destination,
                        'src.name'    => 'GoelectronixWarranty',

                        // TEMPLATE (EXACT MATCH)
                        'template' => json_encode([
                            'id' => '4969014d-0080-4227-9af9-9121a6a82063',
                            'params' => [
                                $company->business_name . ' (' . $company->company_code . ')',
                                (string) number_format($payment->amount, 2, '.', ''),
                                $payment->invoice_number . ' / ' . $payment->payment_id,
                            ],
                        ]),

                        // DOCUMENT MESSAGE (EXACT MATCH TO CURL)
                        'message' => json_encode([
                            'type' => 'document',
                            'document' => [
                                'link' => 'https://fss.gupshup.io/0/public/0/0/gupshup/15557661628/4c5217e4-c9d6-4953-9090-5df341c189a8/1767360384506_Pending%20Amount%20Report.pdf',
                                'filename' => 'Payment_Receipt_' . $payment->payment_id . '.pdf',
                            ],
                        ]),
                    ],
                ]
            );

            $company->update([
                'wa_response' => 'Payment WhatsApp sent successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Gupshup WhatsApp failed', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}