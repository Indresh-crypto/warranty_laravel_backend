<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClaimReason extends Model
{
    protected $table = 'claim_reasons';

    protected $fillable = [
        'reason_type',
        'title',
        'description',
        'status'
    ];
}