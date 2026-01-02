<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ZohoUser;
use App\Models\Retailers;
use App\Models\ZohoInvoice;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;
use DB;
use App\Models\ZohoInvoiceWaLog;
use Carbon\Carbon;

class ZohoInvoiceController extends Controller
{
   
    public function createZohoInvoice(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:org_users,id',
            'company_id' => 'required|integer|exists:org_users,id',
            'customer_id' => 'required|string',
            'date' => 'required|date',
            'due_date' => 'required|date',
            'payment_terms' => 'nullable|integer',
            'payment_terms_label' => 'nullable|string',
            'line_items' => 'required|array|min:1',
            'line_items.*.item_id' => 'required|string',
            'line_items.*.name' => 'required|string',
            'line_items.*.rate' => 'required|numeric',
            'line_items.*.quantity' => 'required|numeric',
            'role' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $orgUser = $this->getOrgUser($request->company_id);
        if (!$orgUser) {
            return response()->json(['error' => 'Organization user not found'], 404);
        }

        try {
            $invoiceData = $request->except(['user_id', 'company_id']);
            $createResponse = $this->zohoPostRequest(
                'https://www.zohoapis.in/books/v3/invoices',
                $orgUser,
                $invoiceData
            );

            if (!isset($createResponse['invoice'])) {
                return response()->json(['error' => 'Invoice creation failed'], 500);
            }
            $invoice = $createResponse['invoice'];
            $this->approveZohoInvoiceInternal($orgUser, $invoice['invoice_id']);
            $retainedOrgUser = ZohoUser::find($companyId);

           $emailData = [
                "send_from_org_email_id" => false,
                "to_mail_ids" => [
                   $retainedOrgUser->email
                ],
                "cc_mail_ids" => [
                   $orgUser->email
                ],
                "subject" => "Invoice from {$orgUser->business_name}, {$invoice['invoice_id']}",
                "body" => "Dear Customer,<br><br>
                        Thanks for your business.<br><br>
                        The invoice {$invoice['invoice_number']} is attached with this email. 
                        You can choose the easy way out and
                        <a href='https://invoice.zoho.in/SecurePayment?CInvoiceID={$invoice['invoice_id']}'>
                        pay online for this invoice</a>.<br><br>
                        Here's an overview of the invoice for your reference.<br><br>
                        <b>Invoice:</b> {$invoice['invoice_number']}<br>
                        <b>Date:</b> {$invoice['date']}<br>
                        <b>Amount:</b> {$invoice['amount']}<br><br>
                        It was great working with you. Looking forward to working with you again.<br><br>
                        Regards,<br>{$orgUser->business_name}<br>",
            ];
            $this->emailInvoiceFromZohoInternal($orgUser, $invoice['invoice_id']);

            ZohoInvoice::create([
                'invoice_id' => $invoice['invoice_id'],
                'contact_id' => $invoice['customer_id'],
                'zoho_json' => json_encode($invoice),
                'org_id' => $orgUser->zoho_org_id,
                'user_id' => $request->user_id,
                'role' => $request->role,
                'company_id' => $request->company_id,
            ]);

            return response()->json([
                'message' => 'Invoice created, approved, and emailed successfully.',
                'data' => $invoice,
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getInvoices(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'org_id' => 'nullable|string|exists:org_users,zoho_org_id',
        'invoice_id' => 'nullable|string',
        'contact_id' => 'nullable|string',
        'per_page' => 'nullable|integer|min:1|max:100',
        'page' => 'nullable|integer|min:1',
        'flag' => 'nullable|string|in:due_today,paid_today',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $query = ZohoInvoice::query();

    // Direct filters
    if ($request->org_id) {
        $query->where('org_id', $request->org_id);
    }

    if ($request->invoice_id) {
        $query->where('invoice_id', 'like', "%{$request->invoice_id}%");
    }

    if ($request->contact_id) {
        $query->where('contact_id', 'like', "%{$request->contact_id}%");
    }

    // Flag logic
    $today = now()->format('Y-m-d');

    if ($request->flag === 'due_today') {
        $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(zoho_json, '$.due_date')) = ?", [$today]);
    }

    if ($request->flag === 'paid_today') {
        $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(zoho_json, '$.last_payment_date')) = ?", [$today]);
    }

    // Other searchable fields
    $searchableFields = (new ZohoInvoice())->getFillable();

    foreach ($request->all() as $key => $value) {
        if (in_array($key, $searchableFields) && !empty($value)) {
            $query->where($key, 'like', "%{$value}%");
        }
    }

    $paginated = $query->paginate($request->get('per_page', 10));

    // ✅ Modify invoice_status for response only
    $paginated->getCollection()->transform(function ($item) {
        $zoho = json_decode($item->zoho_json, true);

        if (
            isset($item->invoice_status) &&
            $item->invoice_status === 'partially_paid' &&
            !empty($zoho['due_date']) &&
            \Carbon\Carbon::parse($zoho['due_date'])->isFuture()
        ) 
        {
            $item->invoice_status = 'sent'; 
        }

        $item->zoho_json = $zoho;
        return $item;
    });

    return response()->json($paginated);
}
    public function sendZohoInvoiceEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:org_users,id',
            'invoice_id' => 'required|string',
            'to_mail_ids' => 'required|array|min:1',
            'to_mail_ids.*' => 'email',
            'cc_mail_ids' => 'nullable|array',
            'cc_mail_ids.*' => 'email',
            'subject' => 'required|string',
            'body' => 'required|string',
            'send_from_org_email_id' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $orgUser = $this->getOrgUser($request->user_id);
        if (!$orgUser) {
            return response()->json(['error' => 'Organization user not found'], 404);
        }

        try {
            $emailData = [
                "send_from_org_email_id" => $request->get('send_from_org_email_id', false),
                "to_mail_ids" => $request->to_mail_ids,
                "cc_mail_ids" => $request->cc_mail_ids ?? [],
                "subject" => $request->subject,
                "body" => $request->body,
            ];

            $response = $this->zohoPostRequest(
                "https://www.zohoapis.in/books/v3/invoices/{$request->invoice_id}/email",
                $orgUser,
                $emailData
            );

            return response()->json([
                'message' => 'Invoice email sent successfully.',
                'data' => $response
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function approveZohoInvoice($companyId, $invoiceId)
    {
        $orgUser = $this->getOrgUser($companyId);
        if (!$orgUser) {
            return response()->json(['error' => 'Organization user not found'], 404);
        }

        try {
            $response = $this->approveZohoInvoiceInternal($orgUser, $invoiceId);

            return response()->json([
                'message' => 'Invoice approved successfully.',
                'data' => $response
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function getOrgUser($companyId)
    {
        return ZohoUser::find($companyId);
    }

    private function zohoPostRequest(string $url, $orgUser, array $jsonBody = [])
    {
        $client = new Client();

        $response = $client->post($url, [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
                'Content-Type' => 'application/json',
            ],
            'query' => [
                'organization_id' => $orgUser->zoho_org_id,
            ],
            'json' => $jsonBody,
        ]);

        return json_decode($response->getBody(), true);
    }

    private function approveZohoInvoiceInternal($orgUser, $invoiceId)
    {
        return $this->zohoPostRequest(
            "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}/approve",
            $orgUser
        );
    }

    private function emailInvoiceFromZohoInternal($orgUser, $invoiceId, array $emailData = [])
    {
        
        return $this->zohoPostRequest(
            "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}/email",
            $orgUser,
            $emailData
        );
    }

    public function getInvoiceDetails($invoiceId, $userId)
    {
        $orgUser = ZohoUser::findOrFail($userId);

        try {
            $invoice = $this->zohoGetRequest(
                "https://www.zohoapis.in/books/v3/invoices/{$invoiceId}",
                $orgUser
            );

            return response()->json(['invoice' => $invoice], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function zohoGetRequest(string $url, $orgUser, array $queryParams = [])
    {
        $client = new \GuzzleHttp\Client();

        $response = $client->get($url, [
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
                'Content-Type' => 'application/json',
            ],
            'query' => array_merge([
                'organization_id' => $orgUser->zoho_org_id,
            ], $queryParams),
        ]);

        return json_decode($response->getBody(), true);
    }
    
    public function syncAllInvoices(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:org_users,id',
            'user_id'    => 'required|integer|exists:org_users,id',
            'role'       => 'nullable|string'
        ]);
    
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $orgUser = ZohoUser::find($request->company_id);
    
        if (!$orgUser || !$orgUser->zoho_access_token || !$orgUser->zoho_org_id) {
            return response()->json(['error' => 'Invalid Zoho credentials'], 400);
        }
    
        $client = new Client();
        $page   = 1;
        $perPage = 200; // Zoho max
        $countInvoices = 0;
    
        try {
            // ✅ Truncate table first
            ZohoInvoice::truncate();
    
            do {
                $response = $client->get("https://www.zohoapis.in/books/v3/invoices", [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
                        'Content-Type'  => 'application/json',
                    ],
                    'query' => [
                        'organization_id' => $orgUser->zoho_org_id,
                        'per_page' => $perPage,
                        'page' => $page,
                    ],
                ]);
    
                $body = json_decode((string) $response->getBody(), true);
    
                if (!isset($body['invoices']) || empty($body['invoices'])) {
                    break;
                }
    
                // Collect all customer IDs for this page
                $customerIds = collect($body['invoices'])->pluck('customer_id')->filter()->unique();
                $retailers = ZohoUser::whereIn('zoho_contact_id', $customerIds)
                                    ->get()
                                    ->keyBy('zoho_contact_id');
    
         
                
                  $Rets = Retailers::whereIn('contact_id', $customerIds)
                                    ->get()
                                    ->keyBy('contact_id');
                                    
                      
                $inserts = [];
                foreach ($body['invoices'] as $invoice) {
                    $productType = null;
                    foreach ($invoice['custom_fields'] ?? [] as $field) {
                        if (($field['api_name'] ?? null) === 'cf_policy_type') {
                            $productType = $field['value'] ?? null;
                            break;
                        }
                    }
    
                    $retailer = $retailers[$invoice['customer_id']] ?? null;
                    
                    $Ret = $Rets[$invoice['customer_id']] ?? null;
    
                    $inserts[] = [
                        'invoice_id'        => trim((string) $invoice['invoice_id']),
                        'contact_id'        => $invoice['customer_id'] ?? null,
                        'zoho_json'         => json_encode($invoice),
                        'org_id'            => $orgUser->zoho_org_id,
                        'user_id'           => $retailer->id ?? 0,
                        'org_code'          => $retailer->org_code ?? '',
                        'org_name'          => $retailer->business_name ?? '',
                        'org_mobile'        => $retailer->mobile ?? '',
                        'role'              => $retailer->role ?? 0,
                        'company_id'        => $request->company_id,
                        'due_date'          => $invoice['due_date'] ?? null,
                        'payment_date'      => $invoice['last_payment_date'] ?? null,
                        'invoice_amount'    => $invoice['total'] ?? null,
                        'balance_amount'    => $invoice['balance'] ?? null,
                        'invoice_status'    => $invoice['status'] ?? null,
                        'invoice_date'      => $invoice['date'] ?? null,
                        'invoice_number'    => $invoice['invoice_number'] ?? null,
                        'customer_name'     => $invoice['customer_name'] ?? null,
                        'due_days'          => $invoice['due_days'] ?? null,
                        'invoice_url'       => $invoice['invoice_url'] ?? null,
                        'salesperson_name'  => $invoice['salesperson_name'] ?? null,
                        'email'             => $invoice['email'] ?? null,
                        'write_off_amount'  => $invoice['write_off_amount'] ?? 0,
                        'product_type'      => $productType,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                        'level1'            => $Ret['level1'] ?? 0,
                        'level2'            => $Ret['level2'] ?? 0,
                        'level3'            => $Ret['level3'] ?? 0,
                        'level4'            => $Ret['level4'] ?? 0,
                        'level5'            => $Ret['level5'] ?? 0,
                        'level1_name'       => $Ret['level1_name'] ?? "",
                        'level2_name'       => $Ret['level2_name'] ?? "",
                        'level3_name'       => $Ret['level3_name'] ?? "",
                        'level4_name'       => $Ret['level4_name'] ?? "",
                        'level5_name'       => $Ret['level5_name'] ?? ""
                    ];
                }
    
                ZohoInvoice::insert($inserts);
    
                $countInvoices += count($body['invoices']);
                $hasMore = $body['page_context']['has_more_page'] ?? false;
                $page++;
    
            } while ($hasMore && $page <= 50); // Max 10,000 invoices
    
            return response()->json([
                'status' => true,
                'message' => 'Invoices synced successfully from Zoho.',
                'count' => $countInvoices,
            ]);
    
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            return response()->json([
                'status' => false,
                'error' => $errorBody['message'] ?? $e->getMessage(),
            ], $e->getResponse()->getStatusCode());
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncAllInvoicesTest(Request $request)
    {
        
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:org_users,id',
            'user_id'    => 'required|integer|exists:org_users,id',
            'role'       => 'nullable|string'
        ]);
    
        if ($validator->fails()) {
            // Log validation errors
            DB::table('ama_error_logs')->insert([
                'api_id'         => null,
                'method_name'    => 'syncAllInvoicesTest',
                'error_message'  => 'Validation failed',
                'additional_info'=> json_encode($validator->errors()->toArray()),
                'status_code'    => 422,
                'is_solved'      => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
    
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $orgUser = ZohoUser::find($request->company_id);
        if (!$orgUser || !$orgUser->zoho_access_token || !$orgUser->zoho_org_id) {
            // Log invalid Zoho credentials
            DB::table('ama_error_logs')->insert([
                'api_id'         => null,
                'method_name'    => 'syncAllInvoicesTest',
                'error_message'  => 'Invalid Zoho credentials',
                'additional_info'=> json_encode([
                    'company_id' => $request->company_id,
                    'user_id' => $request->user_id,
                ]),
                'status_code'    => 400,
                'is_solved'      => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
    
            return response()->json(['error' => 'Invalid Zoho credentials'], 400);
        }
    
        $client = new Client();
        $page   = 1;
        $perPage = 200; // Zoho max
        $allInvoices = [];
    
        try {
            do {
                $response = $client->get("https://www.zohoapis.in/books/v3/invoices", [
                    'headers' => [
                        'Authorization' => 'Zoho-oauthtoken ' . $orgUser->zoho_access_token,
                        'Content-Type'  => 'application/json',
                    ],
                    'query' => [
                        'organization_id' => $orgUser->zoho_org_id,
                        'per_page' => $perPage,
                        'page' => $page,
                    ],
                ]);
    
                $body = json_decode((string) $response->getBody(), true);
    
                if (!isset($body['invoices']) || empty($body['invoices'])) {
                    break;
                }
    
                foreach ($body['invoices'] as $invoice) {
    
                    $productType = null;
                    if (isset($invoice['custom_fields']) && is_array($invoice['custom_fields'])) {
                        foreach ($invoice['custom_fields'] as $field) {
                            if (($field['api_name'] ?? null) === 'cf_policy_type') {
                                $productType = $field['value'] ?? null;
                                break;
                            }
                        }
                    }
    
                    $retailer = ZohoUser::where('zoho_contact_id', $invoice['customer_id'])->first();
    
                    if (!$retailer) {
                        // Log missing retailer
                        DB::table('ama_error_logs')->insert([
                            'api_id'         => null,
                            'method_name'    => 'syncAllInvoicesTest',
                            'error_message'  => "Retailer not found for customer_id {$invoice['customer_id']}",
                            'additional_info'=> json_encode($invoice),
                            'status_code'    => 404,
                            'is_solved'      => 0,
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);
    
                        continue; // Skip this invoice
                    }
    
                    ZohoInvoice::updateOrCreate(
                        ['invoice_id' => $invoice['invoice_id']],
                        [
                            'contact_id'        => $invoice['customer_id'] ?? null,
                            'zoho_json'         => json_encode($invoice),
                            'org_id'            => $orgUser->zoho_org_id,
                            'user_id'           => $retailer->id,
                            'role'              => $request->role,
                            'company_id'        => $request->company_id,
    
                            'due_date'          => $invoice['due_date'] ?? null,
                            'payment_date'      => $invoice['last_payment_date'] ?? null,
                            'invoice_amount'    => $invoice['total'] ?? null,
                            'balance_amount'    => $invoice['balance'] ?? null,
                            'invoice_status'    => $invoice['status'] ?? null,
    
                            'invoice_number'    => $invoice['invoice_number'] ?? null,
                            'customer_name'     => $invoice['customer_name'] ?? null,
                            'due_days'          => $invoice['due_days'] ?? null,
                            'invoice_url'       => $invoice['invoice_url'] ?? null,
                            'salesperson_name'  => $invoice['salesperson_name'] ?? null,
                            'email'             => $invoice['email'] ?? null,
                            'write_off_amount'  => $invoice['write_off_amount'] ?? 0,
                            'product_type'      => $productType,
                        ]
                    );
                }
    
                $allInvoices = array_merge($allInvoices, $body['invoices']);
                $hasMore = $body['page_context']['has_more_page'] ?? false;
                $page++;
    
            } while ($hasMore && $page <= 50); // 200 * 50 = 10,000 invoices max
    
            return response()->json([
                'status' => true,
                'message' => 'Invoices synced successfully from Zoho.',
                'count' => count($allInvoices),
            ]);
    
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
    
            DB::table('ama_error_logs')->insert([
                'api_id'         => null,
                'method_name'    => 'syncAllInvoicesTest',
                'error_message'  => $errorBody['message'] ?? $e->getMessage(),
                'additional_info'=> json_encode([
                    'company_id' => $request->company_id,
                    'user_id'    => $request->user_id,
                ]),
                'status_code'    => $e->getResponse()->getStatusCode(),
                'is_solved'      => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
    
            return response()->json([
                'status' => false,
                'error' => $errorBody['message'] ?? $e->getMessage(),
            ], $e->getResponse()->getStatusCode());
        } catch (\Exception $e) {
            DB::table('ama_error_logs')->insert([
                'api_id'         => null,
                'method_name'    => 'syncAllInvoicesTest',
                'error_message'  => $e->getMessage(),
                'additional_info'=> json_encode([
                    'company_id' => $request->company_id,
                    'user_id'    => $request->user_id,
                ]),
                'status_code'    => 500,
                'is_solved'      => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
    
            return response()->json([
                'status' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
  }

    public function getInvoicesWithSummary(Request $request)
    {
        $today = Carbon::today()->toDateString();
        
        $validator = Validator::make($request->all(), [
            'org_id'       => 'nullable|string|exists:org_users,zoho_org_id',
            'invoice_id'   => 'nullable|string',
            'contact_id'   => 'nullable|string',
            'level1'       => 'nullable|string',
            'date_from'    => 'nullable|date',
            'date_to'      => 'nullable|date',
            'payment_date' => 'nullable|date', 
            'per_page'     => 'nullable|integer|min:1|max:100',
            'page'         => 'nullable|integer|min:1'
        ]);
    
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $query = ZohoInvoice::query();
    
         $today = now()->format('Y-m-d');
    
        if ($request->flag === 'due_today') {
            $query->where('due_date', $today);
            $query->where('invoice_status', '!=', 'paid');
        }
    
        if ($request->flag === 'paid_today') {
            $query->where("payment_date", $today);
        }


        // Static filters
        if ($request->org_id) {
            $query->where('org_id', $request->org_id);
        }
    
        if ($request->invoice_id) {
            $query->where('invoice_id', 'like', "%{$request->invoice_id}%");
        }
    
        if ($request->contact_id) {
            $query->where('contact_id', 'like', "%{$request->contact_id}%");
        }
    
        if ($request->level1) {
            $query->where('level1', $request->level1);
        }
    
        // Date filters for due_date
        if ($request->date_from) {
            $query->where(
                DB::raw("STR_TO_DATE(due_date, '%Y-%m-%d')"),
                '>=',
                $request->date_from
            );
        }
    
        if ($request->date_to) {
            $query->where(
                DB::raw("STR_TO_DATE(due_date, '%Y-%m-%d')"),
                '<=',
                $request->date_to
            );
        }
    
        // ✅ New filter: Filter by payment_date
        if ($request->payment_date) {
            $query->whereDate('payment_date', $request->payment_date);
        }
    
        // Dynamic filters
        $searchableFields = (new ZohoInvoice())->getFillable();
        foreach ($request->all() as $key => $value) {
            if (in_array($key, $searchableFields) && !empty($value)) {
                $query->where($key, 'like', "%{$value}%");
            }
        }
    
        // Clone query for summary
        $summaryQuery = clone $query;
    
        $summary = $summaryQuery->select([
            DB::raw("COUNT(*) as total_invoices"),
            DB::raw("SUM(invoice_amount) as total_invoice_amt"),
            DB::raw("SUM(CASE WHEN invoice_status != 'void' THEN balance_amount ELSE 0 END) as total_balance_amt"),
            DB::raw("COUNT(CASE WHEN balance_amount > 0 THEN 1 END) as balance_inv_count"),
            DB::raw("SUM(CASE WHEN invoice_status = 'overdue' THEN 1 ELSE 0 END) as overdue_count"),
            DB::raw("SUM(CASE WHEN invoice_status = 'overdue' THEN balance_amount ELSE 0 END) as overdue_amount"),
            DB::raw("SUM(CASE WHEN DATE(due_date) = '{$today}' AND invoice_status != 'paid' THEN 1 ELSE 0 END) as due_today_count"),
            DB::raw("SUM(CASE WHEN DATE(due_date) = '{$today}'AND invoice_status != 'paid' THEN balance_amount ELSE 0 END) as due_today_amount"),
            DB::raw("SUM(CASE WHEN due_date > '{$today}' THEN 1 ELSE 0 END) as upcoming_count"),
            DB::raw("SUM(CASE WHEN due_date > '{$today}' THEN balance_amount ELSE 0 END) as upcoming_amount"),
            DB::raw("SUM(CASE WHEN DATE(payment_date) = '{$today}' THEN 1 ELSE 0 END) as paid_today_count"),
            DB::raw("SUM(CASE WHEN DATE(payment_date) = '{$today}' THEN invoice_amount ELSE 0 END) as paid_today_amount")
        ])->first();
    
        // Pagination
        $paginated = $query->paginate($request->get('per_page', 10));
    
        $paginated->getCollection()->transform(function ($item) {
            $item->zoho_json = json_decode($item->zoho_json);
            return $item;
        });
    
        return response()->json([
            'summary'  => $summary,
            'invoices' => $paginated,
        ]);
    }
  
    public function getInvoicesChart(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'date_from' => 'required|date',
        'date_to'   => 'required|date',
        'org_id'    => 'nullable|string|exists:org_users,zoho_org_id',
        'org_code'  => 'nullable|string',
        'level1'    => 'nullable|string',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $query = ZohoInvoice::query();

    // Fixed filters
    if ($request->org_id) {
        $query->where('org_id', $request->org_id);
    }
    if ($request->org_code) {
        $query->where('org_code', $request->org_code);
    }
    if ($request->level1) {
        $query->where('level1', $request->level1);
    }

    // Date filter
    $query->whereBetween(
        DB::raw("STR_TO_DATE(invoice_date, '%Y-%m-%d')"),
        [$request->date_from, $request->date_to]
    );

    // Dynamic filters (based on fillable fields)
    $searchableFields = (new ZohoInvoice())->getFillable();
    foreach ($request->all() as $key => $value) {
        if (in_array($key, $searchableFields) && !empty($value)) {
            $query->where($key, 'like', "%{$value}%");
        }
    }

    // Group by month
    $chartData = $query
        ->select([
            DB::raw("DATE_FORMAT(STR_TO_DATE(invoice_date, '%Y-%m-%d'), '%Y-%m') as month"),
            DB::raw("SUM(invoice_amount) as total_invoice_amt"),
            DB::raw("SUM(balance_amount) as total_balance_amt"),
            DB::raw("COUNT(*) as total_invoices"),
            DB::raw("COUNT(CASE WHEN balance_amount > 0 THEN 1 END) as balance_inv_count")
        ])
        ->groupBy('month')
        ->orderBy('month')
        ->get();

    // Human readable month name
    $chartData->transform(function ($row) {
        $row->month_name = \Carbon\Carbon::createFromFormat('Y-m', $row->month)->format('F Y');
        return $row;
    });

    return response()->json([
        'chart' => $chartData
    ]);
}
    
    public function getInvoicesAndSendMessages(Request $request)
    {
        $apiKey = 'xmzzeoeowfppicbquvp3zupvntzeqh2j';
    
        // Fetch only the columns you need (faster than selecting all)
        $start = (int) $request->input('start', 0);   // default 0
        $end   = (int) $request->input('end', 300);   // default 300
    
        // Ensure valid range
        if ($end <= $start) {
            return response()->json(['error' => 'Invalid range'], 400);
        }
    
        // Calculate offset & limit
        $offset = $start;
        $limit  = $end - $start;
    
        $invoices = ZohoInvoice::query()
            ->where('invoice_status', 'overdue')
            ->orderByRaw("STR_TO_DATE(invoice_date, '%Y-%m-%d') ASC")
            ->offset($offset)
            ->limit($limit)
            ->get([
                'invoice_id',
                'org_name',
                'org_code',
                'invoice_date',
                'invoice_number',
                'invoice_amount',
                'due_date',
                'balance_amount',
                'invoice_url',
                'org_mobile',
            ]);
    
        $successCount = 0;
        $failedCount = 0;
    
    
    
        foreach ($invoices as $invoice) {
            
            $invoiceDate = null;
            if (!empty($invoice->invoice_date)) {
                $invoiceDate = date('d-m-Y', strtotime($invoice->invoice_date));
            }
        
            // Convert due_date to dd-mm-yyyy
            $dueDate = null;
            if (!empty($invoice->due_date)) {
                $dueDate = date('d-m-Y', strtotime($invoice->due_date));
            }
            
            try {
                $apiResponse = $this->sendInvoiceTemplate(
                    $apiKey,
                    $invoice->org_mobile,   
                    $invoice->org_name,            
                    $invoice->org_code,            
                    $invoiceDate,  
                    $invoice->invoice_number,      
                    $invoice->invoice_amount,      
                    $dueDate,
                    $invoice->balance_amount,      
                    $invoice->invoice_url,         
                    "Nitin Sutar",          
                    "9372732399",                               
                    "WhatsApp",                            
                    "+919372732399"                     
                );
    
                // Log WhatsApp message attempt
                ZohoInvoiceWaLog::create([
                    'invoice_id'     => $invoice->invoice_id,
                    'org_name'       => $invoice->org_name,
                    'org_code'       => $invoice->org_code,
                    'invoice_amount' => $invoice->invoice_amount,
                    'invoice_type'   => $invoice->product_type ?? null,
                    'api_response'   => json_encode($apiResponse),
                ]);
    
                $successCount++;
            } catch (\Exception $e) {
                // Log failed attempt
                ZohoInvoiceWaLog::create([
                    'invoice_id'     => $invoice->invoice_id,
                    'org_name'       => $invoice->org_name,
                    'org_code'       => $invoice->org_code,
                    'invoice_amount' => $invoice->invoice_amount,
                    'invoice_type'   => $invoice->product_type ?? null,
                    'api_response'   => json_encode(['error' => $e->getMessage()]),
                ]);
    
                $failedCount++;
            }
        }
    
        return response()->json([
            'message'       => 'WhatsApp message sending completed',
            'total'         => $invoices->count(),
            'success_count' => $successCount,
            'failed_count'  => $failedCount,
        ]);
    }
    private function sendInvoiceTemplate(
        $apiKey,
        $destinationPhone,
        $org,
        $orgCode,
        $invoiceDate,
        $invoiceNo,
        $totalAmount,
        $dueDate,
        $amountDue,
        $invoiceUrl,
        $name1,
        $phoneNo1,
        $name2,
        $phoneNo2
    ) {
        $source = '919372011028';
        $destination = '91' . $destinationPhone;
    
        $templateData = [
            "id" => "67a316f3-e29c-48ce-9c6b-1bfde68af8a7",
            "params" => [
                $org,
                $orgCode,
                $invoiceDate,
                $invoiceNo,
                $totalAmount,
                $dueDate,
                $amountDue,
                $invoiceUrl,
                $name1,
                $phoneNo1,
                $name2,
                $phoneNo2,
            ],
        ];
    
        // Create Guzzle client
        $client = new Client();
    
        try {
            $response = $client->post('https://api.gupshup.io/wa/api/v1/template/msg', [
                'headers' => [
                    'apikey' => $apiKey,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'channel'     => 'whatsapp',
                    'source'      => $source,
                    'destination' => $destination,
                    'src.name'    => 'Goelectronix', // must match your Gupshup app name
                    'template'    => json_encode($templateData),
                ],
            ]);
    
            // Parse response
            $body = json_decode($response->getBody(), true);
    
            return response()->json([
                'ApiResponse' => $body,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    
    // new code , auto invoices
    
    public function cronCreateZohoInvoice(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:org_users,id',
            'company_id' => 'required|integer|exists:org_users,id',
            'customer_id' => 'required|string',
            'date' => 'required|date',
            'due_date' => 'required|date',
            'payment_terms' => 'nullable|integer',
            'payment_terms_label' => 'nullable|string',
            'line_items' => 'required|array|min:1',
            'line_items.*.item_id' => 'required|string',
            'line_items.*.name' => 'required|string',
            'line_items.*.rate' => 'required|numeric',
            'line_items.*.quantity' => 'required|numeric',
            'role' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $orgUser = $this->getOrgUser($request->company_id);
        if (!$orgUser) {
            return response()->json(['error' => 'Organization user not found'], 404);
        }

        try {
            $invoiceData = $request->except(['user_id', 'company_id']);
            $createResponse = $this->zohoPostRequest(
                'https://www.zohoapis.in/books/v3/invoices',
                $orgUser,
                $invoiceData
            );

            if (!isset($createResponse['invoice'])) {
                return response()->json(['error' => 'Invoice creation failed'], 500);
            }
            $invoice = $createResponse['invoice'];
            $this->approveZohoInvoiceInternal($orgUser, $invoice['invoice_id']);
            $retainedOrgUser = ZohoUser::find($companyId);

           $emailData = [
                "send_from_org_email_id" => false,
                "to_mail_ids" => [
                   $retainedOrgUser->email
                ],
                "cc_mail_ids" => [
                   $orgUser->email
                ],
                "subject" => "Invoice from {$orgUser->business_name}, {$invoice['invoice_id']}",
                "body" => "Dear Customer,<br><br>
                        Thanks for your business.<br><br>
                        The invoice {$invoice['invoice_number']} is attached with this email. 
                        You can choose the easy way out and
                        <a href='https://invoice.zoho.in/SecurePayment?CInvoiceID={$invoice['invoice_id']}'>
                        pay online for this invoice</a>.<br><br>
                        Here's an overview of the invoice for your reference.<br><br>
                        <b>Invoice:</b> {$invoice['invoice_number']}<br>
                        <b>Date:</b> {$invoice['date']}<br>
                        <b>Amount:</b> {$invoice['amount']}<br><br>
                        It was great working with you. Looking forward to working with you again.<br><br>
                        Regards,<br>{$orgUser->business_name}<br>",
            ];
            $this->emailInvoiceFromZohoInternal($orgUser, $invoice['invoice_id']);

            ZohoInvoice::create([
                'invoice_id' => $invoice['invoice_id'],
                'contact_id' => $invoice['customer_id'],
                'zoho_json' => json_encode($invoice),
                'org_id' => $orgUser->zoho_org_id,
                'user_id' => $request->user_id,
                'role' => $request->role,
                'company_id' => $request->company_id,
            ]);

            return response()->json([
                'message' => 'Invoice created, approved, and emailed successfully.',
                'data' => $invoice,
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


}