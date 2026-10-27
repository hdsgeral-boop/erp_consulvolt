<?php

namespace App\Jobs\Acrescimos;

use App\Exceptions\ErroNegocio;
use App\Services\Acrescimos\ServicoPropostaAcrescimos;
use App\Services\Integracoes\Operacoes\ServicoOperacoes;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Uma linha (chave) da proposta mensal de acréscimos e diferimentos, de um lote de «Contabilizar Seleccionada(s)» (M-05,
 * Bus::batch via ServicoOperacoes): a mesma regra de `ServicoPropostaAcrescimos::contabilizar()`, mas um trabalho por
 * linha, para que o lote (até 1000 linhas) tenha barra de progresso e continue em segundo plano. Cada linha fica na sua
 * transacção; a proposta é recalculada no momento de cada trabalho (como já era), para evitar duplicados mesmo que o
 * lote demore. Uma falha de negócio (ex.: já contabilizada entretanto) é um resultado normal do trabalho, não um erro do
 * job: fica registada em `ServicoOperacoes::resultados()` e o job termina sem lançar.
 */
final class ContabilizarPropostaAcrescimoJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $empresaId,
        public readonly string $mes,
        public readonly string $chave,
    ) {
        $this->onQueue('alta');
    }

    public function handle(ContextoEmpresa $contexto, ServicoPropostaAcrescimos $servico, ServicoOperacoes $operacoes): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $contexto->executarComo($this->empresaId, function () use ($servico, $operacoes) {
            try {
                $r = $servico->contabilizar($this->mes, [$this->chave]);
                if ($r['ok']) {
                    $this->guardar($operacoes, ['chave' => $this->chave, 'sucesso' => true, 'mensagem' => "Lançamento {$r['ok'][0]['numero_lan']} criado."]);
                } else {
                    $erro = $r['erros'][0] ?? ['mensagem' => 'Não foi possível contabilizar.', 'codigo' => 'ERRO'];
                    $this->guardar($operacoes, ['chave' => $this->chave, 'sucesso' => false, 'mensagem' => $erro['mensagem'], 'codigo' => $erro['codigo'] ?? null]);
                }
            } catch (ErroNegocio $e) {
                // ex.: SEM_LINHAS — a linha deixou de estar pendente entretanto (contabilizada ou alterada por outro trabalho do lote)
                $this->guardar($operacoes, ['chave' => $this->chave, 'sucesso' => false, 'mensagem' => $e->getMessage(), 'codigo' => $e->codigo]);
            } catch (Throwable $e) {
                report($e);
                $this->guardar($operacoes, ['chave' => $this->chave, 'sucesso' => false, 'mensagem' => 'Erro inesperado.', 'codigo' => 'ERRO_INTERNO']);
            }
        });
    }

    private function guardar(ServicoOperacoes $operacoes, array $resultado): void
    {
        if ($this->batch()) {
            $operacoes->registarResultado($this->batch()->id, $resultado);
        }
    }
}
