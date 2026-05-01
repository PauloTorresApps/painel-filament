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
        Schema::create('process_inconsistencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();

            $table->string('tipo', 120)->nullable();
            $table->text('descricao');
            $table->json('eventos_relacionados')->nullable();
            $table->text('possivel_consequencia')->nullable();
            $table->unsignedTinyInteger('score')->nullable();

            $table->timestamps();

            $table->index(['document_analysis_id', 'tipo'], 'idx_inconsistencies_type');
            $table->index(['document_analysis_id', 'score'], 'idx_inconsistencies_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_inconsistencies');
    }
};
