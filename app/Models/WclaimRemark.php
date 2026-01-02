<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WclaimRemark extends Model
{
    use HasFactory;

    protected $fillable = [
        'wclaim_id',
        'user_id',
        'remark',
        'status',
    ];

    public function claim()
    {
        return $this->belongsTo(Wclaim::class, 'wclaim_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}