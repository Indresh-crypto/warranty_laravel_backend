<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskRemark extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'remark',
        'follow_up_date',
        'attachment',
        'extra_data'
    ];

    protected $casts = [
        'extra_data' => 'array',
        'follow_up_date' => 'date'
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }
    
     public function user()
    {
        return $this->belongsTo(TaskUser::class, 'user_id');
    }
    public function getAttachmentAttribute($value)
{
    if (!$value) {
        return null;
    }

    if (filter_var($value, FILTER_VALIDATE_URL)) {
        return $value;
    }

    return asset($value);
}
}