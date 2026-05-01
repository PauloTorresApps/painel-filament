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
        Schema::table('document_analyses', function (Blueprint $table) {
            // Campos para Resumo Evolutivo
            $table->longText('evolutionary_summary')->nullable()->after('ai_analysis')
                ->comment('Resumo evolutivo acumulado durante o processamento');

            $table->integer('current_document_index')->default(0)->after('evolutionary_summary')
                ->comment('Índice do documento sendo processado atualmente (0-based)');

            $table->integer('processed_documents_count')->default(0)->after('current_document_index')
                ->comment('Quantidade de documentos já processados');

            $table->integer('total_documents')->default(0)->after('processed_documents_count')
                ->comment('Total de documentos a serem processados');

            $table->timestamp('last_processed_at')->nullable()->after('total_documents')
                ->comment('Timestamp do último documento processado');

            $table->boolean('is_resumable')->default(false)->after('last_processed_at')
                ->comment('Indica se esta análise pode ser retomada');

            // Índice para consultas de análises retomáveis
            $table->index(['status', 'is_resumable'], 'idx_status_resumable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_analyses', function (Blueprint $table) {
            $table->dropIndex('idx_status_resumable');
            $table->dropColumn([
                'evolutionary_summary',
                'current_document_index',
                'processed_documents_count',
                'total_documents',
                'last_processed_at',
                'is_resumable'
            ]);
        });
    }
};
