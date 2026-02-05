<?php

namespace App\Services;

use App\Models\ContractAnalysis;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service responsável por gerenciar arquivos de contratos
 * 
 * Centraliza operações de salvamento, validação e exclusão de arquivos
 * relacionados a análises contratuais.
 */
class ContractFileManager
{
    /**
     * Tamanho máximo permitido para upload (em bytes)
     * 50MB
     */
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;

    /**
     * Extensões de arquivo permitidas
     */
    private const ALLOWED_EXTENSIONS = ['pdf'];

    /**
     * Diretório base para armazenamento de contratos
     */
    private const STORAGE_PATH = 'contracts';

    /**
     * Salva um arquivo enviado e associa à análise
     * 
     * @param UploadedFile $file Arquivo enviado
     * @param ContractAnalysis $analysis Análise à qual associar o arquivo
     * @return string Caminho do arquivo salvo no storage
     * @throws \Exception Se validação falhar
     */
    public function saveUploadedFile(UploadedFile $file, ContractAnalysis $analysis): string
    {
        // Valida o arquivo
        $this->validateFile($file);

        // Remove arquivo anterior se existir
        if ($analysis->file_path) {
            $this->deleteFile($analysis);
        }

        // Gera nome único para o arquivo
        $fileName = $this->generateUniqueFileName($file, $analysis);

        // Salva o arquivo
        $path = $file->storeAs(self::STORAGE_PATH, $fileName);

        Log::info('ContractFileManager: Arquivo salvo', [
            'analysis_id' => $analysis->id,
            'file_name' => $fileName,
            'path' => $path,
            'size' => $file->getSize(),
        ]);

        return $path;
    }

    /**
     * Valida um arquivo enviado
     * 
     * @param UploadedFile $file Arquivo a validar
     * @throws \Exception Se validação falhar
     */
    public function validateFile(UploadedFile $file): void
    {
        // Verifica se o upload foi bem-sucedido
        if (!$file->isValid()) {
            throw new \Exception('Erro no upload do arquivo.');
        }

        // Verifica tamanho
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $maxSizeMB = self::MAX_FILE_SIZE / (1024 * 1024);
            throw new \Exception("Arquivo muito grande. Tamanho máximo: {$maxSizeMB}MB");
        }

        // Verifica extensão
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            $allowedList = implode(', ', self::ALLOWED_EXTENSIONS);
            throw new \Exception("Formato de arquivo não suportado. Formatos permitidos: {$allowedList}");
        }

        // Verifica MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, ['application/pdf'])) {
            throw new \Exception('Tipo MIME do arquivo inválido.');
        }
    }

    /**
     * Deleta o arquivo associado a uma análise
     * 
     * @param ContractAnalysis $analysis Análise cujo arquivo deletar
     * @return bool True se deletado com sucesso
     */
    public function deleteFile(ContractAnalysis $analysis): bool
    {
        if (!$analysis->file_path) {
            return false;
        }

        try {
            if (Storage::exists($analysis->file_path)) {
                Storage::delete($analysis->file_path);

                Log::info('ContractFileManager: Arquivo deletado', [
                    'analysis_id' => $analysis->id,
                    'file_path' => $analysis->file_path,
                ]);

                return true;
            }
        } catch (\Exception $e) {
            Log::error('ContractFileManager: Erro ao deletar arquivo', [
                'analysis_id' => $analysis->id,
                'file_path' => $analysis->file_path,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Verifica se um arquivo existe
     * 
     * @param ContractAnalysis $analysis Análise a verificar
     * @return bool
     */
    public function fileExists(ContractAnalysis $analysis): bool
    {
        return $analysis->file_path && Storage::exists($analysis->file_path);
    }

    /**
     * Obtém informações sobre o arquivo
     * 
     * @param ContractAnalysis $analysis Análise a verificar
     * @return array|null Informações do arquivo ou null se não existir
     */
    public function getFileInfo(ContractAnalysis $analysis): ?array
    {
        if (!$this->fileExists($analysis)) {
            return null;
        }

        return [
            'path' => $analysis->file_path,
            'size' => Storage::size($analysis->file_path),
            'last_modified' => Storage::lastModified($analysis->file_path),
            'mime_type' => Storage::mimeType($analysis->file_path),
        ];
    }

    /**
     * Gera um nome único para o arquivo
     * 
     * @param UploadedFile $file Arquivo
     * @param ContractAnalysis $analysis Análise
     * @return string Nome único
     */
    private function generateUniqueFileName(UploadedFile $file, ContractAnalysis $analysis): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $timestamp = now()->format('Y-m-d-His');
        $randomString = substr(md5(uniqid()), 0, 8);

        return "contract-{$analysis->id}-{$timestamp}-{$randomString}.{$extension}";
    }

    /**
     * Retorna o tamanho máximo permitido em MB
     * 
     * @return int
     */
    public static function getMaxFileSizeMB(): int
    {
        return self::MAX_FILE_SIZE / (1024 * 1024);
    }

    /**
     * Retorna as extensões permitidas
     * 
     * @return array
     */
    public static function getAllowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }
}
