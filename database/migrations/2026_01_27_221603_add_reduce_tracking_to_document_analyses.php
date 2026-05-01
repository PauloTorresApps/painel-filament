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
            // Fase atual do processamento: download, map, reduce, completed
            $table->string('current_phase', 20)->default('download')->after('status');

            // Progresso da fase REDUCE
            $table->unsignedTinyInteger('reduce_current_level')->default(0)->after('current_phase');
            $table->unsignedTinyInteger('reduce_total_levels')->default(0)->after('reduce_current_level');
            $table->unsignedInteger('reduce_processed_batches')->default(0)->after('reduce_total_levels');
            $table->unsignedInteger('reduce_total_batches')->default(0)->after('reduce_processed_batches');

            // Mensagem descritiva do progresso atual
            $table->string('progress_message', 255)->nullable()->after('reduce_total_batches');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'current_phase',
                'reduce_current_level',
                'reduce_total_levels',
                'reduce_processed_batches',
                'reduce_total_batches',
                'progress_message',
            ]);
        });
    }
};
