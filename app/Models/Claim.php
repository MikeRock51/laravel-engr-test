<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Claim extends Model
{
    use HasFactory;

    protected $table = 'claims';

    protected $fillable = [
        'user_id',
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
     * The relationships that should be eager loaded by default.
     *
     * @var array
     */
    protected $with = [];

    /**
     * Get the insurer that the claim belongs to
     */
    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    /**
     * Get the user that owns the claim
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * Improved to use the relationship's sum query builder method
     * instead of loading all items into memory
     */
    public function calculateTotal(): float
    {
        return $this->items()->sum('subtotal');
    }

    /**
     * Update the total amount for the claim
     * Optimized to avoid reloading items
     */
    public function updateTotal(): void
    {
        $total = $this->calculateTotal();
        $this->total_amount = $total;
        $this->save();
    }

    /**
     * Calculate the processing cost for this claim
     */
    public function getProcessingCost(): float
    {
        return $this->insurer->calculateProcessingCost($this);
    }

    /**
     * Get pending claims query scope
     */
    public function scopePending($query)
    {
        return $query->where('is_batched', false)
            ->where('status', 'pending');
    }

    /**
     * Get batched claims query scope
     */
    public function scopeBatched($query)
    {
        return $query->where('is_batched', true)
            ->where('status', 'batched');
    }

    /**
     * Cache batch date attribute formatted as requested
     */
    protected function formattedBatchDate(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->batch_date ? $this->batch_date->format('M j Y') : null,
        )->shouldCache();
    }
}
