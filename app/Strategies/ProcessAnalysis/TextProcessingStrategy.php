<?php

namespace App\Strategies\ProcessAnalysis;

use App\Contracts\AIProviderInterface;
use App\Models\DocumentMicroAnalysis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TextProcessingStrategy implements DocumentProcessingStrategy
{
    /**
     * Mimetypes que podem ser lidos como texto bruto do disco
     * quando a extração de texto falhar.
     */
    private const TEXT_BASED_MIMETYPES = [
        'text/html',
        'text/plain',
        'text/xml',
        'application/xml',
        'application/xhtml+xml',
    ];

    public function __construct(
        private readonly ?array $mapAnalysisSchema = null
    ) {}

    public function canHandle(DocumentMicroAnalysis $microAnalysis): bool
    {
        // Aceita se há texto extraído
        if (mb_strlen($microAnalysis->extracted_text ?? '') > 0) {
            return true;
        }

        // Fallback: aceita se há conteúdo original em formato textual (HTML, XML, etc.)
        if ($microAnalysis->hasOriginalContent() && $this->isTextBasedMimetype($microAnalysis->mimetype)) {
            return true;
        }

        // Último recurso: aceita qualquer documento para evitar RuntimeException
        // na cadeia de estratégias. O process() gerará uma nota descritiva
        // informando que o conteúdo não pôde ser extraído.
        return true;
    }

    public function process(
        DocumentMicroAnalysis $microAnalysis,
        AIProviderInterface $aiService,
        string $systemPrompt,
        string $documentPrompt,
        bool $deepThinkingEnabled
    ): string {
        $text = $microAnalysis->extracted_text ?? '';

        // Fallback: se não há texto extraído, lê o conteúdo original do disco
        if (mb_strlen($text) === 0 && $microAnalysis->hasOriginalContent()) {
            $text = $this->readOriginalAsText($microAnalysis);

            Log::info('TextProcessingStrategy: Usando conteúdo original como fallback', [
                'micro_id' => $microAnalysis->id,
                'mimetype' => $microAnalysis->mimetype,
                'text_length' => mb_strlen($text),
            ]);
        }

        $textLength = mb_strlen($text);

        // Se o texto ainda está vazio (ex.: HTML contendo apenas imagens escaneadas),
        // gera uma nota descritiva para a IA em vez de lançar exceção.
        // Isso permite que o documento seja registrado no parecer final,
        // mesmo que não haja conteúdo textual extraível.
        if ($textLength === 0) {
            $descricao = $microAnalysis->descricao ?? 'Documento sem descrição';
            $mimetype = $microAnalysis->mimetype ?? 'desconhecido';

            Log::warning('TextProcessingStrategy: Documento sem texto extraível, usando nota descritiva', [
                'micro_id' => $microAnalysis->id,
                'descricao' => $descricao,
                'mimetype' => $mimetype,
            ]);

            $text = "[NOTA: Este documento ({$descricao}) é do tipo {$mimetype} e não foi possível extrair texto do seu conteúdo. "
                  . "O documento pode conter imagens escaneadas ou conteúdo visual que não pôde ser processado como texto. "
                  . "Registre a existência deste documento no parecer, mencionando que seu conteúdo não pôde ser analisado textualmente.]";
        }

        $textLength = mb_strlen($text);

        Log::info('TextProcessingStrategy: Usando análise por texto extraído', [
            'micro_id' => $microAnalysis->id,
            'text_length' => $textLength,
            'structured_output' => config('services.openrouter.structured_map_enabled', false),
        ]);

        // Se structured outputs habilitado, usa JSON schema para resposta consistente
        if (config('services.openrouter.structured_map_enabled', false) && $this->mapAnalysisSchema) {
            try {
                return $aiService->analyzeSingleDocumentStructured(
                    $documentPrompt,
                    $text,
                    $this->mapAnalysisSchema,
                    $deepThinkingEnabled,
                    $systemPrompt
                );
            } catch (\Exception $e) {
                Log::warning('TextProcessingStrategy: Structured output falhou, usando texto livre', [
                    'micro_id' => $microAnalysis->id,
                    'error' => $e->getMessage(),
                ]);

                if (!config('services.openrouter.structured_map_fallback_to_text', false)) {
                    throw $e;
                }
            }
        }

        return $aiService->analyzeSingleDocument(
            $documentPrompt,
            $text,
            $deepThinkingEnabled,
            $systemPrompt
        );
    }

    /**
     * Verifica se o mimetype é baseado em texto (pode ser lido como string).
     */
    private function isTextBasedMimetype(?string $mimetype): bool
    {
        if (!$mimetype) {
            return false;
        }

        $mimetype = strtolower($mimetype);

        foreach (self::TEXT_BASED_MIMETYPES as $textMime) {
            if ($mimetype === $textMime || str_contains($mimetype, $textMime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lê o conteúdo original do disco como texto.
     * Para HTML, remove tags desnecessárias (script, style) para limpar o conteúdo.
     */
    private function readOriginalAsText(DocumentMicroAnalysis $microAnalysis): string
    {
        try {
            $rawContent = Storage::disk('local')->get($microAnalysis->original_content_path);

            if ($rawContent === null) {
                return '';
            }

            $mimetype = strtolower($microAnalysis->mimetype ?? '');

            // Para HTML, faz limpeza básica de tags não-textuais
            if (str_contains($mimetype, 'html')) {
                // Remove scripts e styles
                $rawContent = preg_replace('/<script[^>]*>[\s\S]*?<\/script>/i', '', $rawContent);
                $rawContent = preg_replace('/<style[^>]*>[\s\S]*?<\/style>/i', '', $rawContent);
                // Remove tags HTML, mantém conteúdo
                $rawContent = strip_tags($rawContent);
                // Normaliza espaços
                $rawContent = preg_replace('/\s+/', ' ', $rawContent);
                $rawContent = trim($rawContent);
            }

            return $rawContent;
        } catch (\Exception $e) {
            Log::warning('TextProcessingStrategy: Falha ao ler conteúdo original', [
                'micro_id' => $microAnalysis->id,
                'path' => $microAnalysis->original_content_path,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }
}
