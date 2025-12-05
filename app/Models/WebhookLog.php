<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $fillable = [
        'order_id',
        'idempotency_key',
        'status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}