<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Claim extends Model
{
    use HasFactory;

    protected $table = 'claims';

    protected $fillable = [
        'insurer_id',
        'provider_name',
        'encounter_date',
        'submission_date',
        'priority_level',
        'specialty',
        'total_amount',
        'batch_id',
        'is_batched',
        'batch_date',
        'status'
    ];

    protected $casts = [
        'encounter_date' => 'date',
        'submission_date' => 'date',
        'batch_date' => 'date',
        'is_batched' => 'boolean',
        'priority_level' => 'integer',
        'total_amount' => 'decimal:2'
    ];

    /**
     * Get the insurer that the claim belongs to
     */
    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    /**
     * Get all items for this claim
     */
    public function items(): HasMany
    {
        return $this->hasMany(ClaimItem::class);
    }

    /**
     * Calculate the total amount for the claim based on its items
     */
    public function calculateTotal(): float
    {
        return $this->items->sum('subtotal');
    }

    /**
     * Update the total amount for the claim
     */
    public function updateTotal(): void
    {
        $this->total_amount = $this->calculateTotal();
        $this->save();
    }

    /**
     * Calculate the processing cost for this claim
     */
    public function getProcessingCost(): float
    {
        return $this->insurer->calculateProcessingCost($this);
    }
}
