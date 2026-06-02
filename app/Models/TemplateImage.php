<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TemplateImage extends Model
{
    use HasFactory;

    protected $table = 'template_images';

    protected $fillable = [
        'link',
        'tag',
        'company_id',
        'status'
    ];
}