<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateWhatsappMessage extends Model
{
    use HasFactory;

    protected $table = 'candidates_whatsapp_messages';

    protected $fillable = [
        'candidate_id',
        'phone',
        'message',
        'message_type',
        'direction',
        'status',
    ];
}