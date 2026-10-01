<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Models\ContratoFornecedor;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\MarcoContratoFornecedor;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Contratos de fornecedores e marcos (js/ui_compras_v2.js:4418-4798), corrigidos:
 *   - referência única por fornecedor; datas coerentes; fornecedor não muda depois de haver encomendas;
 *   - encomendas associadas só do MESMO fornecedor e não anuladas (no legado era só uma confirmação), cada encomenda
 *     num só contrato (índice único) — mover de contrato é explícito;
 *   - marcos geridos um a um (o legado apagava e recriava todos a cada gravação, mudando os ids), com Σ montantes
 *     ≤ valor do contrato e data dentro do período; o estado deixa de ser manual: PENDENTE → FATURADO (factura
 *     ligada) → PAGO (factura paga);
 *   - consumo calculado (encomendado, facturado, pago) com aviso quando excede o valor contratado;
 *   - expiração automática (ATIVO → EXPIRADO depois da data de fim); cancelar com motivo e rasto.
 */
final class ServicoContratosFornecedores
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoProcessoCompras $processo,
    ) {}

    public function gravar(array $d, ?ContratoFornecedor $c = null): ContratoFornecedor
    {
        if ($c && $c->estado === 'CANCELADO') {
            throw new ErroNegocio('Um contrato cancelado não se altera.', 'CONTRATO_CANCELADO', 422);
        }
        $this->processo->fornecedor((int) $d['fornecedor_id']);
        if (! empty($d['data_fim']) && ! empty($d['data_inicio']) && $d['data_fim'] < $d['data_inicio']) {
            throw new ErroNegocio('A data de fim não pode ser anterior à de início.', 'DATAS_INVALIDAS', 422);
        }

        return DB::transaction(function () use ($d, $c) {
            $referencia = trim($d['referencia']);
            $repetido = ContratoFornecedor::query()->where('fornecedor_id', $d['fornecedor_id'])->whereRaw('upper(referencia) = upper(?)', [$referencia])
                ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'CANCELADO'))->when($c, fn ($q) => $q->where('id', '<>', $c->id))->exists();
            if ($repetido) {
                throw new ErroNegocio("Já existe o contrato {$referencia} para este fornecedor.", 'CONTRATO_DUPLICADO', 422);
            }
            if ($c && (int) $c->fornecedor_id !== (int) $d['fornecedor_id'] && $this->encomendas($c)->isNotEmpty()) {
                throw new ErroNegocio('O contrato já tem encomendas: o fornecedor não pode mudar.', 'CONTRATO_COM_ENCOMENDAS', 422);
            }
            if ($c && bccomp(number_format((float) $d['valor_total'], 2, '.', ''), $this->somaMarcos($c), 2) < 0) {
                throw new ErroNegocio('O valor do contrato não pode ficar abaixo da soma dos marcos.', 'MARCOS_EXCEDEM_CONTRATO', 422);
            }
            $dados = ['fornecedor_id' => $d['fornecedor_id'], 'referencia' => $referencia, 'descricao' => $d['descricao'] ?? null,
                'data_inicio' => $d['data_inicio'] ?? null, 'data_fim' => $d['data_fim'] ?? null, 'valor_total' => number_format((float) $d['valor_total'], 2, '.', ''),
                'estado' => ! empty($d['data_fim']) && $d['data_fim'] < now()->toDateString() ? 'EXPIRADO' : 'ATIVO'];

            return $c ? tap($c)->update($dados) : ContratoFornecedor::create($dados);
        });
    }

    /** @param  list<int>  $ids */
    public function associarEncomendas(ContratoFornecedor $c, array $ids, bool $mover = false): array
    {
        return DB::transaction(function () use ($c, $ids, $mover) {
            $c = ContratoFornecedor::query()->lockForUpdate()->findOrFail($c->id);
            if ($c->estado !== 'ATIVO') {
                throw new ErroNegocio("O contrato está {$c->estado}: não admite novas encomendas.", 'CONTRATO_NAO_ATIVO', 422);
            }
            $empresa = $this->contexto->obrigatorio();
            foreach (EncomendaCompra::query()->whereIn('id', $ids)->lockForUpdate()->get() as $e) {
                if ((int) $e->fornecedor_id !== (int) $c->fornecedor_id) {
                    throw new ErroNegocio("A encomenda {$e->numero_encomenda} é de outro fornecedor.", 'ENCOMENDA_OUTRO_FORNECEDOR', 422);
                }
                if ($e->estado === 'ANULADA') {
                    throw new ErroNegocio("A encomenda {$e->numero_encomenda} está anulada.", 'ENCOMENDA_ANULADA', 422);
                }
                $outro = DB::table('contratos_fornecedores_encomendas')->where('empresa_id', $empresa)->where('encomenda_compra_id', $e->id)->value('contrato_fornecedor_id');
                if ($outro && (int) $outro !== $c->id) {
                    if (! $mover) {
                        throw new ErroNegocio("A encomenda {$e->numero_encomenda} já está noutro contrato (confirme para mover).", 'ENCOMENDA_NOUTRO_CONTRATO', 422, ['contrato_id' => $outro]);
                    }
                    DB::table('contratos_fornecedores_encomendas')->where('empresa_id', $empresa)->where('encomenda_compra_id', $e->id)->delete();
                }
                if ((int) $outro !== $c->id) {
                    DB::table('contratos_fornecedores_encomendas')->insert(['empresa_id' => $empresa, 'contrato_fornecedor_id' => $c->id, 'encomenda_compra_id' => $e->id]);
                }
                $e->update(['contrato_fornecedor_id' => $c->id]);
            }
            $this->sincronizarPrimeira($c);

            return $this->resumo($c);
        });
    }

    public function desassociarEncomenda(ContratoFornecedor $c, EncomendaCompra $e): array
    {
        return DB::transaction(function () use ($c, $e) {
            DB::table('contratos_fornecedores_encomendas')->where('empresa_id', $this->contexto->obrigatorio())
                ->where('contrato_fornecedor_id', $c->id)->where('encomenda_compra_id', $e->id)->delete();
            if ((int) $e->contrato_fornecedor_id === $c->id) {
                $e->update(['contrato_fornecedor_id' => null]);
            }
            $this->sincronizarPrimeira($c);

            return $this->resumo($c);
        });
    }

    public function gravarMarco(ContratoFornecedor $c, array $d, ?MarcoContratoFornecedor $m = null): MarcoContratoFornecedor
    {
        return DB::transaction(function () use ($c, $d, $m) {
            $c = ContratoFornecedor::query()->lockForUpdate()->findOrFail($c->id);
            if ($c->estado === 'CANCELADO') {
                throw new ErroNegocio('O contrato está cancelado.', 'CONTRATO_CANCELADO', 422);
            }
            if ($m && $m->fatura_compra_id) {
                throw new ErroNegocio('O marco já está facturado: não se altera.', 'MARCO_FATURADO', 422);
            }
            $montante = number_format((float) $d['montante'], 2, '.', '');
            if (bccomp($montante, '0', 2) <= 0) {
                throw new ErroNegocio('O montante do marco tem de ser positivo.', 'MONTANTE_INVALIDO', 422);
            }
            $data = $d['data_prevista'] ?? null;
            if ($data && (($c->data_inicio && $data < $c->data_inicio->toDateString()) || ($c->data_fim && $data > $c->data_fim->toDateString()))) {
                throw new ErroNegocio('A data do marco tem de estar dentro do período do contrato.', 'DATA_FORA_DO_CONTRATO', 422);
            }
            $outros = bcsub($this->somaMarcos($c), $m ? (string) $m->montante : '0', 2);
            if (bccomp(bcadd($outros, $montante, 2), (string) $c->valor_total, 2) > 0) {
                throw new ErroNegocio('A soma dos marcos excede o valor do contrato.', 'MARCOS_EXCEDEM_CONTRATO', 422,
                    ['disponivel' => bcsub((string) $c->valor_total, $outros, 2)]);
            }
            $dados = ['contrato_fornecedor_id' => $c->id, 'titulo' => $d['titulo'], 'data_prevista' => $data, 'montante' => $montante, 'estado' => 'PENDENTE'];

            return $m ? tap($m)->update($dados) : MarcoContratoFornecedor::create($dados);
        });
    }

    public function eliminarMarco(MarcoContratoFornecedor $m): void
    {
        if ($m->fatura_compra_id) {
            throw new ErroNegocio('O marco já está facturado: desligue primeiro a factura.', 'MARCO_FATURADO', 422);
        }
        $m->delete();
    }

    /** Liga (ou desliga, com null) a factura que factura o marco; o estado passa a derivado da factura. */
    public function ligarFatura(MarcoContratoFornecedor $m, ?int $faturaId): MarcoContratoFornecedor
    {
        $c = ContratoFornecedor::query()->findOrFail($m->contrato_fornecedor_id);
        if ($faturaId) {
            $f = FaturaCompra::query()->findOrFail($faturaId);
            if ((int) $f->fornecedor_id !== (int) $c->fornecedor_id || $f->estado === 'ANULADA') {
                throw new ErroNegocio('A factura tem de ser do fornecedor do contrato e não estar anulada.', 'FATURA_INVALIDA', 422);
            }
            if (MarcoContratoFornecedor::query()->where('fatura_compra_id', $faturaId)->where('id', '<>', $m->id)->exists()) {
                throw new ErroNegocio('A factura já está ligada a outro marco.', 'FATURA_JA_LIGADA', 422);
            }
        }
        $m->update(['fatura_compra_id' => $faturaId]);
        $m->update(['estado' => $this->estadoMarco($m)]);

        return $m;
    }

    public function cancelar(ContratoFornecedor $c, string $motivo): ContratoFornecedor
    {
        if ($c->estado === 'CANCELADO') {
            throw new ErroNegocio('O contrato já está cancelado.', 'JA_ANULADO', 422);
        }
        $c->update(['estado' => 'CANCELADO', 'cancelado_em' => now(), 'motivo_cancelamento' => $motivo]);

        return $c;
    }

    /** ATIVO → EXPIRADO depois da data de fim (também corre no agendador). */
    public function expirar(): int
    {
        return ContratoFornecedor::query()->where('estado', 'ATIVO')->whereNotNull('data_fim')->where('data_fim', '<', now()->toDateString())->update(['estado' => 'EXPIRADO']);
    }

    /** Consumo do contrato: encomendado, facturado e pago, e marcos com o estado efectivo. */
    public function resumo(ContratoFornecedor $c): array
    {
        $c->refresh()->load(RelacoesNomes::fornecedor());
        $encomendas = $this->encomendas($c);
        $ids = $encomendas->pluck('id');
        $faturas = FaturaCompra::query()->where(fn ($q) => $q->whereIn('encomenda_compra_id', $ids)
            ->orWhereIn('id', MarcoContratoFornecedor::query()->where('contrato_fornecedor_id', $c->id)->whereNotNull('fatura_compra_id')->select('fatura_compra_id')))
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))->get();
        $soma = fn ($col, $lista) => number_format((float) $lista->sum($col), 2, '.', '');
        $encomendado = $soma('montante_total', $encomendas);
        $marcos = MarcoContratoFornecedor::query()->where('contrato_fornecedor_id', $c->id)->orderBy('data_prevista')->orderBy('id')->get()
            ->map(fn ($m) => $m->toArray() + ['estado_efetivo' => $this->estadoMarco($m)]);

        return $c->toArray() + [
            'encomendas' => $encomendas->map->only(['id', 'numero_encomenda', 'data', 'estado', 'montante_total', 'total_com_imposto'])->values(),
            'marcos' => $marcos,
            'consumo' => [
                'encomendado' => $encomendado, 'faturado' => $soma('montante_total', $faturas), 'pago' => $soma('montante_total', $faturas->where('estado', 'PAGO')),
                'percentagem' => (float) $c->valor_total > 0 ? round((float) $encomendado / (float) $c->valor_total * 100, 1) : null,
                'excedido' => bccomp($encomendado, (string) $c->valor_total, 2) > 0,
            ],
        ];
    }

    private function estadoMarco(MarcoContratoFornecedor $m): string
    {
        if (! $m->fatura_compra_id) {
            return 'PENDENTE';
        }

        return FaturaCompra::query()->whereKey($m->fatura_compra_id)->value('estado') === 'PAGO' ? 'PAGO' : 'FATURADO';
    }

    private function encomendas(ContratoFornecedor $c)
    {
        $ids = DB::table('contratos_fornecedores_encomendas')->where('empresa_id', $this->contexto->obrigatorio())->where('contrato_fornecedor_id', $c->id)->pluck('encomenda_compra_id');

        return EncomendaCompra::query()->whereIn('id', $ids)->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))->orderBy('data')->get();
    }

    private function somaMarcos(ContratoFornecedor $c): string
    {
        return number_format((float) MarcoContratoFornecedor::query()->where('contrato_fornecedor_id', $c->id)->sum('montante'), 2, '.', '');
    }

    /** Compatibilidade com o legado: contratos_fornecedores.encomenda_compra_id = 1.ª encomenda. */
    private function sincronizarPrimeira(ContratoFornecedor $c): void
    {
        $c->update(['encomenda_compra_id' => DB::table('contratos_fornecedores_encomendas')->where('empresa_id', $this->contexto->obrigatorio())
            ->where('contrato_fornecedor_id', $c->id)->min('encomenda_compra_id')]);
    }
}
