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
        Schema::create('process_structured_opinions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();

            $table->longText('sumario_executivo')->nullable();
            $table->longText('diagnostico')->nullable();
            $table->longText('prazos_preclusoes')->nullable();
            $table->longText('prescricao_decadencia')->nullable();
            $table->longText('inconsistencias_atencao')->nullable();
            $table->json('riscos_priorizados')->nullable();
            $table->json('oportunidades')->nullable();
            $table->longText('conclusao_estrategica')->nullable();

            $table->timestamps();

            $table->unique('document_analysis_id', 'uq_structured_opinion_analysis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_structured_opinions');
    }
};
