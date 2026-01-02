<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CategoryProduct extends Model
{
    use HasFactory;

    protected $table = "category_product";

    public $timestamps= false;
    protected $fillable = [
       'category_id', 'warranty_product_id'
    ];
}