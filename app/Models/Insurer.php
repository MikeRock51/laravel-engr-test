<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

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
    ];

    /**
     * Get all claims for this insurer
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * Calculate the processing cost for a specific claim
     */
    public function calculateProcessingCost(Claim $claim): float
    {
        $baseCost = $this->getSpecialtyCost($claim->specialty);

        // Apply priority level multiplier
        $priorityMultiplier = $this->priority_multipliers[$claim->priority_level] ?? 1.0;

        // Apply day of month cost adjustment (20% on 1st, increases linearly to 50% on 30th)
        $dayOfMonth = (int)date('j', strtotime($claim->batch_date));
        $dayFactor = 0.2 + (($dayOfMonth - 1) / 29) * 0.3;

        // Apply claim value multiplier if above threshold
        $valueMultiplier = 1.0;
        if ($claim->total_amount > ($this->claim_value_threshold ?? 1000)) {
            $valueMultiplier = $this->claim_value_multiplier ?? 1.2;
        }

        return $baseCost * $priorityMultiplier * (1 + $dayFactor) * $valueMultiplier;
    }

    /**
     * Get the cost for processing a specific specialty
     */
    private function getSpecialtyCost(string $specialty): float
    {
        return $this->specialty_costs[$specialty] ?? 100.0; // Default cost if specialty not found
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
