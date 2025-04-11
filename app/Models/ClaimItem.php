<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'claim_id',
        'name',
        'unit_price',
        'quantity',
        'subtotal'
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer'
    ];

    /**
     * Get the claim that the item belongs to
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * Calculate subtotal based on unit price and quantity
     */
    public function calculateSubtotal(): float
    {
        return $this->unit_price * $this->quantity;
    }

    /**
     * Set the subtotal before saving
     */
    protected static function booted()
    {
        static::creating(function ($claimItem) {
            $claimItem->subtotal = $claimItem->calculateSubtotal();
        });

        static::updating(function ($claimItem) {
            $claimItem->subtotal = $claimItem->calculateSubtotal();
        });
    }
}
