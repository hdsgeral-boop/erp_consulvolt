<?php

namespace App\Jobs\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ReciboVenda;
use App\Models\Venda;
use App\Services\Integracoes\Operacoes\ServicoOperacoes;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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
 */
final class ContabilizarVendaLoteJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $empresaId,
        /** @var 'documentos'|'recibos' */
        public readonly string $tipo,
        public readonly bool $contabilizar,
        public readonly int $id,
        public readonly ?string $motivo = null,
    ) {
        $this->onQueue('alta');
    }

    public function handle(ContextoEmpresa $contexto, ServicoContabilizacaoVendas $servico, ServicoOperacoes $operacoes): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $contexto->executarComo($this->empresaId, function () use ($servico, $operacoes) {
            $doc = $this->tipo === 'recibos' ? ReciboVenda::query()->find($this->id) : Venda::query()->find($this->id);
            $numero = $doc ? ($this->tipo === 'recibos' ? $doc->numero_recibo : $doc->numero_documento) : null;
            if (! $doc) {
                $this->guardar($operacoes, ['id' => $this->id, 'numero' => null, 'sucesso' => false, 'mensagem' => 'Documento inexistente.', 'codigo' => 'NAO_ENCONTRADO']);

                return;
            }
            try {
                $r = match (true) {
                    $this->tipo === 'recibos' && $this->contabilizar => $servico->contabilizarRecibo($doc),
                    $this->tipo === 'recibos' => $servico->descontabilizarRecibo($doc, (string) $this->motivo),
                    $this->contabilizar => $servico->contabilizar($doc),
                    default => $servico->descontabilizar($doc, (string) $this->motivo),
                };
                $this->guardar($operacoes, ['id' => $this->id, 'numero' => $numero, 'sucesso' => true,
                    'mensagem' => $this->contabilizar ? "Contabilizado (lançamento {$r->numero_lan_contabilizacao})." : 'Descontabilizado (estorno registado).']);
            } catch (ErroNegocio $e) {
                $this->guardar($operacoes, ['id' => $this->id, 'numero' => $numero, 'sucesso' => false, 'mensagem' => $e->getMessage(), 'codigo' => $e->codigo]);
            } catch (Throwable $e) {
                report($e);
                $this->guardar($operacoes, ['id' => $this->id, 'numero' => $numero, 'sucesso' => false, 'mensagem' => 'Erro inesperado: a operação foi desfeita para este documento.', 'codigo' => 'ERRO_INTERNO']);
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
