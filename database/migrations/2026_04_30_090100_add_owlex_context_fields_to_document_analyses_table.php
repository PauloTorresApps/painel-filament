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
            if (!Schema::hasColumn('document_analyses', 'parte_representada')) {
                $table->string('parte_representada', 100)->nullable()->after('numero_processo');
            }

            if (!Schema::hasColumn('document_analyses', 'papel_processual')) {
                $table->string('papel_processual', 100)->nullable()->after('parte_representada');
            }

            if (!Schema::hasColumn('document_analyses', 'objetivo_analise')) {
                $table->string('objetivo_analise', 40)->nullable()->after('papel_processual');
            }

            if (!Schema::hasColumn('document_analyses', 'prazo_em_curso')) {
                $table->string('prazo_em_curso', 255)->nullable()->after('objetivo_analise');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_analyses', function (Blueprint $table) {
            $columns = [
                'parte_representada',
                'papel_processual',
                'objetivo_analise',
                'prazo_em_curso',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('document_analyses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
