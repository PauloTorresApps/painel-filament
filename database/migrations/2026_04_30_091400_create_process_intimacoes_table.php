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
        Schema::create('process_intimacoes', function (Blueprint $table) {
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
            $table->string('tipo', 80)->nullable();
            $table->string('destinatario', 150)->nullable();
            $table->text('conteudo')->nullable();
            $table->string('prazo', 120)->nullable();
            $table->boolean('cumprida')->nullable();

            $table->timestamps();

            $table->index(['document_analysis_id', 'data'], 'idx_intimacoes_analysis_data');
            $table->index(['document_analysis_id', 'tipo'], 'idx_intimacoes_analysis_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_intimacoes');
    }
};
