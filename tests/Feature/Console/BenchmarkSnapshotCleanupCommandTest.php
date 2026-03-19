<?php

use App\Models\AnalysisBenchmarkSnapshot;
use Illuminate\Support\Facades\Artisan;

test('benchmark snapshot cleanup removes only snapshots older than threshold', function () {
    AnalysisBenchmarkSnapshot::query()->create([
        'snapshot_date' => now()->subDays(120)->toDateString(),
        'window_days' => 7,
        'metrics' => ['judicial' => ['completed_analyses' => 1], 'contracts' => ['completed_analyses' => 0]],
    ]);

    AnalysisBenchmarkSnapshot::query()->create([
        'snapshot_date' => now()->subDays(10)->toDateString(),
        'window_days' => 7,
        'metrics' => ['judicial' => ['completed_analyses' => 1], 'contracts' => ['completed_analyses' => 0]],
    ]);

    $exitCode = Artisan::call('analysis:benchmark-snapshot-cleanup', [
        '--older-than-days' => 90,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(AnalysisBenchmarkSnapshot::query()->count())->toBe(1);
    expect(AnalysisBenchmarkSnapshot::query()->first()->snapshot_date->toDateString())->toBe(now()->subDays(10)->toDateString());
});
