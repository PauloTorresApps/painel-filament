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
        Schema::create('process_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();
            $table->foreignId('document_micro_analysis_id')
                ->nullable()
                ->constrained('document_micro_analyses')
                ->nullOnDelete();

            $table->string('evento_ou_id', 255)->nullable();
            $table->date('data')->nullable();
            $table->string('tipo', 120)->nullable();
            $table->string('classificacao', 40)->default('outro');
            $table->text('resumo')->nullable();
            $table->unsignedTinyInteger('relevancia')->nullable();

            $table->foreignId('duplicado_de_item_id')
                ->nullable()
                ->constrained('process_inventory_items')
                ->nullOnDelete();

            $table->boolean('legivel')->default(true);
            $table->boolean('completo')->default(true);
            $table->text('observacoes')->nullable();
            $table->string('content_hash', 64)->nullable();

            $table->timestamps();

            $table->index(['document_analysis_id', 'data'], 'idx_inventory_analysis_data');
            $table->index(['document_analysis_id', 'classificacao'], 'idx_inventory_analysis_class');
            $table->index(['document_analysis_id', 'legivel', 'completo'], 'idx_inventory_quality');
            $table->index(['document_analysis_id', 'content_hash'], 'idx_inventory_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_inventory_items');
    }
};
