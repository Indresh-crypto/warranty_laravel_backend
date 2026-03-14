<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TaskAiMessageTemplate;

class TaskAiMessageTemplateController extends Controller
{

    // List all templates
       public function index(Request $request)
    {
        $query = TaskAiMessageTemplate::query();
    
        if ($request->role) {
            $query->where('role', $request->role);
        }
    
        $data = $query->latest()->get();
    
        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }
    // Store new template
    public function store(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'role' => 'required'
        ]);

        $template = TaskAiMessageTemplate::create([
            'message' => $request->message,
            'role' => $request->role
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Template created successfully',
            'data' => $template
        ]);
    }


    // Show single template
    public function show($id)
    {
        $template = TaskAiMessageTemplate::find($id);

        if (!$template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $template
        ]);
    }


    // Update template
    public function update(Request $request, $id)
    {
        $template = TaskAiMessageTemplate::find($id);

        if (!$template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found'
            ], 404);
        }

        $template->update([
            'message' => $request->message,
            'role' => $request->role
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Template updated successfully',
            'data' => $template
        ]);
    }


    // Delete template
    public function destroy($id)
    {
        $template = TaskAiMessageTemplate::find($id);

        if (!$template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found'
            ], 404);
        }

        $template->delete();

        return response()->json([
            'status' => true,
            'message' => 'Template deleted successfully'
        ]);
    }
}