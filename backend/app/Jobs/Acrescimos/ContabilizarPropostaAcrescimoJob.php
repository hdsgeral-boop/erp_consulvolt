<?php

namespace App\Jobs\Acrescimos;

use App\Exceptions\ErroNegocio;
use App\Services\Acrescimos\ServicoPropostaAcrescimos;
use App\Services\Integracoes\Operacoes\ServicoOperacoes;
use App\Support\Idempotencia\ExecucaoEmCursoException;
use App\Support\Idempotencia\ExecucaoIdempotente;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Uma linha (chave) da proposta mensal de acréscimos e diferimentos, de um lote de «Contabilizar Seleccionada(s)» (M-05,
 * Bus::batch via ServicoOperacoes): a mesma regra de `ServicoPropostaAcrescimos::contabilizar()`, mas um trabalho por
 * linha, para que o lote (até 1000 linhas) tenha barra de progresso e continue em segundo plano. Cada linha fica na sua
 * transacção; a proposta é recalculada no momento de cada trabalho (como já era), para evitar duplicados mesmo que o
 * lote demore. Uma falha de negócio (ex.: já contabilizada entretanto) é um resultado normal do trabalho, não um erro do
 * job: fica registada em `ServicoOperacoes::resultados()` e o job termina sem lançar.
 *
 * Retentativas seguras (ADR-080): `tries = 4` com recuo; o trabalho corre dentro de ExecucaoIdempotente (chave
 * `contab:acrescimos:{mês}:{sha1 da linha}:{lote ou uuid do despacho}`). Uma repetição depois de sucesso devolve o resultado guardado;
 * uma excepção técnica repete-se; se o worker morreu entre o COMMIT do serviço e a gravação do resultado, a retoma já não encontra
 * a linha pendente (SEM_LINHAS / JA_CONTABILIZADO) e, por ser uma retentativa, trata-o como sucesso («já estava contabilizada»).
 */
final class ContabilizarPropostaAcrescimoJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /** Identifica este despacho quando não há lote (estável nas retentativas). */
    private readonly string $despacho;

    public function __construct(
        public readonly int $empresaId,
        public readonly string $mes,
        public readonly string $chave,
    ) {
        $this->despacho = (string) Str::uuid();
        $this->onQueue('alta');
    }

    /** Chave determinística da execução idempotente. */
    public function chaveExecucao(): string
    {
        $lote = $this->batch()?->id ?? "u{$this->despacho}";

        return "contab:acrescimos:{$this->mes}:".sha1($this->chave).":{$lote}";
    }

    public function handle(ContextoEmpresa $contexto, ServicoPropostaAcrescimos $servico, ServicoOperacoes $operacoes, ExecucaoIdempotente $execucao): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $contexto->executarComo($this->empresaId, function () use ($servico, $operacoes, $execucao) {
            try {
                $resultado = $execucao->executar($this->empresaId, $this->chaveExecucao(), fn (int $tentativa) => $this->contabilizarLinha($servico, $tentativa));
            } catch (ExecucaoEmCursoException) {
                $this->release(15);   // outro worker tem esta linha em curso: tenta-se mais tarde

                return;
            }
            $this->guardar($operacoes, $resultado);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function contabilizarLinha(ServicoPropostaAcrescimos $servico, int $tentativa): array
    {
        try {
            $r = $servico->contabilizar($this->mes, [$this->chave]);
            if ($r['ok']) {
                return ['chave' => $this->chave, 'sucesso' => true, 'mensagem' => "Lançamento {$r['ok'][0]['numero_lan']} criado."];
            }
            $erro = $r['erros'][0] ?? ['mensagem' => 'Não foi possível contabilizar.', 'codigo' => 'ERRO'];
            if (($erro['codigo'] ?? null) === 'ERRO') {
                // o serviço devolve as excepções técnicas (não de negócio) como código «ERRO»: aqui são falhas a repetir, não um resultado
                throw new RuntimeException($erro['mensagem']);
            }
            if ($this->jaFeito($tentativa, $erro['codigo'] ?? null)) {
                return ['chave' => $this->chave, 'sucesso' => true, 'mensagem' => 'Já estava contabilizada — retentativa.'];
            }

            return ['chave' => $this->chave, 'sucesso' => false, 'mensagem' => $erro['mensagem'], 'codigo' => $erro['codigo'] ?? null];
        } catch (ErroNegocio $e) {
            // ex.: SEM_LINHAS — a linha deixou de estar pendente entretanto (contabilizada ou alterada por outro trabalho do lote)
            if ($this->jaFeito($tentativa, $e->codigo)) {
                return ['chave' => $this->chave, 'sucesso' => true, 'mensagem' => 'Já estava contabilizada — retentativa.'];
            }

            return ['chave' => $this->chave, 'sucesso' => false, 'mensagem' => $e->getMessage(), 'codigo' => $e->codigo];
        }
    }

    /** Numa retentativa (a tentativa anterior pode ter gravado antes de morrer), «já contabilizada» é o resultado pedido. */
    private function jaFeito(int $tentativa, ?string $codigo): bool
    {
        return $tentativa > 1 && in_array($codigo, ['SEM_LINHAS', 'JA_CONTABILIZADO'], true);
    }

    /** Falha definitiva (esgotadas as tentativas ou tempo excedido): o ecrã de progresso mostra o detalhe. */
    public function failed(Throwable $e): void
    {
        report($e);
        $this->guardar(app(ServicoOperacoes::class), ['chave' => $this->chave, 'sucesso' => false,
            'mensagem' => "Erro inesperado após {$this->tries} tentativas.", 'codigo' => 'ERRO_INTERNO']);
    }

    private function guardar(ServicoOperacoes $operacoes, array $resultado): void
    {
        if ($this->batch()) {
            $operacoes->registarResultado($this->batch()->id, $resultado);
        }
    }
}
