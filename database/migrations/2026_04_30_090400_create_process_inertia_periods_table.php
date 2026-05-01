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
        Schema::create('process_inertia_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();

            $table->date('data_inicio')->nullable();
            $table->date('data_fim')->nullable();
            $table->string('responsavel_aparente', 120)->nullable();
            $table->text('possivel_consequencia')->nullable();
            $table->unsignedInteger('duracao_dias')->nullable();

            $table->timestamps();

            $table->index(['document_analysis_id', 'data_inicio', 'data_fim'], 'idx_inertia_analysis_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_inertia_periods');
    }
};
