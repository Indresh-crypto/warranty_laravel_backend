<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Automation extends Model
{
    protected $fillable = [

        'name',

        'type',

        'module',

        'target_class',

        'schedule_type',

        'schedule_time',

        'cron_expression',

        'parameters',

        'extra_emails',

        'extra_mobiles',

        'channels',

        'email_column',

        'mobile_column',

        'cc_emails',

        'bcc_emails',

        'filters',

        'subject',

        'email_template',

        'whatsapp_template',

        'whatsapp_template_id',

        'is_active',

        'last_run_at',

        'last_execution_key',

        'created_by'
    ];

    protected $casts = [

        'parameters' =>
            'array',

        'extra_emails' =>
            'array',

        'extra_mobiles' =>
            'array',

        'channels' =>
            'array',

        'cc_emails' =>
            'array',

        'bcc_emails' =>
            'array',

        'filters' =>
            'array',

        'is_active' =>
            'boolean'
    ];
}