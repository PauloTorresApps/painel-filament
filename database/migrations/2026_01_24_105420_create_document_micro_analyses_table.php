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
        Schema::create('document_micro_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->onDelete('cascade');

            // Identificação do documento
            $table->integer('document_index')->comment('Índice do documento no array original');
            $table->string('id_documento', 255)->nullable()->comment('ID do documento no e-Proc');
            $table->string('descricao', 500)->nullable()->comment('Descrição do documento');

            // Resultado da análise
            $table->longText('micro_analysis')->nullable()->comment('Micro-análise gerada pela IA');
            $table->longText('extracted_text')->nullable()->comment('Texto extraído do documento');

            // Status do processamento
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending')
                ->comment('Status do processamento');
            $table->text('error_message')->nullable()->comment('Mensagem de erro se falhou');

            // Controle de reduce hierárquico
            $table->integer('reduce_level')->default(0)
                ->comment('0=map (documento original), 1+=reduce (consolidação)');
            $table->json('parent_ids')->nullable()
                ->comment('IDs das micro-análises que geraram esta (reduce)');

            // Metadados
            $table->integer('token_count')->nullable()->comment('Contagem de tokens da análise');
            $table->integer('processing_time_ms')->nullable()->comment('Tempo de processamento em ms');

            $table->timestamps();

            // Índices para consultas eficientes
            $table->index(['document_analysis_id', 'status'], 'idx_analysis_status');
            $table->index(['document_analysis_id', 'reduce_level'], 'idx_analysis_reduce_level');
            $table->index(['document_analysis_id', 'document_index'], 'idx_analysis_doc_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_micro_analyses');
    }
};
