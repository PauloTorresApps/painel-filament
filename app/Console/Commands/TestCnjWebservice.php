<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CnjService;

class TestCnjWebservice extends Command
{
    protected $signature = 'cnj:test {codigo} {tipo=C}';
    protected $description = 'Testa o webservice CNJ (tipo: C para classe, A para assunto)';

    public function handle()
    {
        $codigo = $this->argument('codigo');
        $tipo = strtoupper($this->argument('tipo'));

        if (!in_array($tipo, ['C', 'A'])) {
            $this->error('Tipo deve ser C (classe) ou A (assunto)');
            return 1;
        }

        $this->info("Testando webservice CNJ...");
        $this->info("Código: {$codigo}");
        $this->info("Tipo: " . ($tipo === 'C' ? 'Classe' : 'Assunto'));

        $cnjService = new CnjService();

        try {
            $this->info("Chamando serviço CNJ...");

            if ($tipo === 'C') {
                $descricao = $cnjService->getClasseDescricao((int) $codigo);
            } else {
                $descricao = $cnjService->getAssuntoDescricao((int) $codigo);
            }

            $this->info("Resposta recebida, tipo: " . gettype($descricao));

            if ($descricao) {
                $this->info("✓ Descrição encontrada: {$descricao}");
            } else {
                $this->warn("⚠ Nenhuma descrição encontrada");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("✗ Erro: " . $e->getMessage());
            $this->error("Stack trace:");
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
