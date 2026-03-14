<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskRemark;
use App\Models\TaskUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class TaskController extends Controller
{
    /**
     * =============================
     * CREATE TASK (Manager Only)
     * =============================
     */
   public function createTask(Request $request)
   {
    // Convert extra_data if sent as JSON string (form-data case)
    if ($request->filled('extra_data') && is_string($request->extra_data)) {
        $decoded = json_decode($request->extra_data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $request->merge(['extra_data' => $decoded]);
        }
    }

    $validator = Validator::make($request->all(), [
        'company_id'  => 'required|integer',
        'manager_id'  => 'nullable',
        'employee_id' => 'required|exists:task_users,id',
        'title'       => 'required|string|max:255',
        'description' => 'nullable|string',
        'priority'    => 'nullable|in:Low,Medium,High',
        'due_date'    => 'nullable|date',
        'extra_data'  => 'nullable|array',
        'attachment'  => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();

    try {

        // Upload attachment if exists
        $filePath = null;

        if ($request->hasFile('attachment')) {

            $folder = public_path('task_attachments');

            if (!file_exists($folder)) {
                mkdir($folder, 0755, true);
            }

            $fileName = time() . '_task.' . $request->file('attachment')->extension();
            $request->file('attachment')->move($folder, $fileName);

            $filePath = 'task_attachments/' . $fileName;
        }

            $task = Task::create([
                'company_id'  => $request->company_id,
                'manager_id'  => $request->filled('manager_id') ? $request->manager_id : null,
                'employee_id' => $request->employee_id,
                'title'       => $request->title,
                'description' => $request->description,
                'priority'    => $request->priority ?? 'Medium',
                'due_date'    => $request->due_date,
                'attachment'  => $filePath,
                'extra_data'  => $request->extra_data,
                'status'      => 1,
                'created_by'  => $request->created_by
            ]);

        DB::commit();

        return response()->json([
            'status'  => true,
            'message' => 'Task Created Successfully',
            'data'    => $task
        ]);

    } catch (\Exception $e) {

        DB::rollback();

        return response()->json([
            'status'  => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

public function listTasks(Request $request)
{

    // Convert query string booleans
    $request->merge([
        'due_today' => filter_var($request->due_today, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        'due_tomorrow' => filter_var($request->due_tomorrow, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        'overdue' => filter_var($request->overdue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        'has_attachment' => filter_var($request->has_attachment, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
    ]);

    $validator = Validator::make($request->all(), [
        'id'              => 'nullable|integer',
        'company_id'      => 'nullable|integer',
        'manager_id'      => 'nullable|integer',
        'employee_id'     => 'nullable|integer',
        'created_by'      => 'nullable|integer',
        'user_id'         => 'nullable|integer',

        'task_type'       => 'nullable|in:self_assigned,assigned_to_me,assigned_to_others',

        'status'          => 'nullable|in:1,2,3',
        'priority'        => 'nullable|in:Low,Medium,High',

        'due_today'       => 'nullable|boolean',
        'due_tomorrow'    => 'nullable|boolean',
        'overdue'         => 'nullable|boolean',

        'from_due_date'   => 'nullable|date',
        'to_due_date'     => 'nullable|date',

        'search'          => 'nullable|string',
        'has_attachment'  => 'nullable|boolean',
        'deleted'         => 'nullable|in:1,2',

        'per_page'        => 'nullable|integer|min:1|max:100'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    $query = Task::with([
        'manager:id,name,picture',
        'employee:id,name,picture',
        'creator:id,name,picture',
        'remarks.user:id,name,picture'
    ]);

    /*
    |--------------------------------------------------------------------------
    | Single Task
    |--------------------------------------------------------------------------
    */

    if ($request->filled('id')) {

        $task = $query->find($request->id);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task Not Found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Task Retrieved Successfully',
            'data' => $task
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Task Type Filters
    |--------------------------------------------------------------------------
    */

    if ($request->filled('task_type') && $request->filled('user_id')) {

        $userId = $request->user_id;

        if ($request->task_type == 'self_assigned') {

            $query->where('employee_id', $userId)
                  ->where('created_by', $userId);

        } elseif ($request->task_type == 'assigned_to_me') {

            $query->where('employee_id', $userId)
                  ->where('created_by', '!=', $userId);

        } elseif ($request->task_type == 'assigned_to_others') {

            $query->where('created_by', $userId)
                  ->where('employee_id', '!=', $userId);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Basic Filters
    |--------------------------------------------------------------------------
    */

    if ($request->filled('company_id')) {
        $query->where('company_id', $request->company_id);
    }

   if ($request->filled('manager_id')) {
     
        $query->where(function ($q) use ($request) {
            $q->where('created_by', $request->manager_id)
              ->orWhere('employee_id', $request->manager_id);
        });
    }

    if ($request->filled('created_by')) {
        $query->where('created_by', $request->created_by);
    }

    if ($request->filled('employee_id')) {
        $query->where('employee_id', $request->employee_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Status & Priority
    |--------------------------------------------------------------------------
    */

    if ($request->filled('status')) {
        $query->where('status', $request->status);
    }

    if ($request->filled('priority')) {
        $query->where('priority', $request->priority);
    }

    /*
    |--------------------------------------------------------------------------
    | Due Date Filters
    |--------------------------------------------------------------------------
    */

    if ($request->due_today) {
        $query->whereDate('due_date', now()->toDateString());
    }

    if ($request->due_tomorrow) {
        $query->whereDate('due_date', now()->addDay()->toDateString());
    }

    if ($request->overdue) {
        $query->whereDate('due_date', '<', now()->toDateString())
              ->where('status', '!=', 3);
    }


       if ($request->filled('from_due_date') && $request->filled('to_due_date')) {

    $query->whereDate('due_date', '>=', $request->from_due_date)
          ->whereDate('due_date', '<=', $request->to_due_date);
}

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    if ($request->filled('search')) {

        $search = $request->search;

        $query->where(function ($q) use ($search) {

            $q->where('title', 'like', "%$search%")
              ->orWhere('description', 'like', "%$search%");
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Attachment Filter
    |--------------------------------------------------------------------------
    */

    if ($request->filled('has_attachment')) {

        if ($request->has_attachment == 1) {
            $query->whereNotNull('attachment');
        } else {
            $query->whereNull('attachment');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Soft Delete
    |--------------------------------------------------------------------------
    */

    if ($request->filled('deleted')) {

        if ($request->deleted == 1) {
            $query->onlyTrashed();
        } elseif ($request->deleted == 2) {
            $query->withTrashed();
        }
    }

    $perPage = $request->per_page ?? 10;

    $tasks = $query->orderByDesc('id')->paginate($perPage);

    return response()->json([
        'status' => true,
        'message' => 'Task List Retrieved Successfully',
        'data' => $tasks
    ]);
}
    /**
     * =============================
     * UPDATE TASK
     * =============================
     */
    public function updateTask(Request $request, $id)
    {
        $task = Task::find($id);
    
        if (!$task) {
            return response()->json([
                'status'  => false,
                'message' => 'Task Not Found'
            ], 404);
        }
    
        $validator = Validator::make($request->all(), [
            'title'       => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'nullable|in:1,2,3',
            'priority'    => 'nullable|in:Low,Medium,High',
            'due_date'    => 'nullable|date'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()
            ], 422);
        }
    
        $data = $request->only([
            'title',
            'description',
            'status',
            'priority',
            'due_date'
        ]);
    
        /*
        |--------------------------------------------------------------------------
        | Handle Task Completion
        |--------------------------------------------------------------------------
        */
    
        if ($request->status == 3) {
            $data['completed_at'] = now();
        }
    
        $task->update($data);
    
        return response()->json([
            'status'  => true,
            'message' => $request->status == 3 ? 'Task Completed Successfully' : 'Task Updated Successfully',
            'data'    => $task
        ]);
    }
    public function addRemark(Request $request)
    {
        // ✅ Convert JSON string to array (for form-data support)
        if ($request->filled('extra_data') && is_string($request->extra_data)) {
            $decoded = json_decode($request->extra_data, true);
    
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge(['extra_data' => $decoded]);
            }
        }
    
        $validator = Validator::make($request->all(), [
            'task_id'        => 'required|exists:tasks,id',
            'user_id'        => 'required|exists:task_users,id',
            'remark'         => 'nullable|string',
            'follow_up_date' => 'nullable|date',
            'extra_data'     => 'nullable|array',
            'attachment'     => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()
            ], 422);
        }
    
        DB::beginTransaction();
    
        try {
    
            // ✅ Check Task Exists
            $task = Task::find($request->task_id);
    
            if (!$task) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Task Not Found'
                ], 404);
            }
    
            // ✅ Optional: Restrict remark only by assigned employee
            if ($task->employee_id != $request->user_id) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You are not assigned to this task'
                ], 403);
            }
    
            // ✅ Handle File Upload
            $filePath = null;
    
            if ($request->hasFile('attachment')) {
    
                $folder = public_path('task_remarks');
    
                if (!file_exists($folder)) {
                    mkdir($folder, 0755, true);
                }
    
                $fileName = time() . '_remark.' . $request->file('attachment')->extension();
    
                $request->file('attachment')->move($folder, $fileName);
    
                $filePath = 'task_remarks/' . $fileName;
            }
    
            // ✅ Create Remark
            $remark = TaskRemark::create([
                'task_id'        => $request->task_id,
                'user_id'        => $request->user_id,
                'remark'         => $request->remark,
                'follow_up_date' => $request->follow_up_date,
                'extra_data'     => $request->extra_data,
                'attachment'     => $filePath
            ]);
    
            DB::commit();
    
            return response()->json([
                'status'  => true,
                'message' => 'Remark Added Successfully',
                'data'    => $remark
            ]);
    
        } catch (\Exception $e) {
    
            DB::rollback();
    
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


public function taskDashboard(Request $request)
{
    $validator = Validator::make($request->all(), [
        'user_id' => 'required|exists:task_users,id'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    $user = TaskUser::find($request->user_id);
    $today = Carbon::today();

    // Base Query (Role Based)
    $query = Task::query();

    if ($user->role == 3) {
        // Manager → tasks created by him
        $query->where('manager_id', $user->id);
    } elseif ($user->role == 4) {
        // Employee → tasks assigned to him
        $query->where('employee_id', $user->id);
    }
    // Admin & Company → see all tasks

    $totalTasks = (clone $query)->count();

    $completedTasks = (clone $query)
        ->where('status', 3)
        ->count();

    $todayPending = (clone $query)
        ->whereDate('due_date', $today)
        ->where('status', '!=', 3)
        ->count();

    $todayCompleted = (clone $query)
        ->whereDate('due_date', $today)
        ->where('status', 3)
        ->count();

    $assignedToMe = Task::where('employee_id', $user->id)->count();

    $assignedCompleted = Task::where('employee_id', $user->id)
        ->where('status', 3)
        ->count();

    $selfAssigned = Task::where('manager_id', $user->id)
        ->where('employee_id', $user->id)
        ->count();

    $selfCompleted = Task::where('manager_id', $user->id)
        ->where('employee_id', $user->id)
        ->where('status', 3)
        ->count();

    return response()->json([
        'status' => true,
        'message' => 'Dashboard Data Retrieved Successfully',
        'data' => [
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'today_pending' => $todayPending,
            'today_completed' => $todayCompleted,
            'assigned_to_me' => $assignedToMe,
            'assigned_completed' => $assignedCompleted,
            'self_assigned' => $selfAssigned,
            'self_completed' => $selfCompleted
        ]
    ]);
}

    public function markAsRead(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:task_users,id'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()
            ], 422);
        }
    
        $task = Task::find($id);
    
        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task Not Found'
            ], 404);
        }
    
        // Only assigned employee can mark as read
        if ($task->employee_id != $request->user_id) {
            return response()->json([
                'status' => false,
                'message' => 'You are not assigned to this task'
            ], 403);
        }
    
        // Update read timestamp
        $task->update([
            'employee_read_at' => now()
        ]);
    
        return response()->json([
            'status' => true,
            'message' => 'Task marked as read',
            'read_at' => $task->employee_read_at
        ]);
    }
    public function taskChart(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id'  => 'nullable|integer',
        'employee_id' => 'nullable|integer',
        'manager_id'  => 'nullable|integer',
        'created_by'  => 'nullable|integer',
        'team_id'     => 'nullable|integer',
        'year'        => 'nullable|integer',
        'from_date'   => 'nullable|date',
        'to_date'     => 'nullable|date|after_or_equal:from_date'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    $query = Task::query();

    /*
    |--------------------------------------------------------------------------
    | Dynamic Filters
    |--------------------------------------------------------------------------
    */

    if ($request->filled('company_id')) {
        $query->where('company_id', $request->company_id);
    }

    if ($request->filled('employee_id')) {
        $query->where('employee_id', $request->employee_id);
    }

    if ($request->filled('manager_id')) {
        $query->where('manager_id', $request->manager_id);
    }

    if ($request->filled('created_by')) {
        $query->where('created_by', $request->created_by);
    }

    if ($request->filled('team_id')) {
        $query->whereHas('employee', function ($q) use ($request) {
            $q->where('team_id', $request->team_id);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | SUMMARY BLOCK
    |--------------------------------------------------------------------------
    */

    $summaryQuery = clone $query;

    $summary = [
        'total'     => (clone $summaryQuery)->count(),
        'completed' => (clone $summaryQuery)->where('status', 3)->count(),
        'pending'   => (clone $summaryQuery)->where('status', 1)->count(),
        'overdue'   => (clone $summaryQuery)
                            ->where('status', '!=', 3)
                            ->whereDate('due_date', '<', now())
                            ->count(),
        'due_today' => (clone $summaryQuery)
                            ->whereDate('due_date', now())
                            ->count()
    ];

    /*
    |--------------------------------------------------------------------------
    | MONTHLY CHART (If year provided or default current year)
    |--------------------------------------------------------------------------
    */

    $year = $request->year ?? now()->year;

    $monthlyChart = [];

    for ($month = 1; $month <= 12; $month++) {

        $monthQuery = (clone $query)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month);

        $monthlyChart[] = [
            'month'     => date('M', mktime(0, 0, 0, $month, 1)),
            'total'     => (clone $monthQuery)->count(),
            'completed' => (clone $monthQuery)->where('status', 3)->count(),
            'pending'   => (clone $monthQuery)->where('status', 1)->count(),
            'overdue'   => (clone $monthQuery)
                                ->where('status', '!=', 3)
                                ->whereDate('due_date', '<', now())
                                ->count(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | DAILY CHART (If from_date & to_date provided)
    |--------------------------------------------------------------------------
    */

    $dailyChart = [];

    if ($request->filled('from_date') && $request->filled('to_date')) {

        $start = Carbon::parse($request->from_date);
        $end   = Carbon::parse($request->to_date);

        while ($start <= $end) {

            $dayQuery = (clone $query)
                ->whereDate('created_at', $start->toDateString());

            $dailyChart[] = [
                'date'      => $start->toDateString(),
                'total'     => (clone $dayQuery)->count(),
                'completed' => (clone $dayQuery)->where('status', 3)->count(),
                'pending'   => (clone $dayQuery)->where('status', 1)->count(),
                'overdue'   => (clone $dayQuery)
                                    ->where('status', '!=', 3)
                                    ->whereDate('due_date', '<', now())
                                    ->count(),
            ];

            $start->addDay();
        }
    }

    return response()->json([
        'status' => true,
        'message' => 'Task Chart Data Retrieved Successfully',
        'summary' => $summary,
        'monthly_chart' => $monthlyChart,
        'daily_chart' => $dailyChart
    ]);
}

public function managerTaskStats(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer|exists:task_users,id',
        'from_date'  => 'nullable|date',
        'to_date'    => 'nullable|date'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    $query = Task::query()
        ->join('task_users', 'task_users.id', '=', 'tasks.manager_id')
        ->where('tasks.company_id', $request->company_id)
        ->where('task_users.role', 3); // role 3 = manager

    /*
    |--------------------------------------------------------------------------
    | Optional Date Filter
    |--------------------------------------------------------------------------
    */

    if ($request->filled('from_date') && $request->filled('to_date')) {
        $query->whereBetween('tasks.created_at', [
            $request->from_date . ' 00:00:00',
            $request->to_date . ' 23:59:59'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Manager Stats
    |--------------------------------------------------------------------------
    */

    $stats = $query->selectRaw("
            task_users.id as manager_id,
            task_users.name as manager_name,
            COUNT(tasks.id) as total_tasks,
            SUM(CASE WHEN tasks.status = 1 THEN 1 ELSE 0 END) as pending_tasks,
            SUM(CASE WHEN tasks.status = 3 THEN 1 ELSE 0 END) as completed_tasks
        ")
        ->groupBy('task_users.id', 'task_users.name')
        ->orderByDesc('total_tasks')
        ->get();

    return response()->json([
        'status' => true,
        'message' => 'Manager Task Stats Retrieved Successfully',
        'data' => $stats
    ]);
}

public function teamMemberPerformance(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer',
        'manager_id' => 'nullable|integer',
        'team_id'    => 'nullable|integer',
        'from_date'  => 'nullable|date',
        'to_date'    => 'nullable|date|after_or_equal:from_date'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    $query = Task::query()
        ->join('task_users', 'task_users.id', '=', 'tasks.employee_id')
        ->where('tasks.company_id', $request->company_id);

    /*
    |--------------------------------------------------------------------------
    | Manager Team Filter (Employees reporting to manager)
    |--------------------------------------------------------------------------
    */

    if ($request->filled('manager_id')) {

        $employeeIds = TaskUser::where('report_to', $request->manager_id)
                        ->pluck('id');

        $query->whereIn('tasks.employee_id', $employeeIds);
    }

    /*
    |--------------------------------------------------------------------------
    | Team Filter
    |--------------------------------------------------------------------------
    */

    if ($request->filled('team_id')) {
        $query->where('task_users.team_id', $request->team_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Date Filter
    |--------------------------------------------------------------------------
    */

    if ($request->filled('from_date') && $request->filled('to_date')) {
        $query->whereBetween('tasks.created_at', [
            $request->from_date . ' 00:00:00',
            $request->to_date . ' 23:59:59'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Performance Stats
    |--------------------------------------------------------------------------
    */

    $stats = $query->selectRaw("
            task_users.id as employee_id,
            task_users.name as employee_name,
            task_users.picture as picture,
            COUNT(tasks.id) as total_tasks,

            SUM(CASE WHEN tasks.status = 1 THEN 1 ELSE 0 END) as pending_tasks,

            SUM(CASE WHEN tasks.status = 3 THEN 1 ELSE 0 END) as completed_tasks,

            ROUND(
                (SUM(CASE WHEN tasks.status = 3 THEN 1 ELSE 0 END) 
                / NULLIF(COUNT(tasks.id),0)) * 100, 2
            ) as performance_percentage,

            ROUND(AVG(
                CASE 
                    WHEN tasks.status = 3 AND tasks.completed_at IS NOT NULL
                    THEN TIMESTAMPDIFF(HOUR, tasks.created_at, tasks.completed_at)
                END
            ),2) as avg_completion_hours
        ")
        ->groupBy('task_users.id', 'task_users.name', 'task_users.picture')
        ->orderByDesc('performance_percentage')
        ->get();

    return response()->json([
        'status' => true,
        'message' => 'Team Member Performance Retrieved Successfully',
        'data' => $stats
    ]);
}

public function taskCalendarSummary(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer',
        'user_id'    => 'required|integer',
        'role'       => 'required|integer',
        'from_date'  => 'required|date',
        'to_date'    => 'required|date|after_or_equal:from_date'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    $query = Task::query()
        ->where('company_id', $request->company_id)
        ->whereNotNull('due_date');

    /*
    |--------------------------------------------------------------------------
    | Role Based Filtering
    |--------------------------------------------------------------------------
    */

    // Manager
    if ($request->role == 3) {

        $employeeIds = TaskUser::where('report_to', $request->user_id)
                        ->pluck('id');

        $query->whereIn('employee_id', $employeeIds);
    }

    // Employee
    elseif ($request->role == 4) {

        $query->where('employee_id', $request->user_id);
    }

    // Company (role 2) → all tasks

    /*
    |--------------------------------------------------------------------------
    | Date Range Filter
    |--------------------------------------------------------------------------
    */

    $query->whereBetween('due_date', [
        $request->from_date,
        $request->to_date
    ]);

    /*
    |--------------------------------------------------------------------------
    | Calendar Aggregation
    |--------------------------------------------------------------------------
    */

    $calendar = $query->selectRaw("
        DATE(due_date) as date,
        COUNT(id) as total,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as completed
    ")
    ->groupByRaw('DATE(due_date)')
    ->orderBy('date')
    ->get();

    return response()->json([
        'status' => true,
        'message' => 'Calendar Summary Retrieved',
        'data' => $calendar
    ]);
}
public function taskCalendarTasks(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer',
        'user_id'    => 'required|integer',
        'role'       => 'required',
        'date'       => 'required|date'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'=>false,
            'message'=>$validator->errors()
        ],422);
    }

    $query = Task::with([
        'employee:id,name,picture',
        'creator:id,name',
        'manager:id,name'
    ])
    ->where('company_id',$request->company_id)
    ->whereDate('due_date',$request->date);

    /*
    |--------------------------------------------------------------------------
    | Role Based Logic
    |--------------------------------------------------------------------------
    */

    if($request->role == 3){

        $employeeIds = TaskUser::where('report_to',$request->user_id)->pluck('id');

        $query->whereIn('employee_id',$employeeIds);
    }

    if($request->role == 4){
        $query->where('employee_id',$request->user_id);
    }

    $tasks = $query->orderByDesc('id')->get();

    return response()->json([
        'status'=>true,
        'message'=>'Tasks Retrieved',
        'data'=>$tasks
    ]);
}
public function employeeTaskChart(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer',
        'manager_id' => 'nullable|integer',
        'from_date'  => 'required|date',
        'to_date'    => 'required|date|after_or_equal:from_date'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    $employeeQuery = TaskUser::query()
        ->where('company_id', $request->company_id)
        ->where('role', 4); // employees

    /*
    |--------------------------------------------------------------------------
    | If manager passed → only his team
    |--------------------------------------------------------------------------
    */

    if ($request->filled('manager_id')) {
        $employeeQuery->where('report_to', $request->manager_id);
    }

    $employees = $employeeQuery->pluck('name', 'id');

    /*
    |--------------------------------------------------------------------------
    | Task Aggregation
    |--------------------------------------------------------------------------
    */

    $tasks = Task::where('company_id', $request->company_id)
        ->whereBetween('created_at', [
            $request->from_date . ' 00:00:00',
            $request->to_date . ' 23:59:59'
        ])
        ->whereIn('employee_id', $employees->keys())
        ->selectRaw("
            employee_id,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as completed
        ")
        ->groupBy('employee_id')
        ->get()
        ->keyBy('employee_id');

    /*
    |--------------------------------------------------------------------------
    | Format Chart Response
    |--------------------------------------------------------------------------
    */

    $chart = [];

    foreach ($employees as $id => $name) {

        $chart[] = [
            'employee_id' => $id,
            'employee_name' => $name,
            'pending' => $tasks[$id]->pending ?? 0,
            'in_progress' => $tasks[$id]->in_progress ?? 0,
            'completed' => $tasks[$id]->completed ?? 0
        ];
    }

    return response()->json([
        'status' => true,
        'message' => 'Employee Task Chart Data Retrieved',
        'data' => $chart
    ]);
}
}