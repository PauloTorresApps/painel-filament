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
        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('graph_name');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('status')->default('running');
            $table->string('current_node')->nullable();
            $table->json('state')->nullable();
            $table->timestamps();

            $table->index(['graph_name', 'entity_type', 'entity_id'], 'idx_pipeline_runs_graph_entity');
            $table->index(['entity_type', 'entity_id', 'created_at'], 'idx_pipeline_runs_entity_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipeline_runs');
    }
};
