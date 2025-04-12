<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;

class Insurer extends Model
{
    use HasFactory, Notifiable;

    protected $table = 'insurers';

    protected $fillable = [
        'name',
        'code',
        'daily_capacity',
        'min_batch_size',
        'max_batch_size',
        'date_preference',
        'specialty_costs',
        'priority_multipliers',
        'claim_value_threshold',
        'claim_value_multiplier',
        'email'
    ];

    protected $casts = [
        'specialty_costs' => 'array',
        'priority_multipliers' => 'array',
        'daily_capacity' => 'integer',
        'min_batch_size' => 'integer',
        'max_batch_size' => 'integer',
        'claim_value_threshold' => 'float',
        'claim_value_multiplier' => 'float',
    ];

    /**
     * Get all claims for this insurer
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * Get pending claims for this insurer
     */
    public function pendingClaims()
    {
        return $this->claims()->pending();
    }

    /**
     * Get batched claims for this insurer
     */
    public function batchedClaims()
    {
        return $this->claims()->batched();
    }

    /**
     * Calculate the processing cost for a specific claim
     * Optimized with more efficient type handling and cached day factor calculation
     */
    public function calculateProcessingCost(Claim $claim): float
    {
        $baseCost = $this->getSpecialtyCost($claim->specialty);

        // Apply priority level multiplier with proper array access
        $priorityLevel = min(max((int)$claim->priority_level, 1), 5); // Ensure valid 1-5 range
        $priorityMultiplier = $this->priority_multipliers[$priorityLevel] ?? 1.0;

        // Apply day of month cost adjustment - cache this calculation to avoid repetition
        $dayFactor = $this->getDayFactorForDate($claim->batch_date);

        // Apply claim value multiplier if above threshold
        $valueMultiplier = 1.0;
        $threshold = $this->claim_value_threshold ?? 1000;
        if ((float)$claim->total_amount > $threshold) {
            $valueMultiplier = $this->claim_value_multiplier ?? 1.2;
        }

        return $baseCost * $priorityMultiplier * (1 + $dayFactor) * $valueMultiplier;
    }

    /**
     * Get the day factor for a specific date (cached to avoid recalculation)
     */
    private function getDayFactorForDate($date): float
    {
        $dateString = $date instanceof \Carbon\Carbon ? $date->format('Y-m-d') : $date;
        $cacheKey = "insurer_{$this->id}_day_factor_{$dateString}";

        return Cache::remember($cacheKey, 86400, function () use ($dateString) {
            $dayOfMonth = (int)date('j', strtotime($dateString));
            return 0.2 + (($dayOfMonth - 1) / 29) * 0.3;
        });
    }

    /**
     * Get the cost for processing a specific specialty
     * Cached to improve performance
     */
    private function getSpecialtyCost(string $specialty): float
    {
        $cacheKey = "insurer_{$this->id}_specialty_{$specialty}_cost";

        return Cache::remember($cacheKey, 3600, function () use ($specialty) {
            return $this->specialty_costs[$specialty] ?? 100.0; // Default cost if specialty not found
        });
    }

    /**
     * Route notifications for the mail channel.
     *
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return string
     */
    public function routeNotificationForMail($notification)
    {
        return $this->email;
    }
}
