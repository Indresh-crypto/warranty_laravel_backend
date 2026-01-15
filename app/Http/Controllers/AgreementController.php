<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Loan;
use App\Models\Customer;
use App\Models\Agreement;
use App\Models\Company;

use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Http;
use DB;
use Carbon\Carbon;

class AgreementController extends Controller
{
    public function callback(Request $r) { // webhook from provider
        $agreement = Agreement::where('provider_request_id', $r->get('request_id'))->firstOrFail();
        if ($r->get('status') === 'signed') {
            $agreement->update(['status'=>'signed','signed_at'=>now()]);
            $agreement->loan->update(['status'=>'awaiting_disbursement']);
        } else {
            $agreement->update(['status'=>'failed']);
        }
        return response()->json(['ok'=>true]);
    }
    
    public function generateAgreement($type, $companyId)
    {
        /* ================= FETCH COMPANY ================= */
        $company = Company::findOrFail($companyId);

        /* ================= FETCH SERVICE PROVIDER (PARENT) ================= */
       $serviceProvider = Company::where('id', $company->company_id)
        ->where('role', 2)
        ->first()
            ?? Company::where('id', 1)->first();

        /* ================= SELECT TEMPLATE ================= */
        $templateFile = match ((string) $type) {
            '5' => 'RetailerAgreement.docx',
            '4' => 'AgentAgreement.docx',
            '2' => 'CompanyAgreement.docx',
            default => null,
        };

        if (!$templateFile) {
            return response()->json(['error' => 'Invalid agreement type'], 400);
        }

        $templatePath = storage_path("app/template/{$templateFile}");

        if (!file_exists($templatePath)) {
            return response()->json(['error' => 'Template not found'], 404);
        }

        $template = new TemplateProcessor($templatePath);

        /* ================= EFFECTIVE DATE ================= */
        $effectiveDate = $company->created_at ?? now();

        $template->setValue('effective_day', $effectiveDate->format('d'));
        $template->setValue('effective_month', $effectiveDate->format('F'));
        $template->setValue('effective_year', $effectiveDate->format('Y'));

        /* ================= PARTNER (CHILD COMPANY) ================= */
        $partnerFullAddress =
            ($company->address_line1 ?? '') . "\n" .
            ($company->address_line2 ?? '') . "\n" .
            ($company->district ?? '') . ' ' . ($company->city ?? '') . "\n" .
            ($company->state ?? '') . ' – ' . ($company->pincode ?? '');

        $ownerFullName = trim(
            ($company->owner_first_name ?? '') . ' ' .
            ($company->owner_middle_name ?? '') . ' ' .
            ($company->owner_last_name ?? '')
        );
        
        $now = Carbon::now()->format('d F Y');

        $template->setValue('partner_business_name', $company->business_name ?? '');
        $template->setValue('partner_trade_name', $company->trade_name ?? '');
        $template->setValue('partner_contact_person', $company->contact_person ?? '');
        $template->setValue('partner_contact_phone', $company->contact_phone ?? '');
        $template->setValue('partner_contact_email', $company->contact_email ?? '');

        $template->setValue('partner_signatory_name', $ownerFullName ?? '');
  
        $template->setValue('partner_owner_full_name', $ownerFullName);
        $template->setValue('partner_owner_email', $company->owner_email ?? '');
        $template->setValue('partner_owner_contact', $company->owner_contact ?? '');

        $template->setValue('partner_address_line1', $company->address_line1 ?? '');
        $template->setValue('partner_address_line2', $company->address_line2 ?? '');
        $template->setValue('partner_city', $company->city ?? '');
        $template->setValue('partner_district', $company->district ?? '');
        $template->setValue('partner_state', $company->state ?? '');
        $template->setValue('partner_pincode', $company->pincode ?? '');

        $template->setValue('partner_pan', $company->pan ?? '');
        $template->setValue('partner_gst', $company->gst ?? '');
        $template->setValue('partner_business_type', $company->business_type ?? '');

        $template->setValue('partner_account_no', $company->account_no ?? '');
        $template->setValue('partner_ifsc', $company->ifsc_code ?? '');
        $template->setValue('partner_bank_name', $company->bank_name ?? '');
        $template->setValue('partner_branch_name', $company->branch_name ?? '');

        $template->setValue('partner_company_code', $company->company_code ?? '');
        $template->setValue('partner_role', $company->role ?? '');
        $template->setValue('partner_status', $company->status ?? '');
        $template->setValue('partner_created_at', optional($company->created_at)->format('d-m-Y'));

    
        /* ================= SERVICE PROVIDER ================= */
        if ($serviceProvider) {
            
        
            $serviceProviderAddress =
                ($serviceProvider->address_line1 ?? '') . "\n" .
                ($serviceProvider->address_line2 ?? '') . "\n" .
                ($serviceProvider->district ?? '') . ' ' . ($serviceProvider->city ?? '') . "\n" .
                ($serviceProvider->state ?? '') . ' – ' . ($serviceProvider->pincode ?? '') . ' India';

            $template->setValue('service_provider_business_name', $serviceProvider->business_name ?? '');
            $template->setValue('service_provider_full_address', $serviceProviderAddress);
            $template->setValue('service_provider_signatory_name', $serviceProvider->contact_person ?? '');
            $template->setValue('service_provider_contact_email', $serviceProvider->contact_email ?? '');
            $template->setValue('service_provider_contact_phone', $serviceProvider->contact_phone ?? '');
            $template->setValue('agreement_date', $now ?? '');
            
            $template->setValue('service_provider_gst', $serviceProvider->gst ?? '');
            $template->setValue('service_provider_pan', $serviceProvider->pan ?? '');
        }

        /* ================= SAVE FILES ================= */
        $generatedPath = storage_path('app/public/generated');
        if (!file_exists($generatedPath)) {
            mkdir($generatedPath, 0777, true);
        }

        $docxPath = "{$generatedPath}/Agreement_{$company->id}.docx";
        $pdfPath  = "{$generatedPath}/Agreement_{$company->id}.pdf";

        $template->saveAs($docxPath);

        /* ================= CONVERT TO PDF ================= */
        $command = 'libreoffice --headless --convert-to pdf "' . $docxPath . '" --outdir "' . $generatedPath . '"';
        exec($command);

        if (!file_exists($pdfPath)) {
            return response()->json(['error' => 'PDF conversion failed'], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'Agreement generated successfully',
            'download_url' => asset("storage/generated/Agreement_{$company->id}.pdf")
        ]);
    }

    public function uploadEsignDocument(Request $request, $roleId, $companyId)
    {
        $request->validate([
            'aadhaar_last_four_digit' => 'required|digits:4'
        ]);
    
        DB::beginTransaction();
    
        try {
            $company = Company::findOrFail($companyId);
    
            // Prevent duplicate eSign
            if (!empty($company->esign_json)) {
                return response()->json([
                    "status" => false,
                    "data"   => $company,
                    "message" => "ESign already initiated for this company. Avoid duplicate requests."
                ], 409);
            }
    
            // STEP 1: Generate PDF
            $response = $this->generateAgreement($roleId, $companyId);
    
            if (!$response->getData()?->status) {
                return response()->json([
                    "status" => false,
                    "message" => "Agreement generation failed"
                ], 500);
            }
    
            // STEP 2: Extract file path
            $pdfUrl  = $response->getData()?->download_url;
            $pdfPath = public_path(str_replace(asset(''), '', $pdfUrl));
    
            if (!file_exists($pdfPath)) {
                return response()->json([
                    "status" => false,
                    "message" => "Generated PDF not found on server"
                ], 404);
            }
    
            // STEP 3: Upload document to Cashfree
           
    
                $uploadResponse = Http::withOptions([
                    
                  
                    'http_version' => CURL_HTTP_VERSION_1_1, // ⬅️ IMPORTANT
                ])
                ->withHeaders([
                    'AppId'     => "170VSRM5NLDT0LLU8TZRA1PRV2EIVB",
                    'SecretKey' => "TESTT56GXPUJ9JR5UKZO4WH6PQ2F8NTM5TQ6",
                    'accept'    => '*/*',
                    'Expect'    => '',             // ⬅️ VERY IMPORTANT (disables 100-continue)
                ])
                ->attach(
                    'document',
                    fopen($pdfPath, 'r'),          // stream
                    basename($pdfPath)
                )
                ->post('https://spillas.com/dotnet-api/Cashfree/upload-esign-document');
                
            $uploadJson = $uploadResponse->json();
    
            if (empty($uploadJson['document_id'])) {
                \Log::error("Cashfree Upload Error: " . $uploadResponse->body());
    
                return response()->json([
                    'status' => false,
                    'message' => 'Cashfree upload failed',
                    'api_response' => $uploadJson
                ], 500);
            }
                
                $signPage = match ((int) $company->role) {
                2 => 14,  // Company agreement
                4 => 11,  // Agent agreement
                5 => 10, // Retailer agreement
                default => 1, // fallback safety
            };

            // STEP 4: Create eSign request
            $esignPayload = [
                "verification_id" => $company->pan,
                "document_id"     => $uploadJson['document_id'],
                "notification_modes" => ["email"],
                "auth_type" => "AADHAAR",
                "expiry_in_days" => "10",
                "capture_location" => true,
                "redirect_url" => url('/esign/callback?company_id='.$companyId),
                "signers" => [
                    [
                        "name"  => $company->business_name,
                        "email" => $company->contact_email,
                        "phone" => $company->contact_phone,
                        "sequence" => 1,
                        "aadhaar_last_four_digit" => $request->aadhaar_last_four_digit,
                        "sign_positions" => [
                            [
                                "page" => $signPage,
                                "top_left_x_coordinate" => 250,
                                "bottom_right_x_coordinate" => 450,
                                "top_left_y_coordinate" => 760,
                                "bottom_right_y_coordinate" => 820
                            ]
                        ]
                    ]
                ]
            ];
    
            $esignResponse = Http::withHeaders([
                'AppId'     => "170VSRM5NLDT0LLU8TZRA1PRV2EIVB",
                'SecretKey' => "TESTT56GXPUJ9JR5UKZO4WH6PQ2F8NTM5TQ6",
                'accept'     => '*/*'
            ])
            ->post("https://spillas.com/dotnet-api/Cashfree/create-esign", $esignPayload);
    
            $esignJson = $esignResponse->json();
    
            if ($esignResponse->failed()) {
                \Log::error("Cashfree eSign Error: " . $esignResponse->body());
    
                return response()->json([
                    "status" => false,
                    "message" => "Failed to create eSign request",
                    "api_response" => $esignJson
                ], 500);
            }
    
            // STEP 5: Update DB only if everything successful
            $company->update([
                'document_id' => $uploadJson['document_id'],
                'esign_json'  => json_encode($esignJson)
            ]);
    
            DB::commit();
    
            return response()->json([
                "status" => true,
                "message" => "eSign request started successfully",
                "upload_response" => $uploadJson,
                "esign_response"  => $esignJson
            ]);
    
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error("ESIGN SYSTEM ERROR: " . $e->getMessage());
    
            return response()->json([
                "status" => false,
                "message" => "Unexpected server failure",
                "error" => $e->getMessage()
            ], 500);
        }
    }
    
    public function esignCallback(Request $request)
    {
        $companyId = $request->query('company_id');
        $status    = $request->query('status'); // depends on provider
        $requestId = $request->query('request_id'); // optional
    
        if (!$companyId) {
            return response()->view('esign.failed', [
                'message' => 'Invalid callback request'
            ]);
        }
    
        $company = Company::find($companyId);
    
        if (!$company) {
            return response()->view('esign.failed', [
                'message' => 'Company not found'
            ]);
        }
    
        /**
         * Provider usually sends:
         * status = success | failed | cancelled
         */
        if (in_array($status, ['success', 'signed', 'completed'])) {
    
            $company->update([
                'esign_verified' => 1,
                'status'         => 'active'
            ]);
    
            return response()->view('esign.success', [
                'company' => $company
            ]);
        }
    
        // FAILED / CANCELLED
        return response()->view('esign.failed', [
            'company' => $company,
            'message' => 'eSign was not completed'
        ]);
    }
}

