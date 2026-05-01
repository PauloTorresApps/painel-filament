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
        Schema::table('contract_analyses', function (Blueprint $table) {
            // Metadados da análise
            $table->json('analysis_ai_metadata')->nullable()->after('analysis_result');

            // Metadados do parecer jurídico
            $table->json('legal_opinion_ai_metadata')->nullable()->after('legal_opinion_result');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_analyses', function (Blueprint $table) {
            $table->dropColumn(['analysis_ai_metadata', 'legal_opinion_ai_metadata']);
        });
    }
};
