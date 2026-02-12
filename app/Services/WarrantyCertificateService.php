<?php

namespace App\Services;

use App\Models\WDevice;
use App\Models\Company;
use App\Models\WarrantyProduct;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\TemplateProcessor;
use Carbon\Carbon;

class WarrantyCertificateService
{
    public function generate(WDevice $device): array
    {
        try {

            // Reload relations safely
            $device->load('customer');

            $customer = $device->customer;
            $retailer = Company::find($device->retailer_id);
            $product  = WarrantyProduct::find($device->product_id);

            if (!$customer || !$retailer || !$product) {
                throw new \Exception('Related data missing for certificate generation');
            }

            /**
             * Prevent duplicate certificate generation
             */
            if (!empty($device->certificate_link)) {
                return [
                    'certificate_id'  => $this->extractCertificateId($device->certificate_link),
                    'certificate_url' => $device->certificate_link
                ];
            }

            /**
             * Generate Certificate ID
             */
            $certificateId = 'GX-WNTY-' . now()->year . '-' . str_pad($device->id, 5, '0', STR_PAD_LEFT);
            $verifyUrl = "https://verify.goelectronix.in/cert/{$certificateId}";

            /**
             * Load Template
             */
            $templatePath = storage_path('app/template/WarrantyCertificate.docx');

            if (!file_exists($templatePath)) {
                throw new \Exception('Certificate template not found');
            }

            $templateProcessor = new TemplateProcessor($templatePath);

            /**
             * Replace Variables
             */
            $templateProcessor->setValue('certificateId', $certificateId);
            $templateProcessor->setValue('customerName', $customer->name);
            $templateProcessor->setValue('customerPhone', $customer->mobile);
            $templateProcessor->setValue('brand', $device->brand_name);
            $templateProcessor->setValue('model', $device->model);
            $templateProcessor->setValue('category', $device->category_name);
            $templateProcessor->setValue('imei1', $device->imei1);
            $templateProcessor->setValue('serial', $device->serial ?? '');
            $templateProcessor->setValue('planName', $product->name);
            $templateProcessor->setValue('planSummary', strip_tags($product->features));
            $templateProcessor->setValue('maxClaims', $device->available_claim);
            $templateProcessor->setValue('coverageLimit', number_format($device->device_price, 2));
            $templateProcessor->setValue('retailerName', $retailer->business_name);
            $templateProcessor->setValue('retailerCode', $retailer->company_code);
            $templateProcessor->setValue('retailerAddress', $retailer->address_line1);
            $templateProcessor->setValue('retailerContact', $retailer->contact_phone);
            $templateProcessor->setValue('startDate', now()->toDateString());
            
            $templateProcessor->setValue(
                'endDate',
                Carbon::parse($device->expiry_date)->format('d-m-Y')
            );

            $templateProcessor->setValue('issuedOn', now()->toDateString());
            $templateProcessor->setValue('verifyUrl', $verifyUrl);

            /**
             * Ensure folder exists
             */
            $folderPath = storage_path('app/public/warranty_pdfs');
            if (!file_exists($folderPath)) {
                mkdir($folderPath, 0777, true);
            }

            /**
             * Save DOCX
             */
            $docxFile = "{$folderPath}/{$certificateId}.docx";
            $templateProcessor->saveAs($docxFile);

            /**
             * Convert DOCX → PDF
             */
            exec("libreoffice --headless --convert-to pdf --outdir {$folderPath} {$docxFile}");

            $pdfFileName = "{$certificateId}.pdf";
            $pdfFullPath = "{$folderPath}/{$pdfFileName}";

            if (!file_exists($pdfFullPath)) {
                throw new \Exception('PDF conversion failed');
            }

            $pdfPath = "warranty_pdfs/{$pdfFileName}";
            $certificateLink = Storage::disk('public')->url($pdfPath);

            /**
             * Update Device
             */
            $device->update([
                'certificate_link' => $certificateLink
            ]);

            Log::info('Certificate generated successfully', [
                'device_id' => $device->id,
                'certificate_id' => $certificateId
            ]);

            return [
                'certificate_id'  => $certificateId,
                'certificate_url' => $certificateLink
            ];

        } catch (\Throwable $e) {

            Log::error('Certificate generation failed', [
                'device_id' => $device->id ?? null,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);

            throw $e;
        }
    }

    /**
     * Extract certificate ID from URL (for reuse case)
     */
    private function extractCertificateId(string $url): string
    {
        $file = basename($url); // GX-WNTY-2026-00001.pdf
        return str_replace('.pdf', '', $file);
    }
}