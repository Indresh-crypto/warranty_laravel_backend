<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskUser;
use App\Services\AiService;
use Illuminate\Http\Request;

class TaskAiController extends Controller
{
    public function ask(Request $request, AiService $ai)
    {
        $request->validate([
            'user_id' => 'required|exists:task_users,id',
            'question' => 'required|string'
        ]);

        $user = TaskUser::find($request->user_id);

        // Collect user data
        $tasks = Task::where('employee_id', $user->id)->get();

        $total = $tasks->count();
        $completed = $tasks->where('status', 3)->count();
        $pending = $tasks->where('status', '!=', 3)->count();
        $todayTasks = $tasks->where('due_date', today())->count();

        // Build Smart Prompt
        $prompt = "
            You are an AI assistant for task management system.
            
            User Name: {$user->name}
            Total Tasks: {$total}
            Completed Tasks: {$completed}
            Pending Tasks: {$pending}
            Today's Tasks: {$todayTasks}
            
            User Question:
            {$request->question}
            
            Rules:
            - Answer only based on the provided data.
            - Be clear and professional.
            ";

        $aiResponse = $ai->ask($prompt);

        return response()->json([
            'status' => true,
            'response' => $aiResponse
        ]);
    }
}