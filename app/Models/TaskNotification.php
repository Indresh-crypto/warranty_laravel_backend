<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskNotification extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'title',
        'message',
        'task_id',
        'reference_id',
        'is_read'
    ];
        
        public function task()
    {
        return $this->belongsTo(\App\Models\Task::class);
    }
}