<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\ContratoArrendamento;
use App\Models\ContratoEncargo;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Models\RendaArrendamento;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rendas do período (gerarRendas, recalcularRenda, aprovarRendas e anularRenda do legado). Gerar é idempotente (UNIQUE contrato +
 * período: nunca duplica nem regenera uma renda anulada), o cálculo é o de CalculadoraRenda com a actualização de
 * CalculadoraActualizacao e o pro-rata de CalculadoraPeriodos. Correcções (P3): o primeiro e o último período são proporcionais
 * (o legado cobrava-os inteiros; contrato com `pro_rata = false` reproduz o legado); recalcular mantém o desconto e os extras; uma
 * renda aprovada que se recalcula volta a «prevista» e tem de ser aprovada de novo; a listagem do período é só leitura (gerar é
 * uma acção própria) e mostra as rendas cujo período cobre o mês, não só as que começam nele. Quem aprova não gera (par de
 * segregação do catálogo). Facturar e pagar são de ServicoFacturacaoRendas e ServicoPagamentoRendas.
 */
final class ServicoRendas
{
    /** @var array<int, array<string, mixed>> taxas por ano, para não repetir consultas no mesmo pedido */
    private array $taxasDoAno = [];

    public function __construct(private readonly ContextoEmpresa $contexto, private readonly ServicoTaxasImobiliario $taxas, private readonly ServicoContaCorrente $contaCorrente) {}

    /** @return array<string, float> as taxas do ano das rendas */
    private function taxasDe(string $periodo): array
    {
        $ano = (int) substr($periodo, 0, 4);

        return $this->taxasDoAno[$ano] ??= $this->taxas->doAno($ano)['taxas'];
    }

    /** O último dia do contrato: a saída efectiva ou o termo (null = sem termo). */
    public static function fimEfectivo(ContratoArrendamento $c): ?string
    {
        $f = $c->fim_real ?? $c->fim;

        return $f ? $f->format('Y-m-d') : null;
    }

    /**
     * Dados de um período já calculados (colunas da renda), com a actualização, o pro-rata e os extras.
     *
     * @param  Collection<int, ContratoEncargo>  $encargos
     * @param  array{desconto?: float|string, extras?: list<array<string, mixed>>}  $o
     * @return array<string, mixed>
     */
    public function montar(ContratoArrendamento $c, Collection $encargos, string $periodo, array $o = []): array
    {
        [$de, $ate] = CalculadoraPeriodos::limites($periodo, $c->periodicidade);
        $extras = array_values($o['extras'] ?? []);
        $extrasTotal = array_reduce($extras, fn ($t, $e) => bcadd($t, number_format((float) $e['valor'], 2, '.', ''), 2), '0.00');
        $pr = CalculadoraPeriodos::factorProRata($de, $ate, $c->inicio->format('Y-m-d'), self::fimEfectivo($c), (bool) $c->pro_rata);
        $renda = CalculadoraActualizacao::rendaNoPeriodo(['renda' => $c->renda, 'actualizacao' => $c->actualizacao, 'actualizacao_valor' => $c->actualizacao_valor,
            'actualizacao_meses' => $c->actualizacao_meses, 'actualizacao_base' => $c->actualizacao_base?->format('Y-m-d'), 'inicio' => $c->inicio->format('Y-m-d')], $periodo);
        $calc = CalculadoraRenda::calcular($this->dadosFiscais($c, $encargos), $this->taxasDe($periodo),
            ['renda' => $renda, 'desconto' => $o['desconto'] ?? 0, 'extras_total' => $extrasTotal, 'factor' => $pr['factor']]);

        return ['de' => $de, 'ate' => $ate, 'vencimento' => CalculadoraPeriodos::vencimento($de, $ate, (bool) $c->antecipada, (int) $c->dia_vencimento),
            'extras' => $extras] + $calc;
    }

    /** @param  Collection<int, ContratoEncargo>  $encargos @return array<string, mixed> */
    public function dadosFiscais(ContratoArrendamento $c, Collection $encargos): array
    {
        return ['retencao_ip' => (bool) $c->retencao_ip, 'isencao_ip' => $c->isencao_ip, 'selo_encargo' => $c->selo_encargo, 'finalidade_fiscal' => $c->finalidade_fiscal,
            'renda' => $c->renda, 'encargos' => $encargos->map(fn ($e) => ['valor' => $e->valor, 'incluido_na_renda' => (bool) $e->incluido_na_renda])->all()];
    }

    /** Período a período, as rendas em falta de um contrato activo (idempotente). @return array{criadas: int, periodos: list<string>} */
    public function gerar(ContratoArrendamento $c, ?string $de = null, ?string $ate = null): array
    {
        $ate ??= now()->toDateString();
        $de ??= $c->inicio->format('Y-m-d');
        if ($ate < $de) {
            throw new ErroNegocio('O fim do intervalo é anterior ao início.', 'IMO_INTERVALO_INVALIDO');
        }

        return DB::transaction(function () use ($c, $de, $ate) {
            $c = ContratoArrendamento::query()->lockForUpdate()->findOrFail($c->id);
            if ($c->estado !== 'ACTIVO') {
                throw new ErroNegocio('Só se geram rendas de contratos activos.', 'IMO_CONTRATO_NAO_ACTIVO');
            }
            $encargos = ContratoEncargo::query()->where('contrato_id', $c->id)->orderBy('ordem')->get();
            $existentes = RendaArrendamento::query()->where('contrato_id', $c->id)->pluck('periodo')->all();
            $criadas = [];
            foreach (CalculadoraPeriodos::periodosDoContrato($c->inicio->format('Y-m-d'), self::fimEfectivo($c), $c->periodicidade, $de, $ate) as $periodo) {
                if (in_array($periodo, $existentes, true)) {
                    continue;   // qualquer estado, incluindo anulada: uma renda anulada não se regenera
                }
                try {
                    DB::transaction(fn () => RendaArrendamento::create($this->montar($c, $encargos, $periodo) + ['contrato_id' => $c->id, 'periodo' => $periodo, 'posicao' => $c->posicao,
                        'estado' => 'PREVISTA', 'criado_por' => Autor::nome(), 'atualizado_por' => Autor::nome()]));
                    $criadas[] = $periodo;
                } catch (UniqueConstraintViolationException) {
                    // outro pedido gerou o mesmo período entre a leitura e a escrita: nada a fazer
                }
            }

            return ['criadas' => count($criadas), 'periodos' => $criadas];
        });
    }

    /**
     * «Gerar rendas em falta» de todos os contratos activos até uma data. Um contrato com problema não pára os outros.
     *
     * @return array{criadas: int, contratos: int, problemas: list<array{contrato: string, erro: string}>}
     */
    public function gerarTudo(?string $ate = null): array
    {
        $criadas = 0;
        $contratos = 0;
        $problemas = [];
        foreach (ContratoArrendamento::query()->where('estado', 'ACTIVO')->orderBy('id')->get() as $c) {
            try {
                $r = $this->gerar($c, null, $ate);
                $criadas += $r['criadas'];
                $contratos += $r['criadas'] > 0 ? 1 : 0;
            } catch (ErroNegocio $e) {
                $problemas[] = ['contrato' => $c->numero, 'erro' => $e->getMessage()];
            }
        }

        return ['criadas' => $criadas, 'contratos' => $contratos, 'problemas' => $problemas];
    }

    /**
     * Rendas cujo período cobre o mês `$periodo` (AAAA-MM), de um lado, com a conta corrente de cada uma, e os totais do período.
     *
     * @return array{linhas: list<array<string, mixed>>, totais: array<string, mixed>, outro_lado: int}
     */
    public function doPeriodo(string $periodo, string $posicao): array
    {
        $inicioMes = $periodo.'-01';
        $fimMes = CalculadoraPeriodos::ultimoDia($periodo);
        $base = RendaArrendamento::query()->where('estado', '<>', 'ANULADA')->where('de', '<=', $fimMes)->where('ate', '>=', $inicioMes);
        $outroLado = (clone $base)->where('posicao', '<>', $posicao)->count();
        $rendas = (clone $base)->where('posicao', $posicao)->orderBy('vencimento')->orderBy('id')->get();
        $linhas = $this->enriquecer($rendas);
        $tot = ['PREVISTA' => '0.00', 'APROVADA' => '0.00', 'FACTURADA' => '0.00', 'PAGA' => '0.00'];
        $total = '0.00';
        $selo = '0.00';
        foreach ($linhas as $l) {
            $tot[$l['estado']] = bcadd($tot[$l['estado']], number_format((float) $l['total_receber'], 2, '.', ''), 2);
            $total = bcadd($total, number_format((float) $l['total_receber'], 2, '.', ''), 2);
            $selo = bcadd($selo, number_format((float) ($l['selo_encargo'] === 'SENHORIO' ? $l['selo_valor'] : 0), 2, '.', ''), 2);
        }

        return ['linhas' => $linhas, 'totais' => ['previstas' => (float) $tot['PREVISTA'], 'aprovadas' => (float) $tot['APROVADA'], 'facturadas' => (float) $tot['FACTURADA'],
            'pagas' => (float) $tot['PAGA'], 'total_receber' => (float) $total, 'selo_senhorio' => (float) $selo, 'rendas' => count($linhas)], 'outro_lado' => $outroLado];
    }

    /**
     * Rendas com o contrato, a outra parte, o imóvel e a conta corrente (pago, dívida, atraso, juros), tudo em lote.
     *
     * @param  Collection<int, RendaArrendamento>  $rendas
     * @return list<array<string, mixed>>
     */
    public function enriquecer(Collection $rendas, ?string $hoje = null): array
    {
        if ($rendas->isEmpty()) {
            return [];
        }
        $contratos = ContratoArrendamento::query()->whereIn('id', $rendas->pluck('contrato_id')->unique())->get()->keyBy('id');
        $terceiros = Terceiro::query()->whereIn('id', $contratos->pluck('terceiro_id')->unique())->pluck('nome', 'id');
        $imoveis = Imovel::query()->whereIn('id', $contratos->pluck('imovel_id')->unique())->pluck('nome', 'id');
        $fracoes = Fracao::query()->whereIn('id', $contratos->pluck('fracao_id')->unique())->pluck('designacao', 'id');
        $cc = $this->contaCorrente->linhas($rendas, $contratos, $hoje);
        $saida = [];
        foreach ($rendas as $r) {
            $c = $contratos[$r->contrato_id];
            $saida[] = $r->attributesToArray() + ['contrato_numero' => $c->numero, 'contrato_estado' => $c->estado, 'terceiro_nome' => $terceiros[$c->terceiro_id] ?? null,
                'imovel_nome' => $imoveis[$c->imovel_id] ?? null, 'fracao_designacao' => $fracoes[$c->fracao_id] ?? null, 'ambito' => $c->ambito] + $cc[$r->id];
        }

        return $saida;
    }

    /**
     * Recalcula uma renda ainda não documentada (taxas, actualização, pro-rata, encargos). Mantém o desconto e os extras. Uma renda
     * aprovada volta a «prevista» e tem de ser aprovada de novo, porque o valor aprovado já não é o que se vai facturar.
     *
     * @param  array{desconto?: float|string, notas?: ?string}  $o
     */
    public function recalcular(RendaArrendamento $r, array $o = []): RendaArrendamento
    {
        return DB::transaction(function () use ($r, $o) {
            $r = RendaArrendamento::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->emDocumento()) {
                throw new ErroNegocio('Uma renda que já é documento não se recalcula.', 'IMO_RENDA_EM_DOCUMENTO');
            }
            if ($r->estado === 'ANULADA') {
                throw new ErroNegocio('Uma renda anulada não se recalcula.', 'IMO_RENDA_ANULADA');
            }
            $c = ContratoArrendamento::query()->findOrFail($r->contrato_id);
            $encargos = ContratoEncargo::query()->where('contrato_id', $c->id)->orderBy('ordem')->get();
            $desconto = array_key_exists('desconto', $o) && $o['desconto'] !== null ? $o['desconto'] : $r->desconto;
            $dados = $this->montar($c, $encargos, $r->periodo, ['desconto' => $desconto, 'extras' => $r->extras ?? []]);
            $r->update($dados + ['estado' => 'PREVISTA', 'aprovada_por' => null, 'aprovada_em' => null, 'atualizado_por' => Autor::nome()]
                + (array_key_exists('notas', $o) ? ['notas' => $o['notas']] : []));

            return $r->refresh();
        });
    }

    /**
     * Aprova as rendas previstas indicadas. As que não estão previstas ficam de fora e são devolvidas com o motivo.
     *
     * @param  list<int>  $ids
     * @return array{aprovadas: int, ignoradas: list<array{id: int, motivo: string}>}
     */
    public function aprovar(array $ids): array
    {
        $aprovadas = 0;
        $ignoradas = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            DB::transaction(function () use ($id, &$aprovadas, &$ignoradas) {
                $r = RendaArrendamento::query()->lockForUpdate()->find($id);
                if (! $r) {
                    $ignoradas[] = ['id' => $id, 'motivo' => 'Renda não encontrada.'];

                    return;
                }
                if ($r->estado !== 'PREVISTA') {
                    $ignoradas[] = ['id' => $id, 'motivo' => 'Só se aprovam rendas previstas (esta está '.mb_strtolower(CatalogoContratos::ESTADOS_RENDA[$r->estado]).').'];

                    return;
                }
                $r->update(['estado' => 'APROVADA', 'aprovada_por' => Autor::nome(), 'aprovada_em' => now(), 'atualizado_por' => Autor::nome()]);
                $aprovadas++;
            });
        }

        return ['aprovadas' => $aprovadas, 'ignoradas' => $ignoradas];
    }

    public function anular(RendaArrendamento $r, ?string $motivo): RendaArrendamento
    {
        $motivo = Normaliza::texto($motivo) ?? throw new ErroNegocio('Indique o motivo da anulação.', 'IMO_MOTIVO_OBRIGATORIO');

        return DB::transaction(function () use ($r, $motivo) {
            $r = RendaArrendamento::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->estado === 'FACTURADA') {
                throw new ErroNegocio('A renda já é documento fiscal: corrija-a com nota de crédito em Vendas.', 'IMO_RENDA_FACTURADA');
            }
            if ($r->estado === 'PAGA') {
                throw new ErroNegocio('A renda já tem documento de tesouraria: anule-o na Tesouraria (ou desligue o pagamento) e volte aqui.', 'IMO_RENDA_PAGA');
            }
            if ($r->estado === 'ANULADA') {
                throw new ErroNegocio('A renda já está anulada.', 'IMO_RENDA_ANULADA');
            }
            $r->update(['estado' => 'ANULADA', 'notas' => trim(($r->notas ?? '').' · '.$motivo, ' ·'), 'atualizado_por' => Autor::nome()]);

            return $r->refresh();
        });
    }

    /**
     * Debita um encargo extraordinário ao inquilino (obra, reparação) numa renda ainda não documentada do lado senhorio. Entra no total
     * e no IVA mas não no Imposto Predial nem no Selo (é um débito de despesa). A renda volta a «prevista».
     */
    public function adicionarExtra(RendaArrendamento $r, string $designacao, float|string $valor, ?string $origem = null): RendaArrendamento
    {
        $designacao = Normaliza::texto($designacao) ?? throw new ErroNegocio('Indique a designação do encargo.', 'IMO_EXTRA_DESIGNACAO');
        if (! is_numeric($valor) || (float) $valor <= 0) {
            throw new ErroNegocio('Indique o valor a debitar.', 'IMO_EXTRA_VALOR');
        }

        return DB::transaction(function () use ($r, $designacao, $valor, $origem) {
            $r = RendaArrendamento::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->emDocumento() || $r->estado === 'ANULADA') {
                throw new ErroNegocio('A renda já é documento ou está anulada: escolha uma renda ainda por facturar.', 'IMO_RENDA_EM_DOCUMENTO');
            }
            if ($r->posicao !== 'SENHORIO') {
                throw new ErroNegocio('Só se debita ao inquilino numa renda a receber.', 'IMO_RENDA_LADO');
            }
            $extras = $r->extras ?? [];
            $extras[] = ['designacao' => mb_substr($designacao, 0, 150), 'valor' => number_format((float) $valor, 2, '.', ''), 'data' => now()->toDateString(), 'origem' => $origem];
            $c = ContratoArrendamento::query()->findOrFail($r->contrato_id);
            $encargos = ContratoEncargo::query()->where('contrato_id', $c->id)->orderBy('ordem')->get();
            $r->update($this->montar($c, $encargos, $r->periodo, ['desconto' => $r->desconto, 'extras' => $extras])
                + ['estado' => 'PREVISTA', 'aprovada_por' => null, 'aprovada_em' => null, 'atualizado_por' => Autor::nome()]);

            return $r->refresh();
        });
    }

    /**
     * Retira de uma renda ainda não documentada os encargos extraordinários de uma origem (a obra debitada que se desfaz). Só aqui: a renda
     * já facturada corrige-se com nota de crédito em Vendas. A renda volta a «prevista». Devolve a renda (inalterada se não havia o encargo).
     */
    public function removerExtra(RendaArrendamento $r, string $origem): RendaArrendamento
    {
        return DB::transaction(function () use ($r, $origem) {
            $r = RendaArrendamento::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->emDocumento() || $r->estado === 'ANULADA') {
                throw new ErroNegocio('A renda já é documento ou está anulada: o encargo corrige-se com uma nota de crédito, não aqui.', 'IMO_RENDA_EM_DOCUMENTO');
            }
            $extras = array_values(array_filter($r->extras ?? [], fn ($e) => ($e['origem'] ?? null) !== $origem));
            if (count($extras) === count($r->extras ?? [])) {
                return $r;
            }
            $c = ContratoArrendamento::query()->findOrFail($r->contrato_id);
            $encargos = ContratoEncargo::query()->where('contrato_id', $c->id)->orderBy('ordem')->get();
            $r->update($this->montar($c, $encargos, $r->periodo, ['desconto' => $r->desconto, 'extras' => $extras])
                + ['estado' => 'PREVISTA', 'aprovada_por' => null, 'aprovada_em' => null, 'atualizado_por' => Autor::nome()]);

            return $r->refresh();
        });
    }
}
