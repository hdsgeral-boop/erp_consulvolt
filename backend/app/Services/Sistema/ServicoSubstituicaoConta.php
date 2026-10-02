<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\PlanoConta;
use App\Models\Utilizador;
use App\Support\Cache\ChaveCache;
use App\Support\Cache\InvalidacaoCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Substituir uma conta por outra (js/substituir_conta.js — Configurações › Plano de contas › "Substituir conta").
 *
 * Paridade:
 *  - procura o código EXACTO da conta de origem nos campos de conta das tabelas da empresa, mostra por empresa onde é
 *    usada (contagem por tabela/coluna) e se a conta de destino existe e é de movimento;
 *  - substitui nas empresas escolhidas (a que o utilizador tem acesso); o plano de contas não é alterado (a conta de
 *    origem continua no plano e pode ser eliminada depois, se ficar sem uso).
 *
 * Correcções face ao legado (decisão: o histórico contabilístico não se reescreve — ADR-016):
 *  - o legado reescrevia LANÇAMENTOS já gravados (journal_lines.account_code) e documentos contabilizados, sem
 *    transacção (erros a meio deixavam a substituição parcial) e com um registo só descarregável. Agora:
 *      · só se alteram as referências de CONFIGURAÇÃO e FICHAS (terceiros, produtos, bancos, meios de pagamento,
 *        categorias de activos, configurações contabilísticas dos módulos, mapeamentos de salários, orçamento de
 *        projectos) e os documentos de tesouraria ainda PENDENTES (não integrados);
 *      · lançamentos, estornos, documentos de venda/compra, caixa, extractos, liquidações, acréscimos e abates ficam
 *        como estão e aparecem na pré-visualização apenas como contagem informativa — a passagem de saldos faz-se com
 *        um lançamento de transferência (que fica no Diário);
 *  - origem e destino têm de existir no plano da empresa e ser contas de MOVIMENTO (o legado aceitava qualquer
 *    código de origem e só verificava o destino);
 *  - tudo numa única transacção, com bloqueio das contas, e auditoria por empresa com os ids alterados (valores
 *    anteriores e novos) — reversível por nova substituição;
 *  - as rubricas orçamentais (contas por PREFIXO) não são alteradas automaticamente: mudar o prefixo mudaria o
 *    âmbito da rubrica; aparecem como informativas para revisão manual.
 */
final class ServicoSubstituicaoConta
{
    /** tabela => [colunas, rótulo, condição SQL adicional (sobre a própria tabela) ou null] */
    private const ALTERAVEIS = [
        'terceiros' => [['codigo_conta', 'conta_compra_transitoria'], 'Terceiros', null],
        'produtos' => [['codigo_conta', 'conta_compra', 'conta_custo', 'conta_inventario', 'conta_iva', 'conta_iva_liquidado', 'conta_iva_dedutivel',
            'conta_quebra', 'conta_sobra', 'conta_ativo'], 'Produtos e serviços', null],
        'bancos' => [['codigo_conta'], 'Bancos', null],
        'meios_pagamento' => [['codigo_conta'], 'Meios de pagamento', null],
        'categorias_ativos' => [['conta_ativo', 'conta_amortizacao_acumulada', 'conta_gasto', 'conta_venda', 'conta_perda'], 'Categorias de activos', null],
        'configuracoes_contabeis_compras' => [['codigo_conta'], 'Configuração contabilística de compras', null],
        'configuracoes_contabeis_logistica' => [['codigo_conta'], 'Configuração contabilística de logística', null],
        'configuracoes_contabeis_tesouraria' => [['codigo_conta'], 'Configuração contabilística de tesouraria', null],
        'configuracoes_contabeis_vendas' => [['codigo_conta'], 'Configuração contabilística de vendas', null],
        'configuracoes_lavandaria' => [['conta_compensacao', 'conta_extras'], 'Configuração da lavandaria', null],
        'configuracoes_pos' => [['conta_operador', 'conta_quebra', 'conta_sobra'], 'Configuração do POS', null],
        'mapeamentos_contabeis_rh' => [['numero_conta'], 'Mapeamento de salários', null],
        'mapeamentos_contabeis_sistema_rh' => [['numero_conta'], 'Mapeamento de salários (sistema)', null],
        'linhas_orcamento_projeto' => [['numero_conta'], 'Orçamento de projectos', null],
        'documentos_tesouraria' => [['conta_financeira'], 'Tesouraria — documentos pendentes', "estado = 'PENDENTE'"],
        'itens_documento_tesouraria' => [['codigo_conta'], 'Tesouraria — linhas de documentos pendentes',
            "EXISTS (SELECT 1 FROM documentos_tesouraria d WHERE d.id = itens_documento_tesouraria.documento_tesouraria_id AND d.estado = 'PENDENTE')"],
    ];

    /** Colunas jsonb de configuração cujo VALOR (objecto chave => conta) é substituído. */
    private const ALTERAVEIS_JSON = [
        'configuracoes_acrescimos_diferimentos' => ['contas', 'Configuração de acréscimos e diferimentos'],
    ];

    /** Histórico e documentos contabilizados: só contados. */
    private const INFORMATIVOS = [
        'lancamentos_contabeis' => [['codigo_conta'], 'Lançamentos (Diário)', null],
        'lancamentos_estornados' => [['codigo_conta'], 'Lançamentos estornados (arquivo)', null],
        'itens_venda' => [['codigo_conta'], 'Linhas de documentos de venda', null],
        'recibos_venda' => [['codigo_conta'], 'Recibos', null],
        'documentos_tesouraria' => [['conta_financeira'], 'Tesouraria — documentos integrados/anulados', "estado <> 'PENDENTE'"],
        'itens_documento_tesouraria' => [['codigo_conta'], 'Tesouraria — linhas de documentos integrados/anulados',
            "NOT EXISTS (SELECT 1 FROM documentos_tesouraria d WHERE d.id = itens_documento_tesouraria.documento_tesouraria_id AND d.estado = 'PENDENTE')"],
        'movimentos_caixa' => [['conta_debito', 'conta_credito'], 'Folha de caixa', null],
        'sessoes_caixa' => [['codigo_conta'], 'Sessões de caixa', null],
        'conferencias_caixa' => [['codigo_conta', 'conta_regularizacao'], 'Conferências de caixa', null],
        'linhas_extrato_bancario' => [['codigo_conta'], 'Extractos bancários', null],
        'liquidacoes_pos' => [['conta_comissao', 'conta_destino', 'conta_transitoria'], 'Liquidações do POS', null],
        'abates_vendas_ativos' => [['conta_terceiro'], 'Abates e vendas de activos', null],
        'itens_acrescimos_diferimentos' => [['conta_balanco', 'conta_resultado'], 'Acréscimos e diferimentos (registos)', null],
        'cartas_pagamento_bancario' => [['codigo_conta_bancaria'], 'Cartas de pagamento', null],
    ];

    public function __construct(
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * Pré-visualização por empresa.
     *
     * @param  list<int>  $empresas
     * @return list<array<string, mixed>>
     */
    public function simular(string $origem, string $destino, array $empresas, Utilizador $actor): array
    {
        [$origem, $destino] = $this->normalizar($origem, $destino);
        $r = [];
        foreach ($this->empresasPermitidas($empresas, $actor) as $id => $nome) {
            $contas = $this->contas($id, $origem, $destino, false);
            $alteraveis = $this->contar($id, $origem, self::ALTERAVEIS, true);
            $informativos = $this->contar($id, $origem, self::INFORMATIVOS, false);
            $prefixos = DB::table('rubricas_orcamentais')->where('empresa_id', $id)
                ->whereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(contas) = 'array' THEN contas ELSE '[]'::jsonb END) c WHERE c->>'codigo' = ?)", [$origem])->count();
            if ($prefixos) {
                $informativos[] = ['tabela' => 'rubricas_orcamentais', 'coluna' => 'contas', 'rotulo' => 'Rubricas orçamentais (por prefixo — rever)', 'registos' => $prefixos];
            }
            $r[] = ['empresa_id' => $id, 'empresa' => $nome, 'origem' => $contas['origem'], 'destino' => $contas['destino'], 'pode_substituir' => $contas['erro'] === null,
                'motivo' => $contas['erro'], 'alteraveis' => $alteraveis, 'total_alteraveis' => array_sum(array_column($alteraveis, 'registos')),
                'informativos' => $informativos, 'total_informativos' => array_sum(array_column($informativos, 'registos'))];
        }

        return $r;
    }

    /**
     * Substitui nas empresas indicadas, numa única transacção.
     *
     * @param  list<int>  $empresas
     * @return list<array{empresa_id: int, empresa: string, alterados: array<string, int>, total: int}>
     */
    public function executar(string $origem, string $destino, array $empresas, Utilizador $actor): array
    {
        [$origem, $destino] = $this->normalizar($origem, $destino);
        $permitidas = $this->empresasPermitidas($empresas, $actor);

        return DB::transaction(function () use ($origem, $destino, $permitidas) {
            $r = [];
            foreach ($permitidas as $id => $nome) {
                $contas = $this->contas($id, $origem, $destino, true);
                if ($contas['erro'] !== null) {
                    throw new ErroNegocio("{$nome}: {$contas['erro']}", 'SUBSTITUICAO_IMPOSSIVEL', 422, ['empresa_id' => $id]);
                }
                $alterados = [];
                $ids = [];
                foreach (self::ALTERAVEIS as $tabela => [$colunas, , $condicao]) {
                    foreach ($this->colunasExistentes($tabela, $colunas) as $coluna) {
                        $q = DB::table($tabela)->where('empresa_id', $id)->where($coluna, $origem)->when($condicao, fn ($w) => $w->whereRaw($condicao));
                        $lista = $q->clone()->orderBy('id')->pluck('id')->map(fn ($x) => (int) $x)->all();
                        if ($lista) {
                            DB::table($tabela)->whereIn('id', $lista)->update([$coluna => $destino]);
                            $alterados["{$tabela}.{$coluna}"] = count($lista);
                            $ids["{$tabela}.{$coluna}"] = $lista;
                        }
                    }
                }
                foreach (self::ALTERAVEIS_JSON as $tabela => [$coluna]) {
                    foreach (DB::table($tabela)->where('empresa_id', $id)->whereNotNull($coluna)->lockForUpdate()->get(['id', $coluna]) as $linha) {
                        $valor = json_decode((string) $linha->{$coluna}, true);
                        if (! is_array($valor)) {
                            continue;
                        }
                        $mudou = false;
                        foreach ($valor as $k => $v) {
                            if (is_string($v) && trim($v) === $origem) {
                                $valor[$k] = $destino;
                                $mudou = true;
                            }
                        }
                        if ($mudou) {
                            DB::table($tabela)->where('id', $linha->id)->update([$coluna => json_encode($valor, JSON_UNESCAPED_UNICODE)]);
                            $alterados["{$tabela}.{$coluna}"] = ($alterados["{$tabela}.{$coluna}"] ?? 0) + 1;
                            $ids["{$tabela}.{$coluna}"][] = (int) $linha->id;
                        }
                    }
                }
                InvalidacaoCache::esquecer(ChaveCache::empresa($id, 'logistica', 'catalogo_produtos'));   // agora e depois do commit (R2)
                $total = array_sum($alterados);
                $this->auditoria->registar('Contabilidade/Plano de contas', 'Substituir conta', "{$nome}: conta {$origem} substituída por {$destino} em {$total} registo(s) de configuração e fichas"
                    .' (lançamentos e documentos contabilizados não alterados).', 'plano_contas', $contas['destino']['id'], ['conta' => $origem, 'registos' => $ids], ['conta' => $destino, 'registos' => $ids],
                    empresaId: $id);
                $r[] = ['empresa_id' => $id, 'empresa' => $nome, 'alterados' => $alterados, 'total' => $total];
            }

            return $r;
        });
    }

    /** @return array{0: string, 1: string} */
    private function normalizar(string $origem, string $destino): array
    {
        $origem = trim($origem);
        $destino = trim($destino);
        if ($origem === '' || $destino === '') {
            throw new ErroNegocio('Indique a conta de origem e a conta de destino.', 'VALIDACAO', 422);
        }
        if ($origem === $destino) {
            throw new ErroNegocio('A conta de destino tem de ser diferente da de origem.', 'VALIDACAO', 422);
        }

        return [$origem, $destino];
    }

    /**
     * @param  list<int>  $empresas
     * @return array<int, string> id => nome
     */
    private function empresasPermitidas(array $empresas, Utilizador $actor): array
    {
        $r = [];
        foreach (array_unique(array_map('intval', $empresas)) as $id) {
            if (! $this->empresas->podeAceder($actor, $id)) {
                throw new ErroNegocio("Não tem acesso à empresa #{$id}.", 'EMPRESA_SEM_ACESSO', 403);
            }
            $r[$id] = (string) DB::table('empresas')->where('id', $id)->value('nome');
        }
        if (! $r) {
            throw new ErroNegocio('Escolha pelo menos uma empresa.', 'VALIDACAO', 422);
        }

        return $r;
    }

    /** @return array{origem: ?array, destino: ?array, erro: ?string} */
    private function contas(int $empresa, string $origem, string $destino, bool $bloquear): array
    {
        $q = fn (string $codigo) => DB::table('plano_contas')->where('empresa_id', $empresa)->where('codigo', $codigo)->whereNull('eliminado_em')
            ->when($bloquear, fn ($w) => $w->lockForUpdate())->first(['id', 'codigo', 'descricao', 'tipo']);
        $o = $q($origem);
        $d = $q($destino);
        $apresentar = fn ($c) => $c ? ['id' => (int) $c->id, 'codigo' => $c->codigo, 'descricao' => $c->descricao, 'tipo' => $c->tipo ?? PlanoConta::TIPO_MOVIMENTO] : null;
        $erro = match (true) {
            ! $o => "A conta de origem {$origem} não existe no plano desta empresa.",
            ($o->tipo ?? 'M') === PlanoConta::TIPO_TOTALIZADORA => "A conta de origem {$origem} é totalizadora.",
            ! $d => "A conta de destino {$destino} não existe no plano desta empresa: crie-a primeiro como conta de movimento.",
            ($d->tipo ?? 'M') === PlanoConta::TIPO_TOTALIZADORA => "A conta de destino {$destino} é totalizadora.",
            default => null,
        };

        return ['origem' => $apresentar($o), 'destino' => $apresentar($d), 'erro' => $erro];
    }

    /**
     * @param  array<string, array>  $definicoes
     * @return list<array{tabela: string, coluna: string, rotulo: string, registos: int}>
     */
    private function contar(int $empresa, string $origem, array $definicoes, bool $comJson): array
    {
        $r = [];
        foreach ($definicoes as $tabela => [$colunas, $rotulo, $condicao]) {
            foreach ($this->colunasExistentes($tabela, $colunas) as $coluna) {
                $n = DB::table($tabela)->where('empresa_id', $empresa)->where($coluna, $origem)->when($condicao, fn ($w) => $w->whereRaw($condicao))->count();
                if ($n) {
                    $r[] = ['tabela' => $tabela, 'coluna' => $coluna, 'rotulo' => $rotulo, 'registos' => $n];
                }
            }
        }
        if ($comJson) {
            foreach (self::ALTERAVEIS_JSON as $tabela => [$coluna, $rotulo]) {
                $n = DB::table($tabela)->where('empresa_id', $empresa)
                    ->whereRaw("jsonb_typeof({$coluna}) = 'object' AND EXISTS (SELECT 1 FROM jsonb_each_text({$coluna}) e WHERE trim(e.value) = ?)", [$origem])->count();
                if ($n) {
                    $r[] = ['tabela' => $tabela, 'coluna' => $coluna, 'rotulo' => $rotulo, 'registos' => $n];
                }
            }
        }

        return $r;
    }

    /**
     * @param  list<string>  $colunas
     * @return list<string>
     */
    private function colunasExistentes(string $tabela, array $colunas): array
    {
        return array_values(array_filter($colunas, fn ($c) => Schema::hasColumn($tabela, $c)));
    }
}
