<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappApp extends Model
{
    protected $fillable = [

        'name',

        'app_name',

        'source_number',

        'api_key',

        'base_url',

        'is_active'
    ];

    protected $casts = [

        'is_active' => 'boolean'
    ];

    public function templates()
    {
        return $this->hasMany(
            WhatsappTemplate::class
        );
    }
}