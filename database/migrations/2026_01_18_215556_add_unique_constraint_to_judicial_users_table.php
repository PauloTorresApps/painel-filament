<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Primeiro, remove duplicatas mantendo apenas o registro mais recente
        $duplicates = DB::table('judicial_users')
            ->select('user_id', 'system_id')
            ->groupBy('user_id', 'system_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            // Mantém o registro mais recente (maior ID) e remove os outros
            $idsToDelete = DB::table('judicial_users')
                ->where('user_id', $duplicate->user_id)
                ->where('system_id', $duplicate->system_id)
                ->orderBy('id', 'desc')
                ->skip(1)
                ->pluck('id');

            DB::table('judicial_users')->whereIn('id', $idsToDelete)->delete();
        }

        Schema::table('judicial_users', function (Blueprint $table) {
            $table->unique(['user_id', 'system_id'], 'judicial_users_user_system_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('judicial_users', function (Blueprint $table) {
            $table->dropUnique('judicial_users_user_system_unique');
        });
    }
};
