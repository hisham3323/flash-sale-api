<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = [
        'product_id',
        'qty',
        'amount',
        'status',
        'payment_idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'qty' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}