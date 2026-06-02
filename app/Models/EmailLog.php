<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $fillable = [
        'template_id', 'to_email', 'subject', 'body', 'status', 'response', 'company_id', 'track_id', 'opened_at'
    ];
}