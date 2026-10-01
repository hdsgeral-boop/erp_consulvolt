<?php

namespace App\Services\Consolidacao;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\ExecucaoConsolidacao;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoCambios;
use App\Support\Cache\ChaveCache;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rotina de consolidação e Mapa de Consolidação (js/consolidacao.js:464-743 e 1113-1186).
 *
 * Execução (paridade):
 *   1. lê as linhas das empresas do grupo até à data de fim (100%, linha a linha);
 *   2. numa moeda diferente de AOA valida primeiro os câmbios de todas as datas (o último câmbio até cada data, ServicoCambios);
 *   3. une na holding o plano de contas, os diários, as notas DEMO/fluxo, as UN e os CC (por código) e os terceiros (pelo NIF ou,
 *      sem NIF válido, pelo nome), sem as contas associadas aos terceiros;
 *   4. copia as linhas (tipo AGREGACAO, N.º "E<empresa>-<LAN>", origem e valor em Kz guardados); noutra moeda cada linha é
 *      convertida ao câmbio da sua data;
 *   5. eliminações intragrupo: num lançamento com um terceiro cujo NIF é o de outra empresa do grupo, anula as linhas desse
 *      terceiro e as linhas sem terceiro, excepto as contas excluídas (por omissão 4 e 34); a diferença entre os dois lados vai à
 *      conta de diferenças (5.9.8) e o mapa de divergências compara, por par de empresas, saldos recíprocos e proveitos × custos;
 *   6. noutra moeda ajusta as contas de balanço (classes 1-4) ao câmbio de fecho; o desequilíbrio final vai à conta de reservas de
 *      conversão (5.9.9) — em AOA só os arredondamentos;
 *   7. substitui as linhas geradas da holding, soma os saldos históricos e fecha a execução (totais, moeda e data na holding e no grupo).
 *
 * As linhas da holding são uma projecção regenerada a cada execução (como no legado): as geradas são substituídas, não estornadas,
 * e são gravadas em bloco sem auditoria linha a linha (o legado gravava por IndexedDB nativo, sem hooks); a execução fica auditada.
 *
 * Correcções face ao legado:
 *   - só são substituídas as linhas geradas pela consolidação; o legado apagava também as lançadas directamente na holding
 *     (apagarLinhasHolding, consolidacao.js:365-380), que o próprio mapa mostra como «Outros ajustes»;
 *   - os pares estorno/estornado das empresas não são copiados (anulam-se; no legado um documento descontabilizado não tinha linhas),
 *     salvo quando o estorno é posterior à data de fim (então o original conta e o estorno ainda não);
 *   - as referências a registos de outras empresas que não têm equivalente na holding (sessão POS, acréscimo, projecto, tarefa)
 *     ficam vazias; o legado anulava só projecto e tarefa;
 *   - tudo numa transacção: uma falha não deixa a holding a meio nem a execução «EM_CURSO»;
 *   - valores ao cêntimo com arredondamento half away from zero (ADR-022) em decimal exacto.
 */
final class ServicoConsolidacao
{
    public const TIPO_AGREGACAO = 'AGREGACAO';

    public const TIPO_ELIMINACAO = 'ELIMINACAO';

    public const TIPO_CONVERSAO = 'CONVERSAO';

    public const DIARIO = 'CONS';

    public const TIPO_ORIGEM = 'CONSOLIDACAO';

    private const BLOCO = 500;

    /** Colunas copiadas tal como estão em cada linha agregada. */
    private const COLUNAS_COPIADAS = ['data_documento', 'data_lancamento', 'referencia', 'numero_documento', 'descricao', 'tipo_dc', 'codigo_conta', 'codigo_projeto',
        'periodo_id', 'referencia_documento', 'periodo_contabil', 'documento_origem_id', 'tipo_documento_origem', 'tipo_documento_origem_original', 'url_documento',
        'fonte_dados', 'codigo_moeda', 'valor_moeda', 'taxa_cambio', 'taxa_cambio_id', 'taxa_cambio_manual', 'tipo_origem', 'arredondamento_cambial', 'sistema_origem',
        'nome_utilizador', 'valor_imposto', 'criado_em'];

    /** Tabelas mestre unidas por código: tabela => colunas copiadas (para além do código). */
    private const MESTRES = [
        'plano_contas' => ['descricao', 'tipo', 'natureza_conta'],
        'diarios_contabeis' => ['nome', 'descricao'],
        'notas_demonstracao_resultados' => ['descricao'],
        'notas_fluxo_caixa' => ['descricao'],
        'unidades_negocio' => ['nome', 'descricao'],
        'centros_custo' => ['descricao'],
    ];

    /** @var array<string, string|null> */
    private array $taxas = [];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoConsolidacaoGrupos $grupos,
        private readonly ServicoCambios $cambios,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** Consolida o grupo até `dataFim` na moeda indicada (consolExecutar/consolidar). */
    public function executar(int $grupoId, string $dataFim, ?string $moeda): array
    {
        $grupo = $this->grupos->grupo($grupoId, true);
        $holding = (int) $grupo->empresa_holding_id;
        $ids = $this->grupos->membros($grupo);
        if (! $ids) {
            throw new ErroNegocio('O grupo não tem empresas associadas.', 'GRUPO_SEM_EMPRESAS', 422);
        }
        $moeda = strtoupper($moeda ?: ServicoConsolidacaoGrupos::MOEDA_BASE);
        $emMoeda = $moeda !== ServicoConsolidacaoGrupos::MOEDA_BASE;
        $this->taxas = [];

        return $this->contexto->semIsolamento(fn () => DB::transaction(function () use ($grupo, $holding, $ids, $dataFim, $moeda, $emMoeda) {
            DB::table('grupos_consolidacao')->where('id', $grupo->id)->lockForUpdate()->first(['id']);
            $empresas = Empresa::query()->whereIn('id', $ids)->get(['id', 'nome', 'nif'])->keyBy('id');
            $nome = fn (int $e) => $empresas[$e]->nome ?? "Empresa {$e}";

            // 1) linhas das empresas até à data de fim
            $origem = [];
            foreach ($ids as $e) {
                $origem[$e] = $this->linhasOrigem($e, $dataFim);
            }

            // 2) câmbios antes de mexer na holding
            if ($emMoeda) {
                $faltas = [];
                foreach (array_merge([$dataFim], ...array_map(fn ($ls) => $ls->pluck('data_documento')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->all(), $origem)) as $dia) {
                    if ($this->taxa($holding, $moeda, $dia) === null) {
                        $faltas[$dia] = true;
                    }
                }
                if ($faltas) {
                    $lista = array_keys($faltas);
                    sort($lista);
                    throw new ErroNegocio("Não há câmbio de {$moeda} registado até às datas seguintes (".count($lista).'). Registe os câmbios em Moedas e Câmbios.', 'CAMBIOS_EM_FALTA', 422,
                        ['datas' => array_slice($lista, 0, 50)]);
                }
            }

            // 3) dados mestre e registo da execução
            $mapas = $this->sincronizarDadosMestre($holding, $ids);
            $diarioCons = $this->diarioConsolidacao($holding);
            $agora = now();
            $execucao = $this->contexto->executarComo($holding, fn () => ExecucaoConsolidacao::create([
                'empresa_id' => $holding, 'grupo_consolidacao_id' => $grupo->id, 'empresa_holding_id' => $holding, 'data_execucao' => $agora->toDateString(), 'executado_em' => $agora,
                'data_fim' => $dataFim, 'codigo_moeda' => $moeda, 'estado' => 'EM_CURSO', 'nome_utilizador' => Auth::user()?->nome_utilizador,
            ]));
            $run = $execucao->id;

            // 4) linhas consolidadas
            $novas = [];
            $resumo = [];
            $saldosBalanco = [];
            $contasUsadas = [];
            $normNif = fn ($v) => strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $v));
            $nifGrupo = [];
            $semNif = [];
            foreach ($ids as $e) {
                $n = $normNif($empresas[$e]->nif ?? '');
                strlen($n) >= 5 ? $nifGrupo[$n] = $e : $semNif[] = $nome($e);
            }
            $elimAtiva = $grupo->eliminacao_ativa !== false;
            $excluidas = array_values(array_filter(preg_split('/[,;\s]+/', (string) ($grupo->prefixos_excluidos_eliminacao ?? ServicoConsolidacaoGrupos::PREFIXOS_EXCLUIDOS_PADRAO)) ?: []));
            $excluida = fn (string $c) => (bool) array_filter($excluidas, fn ($p) => str_starts_with($c, $p));
            $tpGrupo = [];
            foreach ($ids as $e) {
                $tpGrupo[$e] = [];
                foreach (DB::table('terceiros')->where('empresa_id', $e)->whereNotNull('nif')->get(['id', 'nif']) as $t) {
                    $cp = $nifGrupo[$normNif($t->nif)] ?? null;
                    if ($cp && $cp !== $e) {
                        $tpGrupo[$e][$t->id] = $cp;
                    }
                }
            }
            $pares = [];
            $sinal = fn ($dc) => $dc === 'D' ? 1 : -1;
            foreach ($ids as $e) {
                $r = ['empresa_id' => $e, 'nome' => $nome($e), 'linhas' => 0, 'debito' => '0.00', 'credito' => '0.00', 'elim_linhas' => 0, 'elim_valor' => '0.00'];
                $contraparte = [];
                if ($elimAtiva) {
                    foreach ($origem[$e] as $l) {
                        $cp = $tpGrupo[$e][$l->terceiro_id] ?? null;
                        $k = $this->chaveLancamento($l);
                        if ($cp && ! isset($contraparte[$k])) {
                            $contraparte[$k] = $cp;
                        }
                    }
                }
                foreach ($origem[$e] as $l) {
                    $n = $this->linhaAgregada($l, $e, $holding, $run, $mapas, $diarioCons);
                    if ($emMoeda) {
                        $t = $this->taxa($holding, $moeda, substr((string) $l->data_documento, 0, 10));
                        $n['valor'] = $this->arred(bcdiv((string) $l->valor, $t, 8));
                        $n['taxa_cambio'] = $t;
                        foreach (['codigo_moeda', 'valor_moeda', 'taxa_cambio_id', 'taxa_cambio_manual', 'arredondamento_cambial'] as $c) {
                            $n[$c] = null;
                        }
                    }
                    $codigo = (string) $l->codigo_conta;
                    $contasUsadas[$codigo] = true;
                    $s = $sinal($n['tipo_dc']);
                    if (preg_match('/^[1-4]/', $codigo)) {
                        $k = "{$e}|{$codigo}";
                        $saldosBalanco[$k] ??= ['empresa' => $e, 'conta' => $codigo, 'kz' => '0.00', 'conv' => '0.00', 'notas' => []];
                        $saldosBalanco[$k]['kz'] = bcadd($saldosBalanco[$k]['kz'], bcmul((string) $s, (string) $l->valor, 2), 2);
                        $saldosBalanco[$k]['conv'] = bcadd($saldosBalanco[$k]['conv'], bcmul((string) $s, $n['valor'], 2), 2);
                        if ($n['nota_demonstracao_id'] !== null) {
                            $saldosBalanco[$k]['notas'][$n['nota_demonstracao_id']] = ($saldosBalanco[$k]['notas'][$n['nota_demonstracao_id']] ?? 0) + 1;
                        }
                    }
                    $r['linhas']++;
                    $r[$n['tipo_dc'] === 'D' ? 'debito' : 'credito'] = bcadd($r[$n['tipo_dc'] === 'D' ? 'debito' : 'credito'], $n['valor'], 2);
                    $novas[] = $n;

                    // eliminação intragrupo
                    $cp = $elimAtiva ? ($contraparte[$this->chaveLancamento($l)] ?? null) : null;
                    if ($cp && ! $excluida($codigo) && (isset($tpGrupo[$e][$l->terceiro_id]) || $l->terceiro_id === null)) {
                        $x = $n;
                        $x['tipo_dc'] = $n['tipo_dc'] === 'D' ? 'C' : 'D';
                        $x['diario_id'] = $diarioCons;
                        $x['numero_lan'] = mb_substr("CONS-{$run}-ELI-E{$e}-".($l->numero_lan ?: $l->id), 0, 50);
                        $x['tipo_consolidacao'] = self::TIPO_ELIMINACAO;
                        $x['empresa_intragrupo_id'] = $cp;
                        $x['descricao'] = "Eliminação intragrupo {$nome($e)} ↔ {$nome($cp)}: ".($l->descricao ?? '');
                        $novas[] = $x;
                        if (preg_match('/^[1-4]/', $codigo)) {
                            $k = "{$e}|{$codigo}";
                            $saldosBalanco[$k]['kz'] = bcsub($saldosBalanco[$k]['kz'], bcmul((string) $s, (string) $l->valor, 2), 2);
                            $saldosBalanco[$k]['conv'] = bcsub($saldosBalanco[$k]['conv'], bcmul((string) $s, $x['valor'], 2), 2);
                        }
                        $r['elim_linhas']++;
                        $r['elim_valor'] = bcadd($r['elim_valor'], $x['valor'], 2);
                        $pares["{$e}|{$cp}"] ??= ['balanco' => '0.00', 'proveitos' => '0.00', 'custos' => '0.00'];
                        $assinado = bcmul((string) $s, $n['valor'], 2);
                        if (str_starts_with($codigo, '6')) {
                            $pares["{$e}|{$cp}"]['proveitos'] = bcsub($pares["{$e}|{$cp}"]['proveitos'], $assinado, 2);
                        } elseif (str_starts_with($codigo, '7')) {
                            $pares["{$e}|{$cp}"]['custos'] = bcadd($pares["{$e}|{$cp}"]['custos'], $assinado, 2);
                        } else {
                            $pares["{$e}|{$cp}"]['balanco'] = bcadd($pares["{$e}|{$cp}"]['balanco'], $assinado, 2);
                        }
                    }
                }
                $resumo[$e] = $r;
            }

            // contas usadas que não existem no plano da holding
            $plano = DB::table('plano_contas')->where('empresa_id', $holding)->whereNull('eliminado_em')->pluck('id', 'codigo')->all();
            $contasCriadas = 0;
            foreach (array_keys($contasUsadas) as $codigo) {
                $contasCriadas += $this->garantirConta($holding, (string) $codigo, "Conta {$codigo}", $plano);
            }

            // diferenças de eliminação e mapa de divergências
            $elim = array_values(array_filter($novas, fn ($x) => $x['tipo_consolidacao'] === self::TIPO_ELIMINACAO));
            $difElim = $this->liquido($elim);
            $dataFmt = date('d/m/Y', strtotime($dataFim));
            if (bccomp($this->abs($difElim), '0.01', 2) >= 0) {
                $conta = $grupo->conta_diferenca_eliminacao ?: ServicoConsolidacaoGrupos::CONTA_DIFERENCA_PADRAO;
                $contasCriadas += $this->garantirConta($holding, $conta, 'Diferenças de eliminação intragrupo (consolidação)', $plano);
                $novas[] = $this->linhaAjuste($holding, $diarioCons, $run, $dataFim, "CONS-{$run}-ELI-DIF", "ELIM {$dataFmt}", 'Eliminações intragrupo',
                    self::TIPO_ELIMINACAO, $conta, bccomp($difElim, '0', 2) > 0 ? 'C' : 'D', $this->abs($difElim), 'Diferenças de eliminação intragrupo (ver mapa de divergências)');
            }
            $divergencias = [];
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    [$a, $b] = [$ids[$i], $ids[$j]];
                    $zero = ['balanco' => '0.00', 'proveitos' => '0.00', 'custos' => '0.00'];
                    $ab = $pares["{$a}|{$b}"] ?? $zero;
                    $ba = $pares["{$b}|{$a}"] ?? $zero;
                    if (! array_filter(array_merge(array_values($ab), array_values($ba)), fn ($v) => bccomp($this->abs($v), '0.01', 2) >= 0)) {
                        continue;
                    }
                    $divergencias[] = ['a' => $nome($a), 'b' => $nome($b), 'empresa_a_id' => $a, 'empresa_b_id' => $b,
                        'saldo_ab' => $ab['balanco'], 'saldo_ba' => $ba['balanco'], 'dif_saldo' => bcadd($ab['balanco'], $ba['balanco'], 2),
                        'proveitos_ab' => $ab['proveitos'], 'custos_ba' => $ba['custos'], 'dif_ab' => bcsub($ab['proveitos'], $ba['custos'], 2),
                        'proveitos_ba' => $ba['proveitos'], 'custos_ab' => $ab['custos'], 'dif_ba' => bcsub($ba['proveitos'], $ab['custos'], 2)];
                }
            }

            // conversão cambial: balanço ao câmbio de fecho
            $ajustes = [];
            if ($emMoeda) {
                $tFecho = $this->taxa($holding, $moeda, $dataFim);
                foreach ($saldosBalanco as $sb) {
                    $ajuste = bcsub($this->arred(bcdiv($sb['kz'], $tFecho, 8)), $sb['conv'], 2);
                    if (bccomp($this->abs($ajuste), '0.01', 2) < 0) {
                        continue;
                    }
                    arsort($sb['notas']);
                    $linha = $this->linhaAjuste($holding, $diarioCons, $run, $dataFim, "CONS-{$run}-CNV", "CONV {$dataFmt}", 'Conversão cambial', self::TIPO_CONVERSAO,
                        $sb['conta'], bccomp($ajuste, '0', 2) > 0 ? 'D' : 'C', $this->abs($ajuste),
                        "Conversão ao câmbio de fecho (1 {$moeda} = {$tFecho} Kz) — {$resumo[$sb['empresa']]['nome']}");
                    $linha['empresa_origem_id'] = $sb['empresa'];
                    $linha['nota_demonstracao_id'] = array_key_first($sb['notas']);
                    $linha['taxa_cambio'] = $tFecho;
                    $ajustes[] = $linha;
                }
            }
            $todas = array_merge($novas, $ajustes);
            $liquido = $this->liquido($todas);
            $reserva = '0.00';
            if (bccomp($this->abs($liquido), '0.01', 2) >= 0) {
                $conta = $grupo->conta_reserva_cambial ?: ServicoConsolidacaoGrupos::CONTA_RESERVA_PADRAO;
                $contasCriadas += $this->garantirConta($holding, $conta, 'Reservas de conversão cambial (consolidação)', $plano);
                $reserva = bcmul($liquido, '-1', 2);
                $todas[] = $this->linhaAjuste($holding, $diarioCons, $run, $dataFim, "CONS-{$run}-CNV", "CONV {$dataFmt}", 'Conversão cambial', self::TIPO_CONVERSAO,
                    $conta, bccomp($liquido, '0', 2) > 0 ? 'C' : 'D', $this->abs($liquido), $emMoeda ? "Reservas de conversão cambial ({$moeda})" : 'Arredondamentos de consolidação');
            }

            // substituir as linhas geradas da holding
            $apagadas = DB::table('lancamentos_contabeis')->where('empresa_id', $holding)
                ->where(fn ($q) => $q->whereNotNull('execucao_consolidacao_id')->orWhereNotNull('tipo_consolidacao'))->delete();
            foreach (array_chunk($todas, self::BLOCO) as $bloco) {
                DB::table('lancamentos_contabeis')->insert($bloco);
            }

            // saldos históricos somados
            DB::table('saldos_historicos')->where('empresa_id', $holding)->delete();
            $soma = [];
            foreach (DB::table('saldos_historicos')->whereIn('empresa_id', $ids)->orderBy('id')->get() as $h) {
                $k = "{$h->ano}|{$h->tipo}|{$h->codigo}";
                $soma[$k] ??= ['empresa_id' => $holding, 'ano' => $h->ano, 'tipo' => $h->tipo, 'tipo_original' => $h->tipo_original, 'codigo' => $h->codigo, 'valor' => '0.00'];
                $soma[$k]['valor'] = bcadd($soma[$k]['valor'], (string) ($h->valor ?? '0'), 2);
            }
            foreach ($soma as &$h) {
                if ($emMoeda && $h['ano']) {
                    $t = $this->taxa($holding, $moeda, "{$h['ano']}-12-31");
                    $h['valor'] = $t ? $this->arred(bcdiv($h['valor'], $t, 8)) : $h['valor'];
                }
                $h['criado_em'] = $h['atualizado_em'] = $agora;
            }
            unset($h);
            foreach (array_chunk(array_values($soma), self::BLOCO) as $bloco) {
                DB::table('saldos_historicos')->insert($bloco);
            }

            // fechar a execução
            [$debitos, $creditos] = [$this->soma($todas, 'D'), $this->soma($todas, 'C')];
            $totais = ['empresas' => array_values($resumo), 'linhas' => count($todas), 'linhas_apagadas' => $apagadas, 'ajustes_conversao' => count($ajustes),
                'reserva' => $reserva, 'debitos' => $debitos, 'creditos' => $creditos, 'contas_criadas' => $contasCriadas + $mapas['contas_criadas'],
                'terceiros_criados' => $mapas['terceiros_criados'],
                'eliminacoes' => ['ativa' => $elimAtiva, 'excluidas' => $excluidas, 'linhas' => count($elim), 'diferenca' => $difElim, 'divergencias' => $divergencias, 'sem_nif' => $semNif]];
            $this->contexto->executarComo($holding, function () use ($execucao, $totais, $grupo, $moeda, $run) {
                $execucao->update(['estado' => 'CONCLUIDA', 'totais' => $totais]);
                $grupo->update(['ultima_execucao_id' => $run, 'moeda_apresentacao' => $moeda]);
            });
            DB::table('empresas')->where('id', $holding)->update(['moeda_consolidacao' => $moeda, 'data_fim_consolidacao' => $dataFim, 'execucao_consolidacao_id' => $run,
                'atualizado_em' => $agora]);
            Cache::forget(ChaveCache::empresa($holding, 'contabilidade', 'plano_contas'));
            $this->auditoria->registar('Contabilidade', 'Executou a consolidação', "Grupo {$grupo->id} até {$dataFim} em {$moeda}: ".count($todas)." linhas ({$apagadas} substituídas).",
                'execucoes_consolidacao', $run, null, null, $holding);

            return ['execucao_id' => $run, 'moeda' => $moeda, 'data_fim' => $dataFim, 'equilibrado' => bccomp($debitos, $creditos, 2) === 0, 'totais' => $totais];
        }));
    }

    /**
     * Mapa de Consolidação (calcularMapa): por conta, grau 2 ou classe, uma coluna por empresa, soma, eliminações, conversão,
     * outros ajustes e consolidado; saldos até à data ou movimentos do período. Débito positivo, crédito negativo.
     *
     * @param  array{nivel?: string, modo?: string, data_inicio?: ?string, data_fim?: ?string, contas?: ?string, ocultar_zeros?: bool}  $f
     */
    public function mapa(int $grupoId, array $f): array
    {
        $grupo = $this->grupos->grupo($grupoId, true);   // Segurança (Fase 6): o mapa mostra uma coluna por empresa-membro
        $execucao = $grupo->ultima_execucao_id ? $this->contexto->semIsolamento(fn () => ExecucaoConsolidacao::query()->find($grupo->ultima_execucao_id)) : null;
        if (! $execucao) {
            throw new ErroNegocio('Consolide a holding antes de gerar o Mapa de Consolidação.', 'SEM_CONSOLIDACAO', 422);
        }
        $holding = (int) $grupo->empresa_holding_id;
        $nivel = $f['nivel'] ?? 'conta';
        $modo = $f['modo'] ?? 'saldo';
        $dataFim = $f['data_fim'] ?? $execucao->data_fim->toDateString();
        $dataInicio = $f['data_inicio'] ?? substr($execucao->data_fim->toDateString(), 0, 4).'-01-01';
        $membros = $this->grupos->membros($grupo);
        $nomes = $this->contexto->semIsolamento(fn () => Empresa::withTrashed()->whereIn('id', $membros)->pluck('nome', 'id'));

        $linhas = DB::table('lancamentos_contabeis')->where('empresa_id', $holding)->whereNotNull('codigo_conta')->whereNotNull('data_documento')
            ->where('codigo_conta', 'not like', '9%')->where('data_documento', '<=', $dataFim)
            ->when($modo === 'periodo', fn ($q) => $q->where('data_documento', '>=', $dataInicio))
            ->selectRaw("codigo_conta, CASE WHEN tipo_consolidacao = 'ELIMINACAO' THEN 'ELIM' WHEN tipo_consolidacao = 'CONVERSAO' THEN 'CONV'
                WHEN tipo_consolidacao = 'AGREGACAO' AND empresa_origem_id IS NOT NULL THEN 'E' || empresa_origem_id ELSE 'OUTROS' END AS coluna,
                COUNT(*) AS n, SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END) AS saldo")
            ->groupBy('codigo_conta', 'coluna')->get();
        if (! empty($f['contas'])) {
            $linhas = $linhas->filter(fn ($l) => $this->contaNoFiltro((string) $l->codigo_conta, (string) $f['contas']))->values();
        }
        $chave = fn (string $c) => $nivel === 'classe' ? substr($c, 0, 1) : ($nivel === '2' ? substr($c, 0, 2) : $c);
        $porChave = [];
        $temConv = $temOutros = false;
        foreach ($linhas as $l) {
            $k = $chave((string) $l->codigo_conta);
            $porChave[$k][$l->coluna] = bcadd($porChave[$k][$l->coluna] ?? '0.00', (string) $l->saldo, 2);
            $temConv = $temConv || $l->coluna === 'CONV';
            $temOutros = $temOutros || $l->coluna === 'OUTROS';
        }
        $colunasEmpresa = array_map(fn ($e) => ['chave' => "E{$e}", 'nome' => $nomes[$e] ?? "Empresa {$e}", 'empresa_id' => $e], $membros);
        $colunas = array_merge($colunasEmpresa, [['chave' => 'SOMA', 'nome' => 'Soma das Empresas'], ['chave' => 'ELIM', 'nome' => 'Eliminações']],
            $temConv ? [['chave' => 'CONV', 'nome' => 'Conversão Cambial']] : [], $temOutros ? [['chave' => 'OUTROS', 'nome' => 'Outros Ajustes']] : [],
            [['chave' => 'TOTAL', 'nome' => 'Consolidado']]);
        $completar = function (array $v) use ($colunasEmpresa, $colunas) {
            $v['SOMA'] = '0.00';
            foreach ($colunasEmpresa as $c) {
                $v['SOMA'] = bcadd($v['SOMA'], $v[$c['chave']] ?? '0.00', 2);
            }
            $v['TOTAL'] = bcadd(bcadd($v['SOMA'], $v['ELIM'] ?? '0.00', 2), bcadd($v['CONV'] ?? '0.00', $v['OUTROS'] ?? '0.00', 2), 2);
            $saida = [];
            foreach ($colunas as $c) {
                $saida[$c['chave']] = $v[$c['chave']] ?? '0.00';
            }

            return $saida;
        };
        $classes = [1 => 'Meios Fixos e Investimentos', 2 => 'Existências', 3 => 'Terceiros', 4 => 'Meios Monetários', 5 => 'Capital e Reservas',
            6 => 'Proveitos e Ganhos', 7 => 'Custos e Perdas', 8 => 'Resultados'];
        $plano = DB::table('plano_contas')->where('empresa_id', $holding)->whereNull('eliminado_em')->pluck('descricao', 'codigo');
        $lista = [];
        foreach ($porChave as $k => $v) {
            $valores = $completar($v);
            if (! empty($f['ocultar_zeros']) && ! array_filter($valores, fn ($x) => bccomp($this->abs($x), '0.005', 3) >= 0)) {
                continue;
            }
            $lista[] = ['codigo' => (string) $k, 'descricao' => $nivel === 'classe' ? ($classes[(int) $k] ?? '') : ($plano[$k] ?? ''), 'valores' => $valores];
        }
        usort($lista, fn ($a, $b) => strnatcmp($a['codigo'], $b['codigo']));
        $somar = function (array $itens) use ($colunas) {
            $t = [];
            foreach ($colunas as $c) {
                $t[$c['chave']] = '0.00';
                foreach ($itens as $i) {
                    $t[$c['chave']] = bcadd($t[$c['chave']], $i['valores'][$c['chave']], 2);
                }
            }

            return $t;
        };
        $saida = [];
        foreach (collect($lista)->groupBy(fn ($r) => substr($r['codigo'], 0, 1))->sortKeys() as $cl => $itens) {
            if ($nivel === 'classe') {
                foreach ($itens as $r) {
                    $saida[] = ['tipo' => 'conta'] + $r;
                }

                continue;
            }
            $saida[] = ['tipo' => 'cabecalho', 'codigo' => (string) $cl, 'descricao' => $classes[(int) $cl] ?? "Classe {$cl}"];
            foreach ($itens as $r) {
                $saida[] = ['tipo' => 'conta'] + $r;
            }
            $saida[] = ['tipo' => 'subtotal', 'codigo' => (string) $cl, 'descricao' => "Total da classe {$cl}", 'valores' => $somar($itens->all())];
        }
        $resultado = [];
        $pl = $somar(array_values(array_filter($lista, fn ($r) => preg_match('/^[67]/', $r['codigo']))));
        foreach ($colunas as $c) {
            $resultado[$c['chave']] = bcmul($pl[$c['chave']], '-1', 2);
        }

        return ['grupo_id' => $grupo->id, 'execucao_id' => $execucao->id, 'moeda' => $execucao->codigo_moeda, 'nivel' => $nivel, 'modo' => $modo,
            'data_inicio' => $modo === 'periodo' ? $dataInicio : null, 'data_fim' => $dataFim, 'colunas' => $colunas, 'linhas' => $saida, 'total' => $somar($lista),
            'resultado' => $resultado, 'lancamentos' => (int) $linhas->sum('n'),
            'aviso' => $dataFim > $execucao->data_fim->toDateString() ? 'A holding foi consolidada até '.$execucao->data_fim->format('d/m/Y').': movimentos posteriores das empresas não estão incluídos.' : null];
    }

    // ───────────── Auxiliares da execução ─────────────

    /** Linhas de uma empresa até à data de fim, sem os pares estorno/estornado já anulados até essa data. */
    private function linhasOrigem(int $empresa, string $dataFim)
    {
        return DB::table('lancamentos_contabeis as l')->where('l.empresa_id', $empresa)->whereNotNull('l.codigo_conta')->whereNotNull('l.data_documento')
            ->where('l.data_documento', '<=', $dataFim)->whereNull('l.estorno_de_id')
            ->where(fn ($q) => $q->whereNull('l.estornado_por_id')
                ->orWhereExists(fn ($x) => $x->from('lancamentos_contabeis as e')->whereColumn('e.id', 'l.estornado_por_id')->where('e.data_documento', '>', $dataFim)))
            ->orderBy('l.id')->get(array_merge(['l.id', 'l.diario_id', 'l.numero_lan', 'l.valor', 'l.terceiro_id', 'l.nota_demonstracao_id', 'l.nota_fluxo_caixa_id',
                'l.unidade_negocio_id', 'l.centro_custo_id'], array_map(fn ($c) => "l.{$c}", self::COLUNAS_COPIADAS)));
    }

    private function linhaAgregada(object $l, int $e, int $holding, int $run, array $mapas, int $diarioCons): array
    {
        $n = ['empresa_id' => $holding];
        foreach (self::COLUNAS_COPIADAS as $c) {
            $n[$c] = $l->{$c};
        }
        $mapear = fn (string $tabela, $id) => $id !== null ? ($mapas[$tabela][$e][$id] ?? null) : null;

        return $n + [
            'diario_id' => $mapear('diarios_contabeis', $l->diario_id) ?? $diarioCons,
            'nota_demonstracao_id' => $mapear('notas_demonstracao_resultados', $l->nota_demonstracao_id),
            'nota_fluxo_caixa_id' => $mapear('notas_fluxo_caixa', $l->nota_fluxo_caixa_id),
            'terceiro_id' => $mapear('terceiros', $l->terceiro_id),
            'unidade_negocio_id' => $mapear('unidades_negocio', $l->unidade_negocio_id),
            'centro_custo_id' => $mapear('centros_custo', $l->centro_custo_id),
            'valor' => bcadd((string) $l->valor, '0', 2), 'numero_lan' => mb_substr("E{$e}-".($l->numero_lan ?? ''), 0, 50),
            'projeto_id' => null, 'tarefa_projeto_id' => null, 'reconciliacao_codigo' => null, 'sessao_pos_id' => null, 'item_acrescimo_diferimento_id' => null,
            'empresa_origem_id' => $e, 'linha_origem_id' => $l->id, 'execucao_consolidacao_id' => $run, 'tipo_consolidacao' => self::TIPO_AGREGACAO,
            'valor_kz_origem' => bcadd((string) $l->valor, '0', 2), 'empresa_intragrupo_id' => null,
            'estorno_de_id' => null, 'estornado_por_id' => null, 'estornado_em' => null, 'atualizado_em' => now(),
        ];
    }

    /** Linha de ajuste (eliminação, conversão, reservas) com o mesmo conjunto de colunas das linhas agregadas. */
    private function linhaAjuste(int $holding, int $diario, int $run, string $data, string $lan, string $documento, string $referencia, string $tipo,
        string $conta, string $dc, string $valor, string $descricao): array
    {
        $n = array_fill_keys(self::COLUNAS_COPIADAS, null);

        return array_merge($n, [
            'empresa_id' => $holding, 'diario_id' => $diario, 'data_documento' => $data, 'data_lancamento' => $data, 'referencia' => $referencia, 'numero_documento' => $documento,
            'descricao' => $descricao, 'tipo_dc' => $dc, 'codigo_conta' => $conta, 'tipo_origem' => self::TIPO_ORIGEM, 'criado_em' => now(),
            'nota_demonstracao_id' => null, 'nota_fluxo_caixa_id' => null, 'terceiro_id' => null, 'unidade_negocio_id' => null, 'centro_custo_id' => null,
            'valor' => $valor, 'numero_lan' => $lan, 'projeto_id' => null, 'tarefa_projeto_id' => null, 'reconciliacao_codigo' => null, 'sessao_pos_id' => null,
            'item_acrescimo_diferimento_id' => null, 'empresa_origem_id' => null, 'linha_origem_id' => null, 'execucao_consolidacao_id' => $run,
            'tipo_consolidacao' => $tipo, 'valor_kz_origem' => null, 'empresa_intragrupo_id' => null, 'estorno_de_id' => null, 'estornado_por_id' => null,
            'estornado_em' => null, 'atualizado_em' => now(),
        ]);
    }

    /**
     * Lançamento da linha para as eliminações, como o legado (chaveLan, consolidacao.js:532): o N.º de lançamento ou, sem ele,
     * diário + documento + data. A chave do ADR-025 (referência) não serve aqui: nos dados reais junta linhas de lançamentos
     * diferentes com a mesma referência e eliminava 90 linhas (diferença de 12,8 milhões) em vez das 4 do legado.
     */
    private function chaveLancamento(object $l): string
    {
        return $l->numero_lan !== null && $l->numero_lan !== ''
            ? "L:{$l->numero_lan}"
            : "D:{$l->diario_id}|{$l->numero_documento}|".substr((string) $l->data_documento, 0, 10);
    }

    /**
     * Copia para a holding os registos mestre em falta (por código; terceiros pelo NIF/nome) e devolve os mapas id origem → id holding.
     *
     * @return array<string, mixed>
     */
    private function sincronizarDadosMestre(int $holding, array $ids): array
    {
        $mapas = ['contas_criadas' => 0, 'terceiros_criados' => 0];
        foreach (self::MESTRES as $tabela => $colunas) {
            [$mapas[$tabela], $criados] = $this->unir($tabela, $holding, $ids, fn ($r) => $r->codigo !== null && $r->codigo !== '' ? (string) $r->codigo : null,
                array_merge(['codigo'], $colunas), $tabela === 'plano_contas' ? ['codigo_moeda' => null] : []);
            if ($tabela === 'plano_contas') {
                $mapas['contas_criadas'] = $criados;
            }
        }
        $chaveTerceiro = function ($t) {
            $nif = preg_replace('/\s/', '', (string) $t->nif);
            if (strlen($nif) >= 5 && ! preg_match('/^0+$/', $nif) && ! preg_match('/^9+$/', $nif)) {
                return "NIF:{$nif}";
            }

            return 'NOME:'.mb_strtolower(trim((string) $t->nome));
        };
        [$mapas['terceiros'], $mapas['terceiros_criados']] = $this->unir('terceiros', $holding, $ids, $chaveTerceiro,
            ['nif', 'nome', 'tipo', 'tipo_original', 'endereco', 'email', 'codigo_moeda', 'telefone', 'fe_pais'], ['codigo_conta' => null, 'conta_compra_transitoria' => null]);

        return $mapas;
    }

    /** @return array{0: array<int, array<int, int>>, 1: int} */
    private function unir(string $tabela, int $holding, array $ids, callable $chave, array $colunas, array $fixos): array
    {
        $logica = Schema::hasColumn($tabela, 'eliminado_em');
        $q = fn (int $e) => DB::table($tabela)->where('empresa_id', $e)->when($logica, fn ($x) => $x->whereNull('eliminado_em'))->orderBy('id');
        $porChave = [];
        foreach ($q($holding)->get(array_merge(['id'], $colunas)) as $x) {
            $k = $chave($x);
            if ($k !== null) {
                $porChave[$k] ??= (int) $x->id;
            }
        }
        $mapa = [];
        $criados = 0;
        foreach ($ids as $e) {
            $mapa[$e] = [];
            foreach ($q($e)->get(array_merge(['id'], $colunas)) as $r) {
                $k = $chave($r);
                if ($k === null) {
                    continue;
                }
                if (! isset($porChave[$k])) {
                    $novo = ['empresa_id' => $holding, 'criado_em' => now(), 'atualizado_em' => now()] + $fixos;
                    foreach ($colunas as $c) {
                        $novo[$c] ??= $r->{$c};
                    }
                    $porChave[$k] = (int) DB::table($tabela)->insertGetId($novo);
                    $criados++;
                }
                $mapa[$e][(int) $r->id] = $porChave[$k];
            }
        }

        return [$mapa, $criados];
    }

    private function diarioConsolidacao(int $holding): int
    {
        $id = DB::table('diarios_contabeis')->where('empresa_id', $holding)->where('codigo', self::DIARIO)->whereNull('eliminado_em')->value('id');

        return $id ? (int) $id : (int) DB::table('diarios_contabeis')->insertGetId(['empresa_id' => $holding, 'codigo' => self::DIARIO, 'nome' => 'Consolidação',
            'descricao' => 'Ajustes de consolidação', 'criado_em' => now(), 'atualizado_em' => now()]);
    }

    /** Cria a conta na holding se não existir (como movimento). Devolve 1 se criou. */
    private function garantirConta(int $holding, string $codigo, string $descricao, array &$plano): int
    {
        if (isset($plano[$codigo])) {
            return 0;
        }
        $plano[$codigo] = DB::table('plano_contas')->insertGetId(['empresa_id' => $holding, 'codigo' => $codigo, 'descricao' => $descricao, 'tipo' => 'M',
            'criado_em' => now(), 'atualizado_em' => now()]);

        return 1;
    }

    /** Câmbio (Kz por 1 unidade) válido na data, em cache por pedido. */
    private function taxa(int $holding, string $moeda, string $dia): ?string
    {
        if (! array_key_exists("{$moeda}|{$dia}", $this->taxas)) {
            $r = $this->cambios->obter($holding, $moeda, $dia);
            $this->taxas["{$moeda}|{$dia}"] = $r && bccomp($r['taxa'], '0', 6) > 0 ? $r['taxa'] : null;
        }

        return $this->taxas["{$moeda}|{$dia}"];
    }

    /** Filtro de contas do legado (filterLinesByAccounts, js/ui_reports.js:27-60): prefixos, intervalos "61-62", "*X" e "X*". */
    private function contaNoFiltro(string $codigo, string $filtro): bool
    {
        foreach (array_filter(array_map('trim', explode(',', $filtro)), fn ($p) => $p !== '') as $p) {
            if (str_contains($p, '-')) {
                [$ini, $fim] = array_map('trim', explode('-', $p, 2));
                $prefixo = substr($codigo, 0, max(strlen($ini), strlen($fim)));
                if (strcmp($prefixo, $ini) >= 0 && strcmp($prefixo, $fim) <= 0) {
                    return true;
                }
            } elseif (str_starts_with($p, '*')) {
                $v = substr($p, 1);
                if (strcmp($codigo, $v) <= 0 || str_starts_with($codigo, $v)) {
                    return true;
                }
            } elseif (str_ends_with($p, '*')) {
                if (strcmp($codigo, substr($p, 0, -1)) >= 0) {
                    return true;
                }
            } elseif (str_starts_with($codigo, $p)) {
                return true;
            }
        }

        return false;
    }

    private function liquido(array $linhas): string
    {
        return bcsub($this->soma($linhas, 'D'), $this->soma($linhas, 'C'), 2);
    }

    private function soma(array $linhas, string $dc): string
    {
        $t = '0.00';
        foreach ($linhas as $l) {
            if ($l['tipo_dc'] === $dc) {
                $t = bcadd($t, (string) $l['valor'], 2);
            }
        }

        return $t;
    }

    /** Arredondamento ao cêntimo, half away from zero (ADR-022). */
    private function arred(string $v): string
    {
        return bccomp($v, '0', 8) >= 0 ? bcadd($v, '0.005', 2) : bcsub($v, '0.005', 2);
    }

    private function abs(string $v): string
    {
        return ltrim($v, '-');
    }
}
