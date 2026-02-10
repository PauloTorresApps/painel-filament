<?php

namespace App\Services;

use Spatie\PdfToText\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PdfToTextService
{
    /**
     * Mínimo de caracteres por página para considerar que há texto extraível.
     * PDFs escaneados geralmente retornam < 100 caracteres por página.
     */
    private const MIN_CHARS_PER_PAGE = 100;

    /**
     * Mínimo de caracteres totais para considerar que o texto extraído é útil.
     * Abaixo disso, sempre tenta OCR independente do número de páginas.
     */
    private const MIN_TOTAL_CHARS = 200;

    /**
     * Converte um arquivo PDF em texto.
     * Detecta automaticamente PDFs escaneados e aplica OCR quando necessário.
     *
     * @param string $pdfContent Conteúdo do PDF em base64
     * @param string|null $tempFileName Nome personalizado para o arquivo temporário
     * @return string Texto extraído do PDF
     * @throws \Exception
     */
    public function extractText(string $pdfContent, ?string $tempFileName = null): string
    {
        $tempPath = null;

        try {
            // Decodifica o base64
            $decodedContent = base64_decode($pdfContent);

            if ($decodedContent === false) {
                throw new \Exception('Falha ao decodificar conteúdo base64');
            }

            // Cria arquivo temporário
            $tempPath = $this->createTempFile($decodedContent, $tempFileName);

            // Primeiro, tenta extrair texto normalmente
            $text = $this->extractTextFromFile($tempPath);
            $pageCount = $this->getPageCount($tempPath);

            // Verifica se o PDF parece ser escaneado (pouco texto extraído)
            $totalChars = mb_strlen($text);
            $charsPerPage = $pageCount > 0 ? $totalChars / $pageCount : 0;

            Log::info('PdfToTextService: Análise inicial do PDF', [
                'file' => $tempFileName,
                'pages' => $pageCount,
                'total_chars' => $totalChars,
                'chars_per_page' => round($charsPerPage, 2),
            ]);

            // Aplica OCR se:
            // 1. Texto total é muito pequeno (< MIN_TOTAL_CHARS), OU
            // 2. Texto por página é muito pequeno (< MIN_CHARS_PER_PAGE)
            $needsOcr = $totalChars < self::MIN_TOTAL_CHARS || $charsPerPage < self::MIN_CHARS_PER_PAGE;

            if ($needsOcr && $this->isOcrAvailable()) {
                Log::info('PdfToTextService: Texto insuficiente, aplicando OCR', [
                    'file' => $tempFileName,
                    'total_chars' => $totalChars,
                    'chars_per_page' => round($charsPerPage, 2),
                    'min_total_threshold' => self::MIN_TOTAL_CHARS,
                    'min_per_page_threshold' => self::MIN_CHARS_PER_PAGE,
                ]);

                $ocrText = $this->extractTextWithOcr($tempPath, $pageCount);
                $ocrChars = mb_strlen($ocrText);

                // Usa o texto do OCR se extraiu algo significativo
                // Ou se o texto original era praticamente inexistente
                if ($ocrChars > 0 && ($ocrChars > $totalChars || $totalChars < 50)) {
                    $text = $ocrText;

                    Log::info('PdfToTextService: Usando texto do OCR', [
                        'file' => $tempFileName,
                        'original_chars' => $totalChars,
                        'ocr_chars' => $ocrChars,
                    ]);
                } else {
                    Log::info('PdfToTextService: OCR não melhorou resultado, mantendo texto original', [
                        'file' => $tempFileName,
                        'original_chars' => $totalChars,
                        'ocr_chars' => $ocrChars,
                    ]);
                }
            } elseif ($needsOcr && !$this->isOcrAvailable()) {
                Log::warning('PdfToTextService: PDF precisa de OCR mas Tesseract não está disponível', [
                    'file' => $tempFileName,
                    'total_chars' => $totalChars,
                ]);
            }

            // Limpa e normaliza o texto
            $text = $this->normalizeText($text);

            return $text;

        } catch (\Exception $e) {
            Log::error('Erro ao extrair texto do PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        } finally {
            // Sempre limpa o arquivo temporário
            $this->cleanupTempFile($tempPath);
        }
    }

    /**
     * Cria um arquivo temporário com o conteúdo do PDF
     */
    private function createTempFile(string $content, ?string $fileName = null): string
    {
        $fileName = $fileName ?? 'pdf_' . uniqid() . '.pdf';
        $tempPath = sys_get_temp_dir() . '/' . $fileName;

        if (file_put_contents($tempPath, $content) === false) {
            throw new \Exception('Falha ao criar arquivo temporário');
        }

        return $tempPath;
    }

    /**
     * Extrai texto de um arquivo PDF diretamente pelo caminho
     *
     * @param string $filePath Caminho completo do arquivo PDF
     * @return string Texto extraído do PDF
     * @throws \Exception
     */
    public function extractTextFromPath(string $filePath): string
    {
        try {
            if (!file_exists($filePath)) {
                throw new \Exception("Arquivo não encontrado: {$filePath}");
            }

            // Primeiro, tenta extrair texto normalmente
            $text = $this->extractTextFromFile($filePath);
            $pageCount = $this->getPageCount($filePath);

            // Verifica se o PDF parece ser escaneado
            $totalChars = mb_strlen($text);
            $charsPerPage = $pageCount > 0 ? $totalChars / $pageCount : 0;

            // Aplica OCR se texto total ou por página for insuficiente
            $needsOcr = $totalChars < self::MIN_TOTAL_CHARS || $charsPerPage < self::MIN_CHARS_PER_PAGE;

            if ($needsOcr && $this->isOcrAvailable()) {
                Log::info('PdfToTextService: Texto insuficiente, aplicando OCR', [
                    'file_path' => $filePath,
                    'total_chars' => $totalChars,
                    'chars_per_page' => round($charsPerPage, 2),
                ]);

                $ocrText = $this->extractTextWithOcr($filePath, $pageCount);
                $ocrChars = mb_strlen($ocrText);

                // Usa OCR se extraiu algo significativo ou texto original era mínimo
                if ($ocrChars > 0 && ($ocrChars > $totalChars || $totalChars < 50)) {
                    $text = $ocrText;

                    Log::info('PdfToTextService: Usando texto do OCR', [
                        'file_path' => $filePath,
                        'original_chars' => $totalChars,
                        'ocr_chars' => $ocrChars,
                    ]);
                }
            } elseif ($needsOcr && !$this->isOcrAvailable()) {
                Log::warning('PdfToTextService: PDF precisa de OCR mas Tesseract não está disponível', [
                    'file_path' => $filePath,
                    'total_chars' => $totalChars,
                ]);
            }

            return $this->normalizeText($text);

        } catch (\Exception $e) {
            Log::error('Erro ao extrair texto do PDF', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Extrai texto do arquivo PDF usando pdftotext
     */
    private function extractTextFromFile(string $filePath): string
    {
        try {
            $pdf = new Pdf();
            return $pdf->setPdf($filePath)->text();
        } catch (\Exception $e) {
            throw new \Exception(
                'pdftotext não está disponível. Instale com: sudo apt-get install poppler-utils'
            );
        }
    }

    /**
     * Obtém o número de páginas do PDF
     */
    private function getPageCount(string $filePath): int
    {
        $output = [];
        $returnCode = 0;

        // Usa pdfinfo para obter informações do PDF
        exec(sprintf('pdfinfo %s 2>/dev/null | grep "Pages:"', escapeshellarg($filePath)), $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            // Extrai o número de páginas da saída "Pages:          5"
            if (preg_match('/Pages:\s*(\d+)/', $output[0], $matches)) {
                return (int) $matches[1];
            }
        }

        // Fallback: assume 1 página se não conseguir determinar
        return 1;
    }

    /**
     * Verifica se as ferramentas de OCR estão disponíveis
     */
    private function isOcrAvailable(): bool
    {
        // Verifica se pdftoppm e tesseract estão instalados
        $pdftoppmOutput = [];
        $tesseractOutput = [];

        exec('which pdftoppm 2>/dev/null', $pdftoppmOutput, $pdftoppmCode);
        exec('which tesseract 2>/dev/null', $tesseractOutput, $tesseractCode);

        return $pdftoppmCode === 0 && $tesseractCode === 0;
    }

    /**
     * Extrai texto do PDF usando OCR (converte páginas em imagens e aplica Tesseract)
     */
    private function extractTextWithOcr(string $pdfPath, int $pageCount): string
    {
        $tempDir = sys_get_temp_dir() . '/pdf_ocr_' . uniqid();
        $extractedTexts = [];

        try {
            // Cria diretório temporário para as imagens
            if (!mkdir($tempDir, 0755, true)) {
                throw new \Exception('Falha ao criar diretório temporário para OCR');
            }

            // Limita a quantidade de páginas para evitar processamento excessivo
            $maxPages = min($pageCount, 50);

            // Converte PDF para imagens PNG (uma por página)
            $outputPrefix = $tempDir . '/page';
            $command = sprintf(
                'pdftoppm -png -r 300 -l %d %s %s 2>/dev/null',
                $maxPages,
                escapeshellarg($pdfPath),
                escapeshellarg($outputPrefix)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                Log::warning('PdfToTextService: pdftoppm falhou', [
                    'return_code' => $returnCode,
                ]);
                return '';
            }

            // Processa cada imagem gerada com OCR
            $imageFiles = glob($tempDir . '/page-*.png');

            if (empty($imageFiles)) {
                // Tenta padrão alternativo (sem hífen)
                $imageFiles = glob($tempDir . '/page*.png');
            }

            // Ordena os arquivos numericamente
            usort($imageFiles, function ($a, $b) {
                preg_match('/(\d+)\.png$/', $a, $matchA);
                preg_match('/(\d+)\.png$/', $b, $matchB);
                return ((int)($matchA[1] ?? 0)) - ((int)($matchB[1] ?? 0));
            });

            foreach ($imageFiles as $index => $imageFile) {
                $pageText = $this->runTesseractOnImage($imageFile);
                if (!empty($pageText)) {
                    $extractedTexts[] = "--- Página " . ($index + 1) . " ---\n" . $pageText;
                }
            }

            return implode("\n\n", $extractedTexts);

        } finally {
            // Limpa diretório temporário e todos os arquivos
            $this->cleanupDirectory($tempDir);
        }
    }

    /**
     * Executa Tesseract OCR em uma imagem, com pré-processamento via ImageMagick
     */
    private function runTesseractOnImage(string $imagePath): string
    {
        $preprocessedPath = null;

        try {
            // Pré-processa a imagem se ImageMagick estiver disponível
            $preprocessedPath = $this->preprocessImageForOcr($imagePath);
            $ocrInputPath = $preprocessedPath ?? $imagePath;

            $outputFile = sys_get_temp_dir() . '/tesseract_' . uniqid();

            // Executa Tesseract com:
            // --oem 1: Motor LSTM neural network (mais preciso)
            // --psm 6: Assume bloco uniforme de texto (melhor para documentos)
            $command = sprintf(
                'tesseract %s %s -l por+eng --oem 1 --psm 6 2>/dev/null',
                escapeshellarg($ocrInputPath),
                escapeshellarg($outputFile)
            );

            exec($command, $output, $returnCode);

            $textFile = $outputFile . '.txt';

            if (!file_exists($textFile)) {
                return '';
            }

            $text = file_get_contents($textFile);
            @unlink($textFile);

            return $text !== false ? $text : '';

        } finally {
            if ($preprocessedPath && file_exists($preprocessedPath)) {
                @unlink($preprocessedPath);
            }
        }
    }

    /**
     * Pré-processa imagem para melhorar resultado do OCR
     * Usa ImageMagick para: escala de cinza, normalização, binarização, deskew
     */
    private function preprocessImageForOcr(string $imagePath): ?string
    {
        $output = [];
        $returnCode = 0;
        exec('which convert 2>/dev/null', $output, $returnCode);

        if ($returnCode !== 0) {
            return null;
        }

        $outputPath = sys_get_temp_dir() . '/pdf_ocr_preprocessed_' . uniqid() . '.png';

        $command = sprintf(
            'convert %s '
            . '-colorspace Gray '
            . '-normalize '
            . '-threshold 50%% '
            . '-despeckle '
            . '-deskew 40%% '
            . '-strip '
            . '%s 2>/dev/null',
            escapeshellarg($imagePath),
            escapeshellarg($outputPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            return null;
        }

        return $outputPath;
    }

    /**
     * Normaliza o texto extraído
     */
    private function normalizeText(string $text): string
    {
        // Remove caracteres de controle
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Normaliza quebras de linha
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove linhas em branco excessivas (mais de 2 seguidas)
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        // Remove espaços no início e fim
        $text = trim($text);

        return $text;
    }

    /**
     * Remove arquivo temporário
     */
    private function cleanupTempFile(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Remove diretório e todo seu conteúdo
     */
    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    /**
     * Verifica se o pdftotext está instalado no sistema
     */
    public function isPdfToTextInstalled(): bool
    {
        try {
            $pdf = new Pdf();
            $pdf->setBinary('/usr/bin/pdftotext');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verifica se o suporte completo a OCR está disponível
     */
    public function isFullOcrSupported(): bool
    {
        return $this->isOcrAvailable();
    }
}
