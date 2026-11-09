<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transactions extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'notes',
        'user_id',
        'numeraha_id',
        'customer_id',
    ];

    public function numeraha(): BelongsTo
    {
        return $this->belongsTo(Numeraha::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customers::class);
    }
}
