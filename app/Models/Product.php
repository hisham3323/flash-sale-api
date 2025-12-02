<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    // Allow these columns to be filled via the API or Seeder
    protected $fillable = [
        'name',
        'price',
        'stock',
    ];

    // Cast data types for better accuracy (ensure price is always a float/decimal)
    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];
}