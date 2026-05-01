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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value')->nullable();
            $table->string('type')->default('string'); // string, boolean, integer, json
            $table->string('group')->default('general'); // Para agrupar configurações
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Inserir configuração padrão de debug
        DB::table('settings')->insert([
            'key' => 'debug_save_analysis_files',
            'value' => 'false',
            'type' => 'boolean',
            'group' => 'debug',
            'description' => 'Quando ativado, salva arquivos de debug (.md) com os resultados das análises de documentos (MAP), consolidações (REDUCE) e parecer final no diretório storage/app/private/analises-debug/',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
