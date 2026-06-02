<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    protected $appends = ['is_read'];

    protected $fillable = [
        'company_id',
        'manager_id',
        'employee_id',
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'attachment',
        'extra_data',
        'employee_read_at',
        'created_by',
        'completed_at',
        'task_type'
    ];

    protected $casts = [
        'extra_data' => 'array',
        'due_date' => 'date'
    ];

    public function manager()
    {
        return $this->belongsTo(TaskUser::class, 'manager_id');
    }

    public function employee()
    {
        return $this->belongsTo(TaskUser::class, 'employee_id');
    }

    public function remarks()
    {
        return $this->hasMany(TaskRemark::class);
    }
    
    public function getAttachmentAttribute($value)
    {
        if (!$value) {
            return null;
        }
    
        // If already full URL, return as is
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
    
        return asset($value);
    }
    
    public function getIsReadAttribute()
    {
        return $this->employee_read_at ? true : false;
    }
    public function creator()
    {
        return $this->belongsTo(TaskUser::class, 'created_by');
    }
    
    public function taskType()
{
    return $this->belongsTo(TaskType::class, 'task_type');
}

}