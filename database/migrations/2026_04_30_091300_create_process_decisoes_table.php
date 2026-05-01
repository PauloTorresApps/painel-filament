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
        Schema::create('process_decisoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();
            $table->foreignId('document_micro_analysis_id')
                ->nullable()
                ->constrained('document_micro_analyses')
                ->nullOnDelete();

            $table->string('evento_ou_id', 255)->nullable();
            $table->date('data')->nullable();
            $table->string('autoridade', 150)->nullable();
            $table->text('conteudo');
            $table->text('efeito_juridico')->nullable();
            $table->string('resultado', 120)->nullable();

            $table->timestamps();

            $table->index(['document_analysis_id', 'data'], 'idx_decisoes_analysis_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_decisoes');
    }
};
