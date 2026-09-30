<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sessões de caixa do POS (abertura, relatório X, fecho Z — js/pos_gestao.js:147-674), com as correcções:
 *   - uma sessão aberta por terminal garantida pela base (índice único parcial) e por lock; o legado lia e depois inseria;
 *   - códigos de sessão e de Z por ServicoNumeracao (o legado podia repetir números, ver ServicoTerminaisPOS);
 *   - o operador de cada venda é quem a regista (o legado gravava sempre quem abriu a sessão);
 *   - desvio dentro da tolerância e diferente de zero: fica DELIBERADO automaticamente (sobra/quebra) e é lançado com a
 *     integração da sessão — no legado passava a «sem desvio» e a diferença física nunca era contabilizada.
 */
final class ServicoSessoesPOS
{
    /** Notas e moedas em Kz para a contagem (showPOSCloseRegisterModal, pos_gestao.js:513). */
    public const DENOMINACOES = [5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
        private readonly ServicoConfigPOS $config,
        private readonly ServicoContabilizacaoPOS $contabilizacao,
        private readonly ServicoFontesLavandariaPOS $lavandaria,
    ) {}

    /** @return array{sessao: SessaoPOS, aviso: ?string} */
    public function abrir(TerminalPOS $t, ?string $fundo = null): array
    {
        $empresa = $this->contexto->obrigatorio();
        if (! $t->ativo) {
            throw new ErroNegocio('O terminal está inactivo.', 'TERMINAL_INATIVO', 422);
        }
        if (! collect($t->meios_pagamento ?? [])->first(fn ($m) => ! empty($m['ativo']) && ! empty($m['conta_transitoria']))) {
            throw new ErroNegocio('Configure pelo menos um meio de pagamento activo com conta transitória no terminal.', 'TERMINAL_SEM_MEIOS', 422);
        }
        $fundo = number_format((float) ($fundo ?? $t->fundo_maneio_padrao ?? 0), 2, '.', '');
        if (bccomp($fundo, '0', 2) < 0) {
            throw new ErroNegocio('O fundo de maneio não pode ser negativo.', 'FUNDO_INVALIDO', 422);
        }

        return DB::transaction(function () use ($t, $fundo, $empresa) {
            TerminalPOS::query()->lockForUpdate()->findOrFail($t->id);
            if (SessaoPOS::query()->where('terminal_pos_id', $t->id)->where('estado', 'ABERTA')->exists()) {
                throw new ErroNegocio("O terminal {$t->codigo} já tem uma sessão aberta.", 'SESSAO_JA_ABERTA', 422);
            }
            $ano = (int) now()->format('Y');
            $n = $this->numeracao->proximo($empresa, "pos_sessao:{$t->id}:{$ano}", fn () => $this->maiorNumero($t, 'codigo_sessao', "{$t->codigo}-{$ano}-", $t->contadores_sessao, $ano));
            $u = Auth::user();
            $sessao = SessaoPOS::create([
                'terminal_pos_id' => $t->id, 'codigo_terminal' => $t->codigo, 'nome_terminal' => $t->nome, 'codigo_sessao' => sprintf('%s-%d-%04d', $t->codigo, $ano, $n),
                'estado' => 'ABERTA', 'aberto_em' => now(), 'fundo_maneio_abertura' => $fundo, 'operador_id' => $u?->id, 'nome_operador' => $u?->nome_utilizador,
                'estado_contabilizacao' => 'PENDENTE', 'estado_liquidacao' => 'PENDENTE', 'estado_desvio' => 'NAO_APLICAVEL',
            ]);
            // sessões antigas por fechar noutro terminal do mesmo operador (aviso, como o legado em pos_gestao.js:147)
            $antigas = SessaoPOS::query()->where('estado', 'ABERTA')->where('operador_id', $u?->id)->whereKeyNot($sessao->id)
                ->where('aberto_em', '<', now()->startOfDay())->pluck('codigo_sessao')->all();

            return ['sessao' => $sessao, 'aviso' => $antigas ? 'Tem sessões abertas de dias anteriores por fechar: '.implode(', ', $antigas).'.' : null];
        });
    }

    /**
     * Totais da sessão a partir das vendas (totaisSessao, pos_gestao.js:230-251) e dos recibos da lavandaria (fonteSessao,
     * lavandaria.js:2369-2377): os recibos entram nos totais por meio, no numerário e nas transferências, mas não em
     * total_vendas (só documentos); o resumo da lavandaria fica em `lavandaria` (não é gravado na sessão).
     *
     * @return array{numero_vendas: int, total_vendas: string, totais_por_metodo: list<array<string, mixed>>, transferencias: list<array<string, mixed>>,
     *               vendas_numerario: string, numerario_esperado: string, lavandaria: array<string, mixed>}
     */
    public function totais(SessaoPOS $s): array
    {
        $porMeio = $transferencias = [];
        $bruto = $numerario = '0.00';
        $vendas = Venda::query()->where('sessao_pos_id', $s->id)->where('tipo_documento', 'FR')->where('estado', '<>', 'ANULADO')->orderBy('id')->get();
        foreach ($vendas as $v) {
            $bruto = bcadd($bruto, (string) $v->total_bruto, 2);
            foreach ($v->pos_pagamentos ?? [] as $p) {
                $valor = number_format((float) ($p['valor'] ?? 0), 2, '.', '');
                $k = $p['meio_id'] ?? $p['tipo'];
                $porMeio[$k] ??= ['meio_id' => $p['meio_id'] ?? null, 'tipo' => $p['tipo'], 'nome' => $p['nome'] ?? $p['tipo'], 'conta_transitoria' => $p['conta_transitoria'] ?? null,
                    'codigo_tpa' => $p['codigo_tpa'] ?? null, 'valor' => '0.00', 'quantidade' => 0];
                $porMeio[$k]['valor'] = bcadd($porMeio[$k]['valor'], $valor, 2);
                $porMeio[$k]['quantidade']++;
                if ($p['tipo'] === 'NUMERARIO') {
                    $numerario = bcadd($numerario, $valor, 2);
                }
                if ($p['tipo'] === 'TRANSFERENCIA') {
                    $transferencias[] = ['venda_id' => $v->id, 'numero_documento' => $v->numero_documento, 'valor' => $valor, 'referencia' => $p['referencia'] ?? null,
                        'meio_id' => $p['meio_id'] ?? null, 'terceiro_id' => $v->cliente_id];
                }
            }
        }
        $lavandaria = $this->lavandaria->acumular($s, $porMeio, $transferencias, $numerario);

        return ['numero_vendas' => $vendas->count(), 'total_vendas' => $bruto, 'totais_por_metodo' => array_values($porMeio), 'transferencias' => $transferencias,
            'vendas_numerario' => $numerario, 'numerario_esperado' => bcadd((string) $s->fundo_maneio_abertura, $numerario, 2), 'lavandaria' => $lavandaria];
    }

    /** Relatório X: totais da sessão aberta, sem fechar (só impressão no legado). */
    public function relatorioX(SessaoPOS $s): array
    {
        return ['sessao' => $s->only(['id', 'codigo_sessao', 'codigo_terminal', 'nome_terminal', 'estado', 'aberto_em', 'fundo_maneio_abertura', 'nome_operador'])]
            + $this->totais($s) + ['emitido_em' => now()->toIso8601String()];
    }

    /**
     * Fecho Z (posConfirmarFecho, pos_gestao.js:560-674).
     *
     * @param  array{contagens?: array<string, int|float>, numerario_contado?: float|string, fechos_tpa?: list<array<string, mixed>>, justificacao?: ?string}  $d
     */
    public function fechar(SessaoPOS $s, array $d): SessaoPOS
    {
        $empresa = $this->contexto->obrigatorio();
        $config = $this->config->obter();

        return DB::transaction(function () use ($s, $d, $empresa, $config) {
            $s = SessaoPOS::query()->lockForUpdate()->findOrFail($s->id);
            if ($s->estado !== 'ABERTA') {
                throw new ErroNegocio('A sessão já está fechada.', 'SESSAO_NAO_ABERTA', 422);
            }
            $t = TerminalPOS::query()->withTrashed()->findOrFail($s->terminal_pos_id);
            $tot = $this->totais($s);
            $lavandaria = $tot['lavandaria'];   // resumo calculado, não é coluna da sessão
            unset($tot['lavandaria']);

            $contagens = [];
            foreach ($d['contagens'] ?? [] as $den => $qtd) {
                if (! in_array((int) $den, self::DENOMINACOES, true) || (float) $qtd < 0 || floor((float) $qtd) != (float) $qtd) {
                    throw new ErroNegocio("Contagem inválida ({$den} × {$qtd}).", 'CONTAGEM_INVALIDA', 422);
                }
                if ((int) $qtd > 0) {
                    $contagens[(string) (int) $den] = (int) $qtd;
                }
            }
            if (! isset($d['contagens']) && ! isset($d['numerario_contado'])) {
                throw new ErroNegocio('Indique a contagem do numerário (por notas e moedas ou o total).', 'CONTAGEM_EM_FALTA', 422);
            }
            $contado = isset($d['contagens'])
                ? number_format(array_sum(array_map(fn ($den, $q) => $den * $q, array_keys($contagens), $contagens)), 2, '.', '')
                : number_format((float) $d['numerario_contado'], 2, '.', '');
            if (bccomp($contado, '0', 2) < 0) {
                throw new ErroNegocio('O numerário contado não pode ser negativo.', 'CONTAGEM_INVALIDA', 422);
            }
            $desvio = bcsub($contado, $tot['numerario_esperado'], 2);

            // TPA: talão de fecho por terminal de pagamento com movimento
            $fechos = [];
            $indicados = collect($d['fechos_tpa'] ?? [])->keyBy('meio_id');
            foreach (array_filter($tot['totais_por_metodo'], fn ($m) => $m['tipo'] === 'TPA') as $m) {
                $f = $indicados[$m['meio_id']] ?? throw new ErroNegocio("Indique o talão de fecho do TPA «{$m['nome']}».", 'FECHO_TPA_EM_FALTA', 422, ['meio_id' => $m['meio_id']]);
                $talao = number_format((float) ($f['valor_talao'] ?? 0), 2, '.', '');
                $fechos[] = ['meio_id' => $m['meio_id'], 'nome' => $m['nome'], 'codigo_tpa' => $m['codigo_tpa'], 'valor_sistema' => $m['valor'], 'operacoes_sistema' => $m['quantidade'],
                    'valor_talao' => $talao, 'operacoes_talao' => (int) ($f['operacoes_talao'] ?? 0), 'referencia_lote' => $f['referencia_lote'] ?? null,
                    'diferenca' => bcsub($talao, $m['valor'], 2)];
            }
            $tolerancia = $config['tolerancia_desvio'];
            $acima = bccomp(ltrim($desvio, '-'), $tolerancia, 2) > 0;
            $tpaDifere = (bool) array_filter($fechos, fn ($f) => bccomp($f['diferenca'], '0', 2) !== 0);
            $justificacao = trim((string) ($d['justificacao'] ?? '')) ?: null;
            if (($acima || $tpaDifere) && ! $justificacao) {
                throw new ErroNegocio($acima ? "O desvio de caixa ({$desvio}) excede a tolerância ({$tolerancia}): justifique." : 'O talão do TPA difere do sistema: justifique.',
                    'JUSTIFICACAO_OBRIGATORIA', 422, ['desvio' => $desvio, 'tolerancia' => $tolerancia]);
            }

            $ano = (int) now()->format('Y');
            $n = $this->numeracao->proximo($empresa, "pos_z:{$t->id}:{$ano}", fn () => $this->maiorNumero($t, 'numero_z', "Z-{$t->codigo}-{$ano}-", $t->contadores_z, $ano));
            $semMovimento = $tot['numero_vendas'] === 0 && ! $lavandaria['movimento'];
            $zero = bccomp($desvio, '0', 2) === 0;
            $s->update($tot + [
                'estado' => 'FECHADA', 'fechado_em' => now(), 'fechado_por' => Auth::user()?->nome_utilizador, 'numero_z' => sprintf('Z-%s-%d-%04d', $t->codigo, $ano, $n),
                'numerario_contado' => $contado, 'contagens_numerario' => $contagens ?: null, 'desvio' => $desvio, 'fechos_tpa' => $fechos, 'justificacao' => $justificacao,
                'estado_contabilizacao' => $semMovimento ? 'SEM_MOVIMENTO' : 'PENDENTE', 'estado_liquidacao' => $semMovimento && $zero ? 'SEM_MOVIMENTO' : 'PENDENTE',
                'estado_desvio' => $zero ? 'SEM_DESVIO' : ($acima ? 'PENDENTE' : 'DELIBERADO'),
                'deliberacao' => $zero || $acima ? null : ['decisao' => bccomp($desvio, '0', 2) > 0 ? 'SOBRA_PROVEITO' : 'FALTA_CUSTO', 'automatica' => true, 'valor' => $desvio,
                    'nota' => "Dentro da tolerância ({$tolerancia})", 'data' => now()->toDateString(), 'por' => Auth::user()?->nome_utilizador, 'em' => now()->toIso8601String()],
            ]);
            if ($semMovimento && ! $zero && ! $acima) {   // sem integração de vendas: o desvio automático é lançado já
                $this->contabilizacao->lancarDesvio($s);
            }

            return $s->refresh();
        });
    }

    /** Maior n.º já usado: contador do legado (JSON por ano) ou o sufixo dos códigos existentes. */
    private function maiorNumero(TerminalPOS $t, string $coluna, string $prefixo, ?array $contadores, int $ano): int
    {
        $existente = (int) SessaoPOS::query()->where('terminal_pos_id', $t->id)->where($coluna, 'like', $prefixo.'%')
            ->selectRaw("MAX(NULLIF(regexp_replace(substring({$coluna} from ?), '[^0-9]', '', 'g'), '')::int) AS n", [strlen($prefixo) + 1])->value('n');

        return max($existente, (int) ($contadores[(string) $ano] ?? 0));
    }
}
