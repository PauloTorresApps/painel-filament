<?php

namespace App\Services;

use SoapClient;
use SoapFault;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CnjService
{
    private string $wsdlUrl;
    private ?SoapClient $soapClient = null;

    public function __construct()
    {
        $baseUrl = config('services.cnj.url');
        $this->wsdlUrl = $baseUrl . '?wsdl';

        try {
            $this->soapClient = new SoapClient($this->wsdlUrl, [
                'trace' => 1,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'soap_version' => SOAP_1_1,
            ]);
        } catch (SoapFault $e) {
            Log::error('Erro ao conectar ao webservice CNJ: ' . $e->getMessage());
        }
    }

    /**
     * Busca o nome de uma classe processual pelo código CNJ
     *
     * @param int $codigoClasse Código CNJ da classe
     * @return string|null Nome da classe ou null se não encontrado
     */
    public function getClasseDescricao(int $codigoClasse): ?string
    {
        return $this->getItemDescricao($codigoClasse, 'C');
    }

    /**
     * Busca o nome de um assunto processual pelo código CNJ
     *
     * @param int $codigoAssunto Código CNJ do assunto
     * @return string|null Nome do assunto ou null se não encontrado
     */
    public function getAssuntoDescricao(int $codigoAssunto): ?string
    {
        return $this->getItemDescricao($codigoAssunto, 'A');
    }

    /**
     * Busca a descrição de um item (classe ou assunto) no webservice CNJ
     * Utiliza cache para evitar consultas repetidas
     *
     * @param int $seqItem Código do item
     * @param string $tipoItem Tipo do item: 'C' para classe, 'A' para assunto
     * @return string|null Descrição do item ou null se não encontrado
     */
    private function getItemDescricao(int $seqItem, string $tipoItem): ?string
    {
        if (!$this->soapClient) {
            return null;
        }

        // Usa cache de 30 dias para reduzir consultas ao webservice
        $cacheKey = "cnj_{$tipoItem}_{$seqItem}";

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($seqItem, $tipoItem) {
            try {
                $response = $this->soapClient->getArrayDetalhesItemPublicoWS(
                    (string) $seqItem,
                    $tipoItem
                );

                // A resposta é um array de objetos stdClass com propriedades key e value
                if (is_array($response) && count($response) > 0) {
                    foreach ($response as $item) {
                        // Cada item é um objeto stdClass com key e value
                        if (is_object($item) && isset($item->key) && $item->key === 'nome' && isset($item->value)) {
                            return $item->value;
                        }
                    }
                }

                Log::warning("Campo 'nome' não encontrado na resposta CNJ", [
                    'tipo' => $tipoItem,
                    'seq' => $seqItem,
                ]);

                return null;
            } catch (SoapFault $e) {
                Log::error("Erro ao consultar item CNJ (tipo: {$tipoItem}, seq: {$seqItem}): " . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * Busca descrições de múltiplas classes de uma vez
     *
     * @param array $codigosClasses Array de códigos de classes
     * @return array Array associativo [codigo => descricao]
     */
    public function getMultiplasClassesDescricoes(array $codigosClasses): array
    {
        $descricoes = [];

        foreach ($codigosClasses as $codigo) {
            $descricoes[$codigo] = $this->getClasseDescricao($codigo);
        }

        return $descricoes;
    }

    /**
     * Busca descrições de múltiplos assuntos de uma vez
     *
     * @param array $codigosAssuntos Array de códigos de assuntos
     * @return array Array associativo [codigo => descricao]
     */
    public function getMultiplosAssuntosDescricoes(array $codigosAssuntos): array
    {
        $descricoes = [];

        foreach ($codigosAssuntos as $codigo) {
            $descricoes[$codigo] = $this->getAssuntoDescricao($codigo);
        }

        return $descricoes;
    }
}
