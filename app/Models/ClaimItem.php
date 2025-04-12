<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

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
     * Use attribute accessor for subtotal to improve performance and avoid redundant calculations
     */
    protected function subtotal(): Attribute
    {
        return Attribute::make(
            get: fn($value) => $value,
            set: fn() => $this->calculateSubtotal()
        );
    }

    /**
     * Calculate subtotal based on unit price and quantity
     */
    public function calculateSubtotal(): float
    {
        return (float)$this->unit_price * (int)$this->quantity;
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
            // Only recalculate if unit_price or quantity has changed
            if ($claimItem->isDirty(['unit_price', 'quantity'])) {
                $claimItem->subtotal = $claimItem->calculateSubtotal();
            }
        });

        // Update claim total when item is saved/deleted
        static::saved(function ($claimItem) {
            if ($claimItem->claim) {
                $claimItem->claim->updateTotal();
            }
        });

        static::deleted(function ($claimItem) {
            if ($claimItem->claim) {
                $claimItem->claim->updateTotal();
            }
        });
    }
}
