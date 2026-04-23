<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analysis_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')->constrained('document_analyses')->cascadeOnDelete();
            $table->uuid('run_id')->index();
            $table->string('layer', 32)->default('deterministic');
            $table->string('metric_name');
            $table->decimal('score', 8, 4);
            $table->decimal('threshold', 8, 4);
            $table->boolean('passed');
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['document_analysis_id', 'created_at']);
            $table->index(['metric_name', 'passed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_evaluations');
    }
};
