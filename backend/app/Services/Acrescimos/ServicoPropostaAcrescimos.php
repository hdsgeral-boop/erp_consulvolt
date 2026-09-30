<?php

namespace App\Services\Acrescimos;

use App\Exceptions\ErroNegocio;
use App\Models\ItemAcrescimoDiferimento;
use App\Models\LancamentoContabil;
use App\Models\PeriodoLancamentoAcrescimo;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Proposta mensal, contabilização em lote e descontabilização (ad_postings; proposta, contabilizar, actualizarEstado,
 * descontabilizar e reconciliacao, ad_dados.js:246-380). Paridade:
 *   - a proposta do mês M reúne, de todos os registos em aberto, as linhas por contabilizar até M (atrasadas incluídas);
 *     data do lançamento = a do período (inicial: data do documento; reconhecimento: fim do mês; regularização/término:
 *     data do pedido), limitada ao fim de M e passada para o fim de M se o exercício estiver encerrado;
 *   - um lançamento no Diário por linha (N.º do documento AD<id>-<AAAAMM>-<TIP>, referência «Acréscimos e diferimentos
 *     #<id>», com terceiro, unidade de negócio, centro de custo e projecto do registo), para se poder descontabilizar cada um;
 *     regularização/término de valor zero ficam registados sem lançamento; os erros de uma linha não impedem as outras;
 *   - estado final do registo recalculado (REGULARIZADO, ANULADO, CONCLUIDO…); alertas de acréscimos sem documento
 *     depois da data limite;
 *   - descontabilizar sem deixar buracos (reconhecimentos posteriores, regularizações e términos primeiro).
 * Correcções: descontabilizar = estorno (ADR-016; o legado apagava as linhas do Diário); cada linha é contabilizada numa
 * transacção com lock do registo e verificação de duplicado (o legado podia duplicar com dois utilizadores em simultâneo);
 * as linhas do Diário ficam ligadas ao registo (`item_acrescimo_diferimento_id`) e com `tipo_origem` ACRESCIMOS.
 */
final class ServicoPropostaAcrescimos
{
    public const ORIGEM = 'ACRESCIMOS';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoItensAcrescimos $itens,
        private readonly ServicoDefinicoesAcrescimos $definicoes,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoExercicios $exercicios,
    ) {}

    /** @return array{mes: string, linhas: list<array<string, mixed>>, alertas: list<array<string, mixed>>, total: string} */
    public function proposta(string $mes): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            throw new ErroNegocio('Escolha o mês (AAAA-MM).', 'MES_INVALIDO', 422);
        }
        $empresa = $this->contexto->obrigatorio();
        $fimAlvo = CalculadoraAcrescimos::fimMes($mes);
        $abertos = ItemAcrescimoDiferimento::query()->whereNotIn('estado', CalculadoraAcrescimos::FECHADOS)->orderBy('id')->get();
        $linhas = [];
        foreach ($abertos as $item) {
            $it = $this->itens->paraCalculo($item);
            foreach (CalculadoraAcrescimos::pendentes($it, $mes, $this->itens->feitos($item)) as $l) {
                $data = ! empty($l['data_preferida']) && CalculadoraAcrescimos::mes($l['data_preferida']) === $l['periodo'] ? $l['data_preferida'] : CalculadoraAcrescimos::fimMes($l['periodo']);
                if ($data > $fimAlvo) {
                    $data = $fimAlvo;
                }
                if ($this->exercicios->encerrado($empresa, (int) substr($data, 0, 4))) {
                    $data = $fimAlvo;
                }
                unset($l['data_preferida']);
                $linhas[] = $l + CalculadoraAcrescimos::contasLinha($it, $l['tipo']) + [
                    'chave' => "{$item->id}|{$l['tipo']}|{$l['periodo']}", 'data' => $data, 'atrasada' => $l['periodo'] < $mes,
                    'tipo_rotulo' => CalculadoraAcrescimos::TIPOS_LINHA[$l['tipo']],
                    'item' => $item->only(['id', 'tipo', 'natureza', 'descricao', 'valor', 'estado', 'terceiro_id', 'conta_resultado', 'conta_balanco']),
                ];
            }
        }
        usort($linhas, fn ($a, $b) => [$a['periodo'], $a['item']['id']] <=> [$b['periodo'], $b['item']['id']]);
        // acréscimos sem documento real depois da data limite
        $hoje = now()->toDateString();
        $ref = min($fimAlvo, $hoje);
        $alertas = $abertos->filter(fn ($i) => $i->tipo === 'ACRESCIMO' && $i->estado === 'ACTIVO' && $i->data_limite && $i->data_limite->toDateString() < $ref)
            ->map(fn ($i) => $i->only(['id', 'descricao', 'valor', 'terceiro_id']) + ['data_limite' => $i->data_limite->toDateString()])->values()->all();

        return ['mes' => $mes, 'linhas' => $linhas, 'alertas' => $alertas,
            'total' => array_reduce($linhas, fn ($s, $l) => bcadd($s, $l['valor'], 2), '0.00')];
    }

    /**
     * Contabiliza as linhas escolhidas da proposta (recalculada no momento, para evitar duplicados).
     *
     * @param  list<string>  $chaves
     * @return array{ok: list<array<string, mixed>>, erros: list<array{chave: string, mensagem: string}>}
     */
    public function contabilizar(string $mes, array $chaves): array
    {
        $diario = $this->definicoes->exigirDiario();
        $escolhidas = array_values(array_filter($this->proposta($mes)['linhas'], fn ($l) => in_array($l['chave'], $chaves, true)));
        if (! $escolhidas) {
            throw new ErroNegocio('Nenhuma das linhas escolhidas está por contabilizar neste mês.', 'SEM_LINHAS', 422);
        }
        $ok = $erros = [];
        foreach ($escolhidas as $l) {
            try {
                $ok[] = DB::transaction(fn () => $this->contabilizarLinha($l, $diario->id));
            } catch (ErroNegocio $e) {
                $erros[] = ['chave' => $l['chave'], 'mensagem' => "{$l['item']['descricao']} ({$l['tipo_rotulo']} {$l['periodo']}): {$e->getMessage()}", 'codigo' => $e->codigo];
            } catch (Throwable $e) {
                report($e);
                $erros[] = ['chave' => $l['chave'], 'mensagem' => "{$l['item']['descricao']} ({$l['tipo_rotulo']} {$l['periodo']}): erro inesperado.", 'codigo' => 'ERRO'];
            }
        }

        return ['ok' => $ok, 'erros' => $erros];
    }

    private function contabilizarLinha(array $l, int $diarioId): array
    {
        $item = ItemAcrescimoDiferimento::query()->lockForUpdate()->findOrFail($l['item']['id']);
        $duplicado = PeriodoLancamentoAcrescimo::query()->where('item_acrescimo_diferimento_id', $item->id)->where('estado', 'CONTABILIZADO')
            ->where('tipo', $l['tipo'])->when($l['tipo'] === 'RECONHECIMENTO', fn ($q) => $q->where('periodo', $l['periodo']))->exists();
        if ($duplicado || in_array($item->estado, CalculadoraAcrescimos::FECHADOS, true)) {
            throw new ErroNegocio('a linha já foi contabilizada entretanto.', 'JA_CONTABILIZADO', 422);
        }
        $this->exercicios->exigirAberto($this->contexto->obrigatorio(), $l['data']);
        $reg = ['empresa_id' => $item->empresa_id, 'item_acrescimo_diferimento_id' => $item->id, 'periodo' => $l['periodo'], 'tipo' => $l['tipo'],
            'valor' => $l['valor'], 'data_documento' => $l['data'], 'estado' => 'CONTABILIZADO', 'por' => Auth::user()?->nome_utilizador, 'em' => now(),
            'diferenca' => $l['diferenca'] ?? null];
        if (bccomp($l['valor'], '0', 2) > 0) {
            foreach ([[$l['debito'], 'débito'], [$l['credito'], 'crédito']] as [$c, $rot]) {
                if ($e = $this->definicoes->erroConta($c, "Conta a {$rot}")) {
                    throw new ErroNegocio($e, 'CONTA_INVALIDA', 422);
                }
            }
            $numeroDocumento = "AD{$item->id}-".str_replace('-', '', $l['periodo']).'-'.substr($l['tipo'], 0, 3);
            $dim = ['terceiro_id' => $item->terceiro_id, 'unidade_negocio_id' => $item->unidade_negocio_id, 'centro_custo_id' => $item->centro_custo_id, 'projeto_id' => $item->projeto_id];
            $criadas = $this->lancamentos->criar([
                'diario_id' => $diarioId, 'data_documento' => $l['data'], 'numero_documento' => $numeroDocumento, 'referencia' => "Acréscimos e diferimentos #{$item->id}",
                'descricao' => mb_substr(CalculadoraAcrescimos::TIPOS_LINHA[$l['tipo']]." {$l['periodo']} — {$item->descricao}", 0, 1000), 'tipo_origem' => self::ORIGEM,
                'linhas' => [['codigo_conta' => $l['debito'], 'tipo_dc' => 'D', 'valor' => $l['valor']] + $dim, ['codigo_conta' => $l['credito'], 'tipo_dc' => 'C', 'valor' => $l['valor']] + $dim],
            ]);
            LancamentoContabil::query()->whereIn('id', $criadas->pluck('id'))->update(['item_acrescimo_diferimento_id' => $item->id]);
            $reg += ['diario_id' => $diarioId, 'numero_lan' => $criadas->first()->numero_lan, 'numero_documento' => $numeroDocumento];
        }
        $p = PeriodoLancamentoAcrescimo::create($reg);
        $this->actualizarEstado($item);

        return $p->toArray() + ['chave' => $l['chave']];
    }

    /** Descontabiliza um lançamento do módulo por estorno, sem deixar buracos no plano. */
    public function descontabilizar(PeriodoLancamentoAcrescimo $p, string $motivo): PeriodoLancamentoAcrescimo
    {
        return DB::transaction(function () use ($p, $motivo) {
            $item = ItemAcrescimoDiferimento::query()->lockForUpdate()->findOrFail($p->item_acrescimo_diferimento_id);
            $p = PeriodoLancamentoAcrescimo::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->estado !== 'CONTABILIZADO') {
                throw new ErroNegocio('Lançamento não encontrado ou já anulado.', 'JA_ANULADO', 422);
            }
            $dependentes = $this->itens->contabilizados($item)->filter(fn ($o) => $o->id !== $p->id && (
                ($p->tipo === 'INICIAL' && $o->tipo !== 'INICIAL')
                || ($p->tipo === 'RECONHECIMENTO' && (in_array($o->tipo, ['REGULARIZACAO', 'ANULACAO', 'TERMINO'], true) || ($o->tipo === 'RECONHECIMENTO' && $o->periodo > $p->periodo)))));
            if ($dependentes->isNotEmpty()) {
                throw new ErroNegocio('Descontabilize primeiro: '.$dependentes->map(fn ($o) => CalculadoraAcrescimos::TIPOS_LINHA[$o->tipo]." {$o->periodo}")->implode(', ').'.',
                    'LANCAMENTOS_DEPENDENTES', 422, ['ids' => $dependentes->pluck('id')->values()->all()]);
            }
            if ($p->numero_lan) {
                $this->lancamentos->estornar($this->localizador->localizar($p->numero_lan, (string) $p->numero_documento), $motivo);
            }
            $p->update(['estado' => 'ANULADO', 'anulado_por' => Auth::user()?->nome_utilizador, 'anulado_em' => now()]);
            $this->actualizarEstado($item);

            return $p->refresh();
        });
    }

    /** Estado do registo a partir dos lançamentos contabilizados (actualizarEstado, ad_dados.js:314-329). */
    public function actualizarEstado(ItemAcrescimoDiferimento $item): void
    {
        $ps = $this->itens->contabilizados($item);
        $estado = 'ACTIVO';
        if ($ps->contains('tipo', 'ANULACAO')) {
            $estado = 'ANULADO';
        } elseif ($ps->contains('tipo', 'REGULARIZACAO')) {
            $estado = 'REGULARIZADO';
        } elseif ($ps->contains('tipo', 'TERMINO')) {
            $estado = 'CONCLUIDO';
        } elseif ($item->regularizacao) {
            $estado = 'A_REGULARIZAR';
        } elseif ($item->termino) {
            $estado = 'A_TERMINAR';
        } elseif ($item->tipo === 'DIFERIMENTO') {
            $it = $this->itens->paraCalculo($item);
            $q = CalculadoraAcrescimos::quotas($it['valor'], $it['data_inicio'], $it['data_fim'], $it['reparticao']);
            $inicial = $item->documento_em_balanco || $ps->contains('tipo', 'INICIAL');
            if ($inicial && $q && collect($q)->every(fn ($x) => $ps->contains(fn ($p) => $p->tipo === 'RECONHECIMENTO' && $p->periodo === $x['periodo']))) {
                $estado = 'CONCLUIDO';
            }
        }
        if ($estado !== $item->estado) {
            $item->update(['estado' => $estado]);
        }
    }

    /**
     * Reconciliação: saldo das contas de balanço segundo o módulo × Diário, até à data.
     *
     * @return list<array{conta: string, modulo: string, diario: string, diferenca: string}>
     */
    public function reconciliacao(?string $data): array
    {
        $porConta = [];
        foreach (ItemAcrescimoDiferimento::query()->orderBy('id')->get() as $item) {
            $feitos = PeriodoLancamentoAcrescimo::query()->where('item_acrescimo_diferimento_id', $item->id)->where('estado', 'CONTABILIZADO')
                ->when($data, fn ($q) => $q->where('data_documento', '<=', $data))->get()
                ->map(fn ($p) => ['tipo' => $p->tipo, 'periodo' => $p->periodo, 'valor' => (string) $p->valor])->all();
            $porConta[$item->conta_balanco] = bcadd($porConta[$item->conta_balanco] ?? '0.00', CalculadoraAcrescimos::saldoBalanco($this->itens->paraCalculo($item), $feitos), 2);
        }
        foreach ($this->definicoes->obter()['contas'] as $c) {
            $porConta[$c] ??= '0.00';
        }
        $diario = LancamentoContabil::query()->whereIn('codigo_conta', array_map('strval', array_keys($porConta)))
            ->when($data, fn ($q) => $q->where('data_documento', '<=', $data))
            ->selectRaw("codigo_conta, SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END) AS saldo")->groupBy('codigo_conta')->pluck('saldo', 'codigo_conta');
        $r = [];
        foreach ($porConta as $conta => $modulo) {
            $d = CalculadoraAcrescimos::dinheiro((string) ($diario[(string) $conta] ?? '0'));
            $r[] = ['conta' => (string) $conta, 'modulo' => $modulo, 'diario' => $d, 'diferenca' => bcsub($d, $modulo, 2)];
        }
        usort($r, fn ($a, $b) => strnatcmp($a['conta'], $b['conta']));

        return $r;
    }
}
