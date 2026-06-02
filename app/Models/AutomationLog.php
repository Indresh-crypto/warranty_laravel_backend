<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationLog extends Model
{
    protected $fillable = [

        'automation_id',

        'name',

        'type',

        'status',

        'total_processed',

        'total_success',

        'total_failed',

        'response',

        'execution_time'
    ];
}