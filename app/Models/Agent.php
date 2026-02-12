<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $table = 'agents';

    protected $fillable = [
        'company_id',
        'agent_code',
        'agent_name',
        'email',
        'phone',
        'password',

        'address_line1',
        'address_line2',
        'state',
        'district',
        'city',
        'pincode',

        'status',
        'is_verified'
    ];

    protected $hidden = ['password'];
}