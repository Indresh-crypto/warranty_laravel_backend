<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\WDevice;
use App\Models\WarrantyClaim;
use App\Models\WCustomer;
use Carbon\Carbon;

class AIWarrantyController extends Controller
{
    public function ask(Request $request)
    {
        $request->validate([
            'question'   => 'required|string',
            'company_id' => 'nullable|integer',
            'agent_id'   => 'nullable|integer'
        ]);

        $questionOriginal = $request->question;
        $question = strtolower($questionOriginal);
        $companyId = $request->company_id;
        $agentId   = $request->agent_id;

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ FAST INTENT: IMEI CHECK
        |--------------------------------------------------------------------------
        */
        if (preg_match('/\b\d{10,15}\b/', $question, $matches)) {

            $imei = $matches[0];

            $exists = WDevice::when($companyId, fn($q)=>$q->where('company_id',$companyId))
                ->where(function($q) use ($imei){
                    $q->where('imei1',$imei)
                      ->orWhere('imei2',$imei);
                })
                ->exists();

            return response()->json([
                'status' => true,
                'response' => $exists
                    ? "Yes, warranty exists for IMEI {$imei}."
                    : "No warranty found for IMEI {$imei}."
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ FAST INTENT: WARRANTY CODE CHECK
        |--------------------------------------------------------------------------
        */
        if (preg_match('/wrt-\d+/i', $question, $matches)) {

            $code = $matches[0];

            $exists = WDevice::where('w_code', $code)->exists();

            return response()->json([
                'status' => true,
                'response' => $exists
                    ? "Yes, warranty {$code} exists."
                    : "No warranty found for {$code}."
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ FAST INTENT: NAME EXIST CHECK
        |--------------------------------------------------------------------------
        */
        if (preg_match('/\b(exist|exists|available|found)\b/i', $question)) {

            $clean = preg_replace('/\b(warranty|user|customer|exists|exist|available|found|database|in|the|of|is|does)\b/i', '', $question);
            $clean = preg_replace('/[^a-zA-Z\s]/', '', $clean);
            $clean = trim($clean);

            if (strlen($clean) > 2) {

                $exists = WCustomer::where('name', 'like', "%{$clean}%")->exists();

                return response()->json([
                    'status' => true,
                    'response' => $exists
                        ? "Yes, warranty exists for {$clean}."
                        : "No warranty found for {$clean}."
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ CACHE STATS (SUPER FAST)
        |--------------------------------------------------------------------------
        */

        $stats = Cache::remember("warranty_stats_{$companyId}_{$agentId}", 60, function () use ($companyId, $agentId) {

            $deviceQuery = WDevice::query();
            $claimQuery  = WarrantyClaim::query();

            if ($companyId) {
                $deviceQuery->where('company_id', $companyId);
                $claimQuery->where('company_id', $companyId);
            }

            if ($agentId) {
                $deviceQuery->where('agent_id', $agentId);
                $claimQuery->where('agent_id', $agentId);
            }

            return [
                'total' => (clone $deviceQuery)->count(),
                'active' => (clone $deviceQuery)->where('is_approved',1)->count(),
                'pending' => (clone $deviceQuery)->where('is_approved',0)->count(),
                'month' => (clone $deviceQuery)
                    ->whereBetween('created_at', [
                        Carbon::now()->startOfMonth(),
                        Carbon::now()->endOfMonth()
                    ])->count(),
                'open_claims' => (clone $claimQuery)->where('status','pending')->count(),
                'approved_commission' => (clone $deviceQuery)
                    ->whereNotNull('invoice_id')
                    ->sum('company_payout'),
                'pending_commission' => (clone $deviceQuery)
                    ->where(function ($q) {
                        $q->whereNull('invoice_id')
                          ->orWhere('invoice_id','');
                    })
                    ->sum('company_payout'),
            ];
        });

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ RULE ENGINE (INSTANT RESPONSE)
        |--------------------------------------------------------------------------
        */

        if (str_contains($question, 'this month')) {
            return response()->json([
                'status' => true,
                'response' => "There are {$stats['month']} warranties registered this month."
            ]);
        }

        if (str_contains($question, 'total')) {
            return response()->json([
                'status' => true,
                'response' => "Total warranties are {$stats['total']}."
            ]);
        }

        if (str_contains($question, 'active')) {
            return response()->json([
                'status' => true,
                'response' => "There are {$stats['active']} active warranties."
            ]);
        }

        if (str_contains($question, 'pending commission')) {
            return response()->json([
                'status' => true,
                'response' => "Pending commission amount is ₹{$stats['pending_commission']}."
            ]);
        }

        if (str_contains($question, 'approved commission')) {
            return response()->json([
                'status' => true,
                'response' => "Approved commission amount is ₹{$stats['approved_commission']}."
            ]);
        }

        if (str_contains($question, 'open claim')) {
            return response()->json([
                'status' => true,
                'response' => "There are {$stats['open_claims']} open claims."
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 6️⃣ AI FALLBACK (ONLY IF COMPLEX QUESTION)
        |--------------------------------------------------------------------------
        */

        $prompt = "
You are a warranty system assistant.

Use ONLY the numbers below:

Total Warranties: {$stats['total']}
Active Warranties: {$stats['active']}
Pending Warranties: {$stats['pending']}
Warranties This Month: {$stats['month']}
Open Claims: {$stats['open_claims']}
Approved Commission: {$stats['approved_commission']}
Pending Commission: {$stats['pending_commission']}

Answer clearly and professionally.

User Question:
{$questionOriginal}

Short answer only.
";

        $response = Http::timeout(40)->post('http://127.0.0.1:11434/api/generate', [
            'model' => 'gemma:2b',
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.0,
                'num_predict' => 100
            ]
        ]);

        if ($response->failed()) {
            return response()->json([
                'status' => false,
                'message' => 'AI server not responding'
            ], 500);
        }

        return response()->json([
            'status' => true,
            'response' => trim($response->json()['response'] ?? 'Unable to generate response.')
        ]);
    }
}