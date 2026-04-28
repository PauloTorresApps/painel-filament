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
            if (!Schema::hasColumn('document_analyses', 'analysis_ai_metadata')) {
                $table->json('analysis_ai_metadata')->nullable()->after('ai_analysis');
            }

            if (!Schema::hasColumn('document_analyses', 'langfuse_trace_id')) {
                $table->uuid('langfuse_trace_id')->nullable()->after('analysis_ai_metadata');
                $table->index('langfuse_trace_id');
            }

            if (!Schema::hasColumn('document_analyses', 'langfuse_session_id')) {
                $table->uuid('langfuse_session_id')->nullable()->after('langfuse_trace_id');
                $table->index('langfuse_session_id');
            }
        });

        Schema::table('contract_analyses', function (Blueprint $table) {
            if (!Schema::hasColumn('contract_analyses', 'langfuse_trace_id')) {
                $table->uuid('langfuse_trace_id')->nullable()->after('infographic_ai_metadata');
                $table->index('langfuse_trace_id');
            }

            if (!Schema::hasColumn('contract_analyses', 'langfuse_session_id')) {
                $table->uuid('langfuse_session_id')->nullable()->after('langfuse_trace_id');
                $table->index('langfuse_session_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_analyses', function (Blueprint $table) {
            if (Schema::hasColumn('document_analyses', 'langfuse_session_id')) {
                $table->dropIndex(['langfuse_session_id']);
                $table->dropColumn('langfuse_session_id');
            }

            if (Schema::hasColumn('document_analyses', 'langfuse_trace_id')) {
                $table->dropIndex(['langfuse_trace_id']);
                $table->dropColumn('langfuse_trace_id');
            }

            if (Schema::hasColumn('document_analyses', 'analysis_ai_metadata')) {
                $table->dropColumn('analysis_ai_metadata');
            }
        });

        Schema::table('contract_analyses', function (Blueprint $table) {
            if (Schema::hasColumn('contract_analyses', 'langfuse_session_id')) {
                $table->dropIndex(['langfuse_session_id']);
                $table->dropColumn('langfuse_session_id');
            }

            if (Schema::hasColumn('contract_analyses', 'langfuse_trace_id')) {
                $table->dropIndex(['langfuse_trace_id']);
                $table->dropColumn('langfuse_trace_id');
            }
        });
    }
};
