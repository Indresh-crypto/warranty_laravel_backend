<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskAiMessageTemplate extends Model
{
    protected $table = 'task_ai_message_templates';

    protected $fillable = [
        'message',
        'role'
    ];
}