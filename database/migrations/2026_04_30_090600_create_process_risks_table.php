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
        Schema::create('process_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();
            $table->foreignId('process_event_id')
                ->nullable()
                ->constrained('process_events')
                ->nullOnDelete();

            $table->text('descricao');
            $table->string('nivel', 20)->default('medio');
            $table->string('categoria', 120)->nullable();
            $table->unsignedTinyInteger('impacto')->nullable();
            $table->unsignedTinyInteger('urgencia')->nullable();
            $table->text('fundamento')->nullable();
            $table->unsignedTinyInteger('score')->nullable();

            $table->timestamps();

            $table->index(['document_analysis_id', 'nivel'], 'idx_risks_level');
            $table->index(['document_analysis_id', 'score'], 'idx_risks_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_risks');
    }
};
