<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\LiquidacaoPOS;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use App\Services\Vendas\ServicoStockVendas;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Integração contabilística das sessões POS e deliberação de desvios (js/pos_prestacao.js:97-489), com as correcções:
 *   - um lançamento por data de venda: D contas transitórias dos meios (numerário/TPA agregados por meio, transferências
 *     uma linha por documento com o cliente) / C proveitos e IVA com os valores do próprio documento — sem rateio nem
 *     «absorver até 1 Kz» — e o CMV (D 71 / C 26) das mercadorias (o legado não lançava CMV no POS);
 *   - descontabilizar = estorno (o legado apagava as linhas do diário);
 *   - a deliberação do desvio é segregada (quem operou a sessão não delibera o próprio desvio) e anula-se por estorno.
 */
final class ServicoContabilizacaoPOS
{
    public const DECISOES = ['SOBRA_PROVEITO', 'FALTA_CUSTO', 'FALTA_OPERADOR', 'SEM_EFEITO'];

    public function __construct(
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoContabilizacaoVendas $vendas,
        private readonly ServicoStockVendas $stockVendas,
        private readonly ServicoConfigPOS $config,
    ) {}

    public function contabilizar(SessaoPOS $s): SessaoPOS
    {
        return DB::transaction(function () use ($s) {
            $s = SessaoPOS::query()->lockForUpdate()->findOrFail($s->id);
            if ($s->estado !== 'FECHADA') {
                throw new ErroNegocio('Só se integram sessões fechadas (fecho Z).', 'SESSAO_NAO_FECHADA', 422);
            }
            if ($s->estado_contabilizacao !== 'PENDENTE') {
                throw new ErroNegocio($s->estado_contabilizacao === 'SEM_MOVIMENTO' ? 'A sessão não tem vendas.' : 'A sessão já está integrada.', 'SESSAO_JA_INTEGRADA', 422);
            }
            $vendas = Venda::query()->where('sessao_pos_id', $s->id)->where('tipo_documento', 'FR')->where('estado', '<>', 'ANULADO')->orderBy('id')->lockForUpdate()->get();
            if ($vendas->isEmpty()) {
                throw new ErroNegocio('A sessão não tem vendas por integrar.', 'SEM_VENDAS', 422);
            }
            $diario = $this->diario();
            $lans = [];
            foreach ($vendas->groupBy(fn ($v) => $v->data_emissao->toDateString()) as $data => $doDia) {
                $linhas = $this->linhas($doDia);
                $numeroLan = $this->lancamentos->criar([
                    'diario_id' => $diario->id, 'data_documento' => $data, 'numero_documento' => $s->numero_z, 'tipo_origem' => 'POS', 'sessao_pos_id' => $s->id,
                    'descricao' => mb_substr("{$s->numero_z} — vendas POS {$s->codigo_terminal} ({$doDia->count()} doc.)", 0, 1000), 'linhas' => $linhas,
                ])->first()->numero_lan;
                $lans[] = $numeroLan;
                Venda::query()->whereIn('id', $doDia->pluck('id'))->update(['contabilizado' => true, 'numero_lan_contabilizacao' => $numeroLan, 'pos_lans_contabilizacao' => json_encode([$numeroLan])]);
            }
            $s->update(['estado_contabilizacao' => 'CONTABILIZADA', 'lans_contabilizacao' => $lans, 'diario_contabilizacao_id' => $diario->id,
                'contabilizado_em' => now(), 'contabilizado_por' => Auth::user()?->nome_utilizador]);
            if (($s->deliberacao['automatica'] ?? false) && empty($s->deliberacao['numero_lan'])) {
                $this->lancarDesvio($s);
            }

            return $s->refresh();
        });
    }

    public function descontabilizar(SessaoPOS $s, string $motivo): SessaoPOS
    {
        return DB::transaction(function () use ($s, $motivo) {
            $s = SessaoPOS::query()->lockForUpdate()->findOrFail($s->id);
            if ($s->estado_contabilizacao !== 'CONTABILIZADA') {
                throw new ErroNegocio('A sessão não está integrada.', 'SESSAO_NAO_INTEGRADA', 422);
            }
            if (LiquidacaoPOS::query()->where('sessao_pos_id', $s->id)->where('estado', 'REGISTADO')->exists()) {
                throw new ErroNegocio('A sessão tem prestação de contas registada: anule-a primeiro.', 'SESSAO_COM_LIQUIDACOES', 422);
            }
            $del = $s->deliberacao;
            if ($del && ! empty($del['numero_lan'])) {
                if (empty($del['automatica'])) {
                    throw new ErroNegocio('O desvio foi deliberado com lançamento: anule a deliberação primeiro.', 'DELIBERACAO_COM_LANCAMENTO', 422);
                }
                $this->lancamentos->estornar($this->localizador->localizar($del['numero_lan'], (string) $s->numero_z), $motivo);
                $del['numero_lan'] = null;
            }
            foreach ($s->lans_contabilizacao ?? [] as $lan) {
                $this->lancamentos->estornar($this->localizador->localizar($lan, (string) $s->numero_z), $motivo);
            }
            Venda::query()->where('sessao_pos_id', $s->id)->update(['contabilizado' => false, 'numero_lan_contabilizacao' => null, 'pos_lans_contabilizacao' => null]);
            $s->update(['estado_contabilizacao' => 'PENDENTE', 'lans_contabilizacao' => null, 'contabilizado_em' => null, 'contabilizado_por' => null, 'deliberacao' => $del,
                'descontabilizado_em' => now(), 'descontabilizado_por' => Auth::user()?->nome_utilizador]);

            return $s;
        });
    }

    /** Deliberação de um desvio acima da tolerância (posConfirmarDeliberacao, pos_prestacao.js:396-460). */
    public function deliberar(SessaoPOS $s, string $decisao, ?string $nota): SessaoPOS
    {
        if (! in_array($decisao, self::DECISOES, true)) {
            throw new ErroNegocio('Decisão inválida.', 'DECISAO_INVALIDA', 422);
        }

        return DB::transaction(function () use ($s, $decisao, $nota) {
            $s = SessaoPOS::query()->lockForUpdate()->findOrFail($s->id);
            if ($s->estado_desvio !== 'PENDENTE') {
                throw new ErroNegocio('A sessão não tem desvio por deliberar.', 'SEM_DESVIO_PENDENTE', 422);
            }
            if ($decisao !== 'SEM_EFEITO' && ! in_array($s->estado_contabilizacao, ['CONTABILIZADA', 'SEM_MOVIMENTO'], true)) {
                throw new ErroNegocio('Integre primeiro a sessão na contabilidade.', 'SESSAO_NAO_INTEGRADA', 422);
            }
            $sobra = bccomp((string) $s->desvio, '0', 2) > 0;
            if (($decisao === 'SOBRA_PROVEITO') !== $sobra && $decisao !== 'SEM_EFEITO') {
                throw new ErroNegocio($sobra ? 'O desvio é uma sobra: a decisão tem de ser «sobra (proveito)» ou «sem efeito».'
                    : 'O desvio é uma falta: a decisão tem de ser «falta (custo)», «falta (operador)» ou «sem efeito».', 'DECISAO_INVALIDA', 422);
            }
            $u = Auth::user();
            if ($u && $s->operador_id && (int) $s->operador_id === (int) $u->id) {   // o legado só avisava (permissoes.js:526)
                throw new ErroNegocio('Segregação de funções: quem operou a sessão não delibera o próprio desvio.', 'SEGREGACAO_FUNCOES', 403);
            }
            $s->update(['estado_desvio' => 'DELIBERADO', 'deliberacao' => ['decisao' => $decisao, 'automatica' => false, 'valor' => (string) $s->desvio, 'nota' => $nota,
                'data' => now()->toDateString(), 'por' => $u?->nome_utilizador, 'em' => now()->toIso8601String()]]);
            $this->lancarDesvio($s);

            return $s->refresh();
        });
    }

    /** Anula uma deliberação manual por estorno (posAnularDeliberacao, pos_prestacao.js:470-489); bloqueada se o numerário já foi prestado. */
    public function anularDeliberacao(SessaoPOS $s, string $motivo): SessaoPOS
    {
        return DB::transaction(function () use ($s, $motivo) {
            $s = SessaoPOS::query()->lockForUpdate()->findOrFail($s->id);
            $del = $s->deliberacao;
            if ($s->estado_desvio !== 'DELIBERADO' || ! $del) {
                throw new ErroNegocio('A sessão não tem deliberação.', 'SEM_DELIBERACAO', 422);
            }
            if (! empty($del['automatica'])) {
                throw new ErroNegocio('Desvio dentro da tolerância: a regularização é automática.', 'DELIBERACAO_AUTOMATICA', 422);
            }
            if (LiquidacaoPOS::query()->where('sessao_pos_id', $s->id)->where('estado', 'REGISTADO')->where('natureza_registo', 'NUMERARIO')->exists()) {
                throw new ErroNegocio('O numerário da sessão já foi prestado: anule primeiro essa liquidação.', 'NUMERARIO_PRESTADO', 422);
            }
            if (! empty($del['numero_lan'])) {
                $this->lancamentos->estornar($this->localizador->localizar($del['numero_lan'], (string) $s->numero_z), $motivo);
            }
            $s->update(['estado_desvio' => 'PENDENTE', 'deliberacao' => null,
                'deliberacao_cancelada' => $del + ['anulado_por' => Auth::user()?->nome_utilizador, 'anulado_em' => now()->toIso8601String(), 'motivo' => $motivo]]);

            return $s;
        });
    }

    /** Lançamento do desvio deliberado: sobra D transitória / C sobras; falta D quebras (ou operador) / C transitória. */
    public function lancarDesvio(SessaoPOS $s): void
    {
        $del = $s->deliberacao;
        if (! $del || $del['decisao'] === 'SEM_EFEITO' || ! empty($del['numero_lan'])) {
            return;
        }
        $valor = ltrim(number_format((float) $s->desvio, 2, '.', ''), '-');
        if (bccomp($valor, '0', 2) === 0) {
            return;
        }
        $transitoria = $this->transitoriaNumerario($s);
        $conta = $this->config->exigirConta(match ($del['decisao']) {
            'SOBRA_PROVEITO' => 'conta_sobra', 'FALTA_CUSTO' => 'conta_quebra', 'FALTA_OPERADOR' => 'conta_operador'
        });
        [$d, $c] = $del['decisao'] === 'SOBRA_PROVEITO' ? [$transitoria, $conta] : [$conta, $transitoria];
        $data = ! empty($del['automatica']) ? $s->fechado_em->toDateString() : now()->toDateString();
        $diario = $this->diario();
        $numeroLan = $this->lancamentos->criar([
            'diario_id' => $diario->id, 'data_documento' => $data, 'numero_documento' => $s->numero_z, 'tipo_origem' => 'POS_DESVIO', 'sessao_pos_id' => $s->id,
            'descricao' => mb_substr("{$s->numero_z} — desvio de caixa ({$del['decisao']})".($del['nota'] ? ": {$del['nota']}" : ''), 0, 1000),
            'linhas' => [['codigo_conta' => $d, 'tipo_dc' => 'D', 'valor' => $valor], ['codigo_conta' => $c, 'tipo_dc' => 'C', 'valor' => $valor]],
        ])->first()->numero_lan;
        $s->update(['deliberacao' => array_merge($del, ['numero_lan' => $numeroLan, 'conta' => $conta, 'conta_transitoria' => $transitoria, 'diario_id' => $diario->id])]);
    }

    /** Linhas do lançamento de um dia de vendas da sessão. */
    private function linhas($vendas): array
    {
        $debitos = $creditos = [];
        foreach ($vendas as $v) {
            $pagos = '0.00';
            foreach ($v->pos_pagamentos ?? [] as $p) {
                $conta = $p['conta_transitoria'] ?? null;
                if (! $conta) {
                    throw new ErroNegocio("{$v->numero_documento}: pagamento por {$p['nome']} sem conta transitória.", 'PAGAMENTO_SEM_CONTA', 422);
                }
                $valor = number_format((float) $p['valor'], 2, '.', '');
                $pagos = bcadd($pagos, $valor, 2);
                $trf = $p['tipo'] === 'TRANSFERENCIA';
                $k = $conta.($trf ? "|{$v->id}" : '');
                $debitos[$k] ??= ['codigo_conta' => $conta, 'tipo_dc' => 'D', 'valor' => '0.00']
                    + ($trf ? ['terceiro_id' => $v->cliente_id, 'numero_documento' => $v->numero_documento, 'descricao' => "Transferência {$p['referencia']} — {$v->numero_documento}"] : []);
                $debitos[$k]['valor'] = bcadd($debitos[$k]['valor'], $valor, 2);
            }
            if (bccomp($pagos, (string) $v->total_bruto, 2) !== 0) {
                throw new ErroNegocio("{$v->numero_documento}: os pagamentos ({$pagos}) não somam o total ({$v->total_bruto}).", 'PAGAMENTOS_INCONSISTENTES', 422,
                    ['venda_id' => $v->id]);
            }
            $dim = ['unidade_negocio_id' => $v->unidade_negocio_id, 'centro_custo_id' => $v->centro_custo_id];
            foreach ($this->vendas->linhasProveitoEIva($v) as $c) {
                $k = "{$c['conta']}|C|".implode('|', $dim);
                $creditos[$k] ??= ['codigo_conta' => $c['conta'], 'tipo_dc' => 'C', 'valor' => '0.00'] + $dim;
                $creditos[$k]['valor'] = bcadd($creditos[$k]['valor'], $c['valor'], 2);
            }
            foreach ($this->stockVendas->linhasCmv($v) as $c) {
                $k = "{$c['codigo_conta']}|{$c['tipo_dc']}|".implode('|', $dim);
                $creditos[$k] ??= ['codigo_conta' => $c['codigo_conta'], 'tipo_dc' => $c['tipo_dc'], 'valor' => '0.00'] + $dim;
                $creditos[$k]['valor'] = bcadd($creditos[$k]['valor'], $c['valor'], 2);
            }
        }

        return array_merge(array_values($debitos), array_values($creditos));
    }

    private function transitoriaNumerario(SessaoPOS $s): string
    {
        $m = collect($s->totais_por_metodo ?? [])->firstWhere('tipo', 'NUMERARIO')
            ?? collect(TerminalPOS::query()->withTrashed()->find($s->terminal_pos_id)?->meios_pagamento ?? [])->first(fn ($m) => $m['tipo'] === 'NUMERARIO' && ! empty($m['ativo']));

        return $m['conta_transitoria'] ?? throw new ErroNegocio('O terminal não tem meio de numerário com conta transitória.', 'SEM_NUMERARIO', 422);
    }

    private function diario()
    {
        return $this->localizador->diario($this->config->obter()['codigo_diario'], 'Vendas POS');
    }
}
