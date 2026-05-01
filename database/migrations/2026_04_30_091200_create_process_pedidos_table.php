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
        Schema::create('process_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_analysis_id')
                ->constrained('document_analyses')
                ->cascadeOnDelete();
            $table->foreignId('document_micro_analysis_id')
                ->nullable()
                ->constrained('document_micro_analyses')
                ->nullOnDelete();

            $table->string('parte', 150)->nullable();
            $table->text('pedido');
            $table->text('fundamento')->nullable();
            $table->string('status', 60)->nullable();

            $table->timestamps();

            $table->index(['document_analysis_id', 'status'], 'idx_pedidos_analysis_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('process_pedidos');
    }
};
