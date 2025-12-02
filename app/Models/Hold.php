<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hold extends Model
{
    protected $fillable = [
        'product_id',
        'qty',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'qty' => 'integer',
    ];

    /**
     * Get the product that owns the hold.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}