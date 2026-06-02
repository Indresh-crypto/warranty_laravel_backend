<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'app',
        'timestamp',
        'version',
        'type',
        'message_id',
        'source',
        'message_type',
        'sender_phone',
        'sender_name',
        'sender_country_code',
        'sender_dial_code',
        'context_id',
        'context_gsId',
        'payload_text',
        'url'
    ];
}