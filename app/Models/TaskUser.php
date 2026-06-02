<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskUser extends Model
{
    use HasFactory;

    protected $table = 'task_users';

    protected $fillable = [
        'name',
        'code',
        'position',
        'mobile',
        'email',
        'location',
        'city',
        'state',
        'district',
        'pincode',
        'report_to',
        'role',
        'otp',
        'otp_expires_at',
        'company_id',
        'status',
        'picture',
        'last_seen_at'
    ];

    // Reporting Manager
    public function manager()
    {
        return $this->belongsTo(TaskUser::class, 'report_to');
    }

    // Subordinates
    public function subordinates()
    {
        return $this->hasMany(TaskUser::class, 'report_to');
    }
    public function assignedTasks()
    {
        return $this->hasMany(Task::class, 'employee_id');
    }

}