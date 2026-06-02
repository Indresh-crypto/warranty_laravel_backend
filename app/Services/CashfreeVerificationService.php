<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class CashfreeVerificationService
{
    private Client $client;
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.cashfree.base_url'), '/');

        $this->client = new Client([
            'timeout'         => 15,
            'connect_timeout' => 10,
            'http_errors'     => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PAN Verification
    |--------------------------------------------------------------------------
    */
    public function verifyPAN(array $payload): array
    {
        try {

            $response = $this->post('/verification/pan/advance', [
                'pan'             => $payload['pan'] ?? null,
                'verification_id' => $payload['verification_id'] ?? null,
                'name'            => $payload['name'] ?? null,
            ]);

            Log::info('PAN VERIFY RESPONSE', [
                'payload'  => $payload,
                'response' => $response,
            ]);

            return $response;

        } catch (\Throwable $e) {

            Log::error('PAN VERIFY ERROR', [
                'payload' => $payload,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            throw new Exception($e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GST Verification
    |--------------------------------------------------------------------------
    */
    public function verifyGST(array $payload): array
    {
        try {

            $response = $this->post('/verification/gstin', [
                'GSTIN'         => $payload['gst'] ?? null,
                'business_name' => $payload['business_name'] ?? null,
            ]);

            Log::info('GST VERIFY RESPONSE', [
                'payload'  => $payload,
                'response' => $response,
            ]);

            return $response;

        } catch (\Throwable $e) {

            Log::error('GST VERIFY ERROR', [
                'payload' => $payload,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            throw new Exception($e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Bank Verification
    |--------------------------------------------------------------------------
    */
    public function verifyBank(array $payload): array
    {
        try {

            $response = $this->post('/verification/bank-account/sync', [
                'bank_account' => $payload['account_number'] ?? null,
                'ifsc'         => $payload['ifsc'] ?? null,
              //  'name'         => $payload['name'] ?? null,
                'phone'        => $payload['phone'] ?? null,
            ]);

            Log::info('BANK VERIFY RESPONSE', [
                'payload'  => $payload,
                'response' => $response,
            ]);

            return $response;

        } catch (\Throwable $e) {

            Log::error('BANK VERIFY ERROR', [
                'payload' => $payload,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            throw new Exception($e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | COMMON POST REQUEST
    |--------------------------------------------------------------------------
    */
    private function post(string $endpoint, array $payload): array
    {
        try {

            $jsonPayload = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
            );

            if ($jsonPayload === false) {
                throw new Exception('JSON encoding failed');
            }

            $signature = $this->generateSignature();

            $response = $this->client->post(
                $this->baseUrl . $endpoint,
                [
                    'headers' => [

                        'x-client-id' =>
                            config('services.cashfree.client_id'),

                        'x-client-secret' =>
                            config('services.cashfree.client_secret'),

                        'x-cf-signature' =>
                            $signature,

                        'Content-Type' =>
                            'application/json',

                        'Accept' =>
                            'application/json',
                    ],

                    'body' => $jsonPayload,
                ]
            );

            $responseBody = (string) $response->getBody();

            Log::info('CASHFREE API RESPONSE', [
                'endpoint' => $endpoint,
                'response' => $responseBody,
            ]);

            return json_decode($responseBody, true) ?? [];

        } catch (ClientException $e) {

            $responseBody = $e->hasResponse()
                ? (string) $e->getResponse()->getBody()
                : null;

            Log::error('CASHFREE CLIENT ERROR', [
                'endpoint' => $endpoint,
                'payload'  => $payload,
                'status'   => $e->getResponse()?->getStatusCode(),
                'response' => $responseBody,
                'message'  => $e->getMessage(),
            ]);

            throw new Exception(
                $responseBody ?: $e->getMessage()
            );

        } catch (RequestException $e) {

            $responseBody = $e->hasResponse()
                ? (string) $e->getResponse()->getBody()
                : null;

            Log::error('CASHFREE REQUEST ERROR', [
                'endpoint' => $endpoint,
                'payload'  => $payload,
                'response' => $responseBody,
                'message'  => $e->getMessage(),
            ]);

            throw new Exception(
                $responseBody ?: $e->getMessage()
            );

        } catch (\Throwable $e) {

            Log::error('CASHFREE GENERAL ERROR', [
                'endpoint' => $endpoint,
                'payload'  => $payload,
                'message'  => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);

            throw new Exception($e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE SIGNATURE
    |--------------------------------------------------------------------------
    */
    private function generateSignature(): string
    {
        $clientId = config('services.cashfree.client_id');

        if (!$clientId) {
            throw new Exception('Cashfree client id missing');
        }

        $timestamp = time();

        $data = $clientId . '.' . $timestamp;

        $publicKeyPath = storage_path(
            'app/accountId_57963_public_key.pem'
        );

        if (!file_exists($publicKeyPath)) {
            throw new Exception('Public key file not found');
        }

        $publicKeyContent = file_get_contents($publicKeyPath);

        if (!$publicKeyContent) {
            throw new Exception('Unable to read public key');
        }

        $publicKey = openssl_pkey_get_public(
            $publicKeyContent
        );

        if (!$publicKey) {
            throw new Exception('Invalid public key');
        }

        $encrypted = null;

        $isEncrypted = openssl_public_encrypt(
            $data,
            $encrypted,
            $publicKey,
            OPENSSL_PKCS1_OAEP_PADDING
        );

        if (!$isEncrypted) {
            throw new Exception('Signature encryption failed');
        }

        return base64_encode($encrypted);
    }
}