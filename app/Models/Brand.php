<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
       'name', 'image', 'description', 'status'
    ];
public function categories()
{
    return $this->belongsToMany(Category::class, 'brand_category', 'brand_id', 'category_id');
}
}
