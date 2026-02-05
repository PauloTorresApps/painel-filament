<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentMicroAnalysis extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'document_index',
        'id_documento',
        'descricao',
        'mimetype',
        'micro_analysis',
        'extracted_text',
        'status',
        'error_message',
        'reduce_level',
        'parent_ids',
        'token_count',
        'processing_time_ms',
        'timeline_events',
    ];

    protected $casts = [
        'document_index' => 'integer',
        'reduce_level' => 'integer',
        'parent_ids' => 'array',
        'token_count' => 'integer',
        'processing_time_ms' => 'integer',
        'timeline_events' => 'array',
    ];

    /**
     * Relacionamento com DocumentAnalysis
     */
    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }

    /**
     * Verifica se o documento é uma imagem
     */
    public function isImage(): bool
    {
        return $this->mimetype && str_starts_with($this->mimetype, 'image/');
    }

    /**
     * Verifica se é um resultado de MAP (documento original)
     */
    public function isMapResult(): bool
    {
        return $this->reduce_level === 0;
    }

    /**
     * Verifica se é um resultado de REDUCE (consolidação)
     */
    public function isReduceResult(): bool
    {
        return $this->reduce_level > 0;
    }

    /**
     * Verifica se está pendente
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Verifica se está em processamento
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Verifica se está completo
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Verifica se falhou
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Marca como em processamento
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /**
     * Marca como completo com a micro-análise
     */
    public function markAsCompleted(string $microAnalysis, ?int $tokenCount = null, ?int $processingTimeMs = null): void
    {
        // Extrai eventos da timeline do JSON embutido na resposta
        $timelineEvents = $this->extractTimelineEvents($microAnalysis);

        $this->update([
            'status' => 'completed',
            'micro_analysis' => $microAnalysis,
            'token_count' => $tokenCount,
            'processing_time_ms' => $processingTimeMs,
            'timeline_events' => $timelineEvents,
        ]);
    }

    /**
     * Extrai eventos da timeline do JSON embutido na resposta da IA
     */
    public function extractTimelineEvents(string $analysis): ?array
    {
        // Procura o bloco JSON entre as tags <timeline_json> e </timeline_json>
        if (preg_match('/<timeline_json>\s*([\s\S]*?)\s*<\/timeline_json>/i', $analysis, $matches)) {
            $jsonString = trim($matches[1]);

            // Remove possíveis blocos de código markdown
            $jsonString = preg_replace('/^```json?\s*/i', '', $jsonString);
            $jsonString = preg_replace('/\s*```$/', '', $jsonString);

            try {
                $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

                // Valida estrutura básica
                if (is_array($data)) {
                    return $data;
                }
            } catch (\JsonException $e) {
                // Log silencioso - não queremos falhar a análise por causa do JSON
                \Illuminate\Support\Facades\Log::warning('DocumentMicroAnalysis: Falha ao parsear timeline JSON', [
                    'micro_id' => $this->id,
                    'error' => $e->getMessage(),
                    'json_preview' => substr($jsonString, 0, 500),
                ]);
            }
        }

        return null;
    }

    /**
     * Retorna os eventos da timeline ordenados por data
     */
    public function getOrderedTimelineEvents(): array
    {
        $events = $this->timeline_events['eventos'] ?? [];

        usort($events, function ($a, $b) {
            $dateA = $a['data'] ?? '9999-99-99';
            $dateB = $b['data'] ?? '9999-99-99';
            return strcmp($dateA, $dateB);
        });

        return $events;
    }

    /**
     * Marca como falho
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Scope para micro-análises de nível MAP
     */
    public function scopeMapLevel($query)
    {
        return $query->where('reduce_level', 0);
    }

    /**
     * Scope para micro-análises de nível REDUCE específico
     */
    public function scopeReduceLevel($query, int $level)
    {
        return $query->where('reduce_level', $level);
    }

    /**
     * Scope para micro-análises completas
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope para micro-análises pendentes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope para micro-análises falhas
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
