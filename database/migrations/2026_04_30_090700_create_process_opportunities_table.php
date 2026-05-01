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
        Schema::create('process_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();

            $table->text('descricao');
            $table->string('prioridade', 20)->default('media');
            $table->string('objetivo', 255)->nullable();
            $table->text('fundamento')->nullable();
            $table->text('ato_recomendado')->nullable();
            $table->unsignedTinyInteger('score')->nullable();

            $table->timestamps();

            $table->index(['document_analysis_id', 'prioridade'], 'idx_opportunities_priority');
            $table->index(['document_analysis_id', 'score'], 'idx_opportunities_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_opportunities');
    }
};
