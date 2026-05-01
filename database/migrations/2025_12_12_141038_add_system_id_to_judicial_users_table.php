<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\System;
use App\Models\JudicialUser;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Primeiro, adiciona a coluna system_id como nullable
        Schema::table('judicial_users', function (Blueprint $table) {
            $table->foreignId('system_id')->nullable()->after('user_id')->constrained()->onDelete('cascade');
        });

        // Migra os dados existentes
        $judicialUsers = JudicialUser::all();
        foreach ($judicialUsers as $judicialUser) {
            // Busca ou cria o sistema baseado no system_name
            $system = System::firstOrCreate(
                ['name' => $judicialUser->system_name],
                [
                    'description' => 'Sistema importado automaticamente',
                    'is_active' => true,
                ]
            );

            // Atualiza o judicial_user com o system_id
            $judicialUser->system_id = $system->id;
            $judicialUser->save();
        }

        // Agora remove a coluna system_name e torna system_id obrigatório
        Schema::table('judicial_users', function (Blueprint $table) {
            $table->dropColumn('system_name');
        });

        Schema::table('judicial_users', function (Blueprint $table) {
            $table->foreignId('system_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('judicial_users', function (Blueprint $table) {
            $table->dropForeign(['system_id']);
            $table->dropColumn('system_id');
            $table->string('system_name')->after('user_login');
        });
    }
};
