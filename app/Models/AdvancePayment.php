<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdvancePayment extends Model
{
    use HasFactory;

    protected $table = 'advance_payments';

    protected $fillable = [
        'retailer_id',
        'payment_id',
        'payment_json',
        'amount'
    ];

    protected $casts = [
        'payment_json' => 'array',
        'amount' => 'float'
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATION: RETAILER COMPANY
    |--------------------------------------------------------------------------
    */

    public function retailer()
    {
        return $this->belongsTo(Company::class, 'retailer_id');
    }
}