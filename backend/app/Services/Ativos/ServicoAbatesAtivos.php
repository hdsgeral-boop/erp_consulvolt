<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\AbateVendaAtivo;
use App\Models\AmortizacaoAtivo;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\LancamentoContabil;
use App\Models\Terceiro;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\DB;

/**
 * Abates e vendas (renderDisposalsView / saveDisposal, js/ui_assets.js:2382-2537): tipos SINISTRO, VENDA e FIM_VIDA; a venda
 * exige o terceiro e a conta do terceiro; o activo passa a ABATIDO.
 * Correcção principal: o legado só registava o abate e mudava o estado — o bem saía do inventário mas ficava no balanço
 * (classe 11/12 e amortização acumulada 18), apesar de as categorias terem as contas de venda e de perda configuradas e nunca
 * usadas. O abate passa a ser contabilizado (diário AM, documento "ABT-<id>", data do abate):
 *   D amortização acumulada (18)            = amortizações acumuladas à data
 *   C conta do activo (11/12)               = valor de aquisição
 *   D conta do terceiro                     = valor de venda / indemnização (se houver)
 *   C conta de venda (6)  mais-valia        = valor − valor líquido contabilístico, se positivo
 *   D conta de perda (7)  menos-valia       = valor líquido contabilístico − valor, se positivo
 * A conta do activo é a indicada, senão a da categoria, senão a da linha de aquisição ligada ao activo. Com
 * contabilizar = false regista-se como no legado (ex.: activos migrados cuja aquisição nunca foi contabilizada).
 * Outras correcções: não se abate com rascunhos de amortização nem com quotas integradas depois da data do abate; um valor
 * de indemnização também exige terceiro e conta; o abate pode ser anulado (estorno do lançamento e reactivação do activo).
 */
final class ServicoAbatesAtivos
{
    public function __construct(
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoPlanoContas $planoContas,
        private readonly ServicoAtivos $ativos,
        private readonly ServicoAmortizacoes $amortizacoes,
    ) {}

    /** @return array{abate: AbateVendaAtivo, numero_lan: ?string, avisos: list<string>} */
    public function registar(array $d): array
    {
        if (! in_array($d['tipo'] ?? null, AbateVendaAtivo::TIPOS, true)) {
            throw new ErroNegocio('Tipo de abate inválido.', 'DADOS_INVALIDOS', 422);
        }
        $valor = CalculadoraAmortizacoes::d($d['valor'] ?? 0);
        if (bccomp($valor, '0', 2) < 0) {
            throw new ErroNegocio('O valor não pode ser negativo.', 'DADOS_INVALIDOS', 422);
        }
        if ($d['tipo'] === 'VENDA' && bccomp($valor, '0', 2) <= 0) {
            throw new ErroNegocio('Indique o valor da venda.', 'DADOS_INVALIDOS', 422);
        }
        $comTerceiro = $d['tipo'] === 'VENDA' || bccomp($valor, '0', 2) > 0;
        if ($comTerceiro && (empty($d['terceiro_id']) || empty($d['conta_terceiro']))) {
            throw new ErroNegocio('Para vendas e indemnizações indique o terceiro e a conta contabilística do terceiro.', 'DADOS_INVALIDOS', 422);
        }
        if ($comTerceiro) {
            Terceiro::query()->findOr($d['terceiro_id'], fn () => throw new ErroNegocio('Terceiro inexistente.', 'DADOS_INVALIDOS', 422));
        }
        $contabilizar = (bool) ($d['contabilizar'] ?? true);

        return DB::transaction(function () use ($d, $valor, $comTerceiro, $contabilizar) {
            $a = AtivoImobilizado::query()->lockForUpdate()->find($d['ativo_imobilizado_id'] ?? 0)
                ?? throw new ErroNegocio('Activo inexistente.', 'DADOS_INVALIDOS', 422);
            if ($a->estado !== AtivoImobilizado::ESTADO_ATIVO) {
                throw new ErroNegocio("O activo {$a->codigo} não está activo (estado {$a->estado}).", 'ATIVO_NAO_ATIVO', 422);
            }
            $data = date('Y-m-d', strtotime((string) $d['data']));
            $regs = AmortizacaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->get();
            if ($regs->contains('contabilizado', false)) {
                throw new ErroNegocio("O activo {$a->codigo} tem amortizações calculadas por integrar: integre-as ou anule-as antes do abate.", 'ATIVO_COM_RASCUNHOS', 422);
            }
            $mesAbate = (int) substr($data, 0, 4) * 12 + (int) substr($data, 5, 2) - 1;
            if ($depois = $regs->filter(fn ($r) => CalculadoraAmortizacoes::ordem($r->periodo_codigo) > $mesAbate)->pluck('periodo_codigo')->values()->all()) {
                throw new ErroNegocio("O activo {$a->codigo} tem quotas integradas depois da data do abate (".implode(', ', $depois).'): reabra esses períodos.', 'QUOTAS_POSTERIORES', 422);
            }
            $avisos = [];
            $emFalta = array_column($this->amortizacoes->mesesPorCalcular($mesAbate, collect([$a])), 'periodo');
            if ($emFalta) {
                $avisos[] = 'Meses com amortização devida e não calculada até à data do abate: '.implode(', ', $emFalta).'.';
            }
            $a = $this->ativos->recalcularAcumulado($a);

            $abate = AbateVendaAtivo::create(['ativo_imobilizado_id' => $a->id, 'tipo' => $d['tipo'], 'data' => $data, 'descricao' => $d['descricao'] ?? null,
                'valor' => $valor, 'terceiro_id' => $comTerceiro ? $d['terceiro_id'] : null, 'conta_terceiro' => $comTerceiro ? trim((string) $d['conta_terceiro']) : null]);
            $numeroLan = $contabilizar ? $this->contabilizar($abate, $a, $d['conta_ativo'] ?? null) : null;
            $a->update(['estado' => AtivoImobilizado::ESTADO_ABATIDO]);

            return ['abate' => $abate->refresh(), 'numero_lan' => $numeroLan, 'avisos' => $avisos];
        });
    }

    /** Anula o abate: estorna o lançamento (se houver), reactiva o activo e retira o registo. */
    public function anular(AbateVendaAtivo $abate, string $motivo): AtivoImobilizado
    {
        return DB::transaction(function () use ($abate, $motivo) {
            $abate = AbateVendaAtivo::query()->lockForUpdate()->findOrFail($abate->id);
            $a = AtivoImobilizado::query()->lockForUpdate()->findOrFail($abate->ativo_imobilizado_id);
            $linha = LancamentoContabil::query()->where('numero_documento', $abate->numeroDocumento())->where('tipo_origem', AbateVendaAtivo::TIPO_ORIGEM)
                ->whereNull('estorno_de_id')->whereNull('estornado_por_id')->orderBy('id')->first();
            if ($linha) {
                $this->lancamentos->estornar($linha, $motivo);
            }
            $abate->delete();
            if (! AbateVendaAtivo::query()->where('ativo_imobilizado_id', $a->id)->exists()) {
                $a->update(['estado' => AtivoImobilizado::ESTADO_ATIVO]);
            }

            return $a->refresh();
        });
    }

    /** Pré-visualização das linhas do lançamento de abate (sem gravar). */
    public function simular(array $d): array
    {
        $a = AtivoImobilizado::query()->findOrFail($d['ativo_imobilizado_id'] ?? 0);
        $a = $this->ativos->recalcularAcumulado($a);
        $abate = new AbateVendaAtivo(['tipo' => $d['tipo'] ?? 'FIM_VIDA', 'valor' => CalculadoraAmortizacoes::d($d['valor'] ?? 0),
            'terceiro_id' => $d['terceiro_id'] ?? null, 'conta_terceiro' => $d['conta_terceiro'] ?? null]);

        return $this->linhas($abate, $a, $d['conta_ativo'] ?? null);
    }

    private function contabilizar(AbateVendaAtivo $abate, AtivoImobilizado $a, ?string $contaAtivo): string
    {
        $r = $this->linhas($abate, $a, $contaAtivo);
        $diario = $this->localizador->diario(AmortizacaoAtivo::DIARIO, AmortizacaoAtivo::NOME_DIARIO);

        return $this->lancamentos->criar([
            'diario_id' => $diario->id, 'data_documento' => $abate->data->toDateString(), 'numero_documento' => $abate->numeroDocumento(),
            'tipo_origem' => AbateVendaAtivo::TIPO_ORIGEM, 'descricao' => mb_substr("Abate ({$abate->tipo}) do activo {$a->codigo} — {$a->descricao}", 0, 1000),
            'linhas' => $r['linhas'],
        ])->first()->numero_lan;
    }

    /** @return array{linhas: list<array>, valor_aquisicao: string, amortizacao_acumulada: string, valor_liquido: string, resultado: string} */
    private function linhas(AbateVendaAtivo $abate, AtivoImobilizado $a, ?string $contaAtivo): array
    {
        $cat = CategoriaAtivo::withTrashed()->find($a->categoria_ativo_id);
        $aquisicao = CalculadoraAmortizacoes::d($a->valor_aquisicao);
        $acumulada = CalculadoraAmortizacoes::d($a->amortizacao_acumulada);
        $liquido = bcsub($aquisicao, $acumulada, 2);
        $valor = CalculadoraAmortizacoes::d($abate->valor);
        $resultado = bcsub($valor, $liquido, 2);
        $contaAtivo = trim((string) $contaAtivo) ?: ($cat?->conta_ativo ?: LancamentoContabil::query()->whereKey($a->lancamento_contabil_id)->value('codigo_conta'));
        if (! $contaAtivo) {
            throw new ErroNegocio("Indique a conta do activo {$a->codigo} (classe 11/12): nem a categoria nem o lançamento de compra a indicam.", 'SEM_CONTA_ATIVO', 422);
        }
        if (! preg_match('/^1[1-4]/', $contaAtivo)) {
            throw new ErroNegocio("A conta do activo {$contaAtivo} não é uma conta de imobilizado (11 a 14).", 'CONTA_INVALIDA', 422);
        }
        $dim = ['unidade_negocio_id' => $a->unidade_negocio_id, 'centro_custo_id' => $a->centro_custo_id];
        $linhas = [['codigo_conta' => $contaAtivo, 'tipo_dc' => 'C', 'valor' => $aquisicao, 'descricao' => "Saída do activo {$a->codigo}"] + $dim];
        if (bccomp($acumulada, '0', 2) > 0) {
            [, $contaAcumulada] = ServicoCategoriasAtivos::exigirContasAmortizacao($cat, (string) $a->codigo);
            $linhas[] = ['codigo_conta' => $contaAcumulada, 'tipo_dc' => 'D', 'valor' => $acumulada, 'descricao' => "Amortizações acumuladas do activo {$a->codigo}"] + $dim;
        }
        if (bccomp($valor, '0', 2) > 0) {
            $linhas[] = ['codigo_conta' => (string) $abate->conta_terceiro, 'tipo_dc' => 'D', 'valor' => $valor, 'terceiro_id' => $abate->terceiro_id,
                'descricao' => ($abate->tipo === 'VENDA' ? 'Venda' : 'Indemnização')." do activo {$a->codigo}"];
        }
        $cmp = bccomp($resultado, '0', 2);
        if ($cmp !== 0) {
            $campo = $cmp > 0 ? 'conta_venda' : 'conta_perda';
            $conta = $cat?->{$campo} ?: throw new ErroNegocio('A categoria «'.($cat->nome ?? '—').'» não tem a conta de '.($cmp > 0 ? 'venda (ganho)' : 'perda').'.', 'CATEGORIA_SEM_CONTAS', 422,
                ['campo' => $campo]);
            $linhas[] = ['codigo_conta' => $conta, 'tipo_dc' => $cmp > 0 ? 'C' : 'D', 'valor' => ltrim($resultado, '-'),
                'descricao' => ($cmp > 0 ? 'Mais-valia' : 'Menos-valia')." no abate do activo {$a->codigo}"] + $dim;
        }
        foreach ($linhas as $l) {
            $this->planoContas->contaDeMovimento($l['codigo_conta']);
        }

        return ['linhas' => $linhas, 'valor_aquisicao' => $aquisicao, 'amortizacao_acumulada' => $acumulada, 'valor_liquido' => $liquido, 'resultado' => $resultado];
    }
}
