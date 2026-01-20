<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\WarrantyFlowLog;

class AdminPaymentController extends Controller
{
    
    public function index(Request $request)
    {
    $query = DB::table('payments_master');

    // Filters
    if ($request->project) {
        $query->where('project', $request->project);
    }
    if ($request->service) {
        $query->where('service', $request->service);
    }

    if ($request->status) {
        $query->where('status', $request->status);
    }

    if ($request->from_date && $request->to_date) {
        $query->whereBetween('paid_at', [
            $request->from_date,
            $request->to_date
        ]);
    }

    if ($request->search) {
        $query->where('payment_id', 'like', '%' . $request->search . '%');
    }

    $payments = $query
        ->orderBy('paid_at', 'desc')
        ->paginate(20);

    return response()->json($payments);
}

    public function show($paymentId)
    {
        $payment = DB::table('payments_master')
            ->where('payment_id', $paymentId)
            ->first();
    
        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }
    
        return response()->json($payment);
    }
    public function warrantyStatus($paymentId)
    {
        $logs = WarrantyFlowLog::where('payment_id', $paymentId)
            ->orderBy('id')
            ->get();
    
        return response()->json([
            'payment_id' => $paymentId,
            'flow' => $logs
        ]);
    }
    
    public function stats()
    {
        return response()->json([
    
            'today_amount' => DB::table('payments_master')
                ->whereDate('paid_at', today())
                ->sum('amount'),
    
            'today_count' => DB::table('payments_master')
                ->whereDate('paid_at', today())
                ->count(),
    
            'total_amount' => DB::table('payments_master')->sum('amount'),
    
            'success_count' => DB::table('payments_master')
                ->where('status', 'captured')
                ->count(),
    
            'failed_count' => DB::table('payments_master')
                ->where('status', 'failed')
                ->count(),
        ]);
    }

    public function retryWarranty($paymentId)
    {
        // Get original webhook payment record
        $payment = DB::table('payments_master')
            ->where('payment_id', $paymentId)
            ->where('project', 'warranty')
            ->where('service', 'activation')
            ->first();
    
        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }
    
        // Prevent retry if already completed
        $completed = WarrantyFlowLog::where('payment_id', $paymentId)
            ->where('step', 'ZOHO_PAYMENT_CREATED')
            ->exists();
    
        if ($completed) {
            return response()->json([
                'message' => 'Warranty already completed'
            ], 409);
        }
    
        // Build job payload again from meta
        $meta = json_decode($payment->meta, true);
    
        $payload = [
    
            'payment_id' => $payment->payment_id,
            'amount' => $payment->amount,
    
            'imei1' => $meta['imei1'] ?? null,
            'product_id' => $meta['product_id'] ?? null,
            'brand_id' => $meta['brand_id'] ?? null,
            'category_id' => $meta['category_id'] ?? null,
            'company_id' => $meta['company_id'] ?? null,
    
            'zoho_product_id' => $meta['zoho_product_id'] ?? null,
            'w_customer_id' => $meta['w_customer_id'] ?? null,
            'retailer_id' => $meta['retailer_id'] ?? null,
            'agent_id' => $meta['agent_id'] ?? null
        ];
    
        // Dispatch again
        WarrantyPaymentFlowJob::dispatch($payload);
    
        // Log admin retry
        WarrantyFlowLog::create([
            'payment_id' => $paymentId,
            'step' => 'ADMIN_MANUAL_RETRY',
            'status' => 1
        ]);
    
        return response()->json([
            'status' => true,
            'message' => 'Warranty retry queued'
        ]);
    }
    
    public function regenerateInvoice($paymentId)
    {
    $deviceLog = WarrantyFlowLog::where('payment_id', $paymentId)
        ->where('step', 'DEVICE_CREATED')
        ->first();

    if (!$deviceLog) {
        return response()->json([
            'error' => 'Device not created yet'
        ], 422);
    }

    $device = WDevice::find($deviceLog->device_id);

    if (!$device) {
        return response()->json([
            'error' => 'Device not found'
        ], 404);
    }

    // Prevent duplicate invoice
    $existingInvoice = WarrantyFlowLog::where('payment_id', $paymentId)
        ->where('step', 'INVOICE_CREATED')
        ->exists();

    if ($existingInvoice) {
        return response()->json([
            'message' => 'Invoice already exists'
        ], 409);
    }

    $meta = DB::table('payments_master')
        ->where('payment_id', $paymentId)
        ->first();

    $notes = json_decode($meta->meta, true);

    // Call invoice generator
    $invoiceResult = app(WarrantyPaymentFlowController::class)
        ->createWarrantyInvoice(
            $device,
            WCustomer::find($device->w_customer_id),
            $device->company_id,
            $notes['zoho_product_id'] ?? null,
            $paymentId
        );

    if (!$invoiceResult['success']) {
        return response()->json([
            'error' => 'Invoice creation failed'
        ], 500);
    }

    WarrantyFlowLog::create([
        'company_id' => 1,
        'payment_id' => $paymentId,
        'device_id' => $device->id,
        'invoice_id' => $invoiceResult['invoice']['invoice_id'],
        'step' => 'INVOICE_CREATED',
        'status' => 1,
        'response_data' => json_encode($invoiceResult)
    ]);

    return response()->json([
        'status' => true,
        'message' => 'Invoice regenerated successfully'
    ]);
}

}