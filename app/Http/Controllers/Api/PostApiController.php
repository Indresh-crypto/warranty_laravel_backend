<?php
namespace App\Http\Controllers\Api;

use App\Models\PostCreateProject;
use App\Models\PostApi;
use App\Models\PostApiParam;
use App\Models\PostApiBody;
use App\Models\PostProjectHeader;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\PostUser;
use App\Models\PostProjectUser;
use App\Models\PostHistory;

class PostApiController extends BaseController
{
    public function index()
    {
    
        return response()->json(
            PostApi::with('params')->get()
        );
    }



    public function store(Request $request)
    {
        // Validation rules
        $rules = [
            'api_name'   => 'required|string|max:255',
            'api_type'   => 'required|string|max:255',
            'end_point'  => 'required|string',
            'modal_name' => 'required|string|max:255',
    
            'params'               => 'nullable|array',
            'params.*.param_key'   => 'nullable|string',
            'params.*.data_type'   => 'nullable|string',
            'params.*.description' => 'nullable|string',
    
            'post_body'                => 'nullable|array',
            'post_body.*.key_name'     => 'nullable|string',
            'post_body.*.key_type'     => 'nullable|string',
            'post_body.*.file'         => 'nullable',
            'post_body.*.description'  => 'nullable|string',
            'post_body.*.data_value'   => 'nullable',
            'post_body.*.is_required'  => 'boolean',
    
            'project_id' => 'required',
            'company_id' => 'nullable',
            'module_id'  => 'nullable',
            'body_type'  => 'nullable',
            'raw_type'   => 'nullable',
            'raw_body'   => ['nullable', function ($attribute, $value, $fail) {
                if (!empty($value)) {
                    // Convert array to JSON if needed
                    if (is_array($value)) {
                        $value = json_encode($value);
                    }
    
                    // Convert single quotes to double quotes if frontend sent invalid JSON
                    if (is_string($value) && strpos($value, "'") !== false) {
                        $value = str_replace("'", '"', $value);
                    }
    
                    // Validate JSON
                    json_decode($value);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $fail("The {$attribute} must be a valid JSON.");
                    }
                }
            }],
        ];
    
        // Validate request
        $validator = Validator::make($request->all(), $rules);
    
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }
    
        $validated = $validator->validated();
    
        // Prepare raw_body
        $rawBody = $validated['raw_body'] ?? null;
        if (is_array($rawBody)) {
            $rawBody = json_encode($rawBody);
        }
        if (is_string($rawBody)) {
            $rawBody = str_replace("'", '"', $rawBody);
        }
    
        // Create main PostApi record
        $postApi = PostApi::create([
            'api_name'   => $validated['api_name'],
            'api_type'   => $validated['api_type'],
            'end_point'  => $validated['end_point'],
            'modal_name' => $validated['modal_name'],
            'project_id' => $validated['project_id'],
            'module_id'  => $validated['module_id'] ?? null,
            'body_type'  => $validated['body_type'] ?? null,
            'raw_type'   => $validated['raw_type'] ?? null,
            'raw_body'   => $validated['raw_body'] ?? null,
        ]);
    
        // Insert Params if available
        if (!empty($validated['params'])) {
            foreach ($validated['params'] as $param) {
                $postApi->params()->create($param);
            }
        }
    
        // Insert Post Body if available
        if (!empty($validated['post_body'])) {
            foreach ($validated['post_body'] as $body) {
                
    
                $postApi->bodies()->create($body);
            }
        }
    
        // Return response with related data
        return response()->json($postApi->load(['params', 'bodies']), 201);
    }
    
   public function update(Request $request, $id)
   {
    // Find the existing record
    $postApi = PostApi::findOrFail($id);

    // Validation rules (same as store)
    $rules = [
        'api_name'   => 'required|string|max:255',
        'api_type'   => 'required|string|max:255',
        'end_point'  => 'required|string',
        'modal_name' => 'required|string|max:255',

        'params'               => 'nullable|array',
        'params.*.param_key'   => 'nullable|string',
        'params.*.data_type'   => 'nullable|string',
        'params.*.description' => 'nullable|string',

        'post_body'               => 'nullable|array',
        'post_body.*.key_name'    => 'nullable|string',
        'post_body.*.key_type'    => 'nullable|string',
        'post_body.*.file'        => 'nullable',
        'post_body.*.description' => 'nullable|string',
        'post_body.*.data_value'  => 'nullable',
        'post_body.*.is_required' => 'boolean',

        'project_id' => 'required',
        'company_id' => 'nullable',
        'module_id'  => 'nullable',
        'body_type'  => 'nullable',
        'raw_type'   => 'nullable',
        'raw_body'   => ['nullable', function ($attribute, $value, $fail) {
            if (!empty($value)) {
                if (is_array($value)) {
                    $value = json_encode($value);
                }

                if (is_string($value) && strpos($value, "'") !== false) {
                    $value = str_replace("'", '"', $value);
                }

                json_decode($value);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $fail("The {$attribute} must be a valid JSON.");
                }
            }
        }],
    ];

    $validator = Validator::make($request->all(), $rules);

    if ($validator->fails()) {
        return response()->json([
            'errors' => $validator->errors()
        ], 422);
    }

    $validated = $validator->validated();

    // Prepare raw_body
    $rawBody = $validated['raw_body'] ?? null;
    if (is_array($rawBody)) {
        $rawBody = json_encode($rawBody);
    }
    if (is_string($rawBody)) {
        $rawBody = str_replace("'", '"', $rawBody);
    }

    // Update main PostApi record
    $postApi->update([
        'api_name'   => $validated['api_name'],
        'api_type'   => $validated['api_type'],
        'end_point'  => $validated['end_point'],
        'modal_name' => $validated['modal_name'],
        'project_id' => $validated['project_id'],
        'module_id'  => $validated['module_id'] ?? null,
        'body_type'  => $validated['body_type'] ?? null,
        'raw_type'   => $validated['raw_type'] ?? null,
        'raw_body'   => $rawBody ?? null,
    ]);

    // Update Params: delete existing and insert new
    $postApi->params()->delete();
    if (!empty($validated['params'])) {
        foreach ($validated['params'] as $param) {
            $postApi->params()->create($param);
        }
    }

    // Update Post Body: delete existing and insert new
    $postApi->bodies()->delete();
    if (!empty($validated['post_body'])) {
        foreach ($validated['post_body'] as $body) {
            $postApi->bodies()->create($body);
        }
    }

    // Return response with updated related data
    return response()->json($postApi->load(['params', 'bodies']), 200);
}
    
    public function show($id)
    {
        // Load API with params and bodies
        $postApi = PostApi::with(['params', 'bodies'])->find($id);
    
        if (!$postApi) {
            return response()->json(['error' => 'PostApi not found'], 404);
        }
    
        // Try to load related project (optional)
        $projectDetails = null;
        if ($postApi->project_id) {
            $projectDetails = PostCreateProject::with('headers')->find($postApi->project_id);
        }
    
        // Attach headers if project exists
        $postApi->headers = $projectDetails->headers ?? [];
    
        // Attach project details if exists
        $postApi->project_details = $projectDetails ?? null;
    
        return response()->json($postApi);
    }
  
   public function destroy($id)
    {
        // Find the PostApi by ID
        $postApi = PostApi::find($id);
    
        if (!$postApi) {
            return response()->json(['message' => 'PostApi not found'], 404);
        }
    
        // Optionally delete related params and bodies manually
        if ($postApi->params()->exists()) {
            $postApi->params()->delete();
        }
    
        if ($postApi->bodies()->exists()) {
            $postApi->bodies()->delete();
        }
    
        // Delete the main PostApi record
        $postApi->delete();
    
        return response()->json(['message' => 'PostApi deleted successfully']);
    }
    
    public function getByModuleId($moduleId)
    {
        // Load all APIs under a given module with params and bodies
        $apis = PostApi::with(['params', 'bodies'])
            ->where('module_id', $moduleId)
            ->get();
    
        if ($apis->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No APIs found for this module',
            ], 404);
        }
    
        // Get the project details for this module (assuming PostModule has project_id)
        $module = \App\Models\PostModule::with('project')->find($moduleId);
    
        if (!$module) {
            return response()->json([
                'status' => false,
                'message' => 'Module not found',
            ], 404);
        }
    
        // Load the project with its headers
        $project = \App\Models\PostCreateProject::with('headers')->find($module->project_id);
    
        // Attach headers and project info to each API
        $apis->transform(function ($api) use ($project) {
            $api->headers = $project ? $project->headers : [];
            $api->project_details = $project;
            return $api;
        });
    
        return response()->json([
            'status'  => true,
            'message' => 'APIs retrieved successfully for this module',
            'data'    => $apis,
        ], 200);
    }
   public function history(Request $request)
   {
    $perPage = $request->get('per_page', 10);

    // Start query
    $query = PostHistory::query();

    // Filter by user_id (exact match)
    if ($request->filled('user_id')) {
        $query->where('user_id', $request->user_id);
    }

    // Filter by api_name (partial match)
    if ($request->filled('api_name')) {
        $query->where('api_name', 'like', '%' . $request->api_name . '%');
    }

    // Optional sorting (default: newest first)
    $query->orderBy('id', 'desc');

    // Paginate results
    $postHistory = $query->paginate($perPage);

    return response()->json([
        'status' => 'success',
        'message' => 'Post history fetched successfully.',
        'data' => $postHistory
    ]);
}
    
     public function storeHistory(Request $request)
    {
        // Validate request data
        $validated = $request->validate([
            'api_id' => 'required|string|max:255',
            'api_name' => 'required|string|max:255',
            'project_id' => 'nullable|integer',
            'base_url' => 'nullable|string',
            'end_point' => 'nullable|string',
            'params' => 'nullable|string',
            'payload' => 'nullable|string',
            'api_type' => 'nullable|string|max:50',
            'resposne_code' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'user_name' =>'required',
            'project_name' =>'required'
        ]);

        // Save data using mass assignment
        $postHistory = PostHistory::create($validated);

        // Return success JSON response
        return response()->json([
            'message' => 'Post history saved successfully',
            'data' => $postHistory
        ], 201);
    }
}