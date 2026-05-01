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
        Schema::create('process_engine_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('risco_processual_score')->nullable();
            $table->unsignedTinyInteger('urgencia_score')->nullable();
            $table->unsignedTinyInteger('oportunidade_score')->nullable();
            $table->unsignedTinyInteger('confiabilidade_score')->nullable();

            $table->json('situacao_atual')->nullable();
            $table->json('pendencias')->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            $table->index(['document_analysis_id', 'generated_at'], 'idx_engine_snapshots_generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_engine_snapshots');
    }
};
