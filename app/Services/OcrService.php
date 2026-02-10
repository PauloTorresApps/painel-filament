<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class OcrService
{
    /**
     * Resolução mínima recomendada para OCR (em pixels de largura)
     * Imagens menores são ampliadas para melhorar a precisão
     */
    private const MIN_WIDTH_FOR_OCR = 2000;

    /**
     * Extrai texto de uma imagem usando Tesseract OCR com pré-processamento
     *
     * @param string $imageContent Conteúdo da imagem em base64
     * @param string $mimetype Tipo MIME da imagem
     * @param string|null $tempFileName Nome personalizado para o arquivo temporário
     * @return string Texto extraído da imagem
     * @throws \Exception
     */
    public function extractText(string $imageContent, string $mimetype, ?string $tempFileName = null): string
    {
        $tempPath = null;
        $preprocessedPath = null;

        try {
            // Decodifica o base64
            $decodedContent = base64_decode($imageContent);

            if ($decodedContent === false) {
                throw new \Exception('Falha ao decodificar conteúdo base64 da imagem');
            }

            // Determina a extensão baseada no mimetype
            $extension = $this->getExtensionFromMimetype($mimetype);

            // Cria arquivo temporário
            $tempPath = $this->createTempFile($decodedContent, $extension, $tempFileName);

            // Pré-processa a imagem para melhorar OCR
            $preprocessedPath = $this->preprocessImage($tempPath, $tempFileName);

            // Usa a imagem pré-processada se disponível, senão usa a original
            $ocrInputPath = $preprocessedPath ?? $tempPath;

            // Extrai texto usando Tesseract
            $text = $this->runTesseract($ocrInputPath);

            // Se o pré-processamento não ajudou, tenta com a imagem original
            if (empty(trim($text)) && $preprocessedPath !== null) {
                Log::info('OcrService: Pré-processamento não gerou resultado, tentando imagem original', [
                    'file' => $tempFileName,
                ]);
                $text = $this->runTesseract($tempPath);
            }

            // Limpa e normaliza o texto
            $text = $this->normalizeText($text);

            Log::info('OcrService: Texto extraído com sucesso', [
                'mimetype' => $mimetype,
                'chars_extracted' => mb_strlen($text),
                'preprocessed' => $preprocessedPath !== null,
            ]);

            return $text;

        } catch (\Exception $e) {
            Log::error('OcrService: Erro ao extrair texto da imagem', [
                'error' => $e->getMessage(),
                'mimetype' => $mimetype,
            ]);
            throw $e;
        } finally {
            // Sempre limpa os arquivos temporários
            $this->cleanupTempFile($tempPath);
            $this->cleanupTempFile($preprocessedPath);
        }
    }

    /**
     * Verifica se o Tesseract está instalado
     */
    public function isAvailable(): bool
    {
        $output = [];
        $returnCode = 0;
        exec('which tesseract 2>/dev/null', $output, $returnCode);

        return $returnCode === 0 && !empty($output);
    }

    /**
     * Retorna a versão do Tesseract
     */
    public function getVersion(): ?string
    {
        $output = [];
        $returnCode = 0;
        exec('tesseract --version 2>&1', $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return $output[0] ?? null;
        }

        return null;
    }

    /**
     * Verifica se o ImageMagick está disponível para pré-processamento
     */
    public function isImageMagickAvailable(): bool
    {
        $output = [];
        $returnCode = 0;
        exec('which convert 2>/dev/null', $output, $returnCode);

        return $returnCode === 0 && !empty($output);
    }

    /**
     * Pré-processa a imagem para melhorar a qualidade do OCR
     *
     * Pipeline:
     * 1. Converte para escala de cinza
     * 2. Amplia imagem se resolução for baixa
     * 3. Normaliza contraste
     * 4. Aplica binarização adaptativa (threshold)
     * 5. Remove ruído (despeckle)
     * 6. Corrige inclinação (deskew)
     */
    private function preprocessImage(string $inputPath, ?string $identifier = null): ?string
    {
        if (!$this->isImageMagickAvailable()) {
            Log::info('OcrService: ImageMagick não disponível, pulando pré-processamento');
            return null;
        }

        try {
            $outputPath = sys_get_temp_dir() . '/ocr_preprocessed_' . ($identifier ?? uniqid()) . '.png';

            // Obtém dimensões da imagem para decidir se precisa de upscale
            $identifyCmd = sprintf(
                'identify -format "%%w" %s 2>/dev/null',
                escapeshellarg($inputPath)
            );
            $width = (int) trim(shell_exec($identifyCmd) ?? '0');

            // Monta o pipeline de pré-processamento do ImageMagick
            $resizeOpt = '';
            if ($width > 0 && $width < self::MIN_WIDTH_FOR_OCR) {
                // Amplia a imagem mantendo proporção
                $scale = (int) ceil((self::MIN_WIDTH_FOR_OCR / $width) * 100);
                $resizeOpt = "-resize {$scale}%";
            }

            $command = sprintf(
                'convert %s '
                . '-colorspace Gray '           // 1. Escala de cinza
                . '%s '                          // 2. Resize (se necessário)
                . '-normalize '                  // 3. Normaliza contraste
                . '-threshold 50%% '             // 4. Binarização
                . '-despeckle '                  // 5. Remove ruído
                . '-deskew 40%% '                // 6. Corrige inclinação
                . '-strip '                      // Remove metadados
                . '%s 2>/dev/null',
                escapeshellarg($inputPath),
                $resizeOpt,
                escapeshellarg($outputPath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($outputPath)) {
                Log::warning('OcrService: Pré-processamento falhou, usando imagem original', [
                    'return_code' => $returnCode,
                    'file' => $identifier,
                ]);
                return null;
            }

            Log::info('OcrService: Imagem pré-processada com sucesso', [
                'file' => $identifier,
                'original_width' => $width,
                'resized' => !empty($resizeOpt),
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::warning('OcrService: Erro no pré-processamento', [
                'error' => $e->getMessage(),
                'file' => $identifier,
            ]);
            return null;
        }
    }

    /**
     * Executa o Tesseract OCR no arquivo
     *
     * Usa OEM 1 (LSTM neural net) + PSM 6 (uniform block of text)
     * para melhor precisão em documentos jurídicos escaneados
     */
    private function runTesseract(string $filePath): string
    {
        // Verifica se o Tesseract está disponível
        if (!$this->isAvailable()) {
            throw new \Exception('Tesseract OCR não está instalado. Instale com: apt-get install tesseract-ocr tesseract-ocr-por');
        }

        // Executa Tesseract com:
        // --oem 1: Motor LSTM neural network (mais preciso)
        // --psm 6: Assume bloco uniforme de texto (melhor para documentos)
        // -l por+eng: Suporte a português e inglês
        $outputFile = sys_get_temp_dir() . '/ocr_output_' . uniqid();
        $command = sprintf(
            'tesseract %s %s -l por+eng --oem 1 --psm 6 2>/dev/null',
            escapeshellarg($filePath),
            escapeshellarg($outputFile)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $textFile = $outputFile . '.txt';

        if (!file_exists($textFile)) {
            throw new \Exception('Tesseract falhou ao gerar arquivo de saída');
        }

        $text = file_get_contents($textFile);

        // Limpa arquivo de saída
        @unlink($textFile);

        if ($text === false) {
            throw new \Exception('Falha ao ler arquivo de saída do Tesseract');
        }

        return $text;
    }

    /**
     * Determina a extensão do arquivo baseada no mimetype
     */
    private function getExtensionFromMimetype(string $mimetype): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/webp' => 'webp',
        ];

        return $map[strtolower($mimetype)] ?? 'png';
    }

    /**
     * Cria um arquivo temporário com o conteúdo da imagem
     */
    private function createTempFile(string $content, string $extension, ?string $fileName = null): string
    {
        $fileName = $fileName ?? 'ocr_' . uniqid();
        $tempPath = sys_get_temp_dir() . '/' . $fileName . '.' . $extension;

        if (file_put_contents($tempPath, $content) === false) {
            throw new \Exception('Falha ao criar arquivo temporário para OCR');
        }

        return $tempPath;
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
}
