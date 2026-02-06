<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyApiLog extends Model
{
    protected $table = 'company_api_logs';

    protected $fillable = [
        'company_id',
        'api_name',
        'method',
        'url',
        'payload',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'payload' => 'array'
    ];

    public $timestamps = false;
}