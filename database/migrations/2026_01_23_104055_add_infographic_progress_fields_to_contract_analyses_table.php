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
            $table->unsignedTinyInteger('infographic_progress_percent')->default(0)->after('infographic_status');
            $table->string('infographic_progress_message')->nullable()->after('infographic_progress_percent');
            $table->string('infographic_current_phase')->nullable()->after('infographic_progress_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'infographic_progress_percent',
                'infographic_progress_message',
                'infographic_current_phase',
            ]);
        });
    }
};
