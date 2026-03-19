<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_benchmark_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedSmallInteger('window_days');
            $table->json('metrics');
            $table->json('slo_breaches')->nullable();
            $table->timestamps();

            $table->index(['snapshot_date', 'window_days'], 'idx_benchmark_snapshot_date_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_benchmark_snapshots');
    }
};
