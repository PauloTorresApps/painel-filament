<?php

namespace App\Models;

use App\Models\ProcessAnalysis\ProcessEvent;
use App\Models\ProcessAnalysis\ProcessInventoryItem;
use App\Models\ProcessAnalysis\ProcessDecisao;
use App\Models\ProcessAnalysis\ProcessIntimacao;
use App\Models\ProcessAnalysis\ProcessPedido;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class DocumentMicroAnalysis extends Model
{
    protected $fillable = [
        'document_analysis_id',
        'document_index',
        'id_documento',
        'descricao',
        'mimetype',
        'original_content_path',
        'processing_strategy',
        'is_scanned',
        'file_annotation_hash',
        'micro_analysis',
        'extracted_text',
        'status',
        'error_message',
        'reduce_level',
        'parent_ids',
        'token_count',
        'processing_time_ms',
        'timeline_events',
        'aggregated_entities',
    ];

    protected $casts = [
        'document_index' => 'integer',
        'reduce_level' => 'integer',
        'parent_ids' => 'array',
        'token_count' => 'integer',
        'processing_time_ms' => 'integer',
        'timeline_events' => 'array',
        'aggregated_entities' => 'array',
        'is_scanned' => 'boolean',
    ];

    /**
     * Relacionamento com DocumentAnalysis
     */
    public function documentAnalysis(): BelongsTo
    {
        return $this->belongsTo(DocumentAnalysis::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(ProcessInventoryItem::class);
    }

    public function sourceEvents(): HasMany
    {
        return $this->hasMany(ProcessEvent::class, 'source_micro_analysis_id');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(ProcessPedido::class);
    }

    public function decisoes(): HasMany
    {
        return $this->hasMany(ProcessDecisao::class);
    }

    public function intimacoes(): HasMany
    {
        return $this->hasMany(ProcessIntimacao::class);
    }

    /**
     * Retorna as entidades agregadas (partes, valores, pontos-chave).
     * Sempre retorna arrays, mesmo se aggregated_entities for null.
     */
    public function getEntities(): array
    {
        return $this->aggregated_entities ?? [
            'partes_mencionadas' => [],
            'valores_monetarios' => [],
            'pontos_chave' => [],
        ];
    }

    /**
     * Verifica se o documento é uma imagem
     */
    public function isImage(): bool
    {
        return $this->mimetype && str_starts_with($this->mimetype, 'image/');
    }

    /**
     * Verifica se o documento é um PDF
     */
    public function isPdf(): bool
    {
        return $this->mimetype && str_contains(strtolower($this->mimetype), 'pdf');
    }

    /**
     * Verifica se o conteúdo original está disponível em disco
     */
    public function hasOriginalContent(): bool
    {
        return $this->original_content_path && Storage::disk('local')->exists($this->original_content_path);
    }

    /**
     * Lê o conteúdo original do disco e retorna como base64
     */
    public function getOriginalContentBase64(): ?string
    {
        if (!$this->hasOriginalContent()) {
            return null;
        }

        $content = Storage::disk('local')->get($this->original_content_path);

        return $content !== null ? base64_encode($content) : null;
    }

    /**
     * Remove o arquivo original do disco para liberar espaço
     */
    public function deleteOriginalContent(): void
    {
        if ($this->original_content_path && Storage::disk('local')->exists($this->original_content_path)) {
            Storage::disk('local')->delete($this->original_content_path);
            $this->update(['original_content_path' => null]);
        }
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
