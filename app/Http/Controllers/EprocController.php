<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EprocService;
use App\Services\CnjService;
use App\Services\EprocDataNormalizer;
use Illuminate\Support\Facades\Log;
use Exception;

class EprocController extends Controller
{
    private EprocDataNormalizer $normalizer;

    public function __construct()
    {
        $this->normalizer = new EprocDataNormalizer();
    }



    public function consultarProcesso(Request $request)
    {
        $request->validate([
            'numero_processo' => 'required|string',
            'user_ws' => 'required|exists:judicial_users,id',
            'password_ws' => 'required|string',
        ]);

        try {
            // Busca o usuário judicial
            $judicialUser = \App\Models\JudicialUser::findOrFail($request->user_ws);

            // Verifica se o usuário judicial pertence ao usuário logado
            if ($judicialUser->user_id !== auth()->id()) {
                return back()
                    ->withInput()
                    ->with('error', 'Você não tem permissão para usar este usuário judicial.');
            }

            $numeroProcesso = $request->input('numero_processo');
            $dataInicial = $request->input('data_inicial');
            $dataFinal = $request->input('data_final');
            $senha = $request->input('password_ws');

            // Instancia o serviço com as credenciais do usuário
            $eprocService = new EprocService($judicialUser->user_login, $senha);

            $resultado = $eprocService->consultarProcesso(
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

            // Normaliza os dados básicos usando o Normalizer
            $dadosBasicos = $processoData['dadosBasicos'] ?? [];

            // Busca descrições de classe e assuntos do CNJ
            $cnjService = new CnjService();

            // Busca descrição da classe
            if (isset($dadosBasicos['classeProcessual'])) {
                $codigoClasse = (int) $dadosBasicos['classeProcessual'];
                $dadosBasicos['classeProcessualNome'] = $cnjService->getClasseDescricao($codigoClasse);
            }

            // Trata assuntos
            if (isset($dadosBasicos['assunto'])) {
                $assuntos = $this->normalizer->normalizeAssuntos($dadosBasicos['assunto']);
                $codigosAssuntos = $this->normalizer->extractAssuntoCodes($assuntos);

                // Busca descrições apenas se houver códigos
                if (!empty($codigosAssuntos)) {
                    $descricoesAssuntos = $cnjService->getMultiplosAssuntosDescricoes($codigosAssuntos);
                    $assuntos = $this->normalizer->addAssuntoDescriptions($assuntos, $descricoesAssuntos);
                }

                $dadosBasicos['assunto'] = $assuntos;
            }

            // Normaliza dados completos do processo
            $dadosNormalizados = $this->normalizer->normalizeProcessData([
                'dadosBasicos' => $dadosBasicos,
                'movimento' => $processoData['movimento'] ?? [],
                'documento' => $processoData['documento'] ?? [],
            ]);

            // Armazena os dados no cache por 10 minutos
            $cacheKey = 'processo_' . md5($numeroProcesso . auth()->id());
            cache()->put($cacheKey, [
                'dadosBasicos' => $dadosNormalizados['dadosBasicos'],
                'movimentos' => $dadosNormalizados['movimentos'],
                'documentos' => $dadosNormalizados['documentos'],
                'numeroProcesso' => $numeroProcesso,
                'judicial_user_id' => $request->user_ws,
                'senha' => $senha
            ], now()->addMinutes(10));

            return redirect()->route('filament.analises.pages.process-details', ['key' => $cacheKey]);

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
            'judicial_user_id' => 'required|exists:judicial_users,id',
            'password_ws' => 'required|string',
        ]);

        try {
            // Busca o usuário judicial
            $judicialUser = \App\Models\JudicialUser::findOrFail($request->judicial_user_id);

            // Verifica se o usuário judicial pertence ao usuário logado
            if ($judicialUser->user_id !== auth()->id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Você não tem permissão para usar este usuário judicial.'
                ], 403);
            }

            $numeroProcesso = $request->input('numero_processo');
            $idDocumento = $request->input('id_documento');
            $senha = $request->input('password_ws');

            // Instancia o serviço com as credenciais do usuário
            $eprocService = new EprocService($judicialUser->user_login, $senha);

            // Consulta o documento com conteúdo completo em base64
            $resultado = $eprocService->consultarDocumentosProcesso(
                $numeroProcesso,
                [$idDocumento]
            );

            // Extrai os documentos da resposta
            // Pode estar em diferentes locais dependendo da estrutura do SOAP
            $documentos = [];
            if (isset($resultado['Body']['respostaConsultarDocumentosProcesso']['documentos'])) {
                $documentos = $resultado['Body']['respostaConsultarDocumentosProcesso']['documentos'];
            } elseif (isset($resultado['documentos'])) {
                $documentos = $resultado['documentos'];
            } elseif (isset($resultado['processo']['documento'])) {
                $documentos = $resultado['processo']['documento'];
            } elseif (isset($resultado['documento'])) {
                $documentos = $resultado['documento'];
            }

            // Garante que é array
            if (!is_array($documentos)) {
                $documentos = [$documentos];
            } elseif (isset($documentos['idDocumento'])) {
                // É um único documento, transforma em array
                $documentos = [$documentos];
            }

            // Busca o documento específico pelo ID
            $documentoEncontrado = null;
            foreach ($documentos as $doc) {
                if (isset($doc['idDocumento']) && $doc['idDocumento'] === $idDocumento) {
                    $documentoEncontrado = $doc;
                    break;
                }
            }

            if (!$documentoEncontrado) {
                return response()->json([
                    'success' => false,
                    'error' => 'Documento não encontrado na resposta do webservice',
                    'debug' => [
                        'estrutura_resposta' => array_keys($resultado),
                        'total_documentos' => count($documentos)
                    ]
                ], 404);
            }

            // Extrai o conteúdo base64 se existir
            // Após processamento MTOM, o conteúdo deve estar em 'conteudo'
            $conteudoBase64 = null;

            // Caso 1: Conteúdo direto (após processamento MTOM)
            if (isset($documentoEncontrado['conteudo']['conteudo'])) {
                $conteudoBase64 = $documentoEncontrado['conteudo']['conteudo'];
            }
            // Caso 2: Conteúdo como string direta
            elseif (isset($documentoEncontrado['conteudo']) && is_string($documentoEncontrado['conteudo'])) {
                $conteudoBase64 = $documentoEncontrado['conteudo'];
            }
            // Caso 3: Conteúdo em outro local (fallback)
            elseif (isset($documentoEncontrado['content'])) {
                $conteudoBase64 = $documentoEncontrado['content'];
            }

            // Extrai o mimetype de onde quer que esteja
            $mimetype = null;
            if (isset($documentoEncontrado['conteudo']['mimetype'])) {
                $mimetype = $documentoEncontrado['conteudo']['mimetype'];
            } elseif (isset($documentoEncontrado['mimetype'])) {
                $mimetype = $documentoEncontrado['mimetype'];
            } elseif (isset($documentoEncontrado['tipoDocumento'])) {
                $mimetype = $documentoEncontrado['tipoDocumento'];
            }

            return response()->json([
                'success' => true,
                'documento' => $documentoEncontrado,
                'conteudoBase64' => $conteudoBase64,
                'mimetype' => $mimetype, // Mimetype direto na raiz para fácil acesso
                'temConteudo' => !empty($conteudoBase64),
                'tamanhoBase64' => $conteudoBase64 ? strlen($conteudoBase64) : 0
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
