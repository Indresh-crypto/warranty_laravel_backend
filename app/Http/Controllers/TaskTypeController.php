<?php

namespace App\Http\Controllers;

use App\Models\TaskType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TaskTypeController extends Controller
{
    /**
     * Display a listing of the resource, filtered by role.
     */
    public function index(Request $request): JsonResponse
    {
        $taskTypes = TaskType::query()
            ->when($request->filled('role'), function ($query) use ($request) {
                return $query->where('role', $request->role);
            })
            ->get();

        return response()->json($taskTypes, 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:task_types,name',
            'role' => 'required|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'errors'  => $validator->errors()
            ], 422);
        }

        $taskType = TaskType::create($validator->validated());

        return response()->json([
            'message' => 'Task Type created successfully',
            'data'    => $taskType
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(TaskType $taskType): JsonResponse
    {
        return response()->json($taskType, 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, TaskType $taskType): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:task_types,name,' . $taskType->id,
            'role' => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'errors'  => $validator->errors()
            ], 422);
        }

        $taskType->update($validator->validated());

        return response()->json([
            'message' => 'Task Type updated successfully',
            'data'    => $taskType
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TaskType $taskType): JsonResponse
    {
        $taskType->delete();

        return response()->json([
            'message' => 'Task Type deleted successfully'
        ], 200);
    }
}