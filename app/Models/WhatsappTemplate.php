<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappTemplate extends Model
{
    protected $fillable = [

        'whatsapp_app_id',

        'template_name',

        'template_id',

        'body',

        'variables',

        'language',

        'is_active'
    ];

    protected $casts = [

        'variables' => 'array',

        'is_active' => 'boolean'
    ];

    public function app()
    {
        return $this->belongsTo(
            WhatsappApp::class,
            'whatsapp_app_id'
        );
    }
}