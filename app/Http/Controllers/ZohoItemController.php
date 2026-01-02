<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use App\Models\ZohoItem;
use App\Models\ZohoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ZohoItemController extends Controller
{
    
      public function getZohoItems(Request $request)
    {
        $query = ZohoItem::get();
    
      
        return response()->json([
            'status' => true,
            'message' => 'Zoho items fetched successfully',
            'data' => $query
        ], 200);
    }
    
    
public function createZohoItem(Request $request)
{
    // ✅ Validate request data
    $validator = Validator::make($request->all(), [
        'company_id'   => 'required|integer',
        'name'         => 'required|string|max:255',
        'rate'         => 'required|numeric',
        'description'  => 'nullable|string',
        'product_type' => 'nullable|in:goods,service',
        'is_taxable'   => 'nullable|boolean',
    ]);

    if ($validator->fails()) {
        return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
    }

    // ✅ Retrieve org user with Zoho credentials
    $orgUser = ZohoUser::where('company_id', $request->company_id)
                      ->whereNotNull('zoho_access_token')
                      ->whereNotNull('zoho_org_id')
                      ->first();

    if (!$orgUser) {
        return response()->json(['status' => false, 'error' => 'Organization user not found or Zoho credentials missing.'], 404);
    }

    // ✅ Prepare payload for Zoho
    $itemData = [
        "name"         => $request->name,
        "rate"         => $request->rate,
        "description"  => $request->description ?? 'Default Item Description',
        "product_type" => $request->product_type ?? 'goods',
        "is_taxable"   => $request->is_taxable ?? true,
    ];

    $client = new Client();

    try {
        // ✅ Send request to Zoho API
        $response = $client->post('https://www.zohoapis.in/books/v3/items', [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
                'Content-Type'  => 'application/json',
            ],
            'query' => [
                'organization_id' => $orgUser->zoho_org_id,
            ],
            'json' => $itemData,
        ]);

        $body = json_decode($response->getBody(), true);
        $zohoItem = $body['item'] ?? null;

        if ($zohoItem) {
            // ✅ Store in your DB
            ZohoItem::create([
                'name'         => $zohoItem['name'],
                'zoho_item_id' => $zohoItem['item_id'],
                'description'  => $zohoItem['description'],
                'rate'         => $zohoItem['rate'],
                'product_type' => $zohoItem['product_type'],
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Item created successfully in Zoho and stored locally.',
            'data' => $zohoItem
        ], 201);

    } catch (\GuzzleHttp\Exception\ClientException $e) {
        $errorBody = json_decode($e->getResponse()->getBody(), true);
        return response()->json([
            'status' => false,
            'error' => $errorBody['message'] ?? $e->getMessage()
        ], $e->getResponse()->getStatusCode());
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'error' => $e->getMessage()
        ], 500);
    }
}

public function updateOrCreateLocalItem(Request $request)
{
    $validator = Validator::make($request->all(), [
        'zoho_item_id' => 'nullable|string|max:50',
        'name'         => 'required|string|max:255',
        'rate'         => 'required|numeric',
        'description'  => 'nullable|string',
        'product_type' => 'required|in:goods,service',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors(),
        ], 422);
    }

    $data = $validator->validated();

    // Try to find by zoho_item_id
    $item = null;
    if (!empty($data['zoho_item_id'])) {
        $item = ZohoItem::where('zoho_item_id', $data['zoho_item_id'])->first();
    }

    if ($item) {
        $item->update($data);
        $message = 'Local item updated successfully.';
    } else {
        $item = ZohoItem::create($data);
        $message = 'Local item created successfully.';
    }

    return response()->json([
        'status' => true,
        'message' => $message,
        'data' => $item,
    ]);
}



public function syncZohoItems(Request $request)
{
    $orgUser = ZohoUser::where('company_id', $request->company_id)->first();

    if (!$orgUser || !$orgUser->zoho_access_token || !$orgUser->zoho_org_id) {
        return response()->json([
            'status' => false,
            'message' => 'Zoho credentials not found.',
        ], 400);
    }

    try {
        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
        ])->get('https://www.zohoapis.in/books/v3/items', [
            'organization_id' => $orgUser->zoho_org_id,
        ]);

        if (!$response->ok()) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch items from Zoho.',
                'zoho_response' => $response->json(),
            ], $response->status());
        }

        $zohoItems = $response->json()['items'] ?? [];
        $updated = 0;
        $notFound = [];

        foreach ($zohoItems as $item) {
            $localItem = ZohoItem::where('name', $item['name'])->first();

            if ($localItem) {
                $localItem->update([
                    'zoho_item_id' => $item['item_id'],
                ]);
                $updated++;
            } else {
                $notFound[] = $item['name'];
            }
        }

        return response()->json([
            'status' => true,
            'message' => "Zoho items synced with local database.",
            'updated_count' => $updated,
            'not_matched' => $notFound,
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Exception occurred: ' . $e->getMessage(),
        ], 500);
    }
}

}
