<?php

namespace App\Http\Controllers;

use App\Traits\WithOtelTracing;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenTelemetry\API\Trace\StatusCode;

class ContractUploadController extends Controller
{
    use WithOtelTracing;

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
        [$span, $scope] = $this->startSpan('painel-laravel-controller', 'controller.contract.upload', [
            'http.route' => 'contracts.upload',
            'app.user_id' => Auth::id(),
            'http.method' => $request->method(),
        ]);

        try {
            // Debug: log do que está sendo recebido
            Log::info('Upload request recebido', [
                'method' => $request->method(),
                'has_file_filepond' => $request->hasFile('filepond'),
                'has_file_file' => $request->hasFile('file'),
                'all_files' => array_keys($request->allFiles()),
                'all_input' => array_keys($request->all()),
                'headers' => [
                    'Upload-Length' => $request->header('Upload-Length'),
                    'Upload-Offset' => $request->header('Upload-Offset'),
                    'Upload-Name' => $request->header('Upload-Name'),
                    'Content-Type' => $request->header('Content-Type'),
                ],
            ]);

            // Verifica se é upload chunked
            $isChunked = $request->has('patch') || $request->header('Upload-Length');
            $span?->setAttribute('upload.chunked', $isChunked);

            if ($isChunked) {
                $span?->setStatus(StatusCode::STATUS_OK);
                return $this->handleChunkedUpload($request);
            }

            $span?->setStatus(StatusCode::STATUS_OK);
            return $this->handleRegularUpload($request);
        } catch (\Exception $e) {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            Log::error('Erro no upload de contrato', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            $this->detachScope($scope);
            $span?->end();
        }
    }

    /**
     * Processa upload regular (arquivo pequeno)
     */
    private function handleRegularUpload(Request $request): JsonResponse
    {
        // FilePond envia com o nome do input (contract, filepond, ou file)
        $fileKey = null;
        foreach (['contract', 'filepond', 'file'] as $key) {
            if ($request->hasFile($key)) {
                $fileKey = $key;
                break;
            }
        }

        if (!$fileKey) {
            return response()->json(['error' => 'Nenhum arquivo enviado'], 400);
        }

        $request->validate([
            $fileKey => 'required|file|mimes:pdf|max:102400', // 100MB
        ]);

        $file = $request->file($fileKey);
        $this->assertValidPdfUpload($file);
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

        if ($uploadLength !== null && (int) $uploadLength > self::MAX_FILE_SIZE) {
            return response()->json(['error' => 'Arquivo excede o limite de 100MB'], 422);
        }

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

        // Append binário seguro (Storage::append pode adicionar quebras de linha em alguns drivers)
        $this->appendChunkToTempFile($uploadId, $chunk);

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

        if ((int) ($meta['total_size'] ?? 0) > self::MAX_FILE_SIZE) {
            Storage::delete([$tempPath, self::TEMP_DIR . "/{$uploadId}.meta"]);

            return response()->json(['error' => 'Arquivo excede o limite de 100MB'], 422);
        }

        if (!$this->isValidPdfName((string) ($meta['original_name'] ?? ''))) {
            Storage::delete([$tempPath, self::TEMP_DIR . "/{$uploadId}.meta"]);

            return response()->json(['error' => 'Apenas arquivos PDF são permitidos'], 422);
        }

        if (!$this->hasPdfMagicFromStoragePath($tempPath)) {
            Storage::delete([$tempPath, self::TEMP_DIR . "/{$uploadId}.meta"]);

            return response()->json(['error' => 'Arquivo inválido: assinatura PDF não encontrada'], 422);
        }

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
        [$span, $scope] = $this->startSpan('painel-laravel-controller', 'controller.contract.upload_delete', [
            'http.route' => 'contracts.upload.delete',
            'app.user_id' => Auth::id(),
        ]);

        $fileId = $request->input('file_id') ?? $request->getContent();

        if (!$fileId) {
            $span?->setStatus(StatusCode::STATUS_ERROR, 'File ID não fornecido');
            $this->detachScope($scope);
            $span?->end();
            return response()->json(['error' => 'File ID não fornecido'], 400);
        }

        $span?->setAttribute('upload.file_id', (string) $fileId);

        // Tenta remover do diretório de contratos
        $contractPath = self::CONTRACTS_DIR . "/{$fileId}";
        if (Storage::exists($contractPath)) {
            Storage::delete($contractPath);
            Log::info('Contrato removido', ['path' => $contractPath]);
            $span?->setStatus(StatusCode::STATUS_OK);
            $this->detachScope($scope);
            $span?->end();
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

        $span?->setStatus(StatusCode::STATUS_OK);
        $this->detachScope($scope);
        $span?->end();

        return response()->json(['success' => true]);
    }

    /**
     * Valida assinatura e extensão do PDF no upload regular.
     */
    private function assertValidPdfUpload(UploadedFile $file): void
    {
        if (!$this->isValidPdfName($file->getClientOriginalName())) {
            abort(422, 'Apenas arquivos PDF são permitidos');
        }

        $handle = @fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            abort(422, 'Não foi possível validar o arquivo enviado');
        }

        $header = fread($handle, 5) ?: '';
        fclose($handle);

        if ($header !== '%PDF-') {
            abort(422, 'Arquivo inválido: assinatura PDF não encontrada');
        }
    }

    private function isValidPdfName(string $fileName): bool
    {
        return strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function hasPdfMagicFromStoragePath(string $relativePath): bool
    {
        if (!Storage::exists($relativePath)) {
            return false;
        }

        $absolutePath = Storage::path($relativePath);
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 5) ?: '';
        fclose($handle);

        return $header === '%PDF-';
    }

    /**
     * Faz append binário do chunk no arquivo temporário do upload.
     */
    private function appendChunkToTempFile(string $uploadId, string $chunk): void
    {
        $tempPath = self::TEMP_DIR . "/{$uploadId}.part";
        $absolutePath = Storage::path($tempPath);

        $bytesWritten = @file_put_contents($absolutePath, $chunk, FILE_APPEND);
        if ($bytesWritten === false) {
            throw new \RuntimeException('Falha ao anexar chunk ao arquivo temporário');
        }
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
