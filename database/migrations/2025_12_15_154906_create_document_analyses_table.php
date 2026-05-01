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
        Schema::create('document_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('numero_processo')->index();
            $table->string('id_documento')->nullable();
            $table->string('descricao_documento')->nullable();
            $table->text('extracted_text')->nullable(); // Texto extraído do PDF
            $table->longText('ai_analysis')->nullable(); // Análise da IA
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->integer('total_characters')->nullable(); // Para estatísticas
            $table->integer('processing_time_ms')->nullable(); // Tempo de processamento
            $table->timestamps();

            // Índices para performance
            $table->index('status');
            $table->index(['user_id', 'numero_processo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_analyses');
    }
};
