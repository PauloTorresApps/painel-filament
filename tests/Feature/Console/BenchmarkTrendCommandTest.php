<?php

use App\Models\AnalysisBenchmarkSnapshot;
use Illuminate\Support\Facades\Artisan;

test('benchmark trend command returns expected trend json data', function () {
    AnalysisBenchmarkSnapshot::query()->create([
        'snapshot_date' => now()->subDays(2)->toDateString(),
        'window_days' => 7,
        'metrics' => [
            'judicial' => ['p95_total_ms' => 1200, 'docs_per_min' => 1.2],
            'contracts' => ['avg_analysis_ms' => 4000],
        ],
        'slo_breaches' => [],
    ]);

    AnalysisBenchmarkSnapshot::query()->create([
        'snapshot_date' => now()->subDay()->toDateString(),
        'window_days' => 7,
        'metrics' => [
            'judicial' => ['p95_total_ms' => 900, 'docs_per_min' => 1.5],
            'contracts' => ['avg_analysis_ms' => 3000],
        ],
        'slo_breaches' => [
            ['key' => 'judicial.p95_total_ms', 'actual' => 900, 'expected' => 800, 'comparator' => '<='],
        ],
    ]);

    $exitCode = Artisan::call('analysis:benchmark-trend', [
        '--days' => 30,
        '--window-days' => 7,
        '--format' => 'json',
    ]);

    $data = json_decode(trim(Artisan::output()), true);

    expect($exitCode)->toBe(0);
    expect($data['trend']['snapshot_count'])->toBe(2);
    expect($data['trend']['judicial_p95_ms_first'])->toBe(1200);
    expect($data['trend']['judicial_p95_ms_last'])->toBe(900);
    expect($data['trend']['slo_breach_snapshots'])->toBe(1);
});
