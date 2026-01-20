<?php

class ZohoPaymentService
{
    public function createPayment($invoiceId, $payload)
    {
        $orgUser = Company::find($payload['company_id']);

        $data = [
            "customer_id" => $payload['customer_id'],
            "amount" => $payload['amount'],
            "reference_number" => $payload['payment_id'],
            "description" => "Warranty Payment",
            "invoices" => [
                [
                    "invoice_id" => $invoiceId,
                    "amount_applied" => $payload['amount']
                ]
            ]
        ];

        $client = new \GuzzleHttp\Client();

        $response = $client->post(
            "https://www.zohoapis.in/books/v3/customerpayments",
            [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
                ],
                'query' => ['organization_id' => $orgUser->zoho_org_id],
                'json' => $data
            ]
        );

        return json_decode($response->getBody(), true);
    }
}