<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContractUploadController extends Controller
{
    /**
     * Diretório temporário para chunks
     */
    private const TEMP_DIR = 'temp/chunks';

    /**
     * Diretório final para contratos
     */
    private const CONTRACTS_DIR = 'contracts';

    /**
     * Tamanho máximo do arquivo (100MB)
     */
    private const MAX_FILE_SIZE = 104857600;

    /**
     * Processa upload de arquivo (com suporte a chunks)
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            // Verifica se é upload chunked
            $isChunked = $request->has('patch') || $request->header('Upload-Length');

            if ($isChunked) {
                return $this->handleChunkedUpload($request);
            }

            return $this->handleRegularUpload($request);
        } catch (\Exception $e) {
            Log::error('Erro no upload de contrato', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Processa upload regular (arquivo pequeno)
     */
    private function handleRegularUpload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf|max:102400', // 100MB
        ]);

        $file = $request->file('file');
        $fileName = $this->generateFileName($file->getClientOriginalName());

        // Salva o arquivo
        $path = $file->storeAs(self::CONTRACTS_DIR, $fileName);

        Log::info('Upload de contrato concluído (regular)', [
            'file_name' => $fileName,
            'path' => $path,
            'size' => $file->getSize()
        ]);

        return response()->json([
            'success' => true,
            'file_id' => $fileName,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize()
        ]);
    }

    /**
     * Processa upload em chunks (FilePond)
     */
    private function handleChunkedUpload(Request $request): JsonResponse
    {
        // FilePond envia HEAD request para verificar se pode fazer upload
        if ($request->isMethod('HEAD')) {
            return response()->json([], 200);
        }

        // FilePond PATCH - recebendo chunk
        $uploadLength = $request->header('Upload-Length');
        $uploadOffset = $request->header('Upload-Offset');
        $uploadName = $request->header('Upload-Name');

        // Se é o primeiro chunk, cria um ID único para o upload
        if ($uploadOffset == 0) {
            $uploadId = Str::uuid()->toString();

            // Armazena metadados do upload
            Storage::put(self::TEMP_DIR . "/{$uploadId}.meta", json_encode([
                'original_name' => $uploadName,
                'total_size' => $uploadLength,
                'received_size' => 0,
            ]));

            // Cria arquivo vazio
            Storage::put(self::TEMP_DIR . "/{$uploadId}.part", '');

            // Retorna o ID do upload para próximos chunks
            return response()->json(['upload_id' => $uploadId], 200)
                ->header('Upload-Offset', 0);
        }

        // Chunks subsequentes - precisa do upload_id
        $uploadId = $request->input('patch') ?? $request->header('Upload-Id');

        if (!$uploadId || !Storage::exists(self::TEMP_DIR . "/{$uploadId}.meta")) {
            return response()->json(['error' => 'Upload ID inválido'], 400);
        }

        // Lê metadados
        $meta = json_decode(Storage::get(self::TEMP_DIR . "/{$uploadId}.meta"), true);

        // Obtém conteúdo do chunk
        $chunk = $request->getContent();
        $chunkSize = strlen($chunk);

        // Append chunk ao arquivo
        Storage::append(self::TEMP_DIR . "/{$uploadId}.part", $chunk);

        // Atualiza metadados
        $meta['received_size'] += $chunkSize;
        Storage::put(self::TEMP_DIR . "/{$uploadId}.meta", json_encode($meta));

        $newOffset = $meta['received_size'];

        // Verifica se o upload está completo
        if ($newOffset >= $meta['total_size']) {
            return $this->finalizeChunkedUpload($uploadId, $meta);
        }

        return response()->json(['success' => true], 200)
            ->header('Upload-Offset', $newOffset);
    }

    /**
     * Finaliza upload chunked - move arquivo para destino final
     */
    private function finalizeChunkedUpload(string $uploadId, array $meta): JsonResponse
    {
        $tempPath = self::TEMP_DIR . "/{$uploadId}.part";
        $fileName = $this->generateFileName($meta['original_name']);
        $finalPath = self::CONTRACTS_DIR . "/{$fileName}";

        // Move arquivo para destino final
        Storage::move($tempPath, $finalPath);

        // Remove metadados
        Storage::delete(self::TEMP_DIR . "/{$uploadId}.meta");

        Log::info('Upload de contrato concluído (chunked)', [
            'upload_id' => $uploadId,
            'file_name' => $fileName,
            'path' => $finalPath,
            'size' => $meta['total_size']
        ]);

        return response()->json([
            'success' => true,
            'file_id' => $fileName,
            'file_path' => $finalPath,
            'file_name' => $meta['original_name'],
            'file_size' => $meta['total_size']
        ]);
    }

    /**
     * Remove arquivo de upload (cancelamento)
     */
    public function delete(Request $request): JsonResponse
    {
        $fileId = $request->input('file_id') ?? $request->getContent();

        if (!$fileId) {
            return response()->json(['error' => 'File ID não fornecido'], 400);
        }

        // Tenta remover do diretório de contratos
        $contractPath = self::CONTRACTS_DIR . "/{$fileId}";
        if (Storage::exists($contractPath)) {
            Storage::delete($contractPath);
            Log::info('Contrato removido', ['path' => $contractPath]);
            return response()->json(['success' => true]);
        }

        // Tenta remover chunks temporários
        $tempPath = self::TEMP_DIR . "/{$fileId}.part";
        $metaPath = self::TEMP_DIR . "/{$fileId}.meta";

        if (Storage::exists($tempPath)) {
            Storage::delete($tempPath);
        }
        if (Storage::exists($metaPath)) {
            Storage::delete($metaPath);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Gera nome único para o arquivo
     */
    private function generateFileName(string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $safeName = Str::slug($baseName);
        $timestamp = now()->format('Ymd_His');
        $unique = Str::random(8);

        return "{$safeName}_{$timestamp}_{$unique}.{$extension}";
    }
}
