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
        Schema::create('process_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();
            $table->foreignId('source_micro_analysis_id')
                ->nullable()
                ->constrained('document_micro_analyses')
                ->nullOnDelete();

            $table->date('data')->nullable();
            $table->string('evento_ou_id', 255)->nullable();
            $table->string('tipo_de_ato', 120)->nullable();
            $table->string('autor_do_ato', 120)->nullable();
            $table->text('resumo_objetivo');
            $table->text('efeito_juridico')->nullable();
            $table->boolean('abriu_prazo')->default(false);
            $table->string('prazo_identificado', 120)->nullable();
            $table->boolean('marco_relevante')->default(false);
            $table->unsignedInteger('ordem')->default(0);

            $table->timestamps();

            $table->index(['document_analysis_id', 'ordem'], 'idx_events_analysis_order');
            $table->index(['document_analysis_id', 'data'], 'idx_events_analysis_date');
            $table->index(['document_analysis_id', 'marco_relevante'], 'idx_events_relevant');
            $table->index(['document_analysis_id', 'abriu_prazo'], 'idx_events_deadline_open');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_events');
    }
};
