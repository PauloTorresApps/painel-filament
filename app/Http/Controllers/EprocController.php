<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Services\EprocService;
use Exception;
use Illuminate\Support\Facades\Log;

class EprocController extends Controller
{
    protected $eprocService;

    public function __construct(EprocService $eprocService)
    {
        $this->eprocService = $eprocService;
    }

    public function index()
    {
        return view('document_analysis.eproc.index');
    }

    public function debug()
    {
        try {
            $funcoes = $this->eprocService->listarFuncoes();
            $tipos = $this->eprocService->listarTipos();

            return response()->json([
                'funcoes' => $funcoes,
                'tipos' => $tipos
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function consultarProcesso(Request $request)
    {
        $request->validate([
            'numero_processo' => 'required|string',
        ]);

        try {
            $numeroProcesso = $request->input('numero_processo');
            $dataInicial = $request->input('data_inicial');
            $dataFinal = $request->input('data_final');

            $resultado = $this->eprocService->consultarProcesso(
                $numeroProcesso,
                $dataInicial,
                $dataFinal,
                true, // incluirCabecalho
                true, // incluirPartes
                false, // incluirEnderecos
                true, // incluirMovimentos
                true  // incluirDocumentos
            );

            // Extrai os dados do processo da resposta
            $processoData = $resultado['processo'] ?? [];

            // Associa documentos aos movimentos
            $movimentos = $processoData['movimento'] ?? [];
            $documentos = $processoData['documento'] ?? [];

            // Agrupa documentos por idMovimento
            $documentosPorMovimento = [];
            foreach ($documentos as $doc) {
                $idMov = $doc['idMovimento'] ?? null;
                if ($idMov) {
                    if (!isset($documentosPorMovimento[$idMov])) {
                        $documentosPorMovimento[$idMov] = [];
                    }
                    $documentosPorMovimento[$idMov][] = $doc;
                }
            }

            // Adiciona documentos aos movimentos
            foreach ($movimentos as &$movimento) {
                $idMov = $movimento['idMovimento'] ?? null;
                $movimento['documentos'] = $documentosPorMovimento[$idMov] ?? [];
            }

            return view('document_analysis.eproc.processo', [
                'dadosBasicos' => $processoData['dadosBasicos'] ?? [],
                'movimentos' => $movimentos,
                'documentos' => $documentos,
                'numeroProcesso' => $numeroProcesso
            ]);

        } catch (Exception $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    public function visualizarDocumento(Request $request)
    {
        $request->validate([
            'numero_processo' => 'required|string',
            'id_documento' => 'required|string',
        ]);

        try {
            $numeroProcesso = $request->input('numero_processo');
            $idDocumento = $request->input('id_documento');

            $resultado = $this->eprocService->consultarDocumentosProcesso(
                $numeroProcesso,
                [$idDocumento]
            );

            return response()->json([
                'success' => true,
                'documento' => $resultado
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
