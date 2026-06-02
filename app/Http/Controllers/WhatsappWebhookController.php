<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use App\Models\WhatsappMessage;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;


class WhatsappWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {

        $apiKey = env('GUPSHUP_API_KEY');
        $appName = "WarrantyMitra"; 
        $source = env('GUPSHUP_WHATSAPP_NUMBER'); 

        try {
            $data = $request->all();

            if (!isset($data['payload'])) {
                Log::warning('Invalid payload received', ['data' => $data]);
                return response()->json(['error' => 'Invalid payload'], 200); 
            }

            $message = new WhatsappMessage();
            $message->app = $data['app'] ?? null;
            $message->timestamp = $data['timestamp'] ?? null;
            $message->version = $data['version'] ?? null;
            $message->type = $data['type'] ?? null;
            $message->message_id = $data['payload']['id'] ?? null;
            $message->source = $data['payload']['source'] ?? null;
            $message->message_type = $data['payload']['type'] ?? null;
            $message->payload_text = $data['payload']['payload']['text'] ?? null;
            $message->url = $data['payload']['payload']['url'] ?? null;
            $message->sender_phone = $data['payload']['sender']['phone'] ?? null;
            $message->sender_name = $data['payload']['sender']['name'] ?? null;
            $message->sender_country_code = $data['payload']['sender']['country_code'] ?? null;
            $message->sender_dial_code = $data['payload']['sender']['dial_code'] ?? null;
            $message->context_id = $data['payload']['context']['id'] ?? null;
            $message->context_gsId = $data['payload']['context']['gsId'] ?? null;

            $message->save();

            $bearerToken = "0";
                $data = [
                    "flag" => "",
                    "asmid" => 0     
                ];
            
                $employee = NewData::where('phone', $message->sender_dial_code)->first();

                if ($employee) {
                    $emp_phone = $employee->emp_mobile;
                    $empname = $employee->name;
                    $shopname = $employee->business_name;
                    $state = $employee->state;
                    $district = $employee->district;
                    $contactno = $employee->phone;
                    $altcontact = $employee->alt_phone;
                
                    $this->optInAndSendMessage($emp_phone, $empname, $shopname, $state, $district, $contactno, $altcontact);
                } else {
                    Log::error("Employee phone number not found for dial code: " . $message->sender_dial_code);
                }
             
         

            return response()->json(['success' => true], 200);
            } catch (\Exception $e) {
                Log::error('Webhook error: ' . $e->getMessage(), ['exception' => $e]);

                return response()->json(['success' => false, 'error' => 'Internal processing error'], 200);
            }
    }
    
 
public function optInAndSendMessage($phone,  $empname, $shopname, $state, $district, $contactno, $altcontact)
{
    $apiKey = env('GUPSHUP_API_KEY');
    $appName = "WarrantyMitra"; 
    $source = env('GUPSHUP_WHATSAPP_NUMBER'); 

    $phone = $request->input('phone');
    
    $optinResponse = $this->optInUser($apiKey, $appName, $phone);

    if (!$optinResponse) {
        return response()->json(['error' => 'Failed to opt-in user'], 400);
    }

    return $this->sendMessage($apiKey, $source, $phone,  $empname, $shopname, $state, $district, $contactno, $altcontact);
}


private function optInUser($apiKey, $appName, $phone)
{
    $response = Http::withHeaders([
        'apikey' => $apiKey,
        'Content-Type' => 'application/x-www-form-urlencoded',
    ])->post("https://api.gupshup.io/sm/api/v1/app/opt/in/{$appName}", [
        'user' => $phone,
    ]);

    if ($response->successful()) {
        sleep(3); 
        return true;
    }
    return false;
}


    private function sendMessage($apiKey, $source, $phone, $empname, $shopname, $state, $district, $contactno, $altcontact)
    {
        // $response = Http::asForm()->withHeaders([
        //     'apikey' => $apiKey
        // ])->post('https://api.gupshup.io/wa/api/v1/template/msg', [
        //     'channel' => 'whatsapp',
        //     'source' => $source,
        //     'destination' => $phone,
        //     'message' => 'Hello',
        //     'src.name' => 'Goexrt',
        //     'disablePreview' => 'false'
        // ]);

        $apiKey = 'xmzzeoeowfppicbquvp3zupvntzeqh2j';
        $source = '918369719004';
        $phone = '919039128100';
        
            
            $templateData = [
                "id" => "efd7f459-3533-4c61-b447-38c61a57735e",
                "params" => [
                    $empname,
                    $shopname,
                    $state,
                    $district,
                    $contactno,
                    $altcontact,
                    "Interested",
                    Carbon::now('Asia/Kolkata')->format('d-m-Y h:i A')
                ]
            ];
        
        $response = Http::asForm()->withHeaders([
            'apikey' => $apiKey
        ])->post('https://api.gupshup.io/wa/api/v1/template/msg', [
            'channel' => 'whatsapp',
            'source' => $source,
            'destination' => $phone,
            'src.name' => 'Goexrt',
            'template' => json_encode($templateData) 
        ]);
        
        return response()->json([
            'ApiResponse' => $response->json()
        ]);

        return $response->json();
    }
    
public function getMessages(Request $request)
{
    $query = WhatsappMessage::query();

    $filterable = [
        'app',
        'timestamp',
        'version',
        'type',
        'message_id',
        'source',
        'message_type',
        'sender_phone',
        'sender_name',
        'sender_country_code',
        'sender_dial_code',
        'context_id',
        'context_gsId',
        'payload_text',
        'url',
    ];

    // Apply dynamic filters
    foreach ($filterable as $column) {
        if ($request->filled($column)) {
            $value = $request->input($column);

            // Exact match for specific columns
            if (in_array($column, ['type', 'message_type', 'app', 'source'])) {
                $query->where($column, $value);
            } else {
                // Partial match for text-based columns
                $query->where($column, 'LIKE', '%' . $value . '%');
            }
        }
    }

    // Optional date range filter
    try {
        if ($request->filled('from_date')) {
            $dateFrom = \Carbon\Carbon::createFromFormat('d-m-Y', $request->input('from_date'))->startOfDay();
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($request->filled('to_date')) {
            $dateTo = \Carbon\Carbon::createFromFormat('d-m-Y', $request->input('to_date'))->endOfDay();
            $query->where('created_at', '<=', $dateTo);
        }
    } catch (\Exception $e) {
        // Ignore invalid dates or log them
    }

    // Order by latest first
    $query->orderByDesc('timestamp');

    // Pagination
    $perPage = $request->input('per_page', 15);
    $messages = $query->paginate($perPage);

    return response()->json([
        'data' => $messages
    ]);
}
}
