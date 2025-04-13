<?php

namespace Database\Seeders;

use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Insurer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ClaimSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all insurers to distribute claims among them
        $insurers = Insurer::all();

        // Get test user
        $testUser = User::where('email', 'test@example.com')->first() ?? User::first();

        // If no user exists, create one (fallback)
        if (!$testUser) {
            $testUser = User::create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => bcrypt('password'),
            ]);
        }

        // Array of provider names for sample data
        $providers = [
            'General Hospital',
            'Mercy Medical Center',
            'St. Luke\'s Hospital',
            'Community Health Clinic',
            'University Medical Center',
            'Family Practice Associates',
            'Urgent Care Plus',
            'Pediatric Specialists Group',
            'Women\'s Health Center',
            'Orthopedic Surgery Center'
        ];

        // Array of specialties from insurer data
        $specialties = [
            'Cardiology',
            'Dermatology',
            'Endocrinology',
            'Gastroenterology',
            'Neurology',
            'Obstetrics',
            'Oncology',
            'Ophthalmology',
            'Orthopedics',
            'Pediatrics',
            'Psychiatry',
            'Urology'
        ];

        // Common medical items for claim items
        $medicalItems = [
            ['name' => 'Initial Consultation', 'price_range' => [100, 250]],
            ['name' => 'Follow-up Visit', 'price_range' => [75, 150]],
            ['name' => 'X-Ray', 'price_range' => [150, 300]],
            ['name' => 'MRI', 'price_range' => [800, 1500]],
            ['name' => 'CT Scan', 'price_range' => [500, 1200]],
            ['name' => 'Blood Test Panel', 'price_range' => [80, 200]],
            ['name' => 'Ultrasound', 'price_range' => [200, 450]],
            ['name' => 'Physical Therapy Session', 'price_range' => [100, 200]],
            ['name' => 'Medication', 'price_range' => [50, 300]],
            ['name' => 'Surgery', 'price_range' => [2000, 10000]],
            ['name' => 'Vaccination', 'price_range' => [60, 150]],
            ['name' => 'Mental Health Session', 'price_range' => [120, 250]],
            ['name' => 'Dental Cleaning', 'price_range' => [100, 200]],
            ['name' => 'Allergy Test', 'price_range' => [150, 300]],
            ['name' => 'Lab Work', 'price_range' => [100, 400]]
        ];

        // Generate batch IDs using provider name + date format
        // Using dates between April 1-10, 2025 (current date is April 12, 2025)
        $batchDates = [
            Carbon::create(2025, 4, 8),
            Carbon::create(2025, 4, 9),
            Carbon::create(2025, 4, 10),
            Carbon::create(2025, 4, 11)
        ];

        // Create provider+date combinations for batch IDs
        $batchIds = [];
        foreach ($providers as $index => $provider) {
            // Only use the first 5 providers for batch IDs
            if ($index >= 5) break;

            // Create 1-2 batch IDs per provider with different dates
            $numBatches = rand(1, 2);
            for ($i = 0; $i < $numBatches; $i++) {
                $date = $batchDates[array_rand($batchDates)];
                $formattedDate = $date->format('M j Y'); // Format as "Apr 10 2025"
                $batchIds[] = [
                    'id' => $provider . ' ' . $formattedDate,
                    'provider' => $provider,
                    'date' => $date->format('Y-m-d')
                ];
            }
        }

        // Create 50 claims
        for ($i = 0; $i < 50; $i++) {
            $insurer = $insurers->random();
            $provider = $providers[array_rand($providers)];
            $specialty = $specialties[array_rand($specialties)];
            $priorityLevel = rand(1, 5);

            // Set dates (ensure encounter date is before submission date)
            $encounterDate = Carbon::now()->subDays(rand(5, 30))->format('Y-m-d');
            $submissionDate = Carbon::parse($encounterDate)->addDays(rand(1, 5))->format('Y-m-d');

            // Determine if this claim should be batched (20% batched, 80% pending)
            $isBatched = rand(1, 100) <= 20;
            $batchDate = null;
            $batchId = null;
            $status = 'pending';

            if ($isBatched) {
                // Select a random batch, but try to match the provider when possible
                $matchingBatches = array_filter($batchIds, function ($batch) use ($provider) {
                    return $batch['provider'] === $provider;
                });

                // If no matching batch for this provider, just pick a random one
                $selectedBatch = !empty($matchingBatches)
                    ? $matchingBatches[array_rand($matchingBatches)]
                    : $batchIds[array_rand($batchIds)];

                $batchId = $selectedBatch['id'];
                $batchDate = $selectedBatch['date'];
                $status = 'batched';

                // Override the provider to match the batch provider for consistency
                $provider = $selectedBatch['provider'];
            }

            // Create the claim
            $claim = Claim::create([
                'insurer_id' => $insurer->id,
                'user_id' => $testUser->id,
                'provider_name' => $provider,
                'encounter_date' => $encounterDate,
                'submission_date' => $submissionDate,
                'priority_level' => $priorityLevel,
                'specialty' => $specialty,
                'total_amount' => 0, // Will be updated after adding items
                'batch_id' => $batchId,
                'is_batched' => $isBatched,
                'batch_date' => $batchDate,
                'status' => $status
            ]);

            // Add 1 to 5 items to each claim
            $itemCount = rand(1, 5);
            for ($j = 0; $j < $itemCount; $j++) {
                $item = $medicalItems[array_rand($medicalItems)];
                $unitPrice = rand($item['price_range'][0], $item['price_range'][1]);
                $quantity = rand(1, 3);
                $subtotal = $unitPrice * $quantity;

                ClaimItem::create([
                    'claim_id' => $claim->id,
                    'name' => (string)$item['name'], // Ensure this is cast to string
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                ]);
            }

            // Update the claim's total amount
            $claim->updateTotal();
        }
    }
}
