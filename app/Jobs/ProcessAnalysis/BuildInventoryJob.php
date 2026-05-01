<?php

namespace App\Jobs\ProcessAnalysis;

use App\Jobs\Middleware\OtelJobMiddleware;
use App\Models\DocumentAnalysis;
use App\Models\ProcessAnalysis\ProcessInventoryItem;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class BuildInventoryJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;
    public int $tries;
    public int $backoff;

    public function __construct(
        public int $analysisId,
        public string $aiProvider,
        public bool $deepThinkingEnabled,
        public array $contextoDados,
        public ?string $aiModelId,
        public int $userId,
        public string $reduceStrategy = 'auto',
        public ?string $mapModelId = null
    ) {
        $this->timeout = config('analysis.jobs.build_inventory.timeout', 300);
        $this->tries = config('analysis.jobs.build_inventory.tries', 2);
        $this->backoff = config('analysis.jobs.build_inventory.backoff', 30);
    }

    public function middleware(): array
    {
        return [new OtelJobMiddleware()];
    }

    public function handle(): void
    {
        $analysis = DocumentAnalysis::find($this->analysisId);

        if (!$analysis || $analysis->status === 'cancelled') {
            return;
        }

        $analysis->update([
            'current_phase' => DocumentAnalysis::PHASE_INVENTORY,
            'progress_message' => 'Consolidando inventario documental...',
            'last_processed_at' => now(),
        ]);

        $documentsById = collect($analysis->job_parameters['documentos'] ?? [])
            ->keyBy(fn (array $doc) => (string) ($doc['idDocumento'] ?? ''));

        $minLegibleChars = (int) config('analysis.owlex.inventory.min_text_chars_legible', 120);
        $hashAlgorithm = (string) config('analysis.owlex.inventory.duplicate_hash_algorithm', 'sha256');

        $hashToMasterItem = [];

        ProcessInventoryItem::where('document_analysis_id', $analysis->id)->delete();

        $microAnalyses = $analysis->microAnalyses()
            ->where('reduce_level', 0)
            ->orderBy('document_index')
            ->get();

        foreach ($microAnalyses as $micro) {
            $sourceDoc = $documentsById->get((string) ($micro->id_documento ?? ''), []);
            $extractedText = trim((string) ($micro->extracted_text ?? ''));
            $description = (string) ($micro->descricao ?? ($sourceDoc['descricao'] ?? 'Documento sem descricao'));
            $isImage = str_starts_with((string) ($micro->mimetype ?? ''), 'image/');

            $hasUsefulText = mb_strlen($extractedText) >= $minLegibleChars;
            $legivel = $micro->status !== 'failed' && ($hasUsefulText || $isImage);
            $completo = $micro->status !== 'failed' && !empty($micro->id_documento);

            $contentHashBase = $extractedText !== ''
                ? $extractedText
                : (string) ($micro->id_documento ?? $description);
            $contentHash = hash($hashAlgorithm, $contentHashBase);

            $masterItemId = $hashToMasterItem[$contentHash] ?? null;

            $item = ProcessInventoryItem::create([
                'document_analysis_id' => $analysis->id,
                'document_micro_analysis_id' => $micro->id,
                'evento_ou_id' => (string) ($sourceDoc['idMovimento'] ?? $sourceDoc['idEvento'] ?? $micro->id_documento ?? ''),
                'data' => $this->resolveDate($sourceDoc),
                'tipo' => (string) ($sourceDoc['tipoDocumento'] ?? $sourceDoc['tipo'] ?? ''),
                'classificacao' => $this->classifyDocument($description),
                'resumo' => mb_substr($description, 0, 1000),
                'relevancia' => $this->estimateRelevance($description),
                'duplicado_de_item_id' => $masterItemId,
                'legivel' => $legivel,
                'completo' => $completo,
                'observacoes' => $this->buildObservation($micro->status, $hasUsefulText, $isImage),
                'content_hash' => $contentHash,
            ]);

            if ($masterItemId === null) {
                $hashToMasterItem[$contentHash] = $item->id;
            }
        }

        Log::info('BuildInventoryJob: Inventario consolidado', [
            'analysis_id' => $analysis->id,
            'inventory_items' => ProcessInventoryItem::where('document_analysis_id', $analysis->id)->count(),
        ]);

        DispatchMapPhaseJob::dispatch(
            $analysis->id,
            $this->aiProvider,
            $this->deepThinkingEnabled,
            $this->contextoDados,
            $this->aiModelId,
            $this->userId,
            $this->reduceStrategy,
            $this->mapModelId
        )->onQueue('analysis');
    }

    private function classifyDocument(string $description): string
    {
        $description = mb_strtolower($description);

        return match (true) {
            str_contains($description, 'peticao inicial') => 'peca_inicial',
            str_contains($description, 'contestacao') => 'contestacao',
            str_contains($description, 'replica') => 'replica',
            str_contains($description, 'sentenca') => 'sentenca',
            str_contains($description, 'acordao') => 'acordao',
            str_contains($description, 'despacho') => 'despacho',
            str_contains($description, 'decisao') => 'decisao',
            str_contains($description, 'certidao') => 'certidao',
            str_contains($description, 'intimacao') => 'intimacao',
            str_contains($description, 'laudo') => 'laudo',
            str_contains($description, 'calculo') => 'calculo',
            str_contains($description, 'recurso') => 'recurso',
            str_contains($description, 'anexo') => 'anexo',
            default => 'outro',
        };
    }

    private function estimateRelevance(string $description): int
    {
        $description = mb_strtolower($description);

        return match (true) {
            str_contains($description, 'sentenca'),
            str_contains($description, 'acordao'),
            str_contains($description, 'decisao') => 95,
            str_contains($description, 'peticao inicial'),
            str_contains($description, 'contestacao'),
            str_contains($description, 'recurso') => 85,
            str_contains($description, 'intimacao'),
            str_contains($description, 'despacho') => 70,
            default => 50,
        };
    }

    private function resolveDate(array $sourceDoc): ?string
    {
        foreach (['data', 'dataMovimento', 'dataDocumento', 'dataHoraMovimento'] as $field) {
            $value = $sourceDoc[$field] ?? null;

            if (empty($value)) {
                continue;
            }

            try {
                return Carbon::parse((string) $value)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function buildObservation(string $microStatus, bool $hasUsefulText, bool $isImage): ?string
    {
        if ($microStatus === 'failed') {
            return 'Falha na ingestao do documento';
        }

        if ($hasUsefulText) {
            return null;
        }

        if ($isImage) {
            return 'Documento de imagem sem OCR suficiente; dependera de analise multimodal';
        }

        return 'Texto insuficiente para validacao de legibilidade';
    }
}
