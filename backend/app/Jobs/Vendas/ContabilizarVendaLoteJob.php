<?php

namespace App\Jobs\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ReciboVenda;
use App\Models\Venda;
use App\Services\Integracoes\Operacoes\ServicoOperacoes;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use App\Support\Idempotencia\ExecucaoEmCursoException;
use App\Support\Idempotencia\ExecucaoIdempotente;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Um documento (ou recibo) de um lote de «Contabilizar/Descontabilizar Seleccionados» (M-06, Bus::batch via
 * ServicoOperacoes — M-05): a mesma regra de `ServicoContabilizacaoVendas::lote()`, mas um trabalho por documento, para
 * que o lote tenha barra de progresso e continue em segundo plano (o utilizador pode sair do ecrã ou fechar o browser).
 * Cada documento fica na sua transacção (ServicoContabilizacaoVendas). Uma falha de negócio (ex.: documento já
 * contabilizado) é um resultado normal do trabalho, não um erro do job: fica registada em
 * `ServicoOperacoes::resultados()` (a mesma forma de `lote()`: id, numero, sucesso, mensagem), e o job termina sem
 * lançar — lançar faria o lote marcar o trabalho como «falhado» (contagem genérica, sem detalhe) e, no driver `sync`
 * usado nos testes, propagar-se-ia até à resposta do próprio pedido HTTP que despachou o lote.
 *
 * Retentativas seguras (ADR-080): `tries = 4` com recuo, porque o trabalho é idempotente — corre dentro de ExecucaoIdempotente
 * (chave `contab:{documentos|recibos}:{contabilizar|descontabilizar}:{id}:{lote}`; o lote, ou um uuid do próprio despacho, separa
 * uma NOVA operação do utilizador sobre o mesmo documento da anterior). Uma repetição depois de sucesso devolve o resultado guardado
 * sem voltar a contabilizar; uma excepção técnica repete-se; se o worker morreu entre o COMMIT do serviço e a gravação do resultado,
 * a retoma bate em «já contabilizado/descontabilizado» com o documento já no estado pedido e trata-o como sucesso. Com
 * `allowFailures()` do lote, uma tentativa que será repetida não conta como falhada: só a falha definitiva (failed()) conta.
 */
final class ContabilizarVendaLoteJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    /** Identifica este despacho quando não há lote (estável nas retentativas: o job é serializado com ele). */
    private readonly string $despacho;

    public function __construct(
        public readonly int $empresaId,
        /** @var 'documentos'|'recibos' */
        public readonly string $tipo,
        public readonly bool $contabilizar,
        public readonly int $id,
        public readonly ?string $motivo = null,
    ) {
        $this->despacho = (string) Str::uuid();
        $this->onQueue('alta');
    }

    /** Chave determinística da execução idempotente. */
    public function chaveExecucao(): string
    {
        $lote = $this->batch()?->id ?? "u{$this->despacho}";

        return "contab:{$this->tipo}:".($this->contabilizar ? 'contabilizar' : 'descontabilizar').":{$this->id}:{$lote}";
    }

    public function handle(ContextoEmpresa $contexto, ServicoContabilizacaoVendas $servico, ServicoOperacoes $operacoes, ExecucaoIdempotente $execucao): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $contexto->executarComo($this->empresaId, function () use ($servico, $operacoes, $execucao) {
            try {
                $resultado = $execucao->executar($this->empresaId, $this->chaveExecucao(), fn (int $tentativa) => $this->contabilizarDocumento($servico, $tentativa));
            } catch (ExecucaoEmCursoException) {
                $this->release(15);   // outro worker tem este documento em curso: tenta-se mais tarde

                return;
            }
            $this->guardar($operacoes, $resultado);
        });
    }

    /**
     * O trabalho propriamente dito. Devolve o resultado (nunca lança erros de negócio); uma excepção técnica sai para a fila repetir.
     *
     * @return array<string, mixed>
     */
    private function contabilizarDocumento(ServicoContabilizacaoVendas $servico, int $tentativa): array
    {
        $doc = $this->tipo === 'recibos' ? ReciboVenda::query()->find($this->id) : Venda::query()->find($this->id);
        $numero = $doc ? ($this->tipo === 'recibos' ? $doc->numero_recibo : $doc->numero_documento) : null;
        if (! $doc) {
            return ['id' => $this->id, 'numero' => null, 'sucesso' => false, 'mensagem' => 'Documento inexistente.', 'codigo' => 'NAO_ENCONTRADO'];
        }
        try {
            $r = match (true) {
                $this->tipo === 'recibos' && $this->contabilizar => $servico->contabilizarRecibo($doc),
                $this->tipo === 'recibos' => $servico->descontabilizarRecibo($doc, (string) $this->motivo),
                $this->contabilizar => $servico->contabilizar($doc),
                default => $servico->descontabilizar($doc, (string) $this->motivo),
            };

            return ['id' => $this->id, 'numero' => $numero, 'sucesso' => true,
                'mensagem' => $this->contabilizar ? "Contabilizado (lançamento {$r->numero_lan_contabilizacao})." : 'Descontabilizado (estorno registado).'];
        } catch (ErroNegocio $e) {
            // retoma depois de um crash entre o COMMIT do serviço e a gravação do resultado: o documento já está no estado pedido
            $jaFeito = $tentativa > 1 && in_array($e->codigo, ['JA_CONTABILIZADO', 'NAO_CONTABILIZADO'], true) && (bool) $doc->refresh()->contabilizado === $this->contabilizar;
            if ($jaFeito) {
                return ['id' => $this->id, 'numero' => $numero, 'sucesso' => true,
                    'mensagem' => ($this->contabilizar ? 'Já estava contabilizado' : 'Já estava descontabilizado').' — retentativa.'];
            }

            return ['id' => $this->id, 'numero' => $numero, 'sucesso' => false, 'mensagem' => $e->getMessage(), 'codigo' => $e->codigo];
        }
    }

    /** Falha definitiva (esgotadas as tentativas ou tempo excedido): o ecrã de progresso mostra o detalhe, não só a contagem genérica. */
    public function failed(Throwable $e): void
    {
        report($e);
        $this->guardar(app(ServicoOperacoes::class), ['id' => $this->id, 'numero' => null, 'sucesso' => false,
            'mensagem' => "Erro inesperado após {$this->tries} tentativas: a operação foi desfeita para este documento.", 'codigo' => 'ERRO_INTERNO']);
    }

    private function guardar(ServicoOperacoes $operacoes, array $resultado): void
    {
        if ($this->batch()) {
            $operacoes->registarResultado($this->batch()->id, $resultado);
        }
    }
}
