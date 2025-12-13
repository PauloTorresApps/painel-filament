<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\EprocService;
use App\Services\CnjService;
use Exception;

class EprocController extends Controller
{
    // Removido a injeção de dependência do EprocService
    // Agora será instanciado dentro de cada método com as credenciais do usuário

    public function debug()
    {
        try {
            $eprocService = new EprocService();
            $funcoes = $eprocService->listarFuncoes();
            $tipos = $eprocService->listarTipos();

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

            // Normaliza os dados básicos
            $dadosBasicos = $processoData['dadosBasicos'] ?? [];

            // Normaliza os polos para garantir que o atributo 'polo' seja uma string
            if (isset($dadosBasicos['polo'])) {
                $dadosBasicos['polo'] = $this->normalizarPolos($dadosBasicos['polo']);
            }

            // Busca descrições de classe e assuntos do CNJ
            $cnjService = new CnjService();

            // Busca descrição da classe
            if (isset($dadosBasicos['classeProcessual'])) {
                $codigoClasse = (int) $dadosBasicos['classeProcessual'];
                $dadosBasicos['classeProcessualNome'] = $cnjService->getClasseDescricao($codigoClasse);
            }

            // Busca descrições dos assuntos
            if (isset($dadosBasicos['assunto'])) {
                $assuntos = is_array($dadosBasicos['assunto']) ? $dadosBasicos['assunto'] : [$dadosBasicos['assunto']];

                // Normaliza: se é um único assunto (tem codigoNacional ou codigoAssunto diretamente), encapsula em array
                if (isset($assuntos['codigoNacional']) || isset($assuntos['codigoAssunto'])) {
                    $assuntos = [$assuntos];
                }

                // Extrai códigos de assuntos (pode ser codigoAssunto ou codigoNacional)
                $codigosAssuntos = [];
                foreach ($assuntos as $assunto) {
                    if (is_array($assunto)) {
                        $codigo = $assunto['codigoAssunto'] ?? $assunto['codigoNacional'] ?? null;
                        if ($codigo) {
                            $codigosAssuntos[] = (int) $codigo;
                        }
                    }
                }

                // Busca descrições apenas se houver códigos
                if (!empty($codigosAssuntos)) {
                    $descricoesAssuntos = $cnjService->getMultiplosAssuntosDescricoes($codigosAssuntos);

                    // Adiciona as descrições aos assuntos
                    foreach ($assuntos as &$assunto) {
                        if (is_array($assunto)) {
                            $codigo = (int) ($assunto['codigoAssunto'] ?? $assunto['codigoNacional'] ?? 0);
                            if ($codigo > 0 && isset($descricoesAssuntos[$codigo])) {
                                $assunto['nomeAssunto'] = $descricoesAssuntos[$codigo];
                                // Garante que codigoAssunto está definido para a view
                                if (!isset($assunto['codigoAssunto'])) {
                                    $assunto['codigoAssunto'] = $codigo;
                                }
                            }
                        }
                    }
                }

                $dadosBasicos['assunto'] = $assuntos;
            }

            // Associa documentos aos movimentos
            // Garante que sempre serão arrays, mesmo quando vazio
            $movimentos = $processoData['movimento'] ?? [];
            $documentos = $processoData['documento'] ?? [];

            // Garante que são arrays (proteção adicional)
            if (!is_array($movimentos)) {
                $movimentos = [$movimentos];
            }
            if (!is_array($documentos)) {
                $documentos = [$documentos];
            }

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

            // Armazena os dados no cache por 10 minutos
            $cacheKey = 'processo_' . md5($numeroProcesso . auth()->id());
            cache()->put($cacheKey, [
                'dadosBasicos' => $dadosBasicos,
                'movimentos' => $movimentos,
                'documentos' => $documentos,
                'numeroProcesso' => $numeroProcesso,
                'judicial_user_id' => $request->user_ws,
                'senha' => $senha
            ], now()->addMinutes(10));

            return redirect()->route('filament.admin.pages.process-details', ['key' => $cacheKey]);

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

            return response()->json([
                'success' => true,
                'documento' => $documentoEncontrado,
                'conteudoBase64' => $conteudoBase64,
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

    /**
     * Normaliza os polos para garantir que o atributo 'polo' seja sempre uma string
     * O SOAP pode retornar o atributo XML 'polo' de formas diferentes
     */
    protected function normalizarPolos(array $polos): array
    {
        // Garante que seja um array de polos
        if (!is_array($polos)) {
            return [];
        }

        // Se for um único polo (array associativo), transforma em array de polos
        if (isset($polos['parte']) || isset($polos['polo'])) {
            $polos = [$polos];
        }

        // Normaliza cada polo
        foreach ($polos as &$polo) {
            if (!is_array($polo)) {
                continue;
            }

            // Extrai o atributo 'polo' se estiver em diferentes formatos
            if (isset($polo['@attributes']['polo'])) {
                // Caso 1: Atributo está em @attributes
                $polo['polo'] = $polo['@attributes']['polo'];
            } elseif (isset($polo['polo']) && is_array($polo['polo'])) {
                // Caso 2: 'polo' é um array (pode ter @attributes dentro)
                if (isset($polo['polo']['@attributes']['polo'])) {
                    $polo['polo'] = $polo['polo']['@attributes']['polo'];
                } elseif (isset($polo['polo'][0])) {
                    // Caso 3: 'polo' é array numérico, pega o primeiro
                    $polo['polo'] = $polo['polo'][0];
                } else {
                    // Caso 4: Usa a primeira chave do array
                    $polo['polo'] = array_values($polo['polo'])[0] ?? 'N/A';
                }
            }
            // Se 'polo' já é string, deixa como está

            // Garante que 'polo' seja sempre string
            if (!isset($polo['polo']) || !is_string($polo['polo'])) {
                $polo['polo'] = 'N/A';
            }
        }

        return $polos;
    }
}
