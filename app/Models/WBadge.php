<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WBadge extends Model
{
    protected $table = 'w_badges';

    protected $fillable = [
        'name',
        'eligibility',
        'description',
        'benefits',
        'image'
    ];
}