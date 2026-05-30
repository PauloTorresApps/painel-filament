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
            if (!Schema::hasColumn('document_analyses', 'graph_run_id')) {
                $table->uuid('graph_run_id')->nullable()->after('langfuse_session_id');
                $table->index('graph_run_id', 'idx_doc_analyses_graph_run_id');
            }

            if (!Schema::hasColumn('document_analyses', 'graph_last_node')) {
                $table->string('graph_last_node')->nullable()->after('graph_run_id');
            }

            if (!Schema::hasColumn('document_analyses', 'graph_state')) {
                $table->json('graph_state')->nullable()->after('graph_last_node');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_analyses', function (Blueprint $table) {
            if (Schema::hasColumn('document_analyses', 'graph_state')) {
                $table->dropColumn('graph_state');
            }

            if (Schema::hasColumn('document_analyses', 'graph_last_node')) {
                $table->dropColumn('graph_last_node');
            }

            if (Schema::hasColumn('document_analyses', 'graph_run_id')) {
                $table->dropIndex('idx_doc_analyses_graph_run_id');
                $table->dropColumn('graph_run_id');
            }
        });
    }
};
