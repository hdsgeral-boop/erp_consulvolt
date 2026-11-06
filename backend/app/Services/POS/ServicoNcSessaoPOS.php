<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\LiquidacaoPOS;
use App\Models\NcSessaoPOS;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;

/**
 * Notas de crédito sobre factura-recibo de sessão POS (F6, Q17). A NC é um documento de Vendas (D proveitos e IVA / C cliente,
 * contabilizada pela facturação); aqui trata-se do dinheiro devolvido, que sai do meio de pagamento da factura:
 *   - sessão ABERTA: os totais da sessão saem líquidos da NC (por meio, numerário, transferências e total de vendas), pelo que o
 *     fecho Z já a inclui; na integração lança-se D cliente / C transitória de cada meio;
 *   - sessão FECHADA por integrar: o instantâneo do Z é ajustado de imediato e o estorno lança-se na integração;
 *   - sessão INTEGRADA: exige a permissão `pos_nc_estornar` e que não haja prestação de contas registada (a transitória já foi
 *     regularizada: anule-a antes); ajusta o instantâneo, estorna logo (D cliente / C transitória) e o item a prestar desce;
 *   - descontabilizar a sessão estorna também estes lançamentos e as NC voltam a «pendentes» (reintegrar não duplica).
 * O valor reparte-se pelos pagamentos da factura em proporção (a última parcela leva o arredondamento).
 */
final class ServicoNcSessaoPOS
{
    public const PERMISSAO = 'pos_nc_estornar';

    public function __construct(
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoConfigPOS $config,
    ) {}

    /** @return ?array{codigo: string, mensagem: string} impedimento de emitir NC sobre vendas desta sessão */
    public static function motivoBloqueio(SessaoPOS $s): ?array
    {
        if ($s->estado_contabilizacao !== 'CONTABILIZADA') {
            return null;
        }
        if (LiquidacaoPOS::query()->where('sessao_pos_id', $s->id)->where('estado', 'REGISTADO')->exists()) {
            return ['codigo' => 'NC_SESSAO_COM_PRESTACAO', 'mensagem' => "A sessão {$s->codigo_sessao} já tem prestação de contas registada: anule a prestação antes de emitir a nota de crédito (a conta transitória já foi regularizada)."];
        }
        $u = Auth::user();
        if (! $u || ! app(ServicoPermissoes::class)->pode($u, self::PERMISSAO, app(ContextoEmpresa::class)->id())) {
            return ['codigo' => 'NC_SESSAO_INTEGRADA', 'mensagem' => "A sessão {$s->codigo_sessao} já está integrada na contabilidade: a nota de crédito exige o estorno na conta transitória e só é permitida a quem tem a permissão «Estornar notas de crédito de sessões integradas»."];
        }

        return null;
    }

    /** Chamado pela emissão da NC (ServicoDocumentosVenda), já com o documento criado. */
    public function registar(Venda $nc, Venda $origem): void
    {
        if (! $origem->sessao_pos_id || $origem->tipo_documento !== 'FR') {
            return;
        }
        $s = SessaoPOS::query()->lockForUpdate()->findOrFail($origem->sessao_pos_id);
        if (NcSessaoPOS::query()->where('venda_nc_id', $nc->id)->exists()) {
            return;   // idempotente
        }
        $valor = number_format((float) $nc->total_bruto, 2, '.', '');
        $linha = NcSessaoPOS::create([
            'empresa_id' => $s->empresa_id, 'sessao_pos_id' => $s->id, 'venda_nc_id' => $nc->id, 'venda_origem_id' => $origem->id, 'valor' => $valor,
            'distribuicao' => self::distribuir($origem, $valor), 'data' => $nc->data_emissao->toDateString(), 'abatida_em_aberta' => $s->estado === 'ABERTA',
            'estado' => 'PENDENTE']);
        if ($s->estado !== 'FECHADA') {
            return;   // aberta: os totais abatem-se ao calcular; o estorno lança-se na integração
        }
        $this->ajustarInstantaneo($s, $linha, $origem);
        if ($s->estado_contabilizacao === 'CONTABILIZADA') {
            $numero = $this->lancar($s, $linha, $nc);
            $s->update(['lans_contabilizacao' => array_merge($s->lans_contabilizacao ?? [], [$numero])]);
            app(ServicoPrestacaoContasPOS::class)->actualizarEstado($s->refresh());
        }
    }

    /**
     * Estornos das NC pendentes da sessão (chamado pela integração); devolve os números de lançamento criados.
     *
     * @return list<string>
     */
    public function lancarPendentes(SessaoPOS $s): array
    {
        $lans = [];
        foreach (NcSessaoPOS::query()->where('sessao_pos_id', $s->id)->where('estado', 'PENDENTE')->orderBy('id')->get() as $linha) {
            $lans[] = $this->lancar($s, $linha, Venda::query()->findOrFail($linha->venda_nc_id));
        }

        return $lans;
    }

    /** Descontabilizar a sessão: os lançamentos já foram estornados; as NC voltam a pendentes. */
    public function repor(SessaoPOS $s): void
    {
        NcSessaoPOS::query()->where('sessao_pos_id', $s->id)->where('estado', 'LANCADA')->update(['estado' => 'PENDENTE', 'numero_lan' => null]);
    }

    /**
     * Repartição do valor pelos pagamentos da factura-recibo, em proporção.
     *
     * @return list<array{meio_id: ?string, tipo: string, nome: string, conta_transitoria: ?string, valor: string}>
     */
    public static function distribuir(Venda $origem, string $valor): array
    {
        $pagos = collect($origem->pos_pagamentos ?? [])->filter(fn ($p) => bccomp(number_format((float) ($p['valor'] ?? 0), 2, '.', ''), '0', 2) > 0)->values();
        $total = $pagos->reduce(fn ($c, $p) => bcadd($c, number_format((float) $p['valor'], 2, '.', ''), 2), '0.00');
        if ($pagos->isEmpty() || bccomp($total, '0', 2) <= 0) {
            throw new ErroNegocio("{$origem->numero_documento}: a factura-recibo não tem pagamentos registados.", 'NC_SEM_PAGAMENTOS', 422);
        }
        $saida = [];
        $resto = $valor;
        foreach ($pagos as $i => $p) {
            $parte = $i === $pagos->count() - 1 ? $resto
                : number_format((float) bcdiv(bcmul($valor, number_format((float) $p['valor'], 2, '.', ''), 6), $total, 6), 2, '.', '');
            $parte = bccomp($parte, $resto, 2) > 0 ? $resto : $parte;
            $resto = bcsub($resto, $parte, 2);
            $saida[] = ['meio_id' => $p['meio_id'] ?? null, 'tipo' => $p['tipo'], 'nome' => $p['nome'] ?? $p['tipo'], 'conta_transitoria' => $p['conta_transitoria'] ?? null, 'valor' => $parte];
        }

        return $saida;
    }

    /** Lançamento do estorno: D conta do cliente da NC / C transitória de cada meio. */
    private function lancar(SessaoPOS $s, NcSessaoPOS $linha, Venda $nc): string
    {
        $cliente = Terceiro::query()->withTrashed()->findOrFail($nc->cliente_id);
        $conta = $cliente->exigirContaCliente("A nota de crédito {$nc->numero_documento} não pode ser estornada na transitória:");
        $creditos = [];
        foreach ($linha->distribuicao as $d) {
            if (bccomp((string) $d['valor'], '0', 2) <= 0) {
                continue;
            }
            $t = $d['conta_transitoria'] ?? throw new ErroNegocio("{$nc->numero_documento}: o meio «{$d['nome']}» não tem conta transitória.", 'PAGAMENTO_SEM_CONTA', 422);
            $creditos[$t] = bcadd($creditos[$t] ?? '0.00', (string) $d['valor'], 2);
        }
        $linhas = [['codigo_conta' => $conta, 'tipo_dc' => 'D', 'valor' => number_format((float) $linha->valor, 2, '.', ''), 'terceiro_id' => $cliente->id, 'numero_documento' => $nc->numero_documento]];
        foreach ($creditos as $t => $v) {
            $linhas[] = ['codigo_conta' => (string) $t, 'tipo_dc' => 'C', 'valor' => $v];
        }
        $numero = $this->lancamentos->criar([
            'diario_id' => $this->localizador->diario($this->config->obter()['codigo_diario'], 'Vendas POS')->id, 'data_documento' => $linha->data->toDateString(), 'numero_documento' => $s->numero_z, 'tipo_origem' => 'POS',
            'sessao_pos_id' => $s->id, 'descricao' => mb_substr("{$s->numero_z} — devolução {$nc->numero_documento} (estorno na transitória)", 0, 1000), 'linhas' => $linhas,
        ])->first()->numero_lan;
        $linha->update(['estado' => 'LANCADA', 'numero_lan' => $numero, 'conta_cliente' => $conta]);

        return $numero;
    }

    /** Abate a NC ao instantâneo do Z de uma sessão fechada (a contagem e o desvio do fecho não mudam). */
    private function ajustarInstantaneo(SessaoPOS $s, NcSessaoPOS $linha, Venda $origem): void
    {
        $meios = $s->totais_por_metodo ?? [];
        $transf = $s->transferencias ?? [];
        $numerario = '0.00';
        foreach ($linha->distribuicao as $d) {
            foreach ($meios as &$m) {
                if (($m['meio_id'] ?? null) === ($d['meio_id'] ?? null) && ($m['tipo'] ?? null) === $d['tipo']) {
                    $m['valor'] = bcsub(number_format((float) $m['valor'], 2, '.', ''), (string) $d['valor'], 2);
                    break;
                }
            }
            unset($m);
            if ($d['tipo'] === 'NUMERARIO') {
                $numerario = bcadd($numerario, (string) $d['valor'], 2);
            }
            if ($d['tipo'] === 'TRANSFERENCIA') {
                foreach ($transf as $i => $t) {
                    if ((int) $t['venda_id'] === $origem->id && ($t['meio_id'] ?? null) === ($d['meio_id'] ?? null)) {
                        $transf[$i]['valor'] = bcsub(number_format((float) $t['valor'], 2, '.', ''), (string) $d['valor'], 2);
                    }
                }
            }
        }
        $s->update(['totais_por_metodo' => $meios, 'transferencias' => array_values(array_filter($transf, fn ($t) => bccomp((string) $t['valor'], '0', 2) > 0)),
            'total_vendas' => bcsub(number_format((float) $s->total_vendas, 2, '.', ''), (string) $linha->valor, 2),
            'vendas_numerario' => bcsub(number_format((float) $s->vendas_numerario, 2, '.', ''), $numerario, 2)]);
    }

    /**
     * Abate as NC emitidas com a sessão aberta aos totais calculados (usado por ServicoSessoesPOS::totais).
     *
     * @param  array<string, array<string, mixed>>  $porMeio
     * @param  list<array<string, mixed>>  $transferencias
     * @return array{bruto: string, numerario: string}
     */
    public static function abater(int $sessaoId, array &$porMeio, array &$transferencias, string $bruto, string $numerario): array
    {
        foreach (NcSessaoPOS::query()->where('sessao_pos_id', $sessaoId)->where('abatida_em_aberta', true)->orderBy('id')->get() as $nc) {
            $bruto = bcsub($bruto, number_format((float) $nc->valor, 2, '.', ''), 2);
            foreach ($nc->distribuicao as $d) {
                $k = $d['meio_id'] ?? $d['tipo'];
                if (isset($porMeio[$k])) {
                    $porMeio[$k]['valor'] = bcsub($porMeio[$k]['valor'], (string) $d['valor'], 2);
                }
                if ($d['tipo'] === 'NUMERARIO') {
                    $numerario = bcsub($numerario, (string) $d['valor'], 2);
                }
                if ($d['tipo'] === 'TRANSFERENCIA') {
                    foreach ($transferencias as $i => $t) {
                        if ((int) $t['venda_id'] === (int) $nc->venda_origem_id && ($t['meio_id'] ?? null) === ($d['meio_id'] ?? null)) {
                            $transferencias[$i]['valor'] = bcsub($t['valor'], (string) $d['valor'], 2);
                        }
                    }
                }
            }
        }
        $transferencias = array_values(array_filter($transferencias, fn ($t) => bccomp($t['valor'], '0', 2) > 0));

        return ['bruto' => $bruto, 'numerario' => $numerario];
    }
}
