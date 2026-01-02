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
      
        $company = Company::findOrFail($companyId);
    
        $Parentcompany = Company::where('id', $company->company_id)
                                ->where('role', 2)
                                ->first();
    
        // Determine template file
       $templateFile = match ((string)$type) {
            '5' => 'RetailerAgreement.docx',   // Retailer role
            '4' => 'AgentAgreement.docx',      // Agent role
            '2' => 'CompanyAgreement.docx',    // Company/Distributor role
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
    
        // CHILD COMPANY PLACEHOLDERS
        $template->setValue('id', $company->id ?? '');
        $template->setValue('business_name', $company->business_name ?? '');
        $template->setValue('contact_person', $company->contact_person ?? '');
        $template->setValue('contact_phone', $company->contact_phone ?? '');
        $template->setValue('contact_email', $company->contact_email ?? '');
        $template->setValue('address_line1', $company->address_line1 ?? '');
        $template->setValue('address_line2', $company->address_line2 ?? '');
        $template->setValue('city', $company->city ?? '');
        $template->setValue('state', $company->state ?? '');
        $template->setValue('district', $company->district ?? '');
        $template->setValue('pincode', $company->pincode ?? '');
        $template->setValue('status', $company->status ?? '');
        $template->setValue('pan', $company->pan ?? '');
        $template->setValue('gst', $company->gst ?? '');
        $template->setValue('business_type', $company->business_type ?? '');
        $template->setValue('is_verified', $company->is_verified ? 'Yes' : 'No');
        $template->setValue('is_payment_success', $company->is_payment_success ? 'Yes' : 'No');
        $template->setValue('trade_name', $company->trade_name ?? '');
        $template->setValue('account_no', $company->account_no ?? '');
        $template->setValue('ifsc_code', $company->ifsc_code ?? '');
        $template->setValue('bank_name', $company->bank_name ?? '');
        $template->setValue('branch_name', $company->branch_name ?? '');
        $template->setValue('role', $company->role ?? '');
        $template->setValue('esign_verified', $company->esign_verified ? 'Yes' : 'No');
        $template->setValue('company_id', $company->company_id ?? '');
        $template->setValue('account_type', $company->account_type ?? '');
        $template->setValue('created_at', $company->created_at?->format('Y-m-d H:i') ?? '');
        $template->setValue('updated_at', $company->updated_at?->format('Y-m-d H:i') ?? '');
    
        // EFFECTIVE DATE
        $effectiveDate = $company->created_at ?? now();
        $template->setValue('effective_day', $effectiveDate->format('d'));
        $template->setValue('effective_month', $effectiveDate->format('F'));
        $template->setValue('effective_year', $effectiveDate->format('Y'));
    
        // PARENT COMPANY PLACEHOLDERS
        if ($Parentcompany) {
            $template->setValue('parent_business_name', $Parentcompany->business_name ?? '');
            $template->setValue('parent_contact_person', $Parentcompany->contact_person ?? '');
            $template->setValue('parent_contact_phone', $Parentcompany->contact_phone ?? '');
            $template->setValue('parent_contact_email', $Parentcompany->contact_email ?? '');
            $template->setValue('parent_address_line1', $Parentcompany->address_line1 ?? '');
            $template->setValue('parent_address_line2', $Parentcompany->address_line2 ?? '');
            $template->setValue('parent_city', $Parentcompany->city ?? '');
            $template->setValue('parent_state', $Parentcompany->state ?? '');
            $template->setValue('parent_district', $Parentcompany->district ?? '');
            $template->setValue('parent_pincode', $Parentcompany->pincode ?? '');
    
            $fullParentAddress =
                ($Parentcompany->address_line1 ?? '') . "\n" .
                ($Parentcompany->address_line2 ?? '') . "\n" .
                ($Parentcompany->district ?? '') . ' ' . ($Parentcompany->city ?? '') . "\n" .
                ($Parentcompany->state ?? '') . ' – ' . ($Parentcompany->pincode ?? '') . " India";
    
            $template->setValue('parent_office_full_address', $fullParentAddress);
        }
    
        // CREATE DIRECTORY IF NOT EXISTS
        $generatedPath = storage_path('app/public/generated');
        if (!file_exists($generatedPath)) mkdir($generatedPath, 0777, true);
    
        // SAVE DOCX + EXPORT AS PDF
        $outputDocx = "{$generatedPath}/{$type}Agreement{$company->id}.docx";
        $outputPdf  = "{$generatedPath}/{$type}Agreement{$company->id}.pdf";
    
        $template->saveAs($outputDocx);
        $command = 'libreoffice --headless --convert-to pdf "' . $outputDocx . '" --outdir "' . $generatedPath . '"';
        exec($command);
    
        if (!file_exists($outputPdf)) return response()->json(['error' => 'PDF conversion failed'], 500);
    
        return response()->json([
            "status" => true,
            "type"   => $type,
            "message" => "Agreement generated successfully",
            "download_url" => asset("storage/generated/{$type}Agreement{$company->id}.pdf")
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
        $uploadResponse = Http::withHeaders([
            'AppId'     => "170HM093HK0HG6YZ37F0X00Q4KJ6ZX",
            'SecretKey' => "TESTPO0KGDZ2FQ8RETK88038KALUEK6AC3AS",
            'accept'    => '*/*'
        ])
        ->attach('document', file_get_contents($pdfPath), basename($pdfPath))
        ->post("https://agitated-cori.103-195-185-254.plesk.page/api/Cashfree/upload-esign-document");

        $uploadJson = $uploadResponse->json();

        if (empty($uploadJson['document_id'])) {
            \Log::error("Cashfree Upload Error: " . $uploadResponse->body());

            return response()->json([
                'status' => false,
                'message' => 'Cashfree upload failed',
                'api_response' => $uploadJson
            ], 500);
        }

        // STEP 4: Create eSign request
        $esignPayload = [
            "verification_id" => $company->pan,
            "document_id"     => $uploadJson['document_id'],
            "notification_modes" => ["email"],
            "auth_type" => "AADHAAR",
            "expiry_in_days" => "10",
            "capture_location" => true,
            "redirect_url" => "https://yourdomain.com/esign/callback?company_id=".$companyId,
            "signers" => [
                [
                    "name"  => $company->business_name,
                    "email" => $company->contact_email,
                    "phone" => $company->contact_phone,
                    "sequence" => 1,
                    "aadhaar_last_four_digit" => $request->aadhaar_last_four_digit,
                    "sign_positions" => [
                        [
                            "page" => 1,
                            "top_left_x_coordinate" => 350,
                            "bottom_right_x_coordinate" => 550,
                            "top_left_y_coordinate" => 700,
                            "bottom_right_y_coordinate" => 770
                        ]
                    ]
                ]
            ]
        ];

        $esignResponse = Http::withHeaders([
            'AppId'      => "170HM093HK0HG6YZ37F0X00Q4KJ6ZX",
            'SecretKey'  => "TESTPO0KGDZ2FQ8RETK88038KALUEK6AC3AS",
            'accept'     => '*/*'
        ])
        ->post("https://agitated-cori.103-195-185-254.plesk.page/api/Cashfree/create-esign", $esignPayload);

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
}

