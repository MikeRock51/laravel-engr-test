<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InsurerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $insurers = [
            [
                'name' => 'Insurer A',
                'code' => 'INS-A',
                'daily_capacity' => 150,
                'min_batch_size' => 5,
                'max_batch_size' => 30,
                'date_preference' => 'submission_date',
                'specialty_costs' => json_encode([
                    'Cardiology' => 120.00,
                    'Dermatology' => 90.00,
                    'Endocrinology' => 110.00,
                    'Gastroenterology' => 105.00,
                    'Neurology' => 130.00,
                    'Obstetrics' => 115.00,
                    'Oncology' => 140.00,
                    'Ophthalmology' => 100.00,
                    'Orthopedics' => 125.00,
                    'Pediatrics' => 95.00,
                    'Psychiatry' => 110.00,
                    'Urology' => 105.00
                ]),
                'priority_multipliers' => json_encode([
                    '1' => 0.8,
                    '2' => 0.9,
                    '3' => 1.0,
                    '4' => 1.2,
                    '5' => 1.5
                ]),
                'claim_value_threshold' => 1200.00,
                'claim_value_multiplier' => 1.25,
                'email' => 'claims@insurer-a.example.com'
            ],
            [
                'name' => 'Insurer B',
                'code' => 'INS-B',
                'daily_capacity' => 100,
                'min_batch_size' => 8,
                'max_batch_size' => 25,
                'date_preference' => 'encounter_date',
                'specialty_costs' => json_encode([
                    'Cardiology' => 130.00,
                    'Dermatology' => 85.00,
                    'Endocrinology' => 120.00,
                    'Gastroenterology' => 100.00,
                    'Neurology' => 140.00,
                    'Obstetrics' => 125.00,
                    'Oncology' => 150.00,
                    'Ophthalmology' => 95.00,
                    'Orthopedics' => 135.00,
                    'Pediatrics' => 90.00,
                    'Psychiatry' => 115.00,
                    'Urology' => 110.00
                ]),
                'priority_multipliers' => json_encode([
                    '1' => 0.9,
                    '2' => 0.95,
                    '3' => 1.0,
                    '4' => 1.1,
                    '5' => 1.3
                ]),
                'claim_value_threshold' => 1000.00,
                'claim_value_multiplier' => 1.2,
                'email' => 'claims@insurer-b.example.com'
            ],
            [
                'name' => 'Insurer C',
                'code' => 'INS-C',
                'daily_capacity' => 80,
                'min_batch_size' => 10,
                'max_batch_size' => 20,
                'date_preference' => 'submission_date',
                'specialty_costs' => json_encode([
                    'Cardiology' => 110.00,
                    'Dermatology' => 80.00,
                    'Endocrinology' => 100.00,
                    'Gastroenterology' => 95.00,
                    'Neurology' => 120.00,
                    'Obstetrics' => 105.00,
                    'Oncology' => 130.00,
                    'Ophthalmology' => 90.00,
                    'Orthopedics' => 115.00,
                    'Pediatrics' => 85.00,
                    'Psychiatry' => 105.00,
                    'Urology' => 95.00
                ]),
                'priority_multipliers' => json_encode([
                    '1' => 0.85,
                    '2' => 0.9,
                    '3' => 1.0,
                    '4' => 1.25,
                    '5' => 1.6
                ]),
                'claim_value_threshold' => 800.00,
                'claim_value_multiplier' => 1.3,
                'email' => 'claims@insurer-c.example.com'
            ],
            [
                'name' => 'Insurer D',
                'code' => 'INS-D',
                'daily_capacity' => 200,
                'min_batch_size' => 3,
                'max_batch_size' => 50,
                'date_preference' => 'encounter_date',
                'specialty_costs' => json_encode([
                    'Cardiology' => 140.00,
                    'Dermatology' => 100.00,
                    'Endocrinology' => 130.00,
                    'Gastroenterology' => 115.00,
                    'Neurology' => 150.00,
                    'Obstetrics' => 135.00,
                    'Oncology' => 160.00,
                    'Ophthalmology' => 105.00,
                    'Orthopedics' => 145.00,
                    'Pediatrics' => 100.00,
                    'Psychiatry' => 125.00,
                    'Urology' => 120.00
                ]),
                'priority_multipliers' => json_encode([
                    '1' => 0.7,
                    '2' => 0.85,
                    '3' => 1.0,
                    '4' => 1.3,
                    '5' => 1.7
                ]),
                'claim_value_threshold' => 1500.00,
                'claim_value_multiplier' => 1.15,
                'email' => 'claims@insurer-d.example.com'
            ]
        ];

        DB::table('insurers')->insert($insurers);
    }
}
