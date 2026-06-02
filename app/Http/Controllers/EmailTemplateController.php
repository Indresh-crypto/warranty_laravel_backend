<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateMapping;
use App\Models\EmailLog;
use App\Services\EmailTemplateService;

class EmailTemplateController extends Controller
{
    protected $service;


    public function index(Request $request)
    {
        try {
    
            $query = EmailTemplate::query();
    
            /*
            |--------------------------------------------------------------------------
            | FILTER: STATUS
            |--------------------------------------------------------------------------
            */
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
    
            /*
            |--------------------------------------------------------------------------
            | SEARCH
            |--------------------------------------------------------------------------
            */
            if ($request->has('search')) {
                $search = $request->search;
    
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%$search%")
                      ->orWhere('subject', 'LIKE', "%$search%");
                });
            }
    
            /*
            |--------------------------------------------------------------------------
            | WITH MAPPINGS (optional)
            |--------------------------------------------------------------------------
            */
            if ($request->has('with_mappings') && $request->with_mappings == 1) {
                $query->with('mappings');
            }
    
            /*
            |--------------------------------------------------------------------------
            | PAGINATION
            |--------------------------------------------------------------------------
            */
            $perPage = $request->per_page ?? 10;
    
            $templates = $query->latest()->paginate($perPage);
    
            return response()->json([
                'status' => true,
                'data' => $templates
            ]);
    
        } catch (\Exception $e) {
    
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function __construct(EmailTemplateService $service)
    {
        $this->service = $service;
    }

    public function destroy($id)
    {
        EmailTemplate::findOrFail($id)->delete();
    
        return response()->json([
            'status' => true,
            'message' => 'Template deleted'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | COMMON VALIDATION RESPONSE
    |--------------------------------------------------------------------------
    */
    private function validationError($validator)
    {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. CREATE TEMPLATE
    |--------------------------------------------------------------------------
    */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'status' => 'nullable|in:draft,active,inactive'
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $template = EmailTemplate::create([
            'name' => $request->name,
            'subject' => $request->subject,
            'body' => $request->body,
            'status' => $request->status ?? 'draft'
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Template created',
            'data' => $template
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. UPDATE TEMPLATE
    |--------------------------------------------------------------------------
    */
    public function update(Request $request, $id)
    {
        $template = EmailTemplate::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'subject' => 'sometimes|string|max:255',
            'body' => 'sometimes|string',
            'status' => 'nullable|in:draft,active,inactive'
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $template->update($request->only('name', 'subject', 'body', 'status'));

        return response()->json([
            'status' => true,
            'message' => 'Template updated',
            'data' => $template
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. CHANGE STATUS
    |--------------------------------------------------------------------------
    */
    public function changeStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,active,inactive'
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $template = EmailTemplate::findOrFail($id);
        $template->update(['status' => $request->status]);

        return response()->json([
            'status' => true,
            'message' => 'Status updated',
            'data' => $template
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. ADD SINGLE MAPPING
    |--------------------------------------------------------------------------
    */
    public function addMapping(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_id' => 'required|exists:email_templates,id',
            'placeholder' => 'required|string|max:255',
            'table_name' => 'required|string|max:255',
            'column_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $allowedTables = ['users', 'orders'];

        if (!in_array($request->table_name, $allowedTables)) {
            return response()->json([
                'status' => false,
                'message' => 'Table not allowed'
            ], 403);
        }

        if (!Schema::hasColumn($request->table_name, $request->column_name)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid column'
            ], 400);
        }

        $mapping = EmailTemplateMapping::updateOrCreate(
            [
                'template_id' => $request->template_id,
                'placeholder' => $request->placeholder
            ],
            [
                'table_name' => $request->table_name,
                'column_name' => $request->column_name
            ]
        );

        return response()->json([
            'status' => true,
            'data' => $mapping
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. BULK MAPPING
    |--------------------------------------------------------------------------
    */
    public function bulkMapping(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_id' => 'required|exists:email_templates,id',
            'mappings' => 'required|array|min:1',
            'mappings.*.placeholder' => 'required|string|max:255',
            'mappings.*.table_name' => 'required|string|max:255',
            'mappings.*.column_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        DB::beginTransaction();

        try {

            $allowedTables = ['users', 'orders'];

            $placeholders = array_column($request->mappings, 'placeholder');

            if (count($placeholders) !== count(array_unique($placeholders))) {
                throw new \Exception("Duplicate placeholders found");
            }

            $savedMappings = [];

            foreach ($request->mappings as $map) {

                if (!in_array($map['table_name'], $allowedTables)) {
                    throw new \Exception("Table not allowed: " . $map['table_name']);
                }

                if (!Schema::hasColumn($map['table_name'], $map['column_name'])) {
                    throw new \Exception("Invalid column: " . $map['column_name']);
                }

                $mapping = EmailTemplateMapping::updateOrCreate(
                    [
                        'template_id' => $request->template_id,
                        'placeholder' => $map['placeholder'],
                    ],
                    [
                        'table_name' => $map['table_name'],
                        'column_name' => $map['column_name'],
                    ]
                );

                $savedMappings[] = $mapping;
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Bulk mappings saved',
                'data' => $savedMappings
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 6. PREVIEW TEMPLATE
    |--------------------------------------------------------------------------
    */
    public function preview($id)
    {
        $template = EmailTemplate::with('mappings')->findOrFail($id);

        $parsed = $this->service->parseTemplate($template);

        return response()->json([
            'status' => true,
            'data' => $parsed
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. SEND TEST EMAIL
    |--------------------------------------------------------------------------
    */
    public function sendTest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_id' => 'required|exists:email_templates,id',
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $template = EmailTemplate::with('mappings')->findOrFail($request->template_id);

        if ($template->status !== 'active') {
            return response()->json([
                'status' => false,
                'message' => 'Template is not active'
            ], 400);
        }

        $parsed = $this->service->parseTemplate($template);

        try {

            Mail::send([], [], function ($message) use ($request, $parsed) {
                $message->to($request->email)
                    ->subject($parsed['subject'])
                    ->html($parsed['body']);
            });

            EmailLog::create([
                'template_id' => $template->id,
                'to_email' => $request->email,
                'subject' => $parsed['subject'],
                'body' => $parsed['body'],
                'status' => 'sent',
                'response' => 'Email sent successfully'
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Email sent'
            ]);

        } catch (\Exception $e) {

            EmailLog::create([
                'template_id' => $template->id,
                'to_email' => $request->email,
                'subject' => $parsed['subject'],
                'body' => $parsed['body'],
                'status' => 'failed',
                'response' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    public function getTables()
    {
    try {

        $tables = DB::select('SHOW TABLES');

        $dbName = env('DB_DATABASE');
        $key = "Tables_in_" . $dbName;

        $tableList = [];

        foreach ($tables as $table) {
            $tableList[] = $table->$key;
        }

        /*
        |--------------------------------------------------------------------------
        | FILTER SYSTEM TABLES (IMPORTANT)
        |--------------------------------------------------------------------------
        */
        $exclude = [
            'migrations',
            'password_resets',
            'failed_jobs',
            'personal_access_tokens'
        ];

        $tableList = array_values(array_diff($tableList, $exclude));

        return response()->json([
            'status' => true,
            'data' => $tableList
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ]);
    }
}

    public function getColumns($table)
    {
        try {
    
            /*
            |--------------------------------------------------------------------------
            | SECURITY: ALLOW ONLY SAFE TABLES
            |--------------------------------------------------------------------------
            */
            $allowedTables = ['users', 'orders'];
    
            if (!in_array($table, $allowedTables)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Table not allowed'
                ], 403);
            }
    
            if (!Schema::hasTable($table)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Table not found'
                ], 404);
            }
    
            $columns = Schema::getColumnListing($table);
    
            return response()->json([
                'status' => true,
                'data' => $columns
            ]);
    
        } catch (\Exception $e) {
    
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    public function mappedTemplates(Request $request)
{
    try {

        $query = EmailTemplate::query();

        /*
        |--------------------------------------------------------------------------
        | ONLY TEMPLATES WHICH HAVE MAPPINGS
        |--------------------------------------------------------------------------
        */
        $query->whereHas('mappings');

        /*
        |--------------------------------------------------------------------------
        | OPTIONAL: FILTER BY STATUS
        |--------------------------------------------------------------------------
        */
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        /*
        |--------------------------------------------------------------------------
        | OPTIONAL: SEARCH
        |--------------------------------------------------------------------------
        */
        if ($request->has('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%$search%")
                  ->orWhere('subject', 'LIKE', "%$search%");
            });
        }

        /*
        |--------------------------------------------------------------------------
        | LOAD MAPPINGS
        |--------------------------------------------------------------------------
        */
        $query->with('mappings');

        /*
        |--------------------------------------------------------------------------
        | PAGINATION
        |--------------------------------------------------------------------------
        */
        $perPage = $request->per_page ?? 10;

        $templates = $query->latest()->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $templates
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ]);
    }
}

public function track($id)

{

    $log = EmailLog::where('track_id', $id)->first();

    if ($log && !$log->opened_at) {

        $log->opened_at = now();

        $log->save();

    }

    // return 1x1 pixel

    return response(base64_decode(

        'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='

    ))->header('Content-Type', 'image/gif');

}
}