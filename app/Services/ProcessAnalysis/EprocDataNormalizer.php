<?php

namespace App\Services\ProcessAnalysis;

use Illuminate\Support\Facades\Log;

/**
 * Service responsável por normalizar dados do e-Proc
 * 
 * Centraliza a lógica de normalização e transformação de dados
 * retornados pelo webservice do e-Proc (SOAP)
 */
class EprocDataNormalizer
{
    /**
     * Normaliza dados completos de um processo
     * 
     * @param array $processoData Dados brutos do processo retornados pelo SOAP
     * @return array Dados normalizados
     */
    public function normalizeProcessData(array $processoData): array
    {
        $dadosBasicos = $processoData['dadosBasicos'] ?? [];

        // Normaliza polos
        if (isset($dadosBasicos['polo'])) {
            $dadosBasicos['polo'] = $this->normalizePolos($dadosBasicos['polo']);
        }

        // Normaliza movimentos e documentos
        $movimentos = $this->normalizeMovimentos($processoData['movimento'] ?? []);
        $documentos = $this->normalizeDocumentos($processoData['documento'] ?? []);

        // Calcula sequência de análise
        $sequenciaGlobal = $this->calculateAnalysisSequence($movimentos);

        // Adiciona sequência aos documentos
        foreach ($documentos as &$doc) {
            $idDoc = $doc['idDocumento'] ?? null;
            $doc['sequencia_analise'] = $sequenciaGlobal[$idDoc] ?? 999999;
        }
        unset($doc);

        // Agrupa documentos por movimento
        $documentosPorMovimento = $this->groupDocumentsByMovement($documentos);

        // Adiciona documentos aos movimentos
        foreach ($movimentos as &$movimento) {
            $idMov = $movimento['idMovimento'] ?? null;
            $movimento['documentos'] = $documentosPorMovimento[$idMov] ?? [];
        }
        unset($movimento);

        return [
            'dadosBasicos' => $dadosBasicos,
            'movimentos' => $movimentos,
            'documentos' => $documentos,
        ];
    }

    /**
     * Normaliza array de movimentos
     * 
     * @param mixed $movimentos Pode ser array ou objeto único
     * @return array Array normalizado de movimentos
     */
    public function normalizeMovimentos($movimentos): array
    {
        if (empty($movimentos)) {
            return [];
        }

        // Garante que é um array
        if (!is_array($movimentos)) {
            $movimentos = [$movimentos];
        }

        // Se parece ser um movimento único (não lista), encapsula
        if (isset($movimentos['idMovimento'])) {
            $movimentos = [$movimentos];
        }

        // Ordena por ID (ordem cronológica)
        usort($movimentos, function ($a, $b) {
            $idA = (int) ($a['idMovimento'] ?? 999999);
            $idB = (int) ($b['idMovimento'] ?? 999999);
            return $idA <=> $idB;
        });

        Log::info('EprocDataNormalizer: Movimentos normalizados', [
            'total' => count($movimentos)
        ]);

        return $movimentos;
    }

    /**
     * Normaliza array de documentos
     * 
     * @param mixed $documentos Pode ser array ou objeto único
     * @return array Array normalizado de documentos
     */
    public function normalizeDocumentos($documentos): array
    {
        if (empty($documentos)) {
            return [];
        }

        // Garante que é um array
        if (!is_array($documentos)) {
            $documentos = [$documentos];
        }

        // Se parece ser um documento único, encapsula
        if (isset($documentos['idDocumento'])) {
            $documentos = [$documentos];
        }

        Log::info('EprocDataNormalizer: Documentos normalizados', [
            'total' => count($documentos)
        ]);

        return $documentos;
    }

    /**
     * Normaliza array de polos (partes do processo)
     * 
     * Garante que o atributo 'polo' seja sempre uma string
     * O SOAP pode retornar o atributo XML 'polo' de formas diferentes
     * 
     * @param mixed $polos Array de polos ou polo único
     * @return array Array normalizado de polos
     */
    public function normalizePolos($polos): array
    {
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
        unset($polo);

        return $polos;
    }

    /**
     * Calcula sequência global de análise baseada em movimentos
     * 
     * @param array $movimentos Array de movimentos (já normalizado e ordenado)
     * @return array Mapa idDocumento => sequencia_analise
     */
    public function calculateAnalysisSequence(array $movimentos): array
    {
        $sequenciaGlobal = [];
        $sequenciaAtual = 1;

        Log::info('EprocDataNormalizer: Calculando sequência global de análise', [
            'total_movimentos' => count($movimentos)
        ]);

        foreach ($movimentos as $movimento) {
            $idMov = $movimento['idMovimento'] ?? null;

            // Pega a lista de IDs de documentos vinculados a este movimento
            $idsDocumentosVinculados = $movimento['idDocumentoVinculado'] ?? [];

            // Normaliza para array se for um único documento
            if (!is_array($idsDocumentosVinculados)) {
                $idsDocumentosVinculados = [$idsDocumentosVinculados];
            }

            $descricaoMovimento = $movimento['movimentoLocal']['descricao'] ?? 'Sem descrição';

            Log::info("Movimento {$idMov}: {$descricaoMovimento}", [
                'id_movimento' => $idMov,
                'documentos_vinculados' => $idsDocumentosVinculados,
                'total_docs_vinculados' => count($idsDocumentosVinculados),
                'sequencia_inicial' => $sequenciaAtual,
                'sequencia_final' => $sequenciaAtual + count($idsDocumentosVinculados) - 1
            ]);

            // Para cada documento vinculado ao movimento, atribui sequência global
            foreach ($idsDocumentosVinculados as $idDoc) {
                $sequenciaGlobal[$idDoc] = $sequenciaAtual;
                $sequenciaAtual++;
            }
        }

        Log::info('EprocDataNormalizer: Sequência global calculada', [
            'total_documentos_sequenciados' => count($sequenciaGlobal),
            'sequencia_maxima' => $sequenciaAtual - 1,
        ]);

        return $sequenciaGlobal;
    }

    /**
     * Agrupa documentos por ID de movimento
     * 
     * @param array $documentos Array de documentos normalizados
     * @return array Mapa idMovimento => array de documentos
     */
    public function groupDocumentsByMovement(array $documentos): array
    {
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

        return $documentosPorMovimento;
    }

    /**
     * Normaliza array de assuntos e adiciona descrições
     * 
     * @param mixed $assuntos Array ou objeto único de assuntos
     * @return array Array normalizado de assuntos
     */
    public function normalizeAssuntos($assuntos): array
    {
        if (empty($assuntos)) {
            return [];
        }

        // Garante que é array
        if (!is_array($assuntos)) {
            $assuntos = [$assuntos];
        }

        // Normaliza: se é um único assunto (tem codigoNacional ou codigoAssunto diretamente), encapsula
        if (isset($assuntos['codigoNacional']) || isset($assuntos['codigoAssunto'])) {
            $assuntos = [$assuntos];
        }

        return $assuntos;
    }

    /**
     * Extrai códigos de assuntos para buscar descrições
     * 
     * @param array $assuntos Array normalizado de assuntos
     * @return array Array de códigos de assuntos
     */
    public function extractAssuntoCodes(array $assuntos): array
    {
        $codigos = [];

        foreach ($assuntos as $assunto) {
            if (is_array($assunto)) {
                $codigo = $assunto['codigoAssunto'] ?? $assunto['codigoNacional'] ?? null;
                if ($codigo) {
                    $codigos[] = (int) $codigo;
                }
            }
        }

        return $codigos;
    }

    /**
     * Adiciona descrições aos assuntos
     * 
     * @param array $assuntos Array de assuntos
     * @param array $descricoes Mapa codigo => descricao
     * @return array Assuntos com descrições adicionadas
     */
    public function addAssuntoDescriptions(array $assuntos, array $descricoes): array
    {
        foreach ($assuntos as &$assunto) {
            if (is_array($assunto)) {
                $codigo = (int) ($assunto['codigoAssunto'] ?? $assunto['codigoNacional'] ?? 0);
                if ($codigo > 0 && isset($descricoes[$codigo])) {
                    $assunto['nomeAssunto'] = $descricoes[$codigo];
                    // Garante que codigoAssunto está definido
                    if (!isset($assunto['codigoAssunto'])) {
                        $assunto['codigoAssunto'] = $codigo;
                    }
                }
            }
        }
        unset($assunto);

        return $assuntos;
    }
}
