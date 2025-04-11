<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurer_id')->constrained('insurers');
            $table->string('provider_name');
            $table->date('encounter_date');
            $table->date('submission_date');
            $table->integer('priority_level')->comment('1-5, where 5 is highest priority');
            $table->string('specialty');
            $table->decimal('total_amount', 10, 2);
            $table->string('batch_id')->nullable()->index();
            $table->boolean('is_batched')->default(false);
            $table->date('batch_date')->nullable();
            $table->string('status')->default('pending')->comment('pending, batched, processed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
