<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use App\Models\ZohoContactData;
use App\Models\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ZohoContactController extends Controller
{
 
 
 public function fetchContacts(Request $request)
{
    $user = Company::find($request->user_id);

    if (!$user || !$user->zoho_org_id || !$user->zoho_access_token) {
        return response()->json([
            'status' => false,
            'error' => 'Zoho credentials not found for this user.'
        ], 400);
    }

    $client = new \GuzzleHttp\Client();
    $allContacts = [];
    $page = 1;

    try {
        do {
            $response = $client->get("https://www.zohoapis.in/books/v3/contacts", [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $user->zoho_access_token,
                    'Content-Type'  => 'application/json',
                ],
                'query' => [
                    'organization_id' => $user->zoho_org_id,
                    'per_page' => 200,
                    'page' => $page,
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            if (!isset($body['contacts'])) {
                break;
            }

            foreach ($body['contacts'] as $contact) {
                ZohoContactData::updateOrCreate(
                    [
                        'contact_id' => $contact['contact_id'] // UNIQUE KEY check
                    ],
                    [
                        'zoho_org_id' =>  $user->zoho_org_id ?? null,
                        'user_id' =>      $user->id ?? null,
                        'contact_name' => $contact['contact_name'] ?? null,
                        'company_name' => $contact['company_name'] ?? null,
                        'contact_type' => $contact['contact_type'] ?? null,
                        'status' => $contact['status'] ?? null,
                        'payment_terms' => $contact['payment_terms'] ?? null,
                        'payment_terms_label' => $contact['payment_terms_label'] ?? null,
                        'currency_id' => $contact['currency_id'] ?? null,
                        'currency_code' => $contact['currency_code'] ?? null,
                        'outstanding_receivable_amount' => $contact['outstanding_receivable_amount'] ?? 0,
                        'unused_credits_receivable_amount' => $contact['unused_credits_receivable_amount'] ?? 0,
                        'first_name' => $contact['first_name'] ?? null,
                        'last_name' => $contact['last_name'] ?? null,
                        'email' => $contact['email'] ?? null,
                        'phone' => $contact['phone'] ?? null,
                        'mobile' => $contact['mobile'] ?? null,
                        'created_time' =>
                            isset($contact['created_time'])
                                ? \Carbon\Carbon::parse($contact['created_time'])->toDateTimeString()
                                : null,
                        'last_modified_time' =>
                            isset($contact['last_modified_time'])
                                ? \Carbon\Carbon::parse($contact['last_modified_time'])->toDateTimeString()
                                : null,
                        'z_json' => json_encode($contact),
                    ]
                );
            }

            $allContacts = array_merge($allContacts, $body['contacts']);
            $hasMore = $body['page_context']['has_more_page'] ?? false;
            $page++;

        } while ($hasMore);

        return response()->json([
            'status' => true,
            'message' => 'Contacts fetched and stored successfully.',
            'total_fetched' => count($allContacts),
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}

//map contact from zoho_contact
public function syncZohoContactsByEmail(Request $request)
{
    // Step 1: Get Zoho User (credentials)
    $zohoUser = ZohoUser::where('company_id', $request->company_id)
        ->whereNotNull('zoho_access_token')
        ->first();

    if (!$zohoUser || !$zohoUser->zoho_access_token || !$zohoUser->zoho_org_id) {
        return response()->json([
            'status' => false,
            'error'  => 'Zoho credentials not found for this organization.',
        ], 400);
    }

    try {
        $client = new Client();

        // Step 2: Get all org users without contact_id
        $users = ZohoUser::where('company_id', $request->company_id)
            ->whereNull('zoho_contact_id')
            ->whereNotNull('email')
            ->get();

        $updated = [];
        $skipped = [];

        foreach ($users as $user) {
          
          $url = "https://www.zohoapis.in/books/v3/contacts";
        $response = $client->get($url, [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $zohoUser->zoho_access_token,
                'Content-Type'  => 'application/json',
            ],
            'query' => [
                'organization_id' => $zohoUser->zoho_org_id,
                'email'           => strtolower(trim($user->email)), // normalize
            ],
        ]);

    $body = json_decode((string) $response->getBody(), true);
    
    // if empty contacts, try full list search
    if (empty($body['contacts'])) {
        $listResponse = $client->get($url, [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $zohoUser->zoho_access_token,
                'Content-Type'  => 'application/json',
            ],
            'query' => [
                'organization_id' => $zohoUser->zoho_org_id,
                'per_page'        => 200, // adjust if needed
            ],
        ]);

    $listBody = json_decode((string) $listResponse->getBody(), true);

            foreach ($listBody['contacts'] ?? [] as $contact) {
                foreach ($contact['contact_persons'] ?? [] as $person) {
                    if (strtolower($person['email'] ?? '') === strtolower(trim($user->email))) {
                        $user->update(['contact_id' => $contact['contact_id']]);
                        break 2; // stop both loops
                    }
                }
            }
        }
                    if (isset($body['code']) && $body['code'] == 0 && !empty($body['contacts'])) {
                        $contact = $body['contacts'][0]; // take first match
                        $contactId = $contact['contact_id'];
        
                        // Step 4: Update contact_id in DB
                        $user->update(['zoho_contact_id' => $contactId]);
        
                        $updated[] = [
                            'user_id'    => $user->id,
                            'email'      => $user->email,
                            'contact_id' => $contactId,
                        ];
                    } else {
                        $skipped[] = [
                            'user_id' => $user->id,
                            'email'   => $user->email,
                            'reason'  => $body['message'] ?? 'Not found in Zoho',
                        ];
                    }
                }
        
                
                return response()->json([
                    'status'  => true,
                    'message' => 'Zoho contacts sync completed.',
                    'updated' => $updated,
                    'skipped' => $skipped,
                ]);
        
            } catch (\Exception $e) {
                return response()->json([
                    'status' => false,
                    'error'  => $e->getMessage(),
                ], 500);
            }
        }
}