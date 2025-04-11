<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->integer('daily_capacity')->default(100);
            $table->integer('min_batch_size')->default(5);
            $table->integer('max_batch_size')->default(50);
            $table->enum('date_preference', ['encounter_date', 'submission_date'])->default('submission_date');
            $table->json('specialty_costs')->nullable();
            $table->json('priority_multipliers')->nullable();
            $table->decimal('claim_value_threshold', 10, 2)->default(1000.00);
            $table->float('claim_value_multiplier')->default(1.2);
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurers');
    }
};
