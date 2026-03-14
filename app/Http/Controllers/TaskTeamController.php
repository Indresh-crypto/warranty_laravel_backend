<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TaskTeam;
use App\Models\TaskUser;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TaskTeamController extends Controller
{
   
   public function createTeam(Request $request)
   {
    $validator = Validator::make($request->all(), [
        'company_id'  => 'required|exists:task_users,id',
        'name'        => 'required|string|max:255',
        'manager_id'  => 'required|exists:task_users,id',
        'members'     => 'nullable|array',
        'members.*'   => 'exists:task_users,id'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();

    try {

        // ✅ Check company role = 2
        $company = TaskUser::where('id', $request->company_id)
                    ->where('role', 2)
                    ->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Company'
            ], 400);
        }

        // ✅ Check manager role = 3
        $manager = TaskUser::where('id', $request->manager_id)
                    ->where('role', 3)
                    ->first();

        if (!$manager) {
            return response()->json([
                'status' => false,
                'message' => 'Selected user is not a Manager'
            ], 400);
        }

        // ✅ Validate Employees
        if (!empty($request->members)) {

            $employees = TaskUser::whereIn('id', $request->members)
                            ->where('role', 4)
                            ->get();

            // Check if any invalid employee
            if ($employees->count() != count($request->members)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Some selected users are not valid employees'
                ], 400);
            }

            // 🔥 IMPORTANT VALIDATION:
            // Check if employee already assigned to another manager
            $alreadyAssigned = $employees->whereNotNull('report_to')
                                ->where('report_to', '!=', $request->manager_id);

            if ($alreadyAssigned->count() > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Employee(s) are already assigned to another manager',
                    'conflicted_employee_ids' => $alreadyAssigned->pluck('id')
                ], 400);
            }
        }

        // ✅ Create team
        $team = TaskTeam::create([
            'company_id' => $request->company_id,
            'name'       => $request->name,
            'manager_id' => $request->manager_id,
            'status'     => 1
        ]);

        // ✅ Attach employees + update report_to
        if (!empty($request->members)) {

            $validEmployeeIds = $employees->pluck('id')->toArray();

            // Attach to pivot
            $team->members()->attach($validEmployeeIds);

            // Update report_to
            TaskUser::whereIn('id', $validEmployeeIds)
                ->update(['report_to' => $request->manager_id]);
        }

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Team Created Successfully',
            'data' => $team->load('manager', 'members')
        ]);

    } catch (\Exception $e) {

        DB::rollback();

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}


    public function listTeams(Request $request)
   {
    $request->validate([
        'company_id' => 'nullable|integer',
        'manager_id' => 'nullable|integer',
        'status'     => 'nullable|boolean',
        'search'     => 'nullable|string',
        'member_id'  => 'nullable|integer',
        'from_date'  => 'nullable|date',
        'to_date'    => 'nullable|date',
        'per_page'   => 'nullable|integer|min:1|max:100'
    ]);

    $perPage = $request->per_page ?? 10;

    $query = TaskTeam::with(['manager', 'members']);

    // ✅ Company Filter
    if ($request->filled('company_id')) {
        $query->where('company_id', $request->company_id);
    }

    // ✅ Manager Filter
    if ($request->filled('manager_id')) {
        $query->where('manager_id', $request->manager_id);
    }

    // ✅ Status Filter
    if ($request->has('status')) {
        $query->where('status', $request->status);
    }

    // ✅ Search by Team Name
    if ($request->filled('search')) {
        $query->where('name', 'like', '%' . $request->search . '%');
    }

    // ✅ Filter by Member ID
    if ($request->filled('member_id')) {
        $query->whereHas('members', function ($q) use ($request) {
            $q->where('user_id', $request->member_id);
        });
    }

    // ✅ Date Range Filter
    if ($request->filled('from_date') && $request->filled('to_date')) {
        $query->whereBetween('created_at', [
            $request->from_date,
            $request->to_date
        ]);
    }

    $teams = $query->orderBy('id', 'desc')->paginate($perPage);

    return response()->json([
        'status' => true,
        'message' => 'Team List Retrieved Successfully',
        'data' => $teams
    ]);
}

public function getTeamById($id)
{
    $team = TaskTeam::with(['manager', 'members'])->find($id);

    if (!$team) {
        return response()->json([
            'status' => false,
            'message' => 'Team Not Found'
        ], 404);
    }

    return response()->json([
        'status' => true,
        'message' => 'Team Retrieved Successfully',
        'data' => $team
    ]);
}
    

   public function updateTeam(Request $request, $id)
{
    $team = TaskTeam::with('members')->find($id);

    if (!$team) {
        return response()->json([
            'status' => false,
            'message' => 'Team Not Found'
        ], 404);
    }

    $validator = Validator::make($request->all(), [
        'name'        => 'nullable|string|max:255',
        'manager_id'  => 'nullable|exists:task_users,id',
        'members'     => 'nullable|array',
        'members.*'   => 'exists:task_users,id',
        'status'      => 'nullable|boolean'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();

    try {

        $newManagerId = $request->manager_id ?? $team->manager_id;

        // ✅ Validate manager role = 3
        if ($request->filled('manager_id')) {

            $manager = TaskUser::where('id', $request->manager_id)
                        ->where('role', 3)
                        ->first();

            if (!$manager) {
                return response()->json([
                    'status' => false,
                    'message' => 'Selected user is not a Manager'
                ], 400);
            }
        }

        // ✅ If members provided, validate employees
        if ($request->has('members')) {

            $employees = TaskUser::whereIn('id', $request->members)
                            ->where('role', 4)
                            ->get();

            if ($employees->count() != count($request->members)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Some selected users are not valid employees'
                ], 400);
            }

            // 🔥 Check if employee already assigned to another manager
            $conflicted = $employees->whereNotNull('report_to')
                ->where('report_to', '!=', $newManagerId);

            if ($conflicted->count() > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Eemployee(s) are already assigned to another manager',
                    'conflicted_employee_ids' => $conflicted->pluck('id')
                ], 400);
            }
        }

        // ✅ Update Team basic fields
        $team->update($request->only([
            'name',
            'manager_id',
            'status'
        ]));

        // ===============================
        // MEMBER SYNC + REPORT_TO LOGIC
        // ===============================

        if ($request->has('members')) {

            $oldMemberIds = $team->members->pluck('id')->toArray();
            $newMemberIds = $request->members ?? [];

            // 🔹 Remove report_to from removed members
            $removedMembers = array_diff($oldMemberIds, $newMemberIds);

            if (!empty($removedMembers)) {
                TaskUser::whereIn('id', $removedMembers)
                    ->update(['report_to' => null]);
            }

            // 🔹 Sync pivot table
            $team->members()->sync($newMemberIds);

            // 🔹 Update report_to for new members
            if (!empty($newMemberIds)) {
                TaskUser::whereIn('id', $newMemberIds)
                    ->update(['report_to' => $newManagerId]);
            }

        } else {

            // 🔹 If only manager changed, update all existing members
            if ($request->filled('manager_id')) {
                $existingMemberIds = $team->members->pluck('id')->toArray();

                if (!empty($existingMemberIds)) {
                    TaskUser::whereIn('id', $existingMemberIds)
                        ->update(['report_to' => $newManagerId]);
                }
            }
        }

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Team Updated Successfully',
            'data' => $team->load('manager', 'members')
        ]);

    } catch (\Exception $e) {

        DB::rollback();

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}
public function deleteTeam($id)
{
    $team = TaskTeam::with('members')->find($id);

    if (!$team) {
        return response()->json([
            'status' => false,
            'message' => 'Team Not Found'
        ], 404);
    }

    DB::beginTransaction();

    try {

        // Get team member ids
        $memberIds = $team->members->pluck('id')->toArray();

        if (!empty($memberIds)) {

            // Reset report_to
            TaskUser::whereIn('id', $memberIds)
                ->update(['report_to' => null]);

            // Detach pivot members
            $team->members()->detach();
        }

        // Soft delete team
        $team->delete();

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Team Deleted Successfully and Members Unassigned'
        ]);

    } catch (\Exception $e) {

        DB::rollback();

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}
public function restoreTeam($id)
{
    $team = Team::withTrashed()->find($id);

    if (!$team) {
        return response()->json([
            'status' => false,
            'message' => 'Team Not Found'
        ], 404);
    }

    $team->restore();

    return response()->json([
        'status' => true,
        'message' => 'Team Restored Successfully'
    ]);
}

public function avgPerformance(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id'  => 'nullable|integer',
        'manager_id'  => 'nullable|integer',
        'employee_id' => 'nullable|integer',
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
    | Company Filter
    |--------------------------------------------------------------------------
    */

    if ($request->filled('company_id')) {
        $query->where('company_id', $request->company_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Manager Filter (employees under manager)
    |--------------------------------------------------------------------------
    */

    if ($request->filled('manager_id')) {

        $employeeIds = TaskUser::where('report_to', $request->manager_id)
                        ->pluck('id');

        $query->whereIn('employee_id', $employeeIds);
    }

    /*
    |--------------------------------------------------------------------------
    | Employee Filter
    |--------------------------------------------------------------------------
    */

    if ($request->filled('employee_id')) {
        $query->where('employee_id', $request->employee_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Date Filter
    |--------------------------------------------------------------------------
    */

    if ($request->filled('from_date') && $request->filled('to_date')) {

        $query->whereBetween('created_at', [
            $request->from_date . ' 00:00:00',
            $request->to_date . ' 23:59:59'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Performance Calculation
    |--------------------------------------------------------------------------
    */

    $totalTasks = (clone $query)->count();

    $completedTasks = (clone $query)
                        ->where('status', 3)
                        ->count();

    $performance = 0;

    if ($totalTasks > 0) {
        $performance = round(($completedTasks / $totalTasks) * 100, 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Employee Count
    |--------------------------------------------------------------------------
    */

    $employeeCount = (clone $query)
                        ->distinct('employee_id')
                        ->count('employee_id');

    return response()->json([
        'status' => true,
        'message' => 'Average Performance Retrieved',
        'data' => [
            'total_employees' => $employeeCount,
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'performance_percentage' => $performance
        ]
    ]);
}
}