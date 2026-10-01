<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\Utilizador;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Throwable;

/**
 * Cópias de segurança por empresa e clonagem (config_geral: exportCompanyDatabase / importCompanyDatabase,
 * js/company_backup.js:2-240; "Importação de Empresa (Clone)", js/app_v2.js:8170-8178).
 *
 * Paridade:
 *  - exportar = todos os dados de UMA empresa num ficheiro JSON (formato próprio, versionado);
 *  - importar = cria uma cópia independente, com ids novos e todas as referências internas remapeadas, sem tocar nos
 *    dados existentes (Opção B do legado), com o nome "<nome> (Restauro <data>)".
 *
 * Correcções face ao legado:
 *  - o legado exportava 50 tabelas escolhidas à mão (faltavam IVA, contratos de fornecedores, POS, projectos, CRM,
 *    orçamento…) e remapeava só ~30 relações; agora as tabelas são TODAS as que têm empresa_id e as relações são as
 *    chaves estrangeiras reais do PostgreSQL (mais as referências conhecidas dentro de JSON: infotipos, colaboradores,
 *    produtos, vendas, meios de pagamento, diários). A carga é transaccional com as FK diferidas: ou entra tudo,
 *    consistente, ou nada;
 *  - "Restaurar na empresa actual" (apagar e repor, restoreCompanyDatabase) NÃO existe: a importação vai sempre para
 *    uma empresa NOVA ou completamente VAZIA (Manutenção de dados: RESTAURAR_EMPRESA → substituída);
 *  - o ficheiro indica a versão do esquema; um ficheiro de outra versão é recusado (evita cargas incoerentes);
 *  - ficam de fora a auditoria, os pedidos de manutenção, as ligações utilizador↔empresa (os utilizadores são
 *    globais) e a consolidação (grupos/membros/execuções ligam várias empresas);
 *  - clonar = só a ESTRUTURA (plano de contas, diários, notas, centros de custo, unidades de negócio, configurações,
 *    rubricas, cargos, bancos, meios de pagamento, categorias, armazéns, modelos…), sem movimentos; referências a
 *    registos não copiados (ex.: gerente de uma unidade) ficam vazias.
 */
final class ServicoCopiaEmpresa
{
    public const FORMATO = 'erp_copia_empresa';

    public const VERSAO = 1;

    /** Tabelas com empresa_id que não entram nas cópias. */
    public const EXCLUIDAS = ['logs_auditoria', 'pedidos_manutencao_equipamentos', 'utilizador_empresa', 'grupos_consolidacao', 'membros_consolidacao', 'execucoes_consolidacao'];

    /** Estrutura copiada pelo clone (sem movimentos, sem documentos fiscais, sem séries nem numeração). */
    public const ESTRUTURA = ['plano_contas', 'diarios_contabeis', 'notas_demonstracao_resultados', 'notas_fluxo_caixa', 'centros_custo', 'unidades_negocio',
        'tipos_documento', 'configuracoes_acrescimos_diferimentos', 'configuracoes_assiduidade', 'configuracoes_contabeis_compras', 'configuracoes_contabeis_logistica',
        'configuracoes_contabeis_tesouraria', 'configuracoes_contabeis_vendas', 'configuracoes_crm', 'configuracoes_deliberacao_compras', 'configuracoes_lavandaria',
        'configuracoes_pos', 'configuracoes_projetos', 'infotipos_salariais', 'tipos_organizacao_rh', 'cargos_funcoes', 'bancos', 'meios_pagamento', 'categorias_produtos',
        'categorias_ativos', 'rubricas_orcamentais', 'mapeamentos_contabeis_rh', 'mapeamentos_contabeis_sistema_rh', 'regras_internas_ia', 'modelos_documentos_rh',
        'modelos_email_crm', 'funis_vendas_crm', 'criterios_avaliacao_rh', 'armazens'];

    /** Referências a ids dentro de colunas JSON (chave => tabela). */
    private const CHAVES_JSON = [
        'infotype_id' => 'infotipos_salariais', 'infotipo_id' => 'infotipos_salariais',
        'employee_id' => 'colaboradores', 'colaborador_id' => 'colaboradores', 'chefia_id' => 'colaboradores', 'aprovador_employee_id' => 'colaboradores',
        'pares' => 'colaboradores', 'subordinados' => 'colaboradores',
        'product_id' => 'produtos', 'produto_id' => 'produtos', 'venda_id' => 'vendas', 'meio_id' => 'meios_pagamento', 'diario_id' => 'diarios_contabeis',
    ];

    /** Marca de uma referência polimórfica de tipo desconhecido (fica vazia). */
    private const TIPO_DESCONHECIDO = '?';

    /** Referências sem chave estrangeira na base (tabela => coluna => tabela referenciada). */
    private const REFERENCIAS_SEM_FK = [
        'resultados_folha_salarial' => ['centro_custo_id' => 'centros_custo', 'colaborador_id' => 'colaboradores', 'periodo_processamento_salarial_id' => 'periodos_processamento_salarial',
            'tipo_organizacao_id' => 'tipos_organizacao_rh', 'unidade_negocio_id' => 'unidades_negocio'],
        'lancamentos_contabeis' => ['periodo_id' => 'periodos_processamento_salarial', 'linha_origem_id' => 'lancamentos_contabeis'],
        'lancamentos_estornados' => ['periodo_id' => 'periodos_processamento_salarial', 'lancamento_original_id' => 'lancamentos_contabeis'],
        'tarefas_projeto' => ['atribuido_a_id' => 'membros_equipa_projeto'],
        'sessoes_pos' => ['operador_id' => 'utilizadores'],
    ];

    /** Referências polimórficas: coluna => [coluna discriminadora, valor => tabela]; tipos desconhecidos ficam vazios. */
    private const REFERENCIAS_POLIMORFICAS = [
        'lancamentos_contabeis' => ['documento_origem_id' => ['tipo_documento_origem', ['FATURA_COMPRA' => 'faturas_compra', 'RECECAO_COMPRA' => 'rececoes_compra']]],
        'lancamentos_estornados' => ['documento_origem_id' => ['tipo_documento_origem', ['FATURA_COMPRA' => 'faturas_compra', 'RECECAO_COMPRA' => 'rececoes_compra']]],
        'movimentos_inventario' => ['documento_id' => ['documento_tipo', ['RECECAO' => 'rececoes_compra', 'GUIA_SAIDA' => 'guias_saida', 'INVENTARIO' => 'sessoes_inventario',
            'INVENTARIO_ANULACAO' => 'sessoes_inventario', 'VENDA' => 'vendas']]],
        'movimentos_caixa' => ['origem_id' => ['tipo_origem', ['FATURA_COMPRA' => 'faturas_compra', 'POS' => 'liquidacoes_pos']]],
    ];

    private const CAMPOS_EMPRESA = ['nome', 'nif', 'endereco', 'provincia', 'municipio', 'comuna', 'telefone', 'email', 'website', 'numero_registo_comercial',
        'rodape_documento', 'logotipo', 'taxa_inss_patronal', 'taxa_inss_trabalhador', 'regras_ia', 'e_consolidacao', 'moeda_consolidacao',
        'he_percentagem_1', 'he_limite_horas', 'he_percentagem_2', 'moeda_funcional'];

    /** @var array<string, array<string, array{tipo: string, nulo: bool}>> */
    private array $colunas = [];

    /** @var array<string, array<string, string>> tabela => coluna => tabela referenciada */
    private array $fks = [];

    /** @var array<string, array<int, true>> existência de registos de tabelas globais */
    private array $existentes = [];

    public function __construct(
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * Escreve a cópia da empresa em $caminho (JSON por streaming).
     *
     * @return array{tabelas: int, linhas: int, por_tabela: array<string, int>}
     */
    public function exportar(int $empresaId, string $caminho): array
    {
        $empresa = Empresa::query()->findOrFail($empresaId);
        $f = fopen($caminho, 'wb') ?: throw new ErroNegocio('Não foi possível criar o ficheiro da cópia.', 'COPIA_ESCRITA', 500);
        $json = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $porTabela = [];
        try {
            fwrite($f, '{"formato":'.$json(self::FORMATO).',"versao":'.self::VERSAO.',"esquema":'.$json($this->versaoEsquema())
                .',"exportado_em":'.$json(now()->toAtomString()).',"empresa_id_origem":'.$empresaId
                .',"empresa":'.$json(array_intersect_key($empresa->getAttributes(), array_flip(self::CAMPOS_EMPRESA))).',"tabelas":[');
            $primeiraTabela = true;
            foreach ($this->tabelasEmpresa() as $tabela) {
                fwrite($f, ($primeiraTabela ? '' : ',').'{"tabela":'.$json($tabela).',"linhas":[');
                $primeiraTabela = false;
                $n = 0;
                foreach ($this->linhasDaBase($tabela, $empresaId) as $linha) {
                    fwrite($f, ($n ? ',' : '').$json($this->decodificarJson($tabela, $linha)));
                    $n++;
                }
                fwrite($f, ']}');
                $porTabela[$tabela] = $n;
            }
            fwrite($f, '],"resumo":'.$json(['tabelas' => count($porTabela), 'linhas' => array_sum($porTabela)]).'}');
        } finally {
            fclose($f);
        }
        $this->auditoria->registar('Sistema/Cópias de segurança', 'Exportar empresa', "Cópia da empresa «{$empresa->nome}»: ".array_sum($porTabela).' linha(s) em '
            .count(array_filter($porTabela)).' tabela(s) com dados.', 'empresas', $empresaId, null, ['por_tabela' => array_filter($porTabela)], empresaId: $empresaId);

        return ['tabelas' => count($porTabela), 'linhas' => array_sum($porTabela), 'por_tabela' => array_filter($porTabela)];
    }

    /**
     * Importa uma cópia para uma empresa NOVA (dados de $novaEmpresa sobre os do ficheiro) ou para $destinoId, se
     * estiver completamente vazia. Com $simular só valida e conta.
     *
     * @param  array{nome?: ?string, nif?: ?string}  $novaEmpresa
     * @return array<string, mixed>
     */
    public function importar(string $caminho, ?int $destinoId, array $novaEmpresa, Utilizador $actor, bool $simular): array
    {
        $cabecalho = $this->lerCabecalho($caminho);
        $nomes = [];
        $itens = Items::fromFile($caminho, ['pointer' => '/tabelas/-/tabela']);
        foreach ($itens as $nome) {
            $nomes[$this->indice($itens->getCurrentJsonPointer())] = (string) $nome;
        }
        $permitidas = $this->tabelasEmpresa();
        $desconhecidas = array_values(array_diff($nomes, $permitidas));
        if ($desconhecidas) {
            throw new ErroNegocio('A cópia tem tabelas que não existem neste sistema: '.implode(', ', $desconhecidas).'.', 'COPIA_INCOMPATIVEL', 422);
        }
        $ids = [];
        $contagens = array_fill_keys($nomes, 0);
        foreach ($this->linhasDoFicheiro($caminho, $nomes) as [$tabela, $linha]) {
            $contagens[$tabela]++;
            if (isset($linha['id'])) {
                $ids[$tabela][] = (int) $linha['id'];
            }
            if ((int) ($linha['empresa_id'] ?? 0) !== (int) $cabecalho['empresa_id_origem']) {
                throw new ErroNegocio("A cópia tem linhas de outra empresa em {$tabela}.", 'COPIA_INVALIDA', 422);
            }
        }
        $dadosEmpresa = $this->dadosNovaEmpresa($cabecalho['empresa'], $novaEmpresa, $destinoId);
        $resumo = ['empresa_origem' => ['id' => $cabecalho['empresa_id_origem'], 'nome' => $cabecalho['empresa']['nome'] ?? null],
            'exportado_em' => $cabecalho['exportado_em'], 'linhas' => array_sum($contagens), 'por_tabela' => array_filter($contagens)];
        if ($destinoId) {
            $this->exigirDestinoVazio($destinoId, $actor);
        }
        if ($simular) {
            return $resumo + ['empresa_destino' => $destinoId ? ['id' => $destinoId] : ['nome' => $dadosEmpresa['nome'], 'nif' => $dadosEmpresa['nif']]];
        }

        return DB::transaction(function () use ($caminho, $nomes, $ids, $cabecalho, $dadosEmpresa, $destinoId, $actor, $resumo) {
            $destino = $destinoId ? Empresa::query()->lockForUpdate()->findOrFail($destinoId) : $this->criarEmpresa($dadosEmpresa, $actor);
            $r = $this->carregar(fn () => $this->linhasDoFicheiro($caminho, $nomes), $ids, (int) $cabecalho['empresa_id_origem'], $destino->id, array_values($nomes), false);
            $this->auditoria->registar('Sistema/Cópias de segurança', 'Importar cópia de empresa', 'Cópia de «'.($cabecalho['empresa']['nome'] ?? '?')."» (exportada em {$cabecalho['exportado_em']}) "
                ."importada para a empresa #{$destino->id} «{$destino->nome}»: {$r['linhas']} linha(s).", 'empresas', $destino->id, null, ['por_tabela' => $r['por_tabela']], empresaId: $destino->id);

            return $resumo + ['empresa_destino' => ['id' => $destino->id, 'nome' => $destino->nome], 'importadas' => $r['linhas'], 'nulificadas' => $r['nulificadas']];
        });
    }

    /**
     * Clona a estrutura da empresa $origemId numa empresa nova.
     *
     * @param  array{nome: string, nif: string}  $novaEmpresa
     * @return array<string, mixed>
     */
    public function clonar(int $origemId, array $novaEmpresa, Utilizador $actor, bool $simular): array
    {
        $origem = Empresa::query()->findOrFail($origemId);
        $tabelas = array_values(array_intersect(self::ESTRUTURA, $this->tabelasEmpresa()));
        $ids = [];
        $contagens = [];
        foreach ($tabelas as $t) {
            $contagens[$t] = DB::table($t)->where('empresa_id', $origemId)->count();
            if ($this->temId($t)) {
                $ids[$t] = DB::table($t)->where('empresa_id', $origemId)->orderBy('id')->pluck('id')->map(fn ($x) => (int) $x)->all();
            }
        }
        $dados = $this->dadosNovaEmpresa(array_intersect_key($origem->getAttributes(), array_flip(self::CAMPOS_EMPRESA)) + ['nome' => $origem->nome], $novaEmpresa, null);
        $dados['e_consolidacao'] = false;
        $resumo = ['empresa_origem' => ['id' => $origem->id, 'nome' => $origem->nome], 'linhas' => array_sum($contagens), 'por_tabela' => array_filter($contagens)];
        if ($simular) {
            return $resumo + ['empresa_destino' => ['nome' => $dados['nome'], 'nif' => $dados['nif']]];
        }

        return DB::transaction(function () use ($tabelas, $ids, $origem, $dados, $actor, $resumo) {
            $destino = $this->criarEmpresa($dados, $actor);
            $r = $this->carregar(function () use ($tabelas, $origem) {
                foreach ($tabelas as $t) {
                    foreach ($this->linhasDaBase($t, $origem->id) as $linha) {
                        yield [$t, $this->decodificarJson($t, $linha)];
                    }
                }
            }, $ids, $origem->id, $destino->id, $tabelas, true);
            $this->auditoria->registar('Sistema/Cópias de segurança', 'Clonar empresa', "Estrutura de «{$origem->nome}» clonada para a empresa #{$destino->id} «{$destino->nome}»: {$r['linhas']} linha(s).",
                'empresas', $destino->id, null, ['origem' => $origem->id, 'por_tabela' => $r['por_tabela']], empresaId: $destino->id);

            return $resumo + ['empresa_destino' => ['id' => $destino->id, 'nome' => $destino->nome], 'importadas' => $r['linhas'], 'ignoradas' => $r['ignoradas'],
                'nulificadas' => $r['nulificadas']];
        });
    }

    /** @return list<string> tabelas com empresa_id (sem partições) que entram nas cópias */
    public function tabelasEmpresa(): array
    {
        return Cache::remember('copia_empresa:tabelas:'.$this->versaoEsquema(), 3600, fn () => array_values(array_diff(array_map(fn ($r) => $r->tabela, DB::select(<<<'SQL'
            SELECT c.relname AS tabela FROM pg_class c
              JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'empresa_id' AND NOT a.attisdropped
             WHERE c.relnamespace = 'public'::regnamespace AND c.relkind IN ('r', 'p') AND NOT c.relispartition
             ORDER BY 1
            SQL)), self::EXCLUIDAS)));
    }

    // ───────────── carga (comum à importação e ao clone) ─────────────

    /**
     * @param  callable(): iterable<array{0: string, 1: array<string, mixed>}>  $linhas  pode ser percorrido de novo
     * @param  array<string, list<int>>  $idsAntigos
     * @param  list<string>  $tabelas  tabelas copiadas (as outras tabelas da empresa não são copiadas)
     * @return array{linhas: int, por_tabela: array<string, int>, ignoradas: int, nulificadas: array<string, int>}
     */
    private function carregar(callable $linhas, array $idsAntigos, int $origem, int $destino, array $tabelas, bool $tolerante): array
    {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $mapa = [];
        foreach ($idsAntigos as $t => $lista) {
            $lista = array_values(array_unique($lista));
            if (! $lista) {
                continue;
            }
            $novos = array_map(fn ($r) => (int) $r->id, DB::select('SELECT nextval(pg_get_serial_sequence(?, \'id\')) AS id FROM generate_series(1, ?)', [$t, count($lista)]));
            $mapa[$t] = array_combine($lista, $novos);
        }
        $daEmpresa = array_flip($this->tabelasEmpresa());
        $copiadas = array_flip($tabelas);
        $r = ['linhas' => 0, 'por_tabela' => [], 'ignoradas' => 0, 'nulificadas' => []];
        $lote = [];
        $tabelaLote = null;
        $gravar = function () use (&$lote, &$tabelaLote, &$r) {
            if ($lote) {
                DB::table($tabelaLote)->insert($lote);
                $r['linhas'] += count($lote);
                $r['por_tabela'][$tabelaLote] = ($r['por_tabela'][$tabelaLote] ?? 0) + count($lote);
                $lote = [];
            }
        };
        foreach ($linhas() as [$tabela, $linha]) {
            if ($tabela !== $tabelaLote || count($lote) >= 500) {
                $gravar();
                $tabelaLote = $tabela;
            }
            $nova = $this->transformar($tabela, $linha, $mapa, $origem, $destino, $daEmpresa, $copiadas, $tolerante, $r['nulificadas']);
            if ($nova === null) {
                $r['ignoradas']++;

                continue;
            }
            $lote[] = $nova;
        }
        $gravar();
        try {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } catch (Throwable $e) {
            throw new ErroNegocio('A cópia tem referências inconsistentes e não foi importada: '.mb_substr($e->getMessage(), 0, 400), 'COPIA_INCONSISTENTE', 422);
        }

        return $r;
    }

    /**
     * @param  array<string, array<int, int>>  $mapa
     * @param  array<string, int>  $nulificadas  contagem "tabela.coluna" => n (por referência)
     * @return array<string, mixed>|null linha a inserir (null = ignorada no clone)
     */
    private function transformar(string $tabela, array $linha, array $mapa, int $origem, int $destino, array $daEmpresa, array $copiadas, bool $tolerante, array &$nulificadas): ?array
    {
        $colunas = $this->colunas($tabela);
        $fks = $this->fks($tabela) + (self::REFERENCIAS_SEM_FK[$tabela] ?? []);
        foreach (self::REFERENCIAS_POLIMORFICAS[$tabela] ?? [] as $coluna => [$discriminador, $tabelas]) {
            if (($linha[$coluna] ?? null) !== null) {
                $fks[$coluna] = $tabelas[(string) ($linha[$discriminador] ?? '')] ?? self::TIPO_DESCONHECIDO;
            }
        }
        $nova = [];
        foreach ($colunas as $coluna => $info) {
            if (! array_key_exists($coluna, $linha)) {
                continue;
            }
            $v = $linha[$coluna];
            if ($coluna === 'id' && $v !== null && isset($mapa[$tabela])) {
                $v = $mapa[$tabela][(int) $v];
            } elseif ($coluna === 'empresa_id') {
                $v = $destino;
            } elseif ($v !== null && isset($fks[$coluna])) {
                $ref = $fks[$coluna];
                if ($ref === self::TIPO_DESCONHECIDO) {
                    $nulificadas["{$tabela}.{$coluna}"] = ($nulificadas["{$tabela}.{$coluna}"] ?? 0) + 1;
                    $nova[$coluna] = null;

                    continue;
                }
                $resolvido = match (true) {
                    $ref === 'empresas' => (int) $v === $origem ? $destino : ($this->existe('empresas', (int) $v) ? (int) $v : null),
                    isset($copiadas[$ref]) => $mapa[$ref][(int) $v] ?? null,
                    isset($daEmpresa[$ref]), in_array($ref, self::EXCLUIDAS, true) => null,
                    default => $this->existe($ref, (int) $v) ? (int) $v : null,
                };
                if ($resolvido === null) {
                    if (! $info['nulo']) {
                        if ($tolerante) {
                            return null;
                        }
                        throw new ErroNegocio("Referência impossível de resolver: {$tabela}.{$coluna} = {$v} ({$ref}).", 'COPIA_INCONSISTENTE', 422);
                    }
                    $nulificadas["{$tabela}.{$coluna}"] = ($nulificadas["{$tabela}.{$coluna}"] ?? 0) + 1;
                }
                $v = $resolvido;
            }
            if ($info['tipo'] === 'jsonb' || $info['tipo'] === 'json') {
                $v = $v === null ? null : json_encode($this->remapearJson($v, $mapa, $copiadas), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            }
            $nova[$coluna] = $v;
        }

        return $nova;
    }

    private function remapearJson(mixed $v, array $mapa, array $copiadas, ?string $chave = null): mixed
    {
        if (is_array($v)) {
            $lista = array_is_list($v);
            foreach ($v as $k => $x) {
                $v[$k] = $this->remapearJson($x, $mapa, $copiadas, $lista ? $chave : (string) $k);
            }

            return $v;
        }
        if ($chave !== null && isset(self::CHAVES_JSON[$chave]) && (is_int($v) || (is_string($v) && ctype_digit($v)))) {
            $ref = self::CHAVES_JSON[$chave];
            if (! isset($copiadas[$ref])) {
                return null;
            }
            $novo = $mapa[$ref][(int) $v] ?? null;

            return $novo === null ? null : (is_string($v) ? (string) $novo : $novo);
        }

        return $v;
    }

    // ───────────── leitura ─────────────

    /** @return Generator<int, array<string, mixed>> */
    private function linhasDaBase(string $tabela, int $empresaId): Generator
    {
        $q = DB::table($tabela)->where('empresa_id', $empresaId);
        $iteravel = $this->temId($tabela) ? $q->orderBy('id')->lazyById(1000) : $q->cursor();
        foreach ($iteravel as $linha) {
            yield (array) $linha;
        }
    }

    /**
     * @param  array<int, string>  $nomes  índice no ficheiro => tabela
     * @return Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    private function linhasDoFicheiro(string $caminho, array $nomes): Generator
    {
        $itens = Items::fromFile($caminho, ['pointer' => '/tabelas/-/linhas', 'decoder' => new ExtJsonDecoder(true)]);
        foreach ($itens as $linha) {
            yield [$nomes[$this->indice($itens->getCurrentJsonPointer())], $linha];
        }
    }

    /** @return array{formato: string, versao: int, esquema: string, exportado_em: string, empresa_id_origem: int, empresa: array<string, mixed>} */
    private function lerCabecalho(string $caminho): array
    {
        $c = [];
        foreach (['formato', 'versao', 'esquema', 'exportado_em', 'empresa_id_origem'] as $campo) {
            foreach (Items::fromFile($caminho, ['pointer' => "/{$campo}", 'decoder' => new ExtJsonDecoder(true)]) as $valor) {
                $c[$campo] = $valor;
                break;
            }
        }
        // um ponteiro para um objecto percorre os seus membros
        foreach (Items::fromFile($caminho, ['pointer' => '/empresa', 'decoder' => new ExtJsonDecoder(true)]) as $chave => $valor) {
            $c['empresa'][$chave] = $valor;
        }
        if (($c['formato'] ?? null) !== self::FORMATO) {
            throw new ErroNegocio('Ficheiro inválido: não é uma cópia de segurança de empresa deste sistema.', 'COPIA_INVALIDA', 422);
        }
        if ((int) ($c['versao'] ?? 0) !== self::VERSAO || ($c['esquema'] ?? null) !== $this->versaoEsquema()) {
            throw new ErroNegocio('A cópia foi feita noutra versão do sistema ('.($c['esquema'] ?? '?').'); actualize/converta antes de importar.', 'COPIA_INCOMPATIVEL', 422,
                ['esquema_ficheiro' => $c['esquema'] ?? null, 'esquema_sistema' => $this->versaoEsquema()]);
        }
        if (! isset($c['empresa_id_origem'], $c['empresa']) || ! is_array($c['empresa'])) {
            throw new ErroNegocio('Ficheiro inválido: falta a empresa de origem.', 'COPIA_INVALIDA', 422);
        }

        return $c;
    }

    private function indice(string $ponteiro): int
    {
        return (int) explode('/', $ponteiro)[2];
    }

    // ───────────── metadados ─────────────

    /** @return array<string, array{tipo: string, nulo: bool}> */
    private function colunas(string $tabela): array
    {
        return $this->colunas[$tabela] ??= collect(DB::select(
            "SELECT column_name AS c, data_type AS t, is_nullable = 'YES' AS n FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position",
            [$tabela]))->mapWithKeys(fn ($r) => [$r->c => ['tipo' => $r->t, 'nulo' => (bool) $r->n]])->all();
    }

    /** @return array<string, string> */
    private function fks(string $tabela): array
    {
        return $this->fks[$tabela] ??= collect(DB::select(<<<'SQL'
            SELECT a.attname AS coluna, ref.relname AS referenciada FROM pg_constraint c
              JOIN pg_class cl ON cl.oid = c.conrelid JOIN pg_class ref ON ref.oid = c.confrelid
              JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
             WHERE c.contype = 'f' AND cl.relname = ? AND array_length(c.conkey, 1) = 1
            SQL, [$tabela]))->mapWithKeys(fn ($r) => [$r->coluna => $r->referenciada])->all();
    }

    private function temId(string $tabela): bool
    {
        return isset($this->colunas($tabela)['id']);
    }

    private function existe(string $tabela, int $id): bool
    {
        if (! isset($this->existentes[$tabela][$id])) {
            $this->existentes[$tabela][$id] = DB::table($tabela)->where('id', $id)->exists();
        }

        return $this->existentes[$tabela][$id];
    }

    /** @param  array<string, mixed>  $linha */
    private function decodificarJson(string $tabela, array $linha): array
    {
        foreach ($this->colunas($tabela) as $coluna => $info) {
            if (($info['tipo'] === 'jsonb' || $info['tipo'] === 'json') && is_string($linha[$coluna] ?? null)) {
                $linha[$coluna] = json_decode($linha[$coluna], true);
            }
        }

        return $linha;
    }

    private function versaoEsquema(): string
    {
        return (string) DB::table(config('database.migrations.table', 'migrations'))->max('migration');
    }

    // ───────────── empresa de destino ─────────────

    /**
     * @param  array<string, mixed>  $doFicheiro
     * @param  array{nome?: ?string, nif?: ?string}  $pedido
     */
    private function dadosNovaEmpresa(array $doFicheiro, array $pedido, ?int $destinoId): array
    {
        if ($destinoId) {
            return [];
        }
        $dados = array_intersect_key($doFicheiro, array_flip(self::CAMPOS_EMPRESA));
        $dados['nome'] = trim((string) (($pedido['nome'] ?? null) ?: ($doFicheiro['nome'] ?? 'Empresa').' (Restauro '.now()->toDateString().')'));
        $dados['nif'] = trim((string) (($pedido['nif'] ?? null) ?: ($doFicheiro['nif'] ?? '')));
        if ($dados['nif'] === '') {
            throw new ErroNegocio('Indique o NIF da nova empresa.', 'NIF_OBRIGATORIO', 422);
        }
        if (Empresa::query()->where('nif', $dados['nif'])->exists()) {
            throw new ErroNegocio("Já existe uma empresa activa com o NIF {$dados['nif']}: indique outro NIF para a nova empresa.", 'NIF_DUPLICADO', 422);
        }

        return $dados;
    }

    private function criarEmpresa(array $dados, Utilizador $actor): Empresa
    {
        $e = Empresa::create($dados + ['estado' => Empresa::ESTADO_ATIVO]);
        if (! $this->permissoes->total($actor) && ! $actor->acesso_todas_empresas) {
            $actor->empresas()->syncWithoutDetaching([$e->id]);
            $this->empresas->invalidarUtilizador($actor->id);
        }

        return $e;
    }

    private function exigirDestinoVazio(int $destinoId, Utilizador $actor): void
    {
        $e = Empresa::query()->find($destinoId);
        if (! $e || ! ($this->permissoes->total($actor) || $actor->acesso_todas_empresas || $actor->empresas()->where('empresas.id', $destinoId)->exists())) {
            throw new ErroNegocio('Empresa de destino não encontrada.', 'NAO_ENCONTRADO', 404);
        }
        $comDados = [];
        foreach ($this->tabelasEmpresa() as $t) {
            if (DB::table($t)->where('empresa_id', $destinoId)->exists()) {
                $comDados[] = $t;
            }
        }
        if ($comDados) {
            throw new ErroNegocio('A empresa de destino não está vazia: a importação nunca se faz por cima de dados existentes. Importe para uma empresa nova.',
                'DESTINO_COM_DADOS', 422, ['tabelas' => $comDados]);
        }
    }
}
