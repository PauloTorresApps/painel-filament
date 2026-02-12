<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service responsável por extrair texto de documentos
 * 
 * Centraliza a lógica de extração de texto de diferentes formatos,
 * permitindo fácil extensão para novos formatos no futuro.
 */
class DocumentTextExtractor
{
    private PdfToTextService $pdfService;

    public function __construct()
    {
        $this->pdfService = new PdfToTextService();
    }

    /**
     * Extrai texto de um documento baseado no caminho do arquivo
     * 
     * @param string $storagePath Caminho do arquivo no Storage do Laravel
     * @return string Texto extraído
     * @throws \Exception Se o arquivo não existir ou formato não suportado
     */
    public function extractFromStorage(string $storagePath): string
    {
        $fullPath = Storage::path($storagePath);

        if (!file_exists($fullPath)) {
            throw new \Exception("Arquivo não encontrado: {$storagePath}");
        }

        return $this->extractFromPath($fullPath);
    }

    /**
     * Extrai texto de um documento baseado no caminho absoluto
     * 
     * @param string $absolutePath Caminho absoluto do arquivo
     * @return string Texto extraído
     * @throws \Exception Se formato não suportado
     */
    public function extractFromPath(string $absolutePath): string
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if (!$this->isSupported($extension)) {
            throw new \Exception("Formato de arquivo não suportado: {$extension}");
        }

        Log::info('DocumentTextExtractor: Extraindo texto', [
            'path' => $absolutePath,
            'extension' => $extension,
        ]);

        try {
            return match ($extension) {
                'pdf' => $this->extractFromPdf($absolutePath),
                default => throw new \Exception("Extração não implementada para: {$extension}"),
            };
        } catch (\Exception $e) {
            Log::error('DocumentTextExtractor: Erro ao extrair texto', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Verifica se um formato de arquivo é suportado
     * 
     * @param string $extension Extensão do arquivo (sem ponto)
     * @return bool
     */
    public function isSupported(string $extension): bool
    {
        return in_array(strtolower($extension), ['pdf']);
    }

    /**
     * Extrai texto de um arquivo PDF
     * 
     * @param string $path Caminho absoluto do PDF
     * @return string Texto extraído
     */
    private function extractFromPdf(string $path): string
    {
        $text = $this->pdfService->extractText($path);

        if (empty($text)) {
            throw new \Exception('Não foi possível extrair texto do PDF. O arquivo pode estar vazio ou corrompido.');
        }

        return $text;
    }

}
