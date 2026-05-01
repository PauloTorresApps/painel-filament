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
            $table->index('current_phase', 'idx_doc_analyses_current_phase');
            $table->index(['status', 'created_at'], 'idx_doc_analyses_status_created');
            $table->index(['user_id', 'status', 'current_phase'], 'idx_doc_analyses_user_status_phase');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_analyses', function (Blueprint $table) {
            $table->dropIndex('idx_doc_analyses_current_phase');
            $table->dropIndex('idx_doc_analyses_status_created');
            $table->dropIndex('idx_doc_analyses_user_status_phase');
        });
    }
};
