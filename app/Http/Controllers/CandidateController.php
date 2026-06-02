<?php
namespace App\Http\Controllers;

use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class CandidateController extends Controller
{
    // ── GET /api/candidates ─────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = Candidate::query();

        // Status filter
        if ($request->status === 'sent') {
            $query->where('status', 'sent');
        } elseif ($request->status === 'unsent') {
            $query->where('status', 'unsent');
        }

        // Search
       // Search (name, mobile, pincode, city, location)
        if ($request->filled('search')) {

    $q = trim($request->search);

    $query->where(function ($qb) use ($q) {

        $qb->where('mobile', 'like', "%{$q}%")
            ->orWhere('name', 'like', "%{$q}%")
            ->orWhere('city', 'like', "%{$q}%")
            ->orWhere('location', 'like', "%{$q}%")
            ->orWhere('qualification', 'like', "%{$q}%")
            ->orWhere('college_name', 'like', "%{$q}%")
            ->orWhere('course', 'like', "%{$q}%")
            ->orWhere('previous_company_name', 'like', "%{$q}%")
            ->orWhere('previous_designation', 'like', "%{$q}%");

        // Search pincode inside location
        if (preg_match('/\d{4,6}/', $q)) {

            $qb->orWhere('location', 'like', "%{$q}%");
        }
    });
}

        // Date range (filter by last_sent_at)
        if ($request->filled('from')) {
            $query->where('last_sent_at', '>=', Carbon::parse($request->from)->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('last_sent_at', '<=', Carbon::parse($request->to)->endOfDay());
        }

        $perPage = $request->input('per_page', 15);
        $paginated = $query->orderByDesc('id')->paginate($perPage);

        // Stats
        $stats = [
            'total'  => Candidate::count(),
            'sent'   => Candidate::where('status', 'sent')->count(),
            'unsent' => Candidate::where('status', 'unsent')->count(),
            'today'  => Candidate::whereDate('last_sent_at', today())->count(),
        ];

        return response()->json([
            'data'  => $paginated->items(),
            'total' => $paginated->total(),
            'stats' => $stats,
        ]);
    }

    // ── POST /api/candidates/import ─────────────────────────────────────
public function import(Request $request)
{
    try {

        \Log::info('Import Started');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        \Log::info('File validation passed');

       $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile(
            $request->file('file')->getPathname()
        );
        
        $reader->setReadDataOnly(true);
        
        $spreadsheet = $reader->load(
            $request->file('file')->getPathname()
        );

        \Log::info('Excel loaded successfully');

        $sheet = $spreadsheet->getActiveSheet()->toArray();

        \Log::info('Total rows found: ' . count($sheet));

        $header = array_map(function ($h) {
            return strtolower(trim((string) $h));
        }, array_shift($sheet));

        \Log::info('Headers detected', $header);

        $columnMap = [
            'full name' => 'name',

            'mobile no.' => 'mobile',
            'mobile no' => 'mobile',
            'mobile' => 'mobile',

            'city' => 'city',
            'location' => 'location',
            'qualification' => 'qualification',
            'level of experience' => 'experience_level',
            'gender' => 'gender',
            'resume link' => 'resume_link',
            'profile link' => 'profile_link',
            'current salary' => 'current_salary',
            'course' => 'course',
            'college name' => 'college_name',
            'previous designation' => 'previous_designation',
            'previous company name' => 'previous_company_name',
            'data source' => 'data_source',
        ];

        $imported = 0;
        $skipped = 0;

        foreach ($sheet as $rowIndex => $row) {

            try {

                \Log::info("Processing row: " . ($rowIndex + 2));

                $data = [];

                foreach ($header as $index => $columnName) {

                    if (!isset($columnMap[$columnName])) {
                        continue;
                    }

                    $dbColumn = $columnMap[$columnName];

                    $data[$dbColumn] = trim((string) ($row[$index] ?? ''));
                }

                \Log::info('Mapped Data', $data);

                // Mobile cleanup
                $mobile = preg_replace('/\D/', '', $data['mobile'] ?? '');

                \Log::info('Cleaned Mobile: ' . $mobile);

                if (strlen($mobile) < 10) {

                    \Log::warning('Skipped - Invalid mobile');

                    $skipped++;
                    continue;
                }

                if (strlen($mobile) === 10) {
                    $mobile = '91' . $mobile;
                }

                // duplicate check
                $exists = \App\Models\Candidate::where('mobile', $mobile)->exists();

                if ($exists) {

                    \Log::warning('Skipped - Duplicate mobile: ' . $mobile);

                    $skipped++;
                    continue;
                }

                $data['mobile'] = $mobile;
                $data['status'] = 'unsent';

                \Log::info('Creating Candidate', $data);

                \App\Models\Candidate::create($data);

                \Log::info('Candidate inserted successfully');

                $imported++;

            } catch (\Exception $e) {

                \Log::error('Row Error', [
                    'row' => $rowIndex + 2,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                ]);

                $skipped++;

                continue;
            }
        }

        \Log::info("Import Finished. Imported: {$imported}, Skipped: {$skipped}");

        return response()->json([
            'success' => true,
            'message' => "Imported {$imported}, skipped {$skipped}",
            'imported' => $imported,
            'skipped' => $skipped,
        ]);

    } catch (\Exception $e) {

        \Log::error('Import Fatal Error', [
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}
public function sendWhatsapp(Request $request)
{
    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */
    $request->validate([
        'ids'   => 'required|array',
        'ids.*' => 'integer|exists:candidates,id',
    ]);

    /*
    |--------------------------------------------------------------------------
    | FETCH CANDIDATES
    |--------------------------------------------------------------------------
    */
    $candidates = \App\Models\Candidate::whereIn('id', $request->ids)
        ->select('id', 'mobile', 'send_count')
        ->get();

    $success = 0;
    $failed  = 0;

    /*
    |--------------------------------------------------------------------------
    | LOOP
    |--------------------------------------------------------------------------
    */
    foreach ($candidates as $candidate) {

        try {

            /*
            |--------------------------------------------------------------------------
            | MOBILE FORMAT
            |--------------------------------------------------------------------------
            */
            $mobile = preg_replace('/\D/', '', $candidate->mobile);

            $destination = str_starts_with($mobile, '91')
                ? $mobile
                : '91' . $mobile;

            /*
            |--------------------------------------------------------------------------
            | STEP 1 : OPT-IN API CALL
            |--------------------------------------------------------------------------
            */
            $optinResponse = Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'apikey'       => 'xmzzeoeowfppicbquvp3zupvntzeqh2j',
            ])->asForm()->post(
                'https://api.gupshup.io/sm/api/v1/app/opt/in/Goelectronix',
                [
                    'user' => $destination,
                    'channel' => 'whatsapp'
                ]
            );

            sleep(1);

            $params = [];

            $response = Http::withHeaders([
                'Cache-Control' => 'no-cache',
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'apikey'        => 'xmzzeoeowfppicbquvp3zupvntzeqh2j',
            ])->asForm()->post(
                'https://api.gupshup.io/wa/api/v1/template/msg',
                [
                    'channel'     => 'whatsapp',
                    'source'      => '919372011028',
                    'destination' => $destination,
                    'src.name'    => 'Goelectronix',

                    'template' => json_encode([
                        "id" => "8cd7360b-8f39-43f9-a799-d02ae344cf0e",
                        "params" => $params
                    ], JSON_UNESCAPED_SLASHES),

                    'message' => json_encode([
                        "type" => "image",
                        "image" => [
                            "link" => "https://media.licdn.com/dms/image/v2/D4D0BAQExgePoZh64lg/company-logo_200_200/company-logo_200_200/0/1706707195923/goelectronix_technologies_private_limited_logo?e=2147483647&v=beta&t=x5psH1cSOKyZVaPyjtvnNu6MHvQmPQWowNF2PVBUUps"
                        ]
                    ], JSON_UNESCAPED_SLASHES)
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | RESPONSE DATA
            |--------------------------------------------------------------------------
            */
            $responseData = $response->json();

           
            /*
            |--------------------------------------------------------------------------
            | SUCCESS CHECK
            |--------------------------------------------------------------------------
            */
            if (
                $response->successful() &&
                isset($responseData['status']) &&
                strtolower($responseData['status']) === 'submitted'
            ) {

                $candidate->update([
                    'status'       => 'sent',
                    'last_sent_at' => now(),
                    'send_count'   => ($candidate->send_count ?? 0) + 1
                ]);

                $success++;

            } else {

                $candidate->update([
                    'status'       => 'unsent',
                    'last_sent_at' => now(),
                    'send_count'   => ($candidate->send_count ?? 0) + 1
                ]);

                $failed++;

              
            }

        } catch (\Exception $e) {

            /*
            |--------------------------------------------------------------------------
            | EXCEPTION HANDLING
            |--------------------------------------------------------------------------
            */
            $candidate->update([
                'status'       => 'unsent',
                'last_sent_at' => now(),
                'send_count'   => ($candidate->send_count ?? 0) + 1
            ]);

            $failed++;

         
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FINAL RESPONSE
    |--------------------------------------------------------------------------
    */
    return response()->json([
        'status' => true,
        'message' => 'WhatsApp sending completed',
        'summary' => [
            'total'   => $candidates->count(),
            'success' => $success,
            'failed'  => $failed
        ]
    ]);
}
}
