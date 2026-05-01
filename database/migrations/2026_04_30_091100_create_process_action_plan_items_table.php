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
        Schema::create('process_action_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_structured_opinion_id')
                ->constrained('process_structured_opinions')
                ->cascadeOnDelete();

            $table->string('prioridade', 20)->default('media');
            $table->text('ato_recomendado');
            $table->text('objetivo')->nullable();
            $table->text('fundamento')->nullable();
            $table->string('prazo', 120)->nullable();
            $table->unsignedTinyInteger('urgencia')->nullable();
            $table->unsignedTinyInteger('risco_de_nao_agir')->nullable();
            $table->json('documentos_necessarios')->nullable();
            $table->unsignedTinyInteger('grau_confianca')->nullable();
            $table->unsignedInteger('ordem')->default(0);

            $table->timestamps();

            $table->index(['process_structured_opinion_id', 'ordem'], 'idx_action_plan_order');
            $table->index(['process_structured_opinion_id', 'prioridade'], 'idx_action_plan_priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_action_plan_items');
    }
};
