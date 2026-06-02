<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\OrgUser;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $fillable = [

        'type',

        'action',

        'user_id',

        'message',

        'payload',

        'ip',

        'url',

        'method',

        'status',

        'exception',
        'tag'
    ];

    protected $casts = [

        'payload' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(
            OrgUser::class,
            'user_id'
        );
    }
}