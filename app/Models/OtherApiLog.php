<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtherApiLog extends Model
{
    use HasFactory;
    
    protected $table = 'other_api_logs';

    protected $fillable = [
       'method_name',
       'error_message',
       'payload'	
    ];

/*
    protected $casts = [
        'payload' => 'array'
    ];
*/
}