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
        Schema::create('process_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();
            $table->foreignId('process_event_id')
                ->nullable()
                ->constrained('process_events')
                ->nullOnDelete();

            $table->string('tipo', 30)->default('prazo');
            $table->string('ato', 255)->nullable();
            $table->date('marco_inicial')->nullable();
            $table->string('regra_de_contagem', 255)->nullable();
            $table->string('prazo', 120)->nullable();
            $table->date('data_final_estimada')->nullable();
            $table->boolean('foi_cumprido')->nullable();
            $table->string('regime_juridico', 120)->nullable();
            $table->json('atos_interruptivos')->nullable();
            $table->text('conclusao')->nullable();
            $table->string('status', 30)->default('pendente');

            $table->timestamps();

            $table->index(['document_analysis_id', 'tipo', 'status'], 'idx_deadlines_type_status');
            $table->index(['document_analysis_id', 'data_final_estimada'], 'idx_deadlines_due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_deadlines');
    }
};
