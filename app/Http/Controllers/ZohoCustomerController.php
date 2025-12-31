<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use App\Models\ZohoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Http\Requests\StoreZohoUserRequest;
use Illuminate\Support\Facades\Hash;
use App\Models\Company;


class ZohoCustomerController extends Controller
{
    public function createContact(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'         => 'required|exists:companies,id',
            'contact_name'    => 'required|string|max:255',
            'company_name'    => 'required|string|max:255',
            'gst_no'          => 'nullable|string|max:15',
            'gst_treatment'   => 'nullable|in:business_gst,consumer,overseas,unregistered_business',
            'contact_type'    => 'required|in:customer,vendor',

            'billing_address.attention' => 'nullable|string|max:255',
            'billing_address.address'   => 'nullable|string|max:255',
            'billing_address.city'      => 'nullable|string|max:255',
            'billing_address.state'     => 'nullable|string|max:255',
            'billing_address.zip'       => 'nullable|numeric',
            'billing_address.phone'     => 'nullable|string|max:20',

            'shipping_address.attention' => 'nullable|string|max:255',
            'shipping_address.address'   => 'nullable|string|max:255',
            'shipping_address.city'      => 'nullable|string|max:255',
            'shipping_address.state'     => 'nullable|string|max:255',
            'shipping_address.zip'       => 'nullable|numeric',

            'contact_persons'              => 'nullable|array',
            'contact_persons.*.first_name' => 'required|string|max:255',
            'contact_persons.*.last_name'  => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Company::find($request->user_id);
        if (!$user || !$user->zoho_access_token || !$user->zoho_org_id) {
            return response()->json([
                'status' => false,
                'error' => 'Zoho credentials not found for this organization.'
            ], 400);
        }

        $payload = [
            "contact_name"     => $request->contact_name,
            "company_name"     => $request->company_name,
            "has_transaction"  => true,
            "contact_type"     => $request->contact_type,
        ];

        if ($request->filled('gst_no')) {
            $payload['gst_no'] = $request->gst_no;
        }

        if ($request->filled('billing_address')) {
            $payload['billing_address'] = $request->billing_address;
        }

        if ($request->filled('shipping_address')) {
            $payload['shipping_address'] = $request->shipping_address;
        }

        if ($request->filled('contact_persons')) {
            $payload['contact_persons'] = $request->contact_persons;
        }

        $client = new Client();

        try {
            $response = $client->post("https://www.zohoapis.in/books/v3/contacts", [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $user->zoho_access_token,
                    'Content-Type'  => 'application/json',
                ],
                'query' => [
                    'organization_id' => $user->zoho_org_id,
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
     
            $contactData = $body['contact'] ?? null;

            if ($contactData) {
                Company::create([
                    'z_json'     => json_encode($contactData),  
                    'contact_id' => $contactData['contact_id'],
                    'org_id'     => $user->zoho_org_id,
                ]);
            }

            return response()->json([
                'status' => true,
                'data' => $body,
                'message' => 'Contact created successfully in Zoho.',
            ], $response->getStatusCode());

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            return response()->json([
                'status' => false,
                'error' => $errorBody['message'] ?? $e->getMessage(),
            ], $e->getResponse()->getStatusCode());
        }
    }

    public function updateZohoAccessToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'sometimes'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }
    
        $isSingleCompanyRequest = !empty($request->company_id);
    
        // Fetch companies
        $companies = Company::query()
            ->when($request->company_id, function ($q) use ($request) {
                $ids = is_array($request->company_id)
                    ? $request->company_id
                    : array_map('trim', explode(',', $request->company_id));
    
                $q->whereIn('id', $ids);
            })
            ->get();
    
        if ($companies->isEmpty()) {
            return response()->json([
                'status'  => false,
                'message' => 'No companies found'
            ], 404);
        }
    
        $updated = [];
        $failed  = [];
    
        /**
         * 🚀 STEP 1: Verify & update Zoho credentials if explicitly sent
         */
        if ($request->filled([
            'company_id',
            'zoho_client_id',
            'zoho_client_secret',
            'zoho_redirect_uri',
            'zoho_refresh_token'
        ])) {
            try {
                $client = new \GuzzleHttp\Client();
                $url = 'https://accounts.zoho.in/oauth/v2/token';
    
                $response = $client->post($url, [
                    'query' => [
                        'refresh_token' => $request->zoho_refresh_token,
                        'client_id'     => $request->zoho_client_id,
                        'client_secret' => $request->zoho_client_secret,
                        'redirect_uri'  => $request->zoho_redirect_uri,
                        'grant_type'    => 'refresh_token'
                    ]
                ]);
    
                $data = json_decode($response->getBody(), true);
    
                if (empty($data['access_token'])) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Zoho credential verification failed',
                        'error'   => $data['error'] ?? 'access_token not returned'
                    ], 400);
                }
    
                // Update Zoho credentials + token
                Company::where('id', $request->company_id)->update([
                    'zoho_client_id'     => $request->zoho_client_id,
                    'zoho_client_secret' => $request->zoho_client_secret,
                    'zoho_redirect_uri'  => $request->zoho_redirect_uri,
                    'zoho_refresh_token' => $request->zoho_refresh_token,
                    'zoho_access_token'  => $data['access_token']
                ]);
    
            } catch (\Throwable $e) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Zoho verification exception',
                    'error'   => $e->getMessage()
                ], 500);
            }
        }
    
        /**
         * 🔄 STEP 2: Refresh access token(s)
         */
        foreach ($companies as $company) {
            try {
                $client = new \GuzzleHttp\Client();
                $url = 'https://accounts.zoho.in/oauth/v2/token';
    
                $response = $client->post($url, [
                    'query' => [
                        'refresh_token' => $company->zoho_refresh_token,
                        'client_id'     => $company->zoho_client_id,
                        'client_secret' => $company->zoho_client_secret,
                        'redirect_uri'  => $company->zoho_redirect_uri,
                        'grant_type'    => 'refresh_token'
                    ]
                ]);
    
                $data = json_decode($response->getBody(), true);
    
                if (empty($data['access_token'])) {
                    $errorMessage = $data['error'] ?? 'access_token not returned';
    
                    if ($isSingleCompanyRequest) {
                        return response()->json([
                            'status'  => false,
                            'message' => 'Zoho token update failed',
                            'error'   => $errorMessage,
                            'company' => [
                                'id'   => $company->id,
                                'name' => $company->business_name
                            ]
                        ], 400);
                    }
    
                    $failed[] = [
                        'id'    => $company->id,
                        'error' => $errorMessage
                    ];
                    continue;
                }
    
                $company->update([
                    'zoho_access_token' => $data['access_token']
                ]);
    
                $updated[] = [
                    'id'   => $company->id,
                    'name' => $company->business_name
                ];
    
            } catch (\Throwable $e) {
    
                \Log::error("Zoho Update Error (CompanyID {$company->id}): " . $e->getMessage());
    
                if ($isSingleCompanyRequest) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Zoho token update failed',
                        'error'   => $e->getMessage(),
                        'company' => [
                            'id'   => $company->id,
                            'name' => $company->business_name
                        ]
                    ], 500);
                }
    
                $failed[] = [
                    'id'    => $company->id,
                    'error' => $e->getMessage()
                ];
            }
        }
    
        return response()->json([
            'status'  => true,
            'message' => 'Zoho access token update completed',
            'updated' => $updated,
            'failed'  => $failed
        ]);
    }
    public function getZohoContacts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'org_id' => 'nullable|exists:zoho_users,id',
            'contact_id' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $query = Company::query();

        if ($request->filled('org_id')) {
            $query->where('org_id', $request->org_id);
        }

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->contact_id);
        }

        $perPage = $request->input('per_page', 10);

        $contacts = $query->orderByDesc('id')->paginate($perPage);

        // Decode z_json for each item
        $contacts->getCollection()->transform(function ($item) {
            $item->z_json = json_decode($item->z_json, true);
            return $item;
        });

        return response()->json([
            'status' => true,
            'data' => $contacts
        ]);
    }

    public function updateContact(Request $request, $contactId)
    {
    $validator = Validator::make($request->all(), [
        'user_id'         => 'required|exists:zoho_users,id',
        'contact_name'    => 'sometimes|required|string|max:255',
        'company_name'    => 'sometimes|required|string|max:255',
        'gst_no'          => 'nullable|string|max:15',
        'gst_treatment'   => 'nullable|in:business_gst,consumer,overseas,unregistered_business',
        'contact_type'    => 'nullable|in:customer,vendor',

        'billing_address.attention' => 'nullable|string|max:255',
        'billing_address.address'   => 'nullable|string|max:255',
        'billing_address.city'      => 'nullable|string|max:255',
        'billing_address.state'     => 'nullable|string|max:255',
        'billing_address.zip'       => 'nullable|numeric',
        'billing_address.phone'     => 'nullable|string|max:20',

        'shipping_address.attention' => 'nullable|string|max:255',
        'shipping_address.address'   => 'nullable|string|max:255',
        'shipping_address.city'      => 'nullable|string|max:255',
        'shipping_address.state'     => 'nullable|string|max:255',
        'shipping_address.zip'       => 'nullable|numeric',

        'contact_persons'              => 'nullable|array',
        'contact_persons.*.first_name' => 'required_with:contact_persons|string|max:255',
        'contact_persons.*.last_name'  => 'required_with:contact_persons|string|max:255',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $user =Company::find($request->id);
    if (!$user || !$user->zoho_access_token || !$user->zoho_org_id) {
        return response()->json([
            'status' => false,
            'error' => 'Zoho credentials not found for this organization.'
        ], 400);
    }

    $payload = $request->except(['user_id']);
    $client = new Client();

    try {
        $response = $client->put("https://www.zohoapis.in/books/v3/contacts/{$contactId}", [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $user->zoho_access_token,
                'Content-Type'  => 'application/json',
            ],
            'query' => [
                'organization_id' => $user->zoho_org_id,
            ],
            'json' => $payload,
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $contactData = $body['contact'] ?? null;

       

        return response()->json([
            'status' => true,
            'data' => $body,
            'message' => 'Contact updated successfully in Zoho.',
        ], $response->getStatusCode());

    } catch (\GuzzleHttp\Exception\ClientException $e) {
        $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
        return response()->json([
            'status' => false,
            'error' => $errorBody['message'] ?? $e->getMessage(),
        ], $e->getResponse()->getStatusCode());
    }
}

    public function signupUser(StoreZohoUserRequest $request)
    {
        $data = $request->validated();

        // Handle logo upload if sent
        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        }

        // Hash password before storing
        $data['password'] = Hash::make($data['password']);

        // Create user
        $user = Company::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Zoho User created successfully',
            'data'    => $user,
        ], 201);
    }
    public function createContactFromCompany(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'    => 'required|exists:companies,id',
            'company_id' => 'required|exists:companies,id',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        /** 
         * Company that has Zoho credentials
         */
        $zohoCompany = Company::find($request->company_id);
    
        if (
            !$zohoCompany ||
            !$zohoCompany->zoho_access_token ||
            !$zohoCompany->zoho_org_id
        ) {
            return response()->json([
                'status' => false,
                'error'  => 'Zoho credentials not found.'
            ], 400);
        }
    
        /**
         * Company whose data will be pushed to Zoho
         */
        $company = Company::find($request->user_id);
    
        if (!$company) {
            return response()->json([
                'status' => false,
                'error'  => 'Company data not found.'
            ], 404);
        }
    
        /**
         * Build Zoho Contact Payload from Company Model
         */
        $payload = [
            "contact_name"    => $company->business_name,
            "company_name"    => $company->trade_name ?? $company->business_name,
            "has_transaction" => true,
            "contact_type"    => "customer",
    
            "billing_address" => [
                "attention" => $company->contact_person,
                "address"   => $company->address_line1,
                "street2"   => $company->address_line2,
                "city"      => $company->city,
                "state"     => $company->state,
                "zip"       => $company->pincode,
                "country"   => "India",
                "phone"     => $company->contact_phone,
            ],
    
            "contact_persons" => [
                [
                    "first_name" => $company->contact_person,
                    "email"      => $company->contact_email,
                    "phone"      => $company->contact_phone,
                ]
            ],
        ];
    
        if (!empty($company->gst)) {
            $payload['gst_no'] = $company->gst;
            $payload['gst_treatment'] = 'business_gst';
        }
    
        $client = new \GuzzleHttp\Client();
    
        try {
            $response = $client->post(
                "https://www.zohoapis.in/books/v3/contacts",
                [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $zohoCompany->zoho_access_token,
                        'Content-Type'  => 'application/json',
                    ],
                    'query' => [
                        'organization_id' => $zohoCompany->zoho_org_id,
                    ],
                    'json' => $payload,
                ]
            );
        
            $body = json_decode($response->getBody(), true);
        
            if (!empty($body['contact']['contact_id'])) {
                $company->update([
                    'zoho_id' => $body['contact']['contact_id'],
                    'z_json'  => json_encode($body['contact']),
                ]);
            }
        
            return response()->json([
                'status'  => true,
                'message' => 'Contact created in Zoho successfully',
                'zoho_id' => $body['contact']['contact_id'],
                'data'    => $body,
            ], 200);
        
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = json_decode(
                $e->getResponse()->getBody()->getContents(),
                true
            );
        
            return response()->json([
                'status' => false,
                'error'  => $errorBody['message'] ?? $e->getMessage(),
            ], $e->getResponse()->getStatusCode());
        }
    }
}
