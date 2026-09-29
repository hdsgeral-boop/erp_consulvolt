<?php

namespace App\Services\Migracao;

use App\Exceptions\ErroNegocio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * ETL do backup Dexie do ERP legado para o esquema PostgreSQL em português.
 *
 *   1. Extracção  — streaming do JSON para etl_linhas_legado (linhas fictícias "dado mestre" descartadas).
 *   2. Carga      — tabela a tabela, por ordem de dependências, numa única transacção com as FKs adiadas:
 *                   conversão de tipos, normalizações (código + *_original), regras de integridade
 *                   (ferramentas/levantamento/regras_integridade.mjs), empresa derivada, polimórficos, pivôs.
 *   3. Validação  — contagens, órfãos por FK, D−C por empresa; sequences recalibradas; COMMIT
 *                   (ou ROLLBACK em simulação). O COMMIT falha se sobrar qualquer órfão (FKs DEFERRABLE).
 *
 * Contratos: database/legado/esquema.json (destino) e database/legado/mapa_de_para.json (normalizações).
 */
final class ServicoMigracaoLegado
{
    private const EMPRESAS_REAIS_COM_FLAG_MESTRE = [10, 18];

    private const TABELAS_FASE1 = ['companies' => 'empresas', 'user_profiles' => 'perfis_utilizador', 'users' => 'utilizadores', 'audit_logs' => 'logs_auditoria'];

    /** Campos gravados apenas nas linhas de recepção de compra (moedas_compras.js) — ADR-020. */
    private const CAMPOS_RECECAO_COMPRA = ['fx_q1', 'fx_v1', 'fx_q2', 'fx_v2', 'value_kz', 'unit_cost_kz'];

    private string $execucao;

    private RegistoOcorrencias $registo;

    private ConversorTipos $conversor;

    /** @var array<string, array<string, mixed>> tabela destino => definição do contrato */
    private array $esquema = [];

    /** @var array<string, array<string, array<string, string>>> tabela destino => coluna => mapa de normalização */
    private array $normalizacoes = [];

    /** @var array<string, array<int, true>> tabela legado => ids presentes no backup (candidatos) */
    private array $candidatos = [];

    /** @var array<string, array<int, int|null>> tabela destino => [id => empresa_id] das linhas carregadas */
    private array $validos = [];

    /** @var array<string, array<int, true>> tabela destino => ids postos em quarentena */
    private array $emQuarentena = [];

    /** @var array<string, int> tabela legado => linhas reais lidas */
    private array $lidas = [];

    /** @var array<string, int> tabela destino => linhas inseridas */
    private array $inseridas = [];

    /** @var array<string, int> "tabela.coluna" => FKs "sem valor" (0, "", NaN) convertidas em NULL */
    private array $fksVazias = [];

    /** @var array<int, int> empresa_id => id do diário de recuperação criado */
    private array $diariosRecuperacao = [];

    private int $ficticias = 0;

    /** @var array<int, float> empresa_id => efeito líquido (D−C) do arredondamento a 2 casas nos lançamentos */
    private array $efeitoArredondamento = [];

    /** Último relatório calculado (guardado mesmo quando a validação falha). */
    private ?array $relatorio = null;

    /** Contexto da linha em processamento (para as ocorrências do conversor). */
    private array $linhaAtual = ['tabela' => '', 'id' => null, 'destino' => null, 'coluna' => null, 'empresa' => null];

    /** @var callable(string): void */
    private $progresso;

    public function __construct(private readonly string $caminho, ?callable $progresso = null)
    {
        $this->progresso = $progresso ?? fn (string $m) => null;
        $this->carregarContratos();
    }

    /**
     * @return array<string, mixed> relatório da execução
     */
    public function executar(bool $simulacao, bool $substituir, ?string $executadoPor = null): array
    {
        $leitor = new LeitorBackupDexie($this->caminho);
        $leitor->validarFormato();

        $this->execucao = now()->format('Ymd_His').'_'.Str::lower(Str::random(6));
        $this->registo = new RegistoOcorrencias($this->execucao);
        $this->conversor = new ConversorTipos(function (string $regra, string $gravidade, mixed $valor, ?string $final, string $descricao) {
            $l = $this->linhaAtual;
            $this->registo->ocorrencia($l['tabela'], $l['id'], $l['destino'], $l['coluna'], $regra, $gravidade, $valor, $final, $descricao, null, $l['empresa']);
        });

        $sha = hash_file('sha256', $this->caminho);
        DB::table('execucoes_migracao')->insert([
            'codigo' => $this->execucao, 'ficheiro' => $this->caminho, 'sha256' => $sha, 'tamanho_bytes' => filesize($this->caminho),
            'estado' => 'EM_CURSO', 'simulacao' => $simulacao, 'executado_por' => $executadoPor,
        ]);

        $inicio = microtime(true);
        DB::beginTransaction();
        try {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            $this->verificarDestino($substituir);

            $this->informar('1/3 Extracção (streaming) para a área de preparação…');
            $this->extrair($leitor);

            $this->informar('2/3 Transformação e carga…');
            $this->carregar();

            $this->informar('3/3 Validação…');
            $this->registo->descarregar();
            $relatorio = $this->relatorio = $this->validar();
            if ($relatorio['orfaos']) {
                throw new ErroNegocio('Órfãos por resolver: '.json_encode($relatorio['orfaos']).' — acrescentar a regra de integridade correspondente.', 'ETL_ORFAOS');
            }
            $this->recalibrarSequencias();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');   // força já a verificação das FKs adiadas
            DB::table('etl_linhas_legado')->where('execucao', $this->execucao)->delete();

            $relatorio['duracao_segundos'] = round(microtime(true) - $inicio, 1);
            $relatorio['execucao'] = $this->execucao;
            $relatorio['sha256'] = $sha;
            $relatorio['simulacao'] = $simulacao;

            if ($simulacao) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            DB::table('execucoes_migracao')->where('codigo', $this->execucao)
                ->update(['estado' => 'FALHADA', 'erro' => mb_substr($e->getMessage(), 0, 10000), 'concluido_em' => now(),
                    'relatorio' => $this->relatorio ? json_encode($this->relatorio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
            throw $e;
        }

        DB::table('execucoes_migracao')->where('codigo', $this->execucao)->update([
            'estado' => $simulacao ? 'SIMULADA' : 'CONCLUIDA', 'concluido_em' => now(),
            'linhas_lidas' => array_sum($this->lidas), 'linhas_ficticias' => $this->ficticias,
            'linhas_migradas' => array_sum($this->inseridas), 'linhas_quarentena' => $this->registo->totalQuarentena(),
            'relatorio' => json_encode($relatorio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $relatorio;
    }

    // ── contratos ────────────────────────────────────────────────────────────────

    private function carregarContratos(): void
    {
        $esquema = json_decode(file_get_contents(database_path('legado/esquema.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($esquema['tabelas'] as $t) {
            $t['colunas_por_nome'] = array_column($t['colunas'], null, 'coluna');
            $this->esquema[$t['tabela']] = $t;
        }
        $mapa = json_decode(file_get_contents(database_path('legado/mapa_de_para.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($mapa['tabelas'] as $t) {
            foreach ($t['colunas'] as $c) {
                if (! empty($c['normalizacao'])) {
                    $this->normalizacoes[$t['pt']][$c['pt']] = $c['normalizacao']['mapa'];
                }
            }
        }
    }

    private function verificarDestino(bool $substituir): void
    {
        $tabelas = array_merge(array_keys($this->esquema), ['empresas', 'perfis_utilizador', 'utilizadores', 'utilizador_empresa', 'tokens_acesso', 'logs_auditoria']);
        $temDados = DB::table('empresas')->exists() || DB::table('lancamentos_contabeis')->exists();

        if ($temDados && ! $substituir) {
            throw new ErroNegocio('A base de destino já tem dados. Use --substituir para apagar TODOS os dados de negócio e voltar a migrar.', 'DESTINO_COM_DADOS');
        }
        if ($substituir) {
            $lista = implode(', ', array_map(fn ($t) => "\"{$t}\"", array_diff($tabelas, ['ocorrencias_migracao', 'quarentena_migracao'])));
            DB::statement("TRUNCATE {$lista} RESTART IDENTITY CASCADE");
        }
    }

    // ── 1. extracção ────────────────────────────────────────────────────────────

    private function extrair(LeitorBackupDexie $leitor): void
    {
        $lote = [];
        foreach ($leitor->linhas($leitor->tabelas()) as [$tabela, $linha]) {
            if (($linha['is_master_data'] ?? 0) == 1 && ! ($tabela === 'companies' && in_array((int) ($linha['id'] ?? 0), self::EMPRESAS_REAIS_COM_FLAG_MESTRE, true))) {
                $this->ficticias++;

                continue;
            }
            $this->lidas[$tabela] = ($this->lidas[$tabela] ?? 0) + 1;
            $id = $this->conversorChave($linha['id'] ?? null);
            if ($id !== null) {
                $this->candidatos[$tabela][$id] = true;
            }
            $lote[] = ['execucao' => $this->execucao, 'tabela' => $tabela, 'id_legado' => isset($linha['id']) ? (string) $linha['id'] : null,
                'dados' => json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)];
            if (count($lote) >= 1000) {
                DB::table('etl_linhas_legado')->insert($lote);
                $lote = [];
            }
        }
        if ($lote) {
            DB::table('etl_linhas_legado')->insert($lote);
        }
        $this->informar(sprintf('   %s linhas reais em %d tabelas; %s linhas fictícias descartadas.',
            number_format(array_sum($this->lidas), 0, ',', ' '), count($this->lidas), number_format($this->ficticias, 0, ',', ' ')));
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function linhasDe(string $tabelaLegado): \Generator
    {
        $ultimo = 0;
        while (true) {
            $lote = DB::table('etl_linhas_legado')->where('execucao', $this->execucao)->where('tabela', $tabelaLegado)
                ->where('id', '>', $ultimo)->orderBy('id')->limit(2000)->get(['id', 'dados']);
            if ($lote->isEmpty()) {
                return;
            }
            foreach ($lote as $r) {
                $ultimo = $r->id;
                yield json_decode($r->dados, true);
            }
        }
    }

    // ── 2. carga ────────────────────────────────────────────────────────────────

    private function carregar(): void
    {
        $this->carregarEmpresas();
        $this->carregarPerfis();
        $this->carregarUtilizadores();

        foreach ($this->ordemTopologica() as $tabela) {
            $def = $this->esquema[$tabela];
            if ($def['legado'] === null || $def['pivo']) {
                continue;
            }
            $this->carregarTabela($def);
        }

        $this->validarChavesEmpresas();
        $this->carregarPivos();
        $this->carregarLinhasFaturasCompra();
        $this->carregarLigacoesUtilizadorEmpresa();
        $this->carregarAuditoria();
    }

    /**
     * As facturas de fornecedor do legado guardavam as linhas embutidas (purchase_invoices.items[]); o sistema novo
     * trabalha com linhas reais ligadas à linha da encomenda (anulação, contabilização, conta transitória).
     * Expande-as para itens_compra (tipo FATURA). O legado ligava por vezes à linha da PROPOSTA ("linhas virtuais",
     * ui_compras_v2.js:1491-1506): nesse caso liga-se à linha da encomenda da factura com o mesmo produto (ocorrência).
     */
    private function carregarLinhasFaturasCompra(): void
    {
        $proximo = (int) DB::table('itens_compra')->max('id');
        $lote = [];
        $comLinhas = DB::table('itens_compra')->whereNotNull('fatura_compra_id')->distinct()->pluck('fatura_compra_id')->flip();
        foreach (DB::table('faturas_compra')->whereNotNull('itens')->orderBy('id')->get() as $f) {
            $itens = json_decode((string) $f->itens, true);
            if (! is_array($itens) || ! $itens || isset($comLinhas[$f->id])) {
                continue;
            }
            $this->linhaAtual = ['tabela' => 'purchase_invoices', 'id' => (string) $f->id, 'destino' => 'itens_compra', 'coluna' => 'item_encomenda_id', 'empresa' => $f->empresa_id];
            $daEncomenda = $f->encomenda_compra_id ? DB::table('itens_compra')->where('encomenda_compra_id', $f->encomenda_compra_id)->orderBy('id')->get()->keyBy('id') : collect();
            $usados = [];
            foreach ($itens as $i) {
                $q = (string) ($i['quantity'] ?? 0);
                $p = (string) ($i['unit_price'] ?? 0);
                $t = (string) ($i['tax_rate'] ?? 0);
                $liq = isset($i['net_kz']) ? number_format((float) $i['net_kz'], 2, '.', '') : number_format(round((float) $q * (float) $p, 2), 2, '.', '');
                $iva = isset($i['tax_kz']) ? number_format((float) $i['tax_kz'], 2, '.', '') : number_format(round((float) $liq * (float) $t / 100, 2), 2, '.', '');
                $produto = $this->conversorChave($i['product_id'] ?? null);
                $produto = $produto !== null && array_key_exists($produto, $this->validos['produtos'] ?? []) ? $produto : null;

                $itemId = $this->conversorChave($i['item_id'] ?? null);
                $ligacao = $itemId !== null && $daEncomenda->has($itemId) ? $itemId : null;
                if ($ligacao === null && $f->encomenda_compra_id) {
                    $ligacao = $daEncomenda->first(fn ($l) => (int) $l->produto_id === (int) $produto && ! isset($usados[$l->id]))?->id;
                    $ligacao !== null
                        ? $this->informacao('itens_compra', 'item_encomenda_id', 'SEMANTICA', $itemId, (string) $ligacao,
                            "Linha da factura ligada no legado à linha #{$itemId} da proposta: ligada à linha #{$ligacao} da encomenda (mesmo produto)")
                        : $this->anularFk('itens_compra', 'item_encomenda_id', $itemId, $daEncomenda->isEmpty()
                            ? "A encomenda #{$f->encomenda_compra_id} não tem linhas no backup (apagadas no legado, ex.: clearAllTransactions apagava purchase_items de todas as empresas): linha da factura sem ligação"
                            : 'Linha da factura sem linha correspondente na encomenda');
                }
                if ($ligacao !== null) {
                    $usados[$ligacao] = true;
                }
                $lote[] = [
                    'id' => ++$proximo, 'empresa_id' => $f->empresa_id, 'tipo_documento_origem' => 'FATURA', 'tipo_documento_origem_original' => 'INVOICE (items[])',
                    'fatura_compra_id' => $f->id, 'item_encomenda_id' => $ligacao, 'produto_id' => $produto, 'quantidade' => $q, 'preco_unitario' => number_format((float) $p, 2, '.', ''),
                    'taxa_imposto' => $t, 'total' => $liq, 'total_kz' => $liq, 'imposto_kz' => $iva,
                    'preco_unitario_moeda' => isset($i['unit_price_currency']) ? number_format((float) $i['unit_price_currency'], 2, '.', '') : null,
                    'total_moeda' => isset($i['net_currency']) ? number_format((float) $i['net_currency'], 2, '.', '') : null,
                    'imposto_moeda' => isset($i['tax_currency']) ? number_format((float) $i['tax_currency'], 2, '.', '') : null,
                    'valor_transitoria_kz' => isset($i['valor_328_kz']) ? number_format((float) $i['valor_328_kz'], 2, '.', '') : null,
                ];
            }
        }
        foreach (array_chunk($lote, 500) as $parte) {
            $this->inserir('itens_compra', $parte);
        }
    }

    /** Ordem de carga: dependências (FKs) primeiro; ciclos resolvidos pela ordem de menor dependência pendente. */
    private function ordemTopologica(): array
    {
        $deps = [];
        foreach ($this->esquema as $t => $def) {
            $deps[$t] = [];
            foreach ($def['colunas'] as $c) {
                $alvo = $c['fk']['tabela'] ?? null;
                if ($alvo && $alvo !== $t && isset($this->esquema[$alvo])) {
                    $deps[$t][$alvo] = true;
                }
            }
        }
        $ordem = [];
        $feitas = [];
        $pendentes = $deps;
        while ($pendentes) {
            $prontas = array_keys(array_filter($pendentes, fn (array $d) => array_diff_key($d, $feitas) === []));
            if (! $prontas) {   // ciclo de FKs: avançar a tabela com menos dependências por satisfazer
                uasort($pendentes, fn (array $a, array $b) => count(array_diff_key($a, $feitas)) <=> count(array_diff_key($b, $feitas)));
                $prontas = [array_key_first($pendentes)];
            }
            sort($prontas);
            foreach ($prontas as $t) {
                $ordem[] = $t;
                $feitas[$t] = true;
                unset($pendentes[$t]);
            }
        }

        return $ordem;
    }

    /** @param  array<string, mixed>  $def */
    private function carregarTabela(array $def): void
    {
        $tabela = $def['tabela'];
        $leg = $def['legado'];
        $colunas = $def['colunas'];
        $lote = [];
        $tamanhoLote = max(1, intdiv(60000, max(1, count($colunas))));   // limite de parâmetros do PostgreSQL
        $chavesVistas = [];
        // Tabelas cujo id não vem do legado (ex.: system_config é chaveada por "key"): a base gera o id.
        $idDoLegado = ($def['colunas_por_nome']['id']['legado'] ?? null) !== null;

        foreach ($this->linhasDe($leg) as $orig) {
            $id = $this->conversorChave($orig['id'] ?? null);
            $empresaLegado = $this->empresaLegado($orig);
            $this->linhaAtual = ['tabela' => $leg, 'id' => $id !== null ? (string) $id : null, 'destino' => $tabela, 'coluna' => null, 'empresa' => $empresaLegado];

            $linha = $this->mapear($def, $orig);
            $motivo = $this->aplicarRegras($def, $orig, $linha, $chavesVistas);

            if ($motivo === null) {
                $motivo = $this->validarChaves($def, $orig, $linha);
            }
            if ($motivo !== null) {
                $this->registo->quarentena($leg, $this->linhaAtual['id'], $motivo, $orig, $empresaLegado);
                if ($id !== null) {
                    $this->emQuarentena[$tabela][$id] = true;
                }

                continue;
            }

            if ($idDoLegado) {
                $this->validos[$tabela][$linha['id']] = $linha['empresa_id'] ?? null;
            } else {
                unset($linha['id']);
            }
            $lote[] = $linha;
            if (count($lote) >= $tamanhoLote) {
                $this->inserir($tabela, $lote);
                $lote = [];
            }
        }
        if ($lote) {
            $this->inserir($tabela, $lote);
        }
        $this->registo->descarregar();
    }

    /**
     * Converte a linha do legado nas colunas do destino (tipos, normalizações, texto original).
     *
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $orig
     * @return array<string, mixed>
     */
    private function mapear(array $def, array $orig): array
    {
        $linha = [];
        foreach ($def['colunas'] as $c) {
            $col = $c['coluna'];
            $leg = $c['legado'];
            $this->linhaAtual['coluna'] = $col;
            $v = $leg !== null ? ($orig[$leg] ?? null) : null;

            if ($leg === null) {
                $linha[$col] = null;
            } elseif ($c['origem'] === 'texto_original') {
                $linha[$col] = ConversorTipos::vazio($v) ? null : mb_substr(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), 0, 255);
            } elseif ($c['origem'] === 'derivada') {
                $linha[$col] = null;   // preenchida pelas regras
            } elseif ($c['fk'] !== null && $col !== 'id') {
                // Valores não numéricos (NaN, códigos, -1) ficam NULL aqui e são tratados em validarChaves (que vê o original).
                $linha[$col] = $this->conversorChave($v);
                if ($linha[$col] === null && $v !== null && ($v === '' || $v === 0 || $v === '0')) {
                    $this->fksVazias["{$def['tabela']}.{$col}"] = ($this->fksVazias["{$def['tabela']}.{$col}"] ?? 0) + 1;
                }
            } elseif (isset($this->normalizacoes[$def['tabela']][$col])) {
                $linha[$col] = $this->normalizar($def['tabela'], $col, $v);
            } else {
                $linha[$col] = $this->conversor->converter($v, $c['tipo']);
            }
        }
        $this->linhaAtual['coluna'] = null;

        return $linha;
    }

    private function normalizar(string $tabela, string $coluna, mixed $v): ?string
    {
        if (ConversorTipos::vazio($v)) {
            return null;
        }
        $codigo = $this->normalizacoes[$tabela][$coluna][ConversorTipos::dobrar((string) $v)] ?? null;
        if ($codigo === null) {
            $this->registo->ocorrencia($this->linhaAtual['tabela'], $this->linhaAtual['id'], $tabela, $coluna, 'NORMALIZACAO', 'AVISO',
                $v, null, 'Valor sem código normalizado: código NULL, texto preservado em *_original', null, $this->linhaAtual['empresa']);
        }

        return $codigo;
    }

    /**
     * Regras específicas por tabela (ADR-005, ADR-020). Devolve o motivo de quarentena ou null.
     *
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $orig
     * @param  array<string, mixed>  $linha
     * @param  array<string, true>  $chavesVistas
     */
    private function aplicarRegras(array $def, array $orig, array &$linha, array &$chavesVistas): ?string
    {
        // Consolidação: a empresa dona do grupo/execução é a holding (o legado só grava holding_company_id).
        if (($linha['empresa_id'] ?? null) === null && ($linha['empresa_holding_id'] ?? null) !== null) {
            $linha['empresa_id'] = $linha['empresa_holding_id'];
        }

        switch ($def['tabela']) {
            case 'plano_contas':
                $chave = ($linha['empresa_id'] ?? '').'|'.mb_strtoupper(trim((string) ($linha['codigo'] ?? '')));
                if (isset($chavesVistas[$chave])) {
                    return "Conta duplicada na mesma empresa ({$chave}); mantida a primeira ocorrência (id {$chavesVistas[$chave]}).";
                }
                $chavesVistas[$chave] = $linha['id'];
                break;

            case 'lancamentos_contabeis':
                if (is_numeric($orig['value'] ?? null) && $linha['valor'] !== null && $linha['empresa_id'] !== null) {
                    $sinal = ($linha['tipo_dc'] ?? null) === 'C' ? -1 : 1;
                    $this->efeitoArredondamento[$linha['empresa_id']] = ($this->efeitoArredondamento[$linha['empresa_id']] ?? 0.0)
                        + $sinal * ((float) $linha['valor'] - (float) $orig['value']);
                }
                break;

            case 'mapeamentos_contabeis_rh':
            case 'mapeamentos_contabeis_sistema_rh':
                $legado = $orig['org_type_id'] ?? null;
                $linha['avencado'] = is_numeric($legado) && (int) $legado === -1;
                if ($linha['avencado']) {
                    $linha['tipo_organizacao_id'] = null;
                    $this->informacao($def['tabela'], 'tipo_organizacao_id', 'SEMANTICA', -1, null, "org_type_id = -1 (coluna 'Avençado') convertido em avencado = true");
                }
                break;

            case 'vendas':
                $sessao = $orig['pos_session_id'] ?? null;
                if (is_string($sessao) && $sessao !== '' && ! ctype_digit($sessao)) {
                    $linha['sessao_pos_legado_codigo'] = mb_substr($sessao, 0, 50);
                    $linha['sessao_pos_id'] = null;
                    $this->informacao('vendas', 'sessao_pos_id', 'CODIGO_LEGADO', $sessao, null, 'Sessão POS do localStorage do legado: guardada como código, sem FK');
                }
                break;

            case 'itens_compra':
                return $this->resolverItemCompra($orig, $linha);

            case 'itens_guia_saida':
                return $this->resolverItemGuia($orig, $linha);
        }

        return null;
    }

    /** itens_compra: parent_id + parent_type do legado -> uma FK real por tipo de documento. */
    private function resolverItemCompra(array $orig, array &$linha): ?string
    {
        $alvos = ['PEDIDO' => ['pedido_compra_id', 'pedidos_compra'], 'COTACAO' => ['cotacao_compra_id', 'cotacoes_compra'],
            'ENCOMENDA' => ['encomenda_compra_id', 'encomendas_compra'], 'FATURA' => ['fatura_compra_id', 'faturas_compra']];
        $tipo = $linha['tipo_documento_origem'] ?? null;
        $pai = $this->conversorChave($orig['parent_id'] ?? null);
        $encomendaLegado = $linha['encomenda_compra_id'] ?? null;

        foreach ($alvos as [$col]) {
            $linha[$col] = null;
        }
        if ($tipo === null || $pai === null || ! isset($alvos[$tipo])) {
            return 'Linha de compra sem documento-pai (parent_type/parent_id em falta).';
        }
        [$col, $tab] = $alvos[$tipo];
        if (! array_key_exists($pai, $this->validos[$tab] ?? [])) {
            return "Documento-pai inexistente: {$tab} #{$pai}.";
        }
        $linha[$col] = $pai;
        if ($encomendaLegado !== null && ! ($tipo === 'ENCOMENDA' && $encomendaLegado === $pai)) {
            $this->informacao('itens_compra', 'encomenda_compra_id', 'POLIMORFICO', $encomendaLegado, (string) $pai,
                "order_id do legado descartado: a linha pertence a {$tab} #{$pai} (parent_type)");
        }
        $linha['empresa_id'] ??= $this->validos[$tab][$pai];

        return null;
    }

    /** itens_guia_saida: delivery_id do legado aponta para guias de saída OU recepções de compra (ADR-020). */
    private function resolverItemGuia(array $orig, array &$linha): ?string
    {
        $pai = $this->conversorChave($orig['delivery_id'] ?? null);
        $linha['guia_saida_id'] = null;
        $linha['rececao_compra_id'] = null;
        if ($pai === null) {
            return 'Linha de guia sem delivery_id.';
        }
        $existeGuia = array_key_exists($pai, $this->validos['guias_saida'] ?? []);
        $existeRececao = array_key_exists($pai, $this->validos['rececoes_compra'] ?? []);
        $camposCompra = array_filter(self::CAMPOS_RECECAO_COMPRA, fn ($c) => array_key_exists($c, $orig) && ! ConversorTipos::vazio($orig[$c]));

        $destino = match (true) {
            $existeRececao && ($camposCompra || ! $existeGuia) => 'rececao',
            $existeGuia && (! $camposCompra || ! $existeRececao) => 'guia',
            default => null,
        };
        if ($destino === null) {
            return "delivery_id #{$pai} não existe em guias_saida nem em rececoes_compra.";
        }
        if ($destino === 'rececao') {
            $linha['rececao_compra_id'] = $pai;
            $linha['empresa_id'] ??= $this->validos['rececoes_compra'][$pai];
        } else {
            $linha['guia_saida_id'] = $pai;
            $linha['empresa_id'] ??= $this->validos['guias_saida'][$pai];
        }
        if ($existeGuia && $existeRececao) {
            $this->informacao('itens_guia_saida', $destino === 'rececao' ? 'rececao_compra_id' : 'guia_saida_id', 'POLIMORFICO', $pai, $destino,
                'delivery_id existe nos dois documentos; decidido pelos campos da linha ('.($camposCompra ? 'contadores cambiais de compra' : 'sem campos de compra').')');
        }

        return null;
    }

    /**
     * Valida todas as FKs da linha (órfãos) e a empresa. Devolve motivo de quarentena ou null.
     *
     * @param  array<string, mixed>  $def
     * @param  array<string, mixed>  $orig
     * @param  array<string, mixed>  $linha
     */
    private function validarChaves(array $def, array $orig, array &$linha): ?string
    {
        $tabela = $def['tabela'];

        // Empresa derivada do documento-pai (linhas-filho sem company_id no legado)
        if (! $def['global'] && ($linha['empresa_id'] ?? null) === null && $def['empresa_derivada_de']) {
            [$colPai, $tabPai] = $def['empresa_derivada_de'];
            $pai = $linha[$colPai] ?? null;
            if ($pai !== null && array_key_exists($pai, $this->validos[$tabPai] ?? [])) {
                $linha['empresa_id'] = $this->validos[$tabPai][$pai];
            }
        }

        foreach ($def['colunas'] as $c) {
            if ($c['fk'] === null || $c['coluna'] === 'id') {
                continue;
            }
            $col = $c['coluna'];
            $alvo = $c['fk']['tabela'];
            $valor = $linha[$col] ?? null;
            $bruto = $c['legado'] !== null ? ($orig[$c['legado']] ?? null) : null;

            if ($col === 'empresa_id') {
                if ($def['global']) {
                    if ($valor !== null && ! array_key_exists($valor, $this->validos['empresas'] ?? [])) {
                        $this->anularFk($tabela, $col, $valor, 'Empresa inexistente: a linha passa a global');
                        $linha[$col] = null;
                    }

                    continue;
                }
                if ($valor === null) {
                    return 'Linha sem empresa (nem no legado nem derivável do documento-pai).';
                }
                if (! array_key_exists($valor, $this->validos['empresas'] ?? [])) {
                    return "Empresa #{$valor} não migrada (fictícia ou eliminada no legado).";
                }

                continue;
            }

            // Já tratados pelas regras específicas (sem ocorrência de FK anulada)
            if (($col === 'tipo_organizacao_id' && ! empty($linha['avencado'])) || ($col === 'sessao_pos_id' && ! empty($linha['sessao_pos_legado_codigo']))) {
                continue;
            }
            // Valor presente mas não numérico (ex.: "NaN", códigos): órfão
            $naoNumerico = $valor === null && $bruto !== null && ! ConversorTipos::vazio($bruto) && ! in_array($bruto, [0, '0'], true) && $this->conversorChave($bruto) === null;
            if ($naoNumerico || ($bruto === 'NaN')) {
                $motivo = $this->tratarOrfao($def, $col, $alvo, $bruto, $linha);
                if ($motivo !== null) {
                    return $motivo;
                }

                continue;
            }
            if ($valor === null || $this->existe($alvo, $valor)) {
                continue;
            }
            $motivo = $this->tratarOrfao($def, $col, $alvo, $valor, $linha);
            if ($motivo !== null) {
                return $motivo;
            }
        }

        return null;
    }

    private function existe(string $tabela, int $id): bool
    {
        if (isset($this->validos[$tabela])) {
            return array_key_exists($id, $this->validos[$tabela] ?? []) && ! isset($this->emQuarentena[$tabela][$id]);
        }
        // Tabela ainda não carregada (ciclo de FKs): ids do backup; o COMMIT confirma (FKs adiadas).
        $leg = $this->esquema[$tabela]['legado'] ?? array_search($tabela, self::TABELAS_FASE1, true);

        return $leg && isset($this->candidatos[$leg][$id]);
    }

    /** Aplica a regra de integridade a uma FK órfã. Devolve motivo de quarentena ou null (FK anulada/corrigida). */
    private function tratarOrfao(array $def, string $col, string $alvo, mixed $valor, array &$linha): ?string
    {
        $tabela = $def['tabela'];

        // Lançamentos com diário inexistente/NaN: diário de recuperação (retirá-los alteraria saldos) — ADR-005
        if ($col === 'diario_id' && in_array($tabela, ['lancamentos_contabeis', 'lancamentos_estornados'], true)) {
            $linha[$col] = $this->diarioRecuperacao((int) $linha['empresa_id']);
            $this->registo->ocorrencia($def['legado'], $this->linhaAtual['id'], $tabela, $col, 'DIARIO_RECUPERACAO', 'AVISO', $valor,
                (string) $linha[$col], 'Diário inexistente no legado: lançamento reapontado para o diário REC', null, $this->linhaAtual['empresa']);

            return null;
        }
        // Linha-filho sem documento-pai: sem contexto, vai para quarentena
        $cascata = $def['colunas_por_nome'][$col]['fk']['cascata'] ?? false;
        if ($cascata || ($tabela === 'itens_documento_tesouraria' && $col === 'documento_tesouraria_id')
            || ($tabela === 'transferencias_centros_custo_ativos' && $col === 'ativo_imobilizado_id')) {
            return "Documento-pai inexistente: {$alvo} #{$valor}.";
        }

        $this->anularFk($tabela, $col, $valor, "Referência a {$alvo} #{$valor} inexistente: FK anulada");
        $linha[$col] = null;

        return null;
    }

    private function anularFk(string $tabela, ?string $coluna, mixed $valor, string $descricao): void
    {
        $this->registo->ocorrencia($this->linhaAtual['tabela'], $this->linhaAtual['id'], $tabela, $coluna, 'ANULAR_FK', 'AVISO',
            $valor, null, $descricao, null, $this->linhaAtual['empresa']);
    }

    private function informacao(string $tabela, ?string $coluna, string $regra, mixed $valor, ?string $final, string $descricao): void
    {
        $this->registo->ocorrencia($this->linhaAtual['tabela'], $this->linhaAtual['id'], $tabela, $coluna, $regra, 'INFO',
            $valor, $final, $descricao, null, $this->linhaAtual['empresa']);
    }

    private function diarioRecuperacao(int $empresaId): int
    {
        if (isset($this->diariosRecuperacao[$empresaId])) {
            return $this->diariosRecuperacao[$empresaId];
        }
        $existente = DB::table('diarios_contabeis')->where('empresa_id', $empresaId)->where('codigo', 'REC')->value('id');
        $id = $existente ?? (int) DB::table('diarios_contabeis')->max('id') + 1;
        if (! $existente) {
            DB::table('diarios_contabeis')->insert(['id' => $id, 'empresa_id' => $empresaId, 'codigo' => 'REC',
                'descricao' => 'DIÁRIO RECUPERADO DO LEGADO (lançamentos com diário inexistente)']);
            $this->validos['diarios_contabeis'][$id] = $empresaId;
            $this->inseridas['diarios_contabeis'] = ($this->inseridas['diarios_contabeis'] ?? 0) + 1;
        }

        return $this->diariosRecuperacao[$empresaId] = $id;
    }

    /** @param  list<array<string, mixed>>  $linhas */
    private function inserir(string $tabela, array $linhas): void
    {
        DB::table($tabela)->insert($linhas);
        $this->inseridas[$tabela] = ($this->inseridas[$tabela] ?? 0) + count($linhas);
    }

    // ── tabelas da Fase 1 (esquema próprio) ─────────────────────────────────────

    private function carregarEmpresas(): void
    {
        $lote = [];
        foreach ($this->linhasDe('companies') as $o) {
            $id = $this->conversorChave($o['id'] ?? null);
            $this->linhaAtual = ['tabela' => 'companies', 'id' => (string) $id, 'destino' => 'empresas', 'coluna' => null, 'empresa' => $id];
            $estado = ConversorTipos::vazio($o['status'] ?? null) ? null : ConversorTipos::dobrar((string) $o['status']);
            $lote[] = [
                'id' => $id, 'nome' => trim((string) ($o['name'] ?? "Empresa {$id}")), 'nif' => trim((string) ($o['nif'] ?? '')),
                'endereco' => $this->conversor->converter($o['address'] ?? null, 'text'),
                'provincia' => $this->conversor->converter($o['province'] ?? null, 'varchar(100)'),
                'municipio' => $this->conversor->converter($o['municipality'] ?? null, 'varchar(100)'),
                'comuna' => $this->conversor->converter($o['commune'] ?? null, 'varchar(100)'),
                'telefone' => $this->conversor->converter($o['phone'] ?? null, 'varchar(50)'),
                'email' => $this->conversor->converter($o['email'] ?? null, 'varchar(150)'),
                'website' => $this->conversor->converter($o['website'] ?? null, 'varchar(255)'),
                'numero_registo_comercial' => $this->conversor->converter($o['crc'] ?? null, 'varchar(50)'),
                'rodape_documento' => $this->conversor->converter($o['doc_footer'] ?? null, 'text'),
                'logotipo' => ConversorTipos::vazio($o['logo'] ?? null) ? null : (string) $o['logo'],
                'taxa_inss_patronal' => $this->conversor->converter($o['inss_patronal'] ?? null, 'numeric(5,2)') ?? '8.00',
                'taxa_inss_trabalhador' => $this->conversor->converter($o['inss_trabalhador'] ?? null, 'numeric(5,2)') ?? '3.00',
                'regras_ia' => $this->conversor->converter($o['ai_rules'] ?? null, 'text'),
                'estado' => in_array($estado, ['INACTIVE', 'INATIVO', 'INACTIVO'], true) ? 'INATIVO' : 'ATIVO',
                'estado_original' => $this->conversor->converter($o['status'] ?? null, 'varchar(20)'),
                'e_consolidacao' => (bool) ($this->conversor->converter($o['is_consolidation'] ?? null, 'boolean') ?? false),
                'moeda_consolidacao' => $this->conversor->converter($o['consolidation_currency'] ?? null, 'varchar(10)'),
                'data_fim_consolidacao' => $this->conversor->converter($o['consolidation_date_end'] ?? null, 'date'),
                'execucao_consolidacao_id' => $this->conversorChave($o['consolidation_run_id'] ?? null),
                'he_percentagem_1' => $this->conversor->converter($o['he_percentagem_1'] ?? null, 'numeric(9,4)'),
                'he_limite_horas' => $this->conversor->converter($o['he_limite_horas'] ?? null, 'numeric(12,3)'),
                'he_percentagem_2' => $this->conversor->converter($o['he_percentagem_2'] ?? null, 'numeric(9,4)'),
                'moeda_funcional' => $this->conversor->converter($o['functional_currency'] ?? null, 'varchar(10)'),
            ];
            $this->validos['empresas'][$id] = $id;
            if (in_array($id, self::EMPRESAS_REAIS_COM_FLAG_MESTRE, true)) {
                $this->informacao('empresas', null, 'DADO_MESTRE', 1, null, "Empresa #{$id} marcada como 'dado mestre' no legado mas real: mantida (decisão 2026-09-29)");
            }
        }
        $this->inserir('empresas', $lote);
    }

    /** empresas.execucao_consolidacao_id só pode ser validada depois de carregadas as execuções de consolidação (ciclo). */
    private function validarChavesEmpresas(): void
    {
        foreach (DB::table('empresas')->whereNotNull('execucao_consolidacao_id')->get(['id', 'execucao_consolidacao_id']) as $e) {
            if (! array_key_exists($e->execucao_consolidacao_id, $this->validos['execucoes_consolidacao'] ?? [])) {
                $this->linhaAtual = ['tabela' => 'companies', 'id' => (string) $e->id, 'destino' => 'empresas', 'coluna' => 'execucao_consolidacao_id', 'empresa' => $e->id];
                $this->anularFk('empresas', 'execucao_consolidacao_id', $e->execucao_consolidacao_id, 'Execução de consolidação inexistente: FK anulada');
                DB::table('empresas')->where('id', $e->id)->update(['execucao_consolidacao_id' => null]);
            }
        }
    }

    /**
     * Perfis: os do formato antigo (sem _v2 e sem all:true) são convertidos para v2 com o resultado do próprio
     * converterAntigo do legado (database/legado/perfis_convertidos.json); o original fica em permissoes_originais.
     */
    private function carregarPerfis(): void
    {
        $ficheiro = database_path('legado/perfis_convertidos.json');
        $convertidos = is_file($ficheiro) ? json_decode(file_get_contents($ficheiro), true)['perfis'] : [];
        $lote = [];
        foreach ($this->linhasDe('user_profiles') as $o) {
            $id = $this->conversorChave($o['id'] ?? null);
            $this->linhaAtual = ['tabela' => 'user_profiles', 'id' => (string) $id, 'destino' => 'perfis_utilizador', 'coluna' => 'permissoes', 'empresa' => null];
            $perms = is_array($o['permissions'] ?? null) ? $o['permissions'] : [];
            $originais = null;
            if (($perms['_v2'] ?? null) !== true && ($perms['all'] ?? null) !== true) {
                $conv = $convertidos[(string) $id] ?? null;
                if ($conv === null || $conv['original'] != $perms) {
                    throw new ErroNegocio("Perfil #{$id} no formato antigo sem conversão válida: correr ferramentas/levantamento/converter_perfis_antigos.mjs sobre este backup.", 'ETL_PERFIL_ANTIGO');
                }
                $originais = json_encode($perms, JSON_UNESCAPED_UNICODE);
                $perms = $conv['convertido'];
                $this->informacao('perfis_utilizador', 'permissoes', 'CONVERSAO_PERFIL', null, (string) (count($perms) - 1),
                    'Perfil no formato antigo convertido para v2 com o converterAntigo do legado (original em permissoes_originais)');
            }
            $lote[] = ['id' => $id, 'nome' => trim((string) ($o['name'] ?? "Perfil {$id}")),
                'permissoes' => json_encode($perms ?: new \stdClass, JSON_UNESCAPED_UNICODE), 'permissoes_originais' => $originais];
            $this->validos['perfis_utilizador'][$id] = null;
        }
        if ($lote) {
            $this->inserir('perfis_utilizador', $lote);
        }
    }

    private function carregarUtilizadores(): void
    {
        $papeis = $this->normalizacoesUtilizadores();
        foreach ($this->linhasDe('users') as $o) {
            $id = $this->conversorChave($o['id'] ?? null);
            $this->linhaAtual = ['tabela' => 'users', 'id' => (string) $id, 'destino' => 'utilizadores', 'coluna' => null, 'empresa' => null];
            $papel = $papeis[ConversorTipos::dobrar((string) ($o['role'] ?? ''))] ?? 'UTILIZADOR';
            $perfil = $this->conversorChave($o['profile_id'] ?? null);
            if ($perfil !== null && ! array_key_exists($perfil, $this->validos['perfis_utilizador'] ?? [])) {
                $this->anularFk('utilizadores', 'perfil_utilizador_id', $perfil, 'Perfil inexistente: FK anulada');
                $perfil = null;
            }
            $empresas = array_values(array_filter(array_map(fn ($e) => $this->conversorChave($e), (array) ($o['allowed_companies'] ?? []))));
            $todas = $papel === 'SUPER_ADMINISTRADOR' || $empresas === [];
            if ($empresas === [] && $papel !== 'SUPER_ADMINISTRADOR') {
                $this->informacao('utilizadores', 'acesso_todas_empresas', 'SEMANTICA', '[]', 'true',
                    'allowed_companies vazio significava "todas as empresas" no legado: acesso_todas_empresas = true');
            }
            $temHash = ! ConversorTipos::vazio($o['password_hash'] ?? null) && ! ConversorTipos::vazio($o['password_salt'] ?? null);
            $linha = [
                'id' => $id, 'nome_utilizador' => (string) $o['username'], 'nome_completo' => $this->conversor->converter($o['name'] ?? null, 'varchar(200)'),
                'email' => $this->conversor->converter($o['email'] ?? null, 'varchar(150)'),
                'palavra_passe' => null, 'hash_password_legado' => $temHash ? (string) $o['password_hash'] : null,
                'salt_password_legado' => $temHash ? (string) $o['password_salt'] : null,
                'algoritmo_password_legado' => $temHash ? ($o['password_algo'] ?? null) : null,
                'papel' => $papel, 'papel_original' => $this->conversor->converter($o['role'] ?? null, 'varchar(30)'),
                'perfil_utilizador_id' => $perfil, 'acesso_todas_empresas' => $todas,
                'modulos_permitidos' => isset($o['allowed_modules']) ? json_encode($o['allowed_modules'], JSON_UNESCAPED_UNICODE) : null,
                'ativo' => true,
            ];
            if (! $temHash) {
                if (ConversorTipos::vazio($o['password'] ?? null)) {
                    $this->registo->quarentena('users', (string) $id, 'Utilizador sem palavra-passe (nem hash nem texto).', $this->semSegredos($o), null);

                    continue;
                }
                // Palavra-passe em texto simples no legado: nunca é persistida — só o hash Argon2id.
                $linha['palavra_passe'] = Hash::make((string) $o['password']);
                $this->informacao('utilizadores', 'palavra_passe', 'SEGURANCA', null, null, 'Palavra-passe em texto simples no legado convertida directamente para Argon2id');
            }
            $this->inserir('utilizadores', [$linha]);
            $this->validos['utilizadores'][$id] = null;
        }
    }

    /** users.allowed_companies e users.colaboradores -> utilizador_empresa (após colaboradores carregados). */
    private function carregarLigacoesUtilizadorEmpresa(): void
    {
        foreach ($this->linhasDe('users') as $o) {
            $id = $this->conversorChave($o['id'] ?? null);
            if (! array_key_exists($id, $this->validos['utilizadores'] ?? [])) {
                continue;
            }
            $this->linhaAtual = ['tabela' => 'users', 'id' => (string) $id, 'destino' => 'utilizador_empresa', 'coluna' => null, 'empresa' => null];
            $ligacoes = [];
            foreach ((array) ($o['allowed_companies'] ?? []) as $e) {
                $e = $this->conversorChave($e);
                if ($e !== null) {
                    $ligacoes[$e] = null;
                }
            }
            foreach ((array) ($o['colaboradores'] ?? []) as $empresa => $colaborador) {
                $e = $this->conversorChave($empresa);
                if ($e !== null) {
                    $ligacoes[$e] = $this->conversorChave($colaborador);
                }
            }
            $lote = [];
            foreach ($ligacoes as $empresa => $colaborador) {
                if (! array_key_exists($empresa, $this->validos['empresas'] ?? [])) {
                    $this->anularFk('utilizador_empresa', 'empresa_id', $empresa, 'Empresa não migrada: ligação do utilizador ignorada');

                    continue;
                }
                if ($colaborador !== null && ($this->validos['colaboradores'][$colaborador] ?? false) !== $empresa) {
                    $this->anularFk('utilizador_empresa', 'colaborador_id', $colaborador, "Colaborador #{$colaborador} inexistente na empresa #{$empresa}: ligação sem colaborador");
                    $colaborador = null;
                }
                $lote[] = ['utilizador_id' => $id, 'empresa_id' => $empresa, 'colaborador_id' => $colaborador];
            }
            if ($lote) {
                $this->inserir('utilizador_empresa', $lote);
            }
        }
    }

    private function carregarAuditoria(): void
    {
        $lote = [];
        foreach ($this->linhasDe('audit_logs') as $o) {
            $id = $this->conversorChave($o['id'] ?? null);
            $this->linhaAtual = ['tabela' => 'audit_logs', 'id' => (string) $id, 'destino' => 'logs_auditoria', 'coluna' => null, 'empresa' => null];
            $utilizador = $o['user'] ?? null;
            if (is_array($utilizador)) {   // 18 linhas gravaram o objecto de sessão inteiro
                $utilizador = $utilizador['username'] ?? null;
            }
            $registo = $o['record_id'] ?? null;
            if (is_array($registo)) {
                $registo = implode(',', $registo);
            }
            $ocorrido = $this->conversor->converter($o['timestamp'] ?? null, 'timestamptz');
            if ($ocorrido === null) {
                $this->registo->quarentena('audit_logs', (string) $id, 'Log de auditoria sem data válida.', $o, null);

                continue;
            }
            $lote[] = [
                'id' => $id, 'empresa_id' => $this->conversorChave($o['company_id'] ?? null),   // sem FK: histórico imutável (ADR-009)
                'utilizador_id' => null, 'nome_utilizador' => $utilizador !== null ? mb_substr((string) $utilizador, 0, 100) : null,
                'ocorrido_em' => $ocorrido, 'modulo' => mb_substr((string) ($o['module'] ?? 'Desconhecido'), 0, 100),
                'acao' => mb_substr((string) ($o['action'] ?? 'Desconhecida'), 0, 100), 'tabela' => null,
                'registo_id' => $registo !== null && $registo !== '' ? mb_substr((string) $registo, 0, 255) : null,
                'detalhes' => isset($o['details']) ? (is_string($o['details']) ? $o['details'] : json_encode($o['details'], JSON_UNESCAPED_UNICODE)) : null,
            ];
            if (count($lote) >= 4000) {
                $this->inserir('logs_auditoria', $lote);
                $lote = [];
            }
        }
        if ($lote) {
            $this->inserir('logs_auditoria', $lote);
        }
        $this->registo->descarregar();
    }

    /** Listas de ids do legado (_legado) -> tabelas pivô com FK real. */
    private function carregarPivos(): void
    {
        $pivos = json_decode(file_get_contents(database_path('legado/esquema.json')), true)['pivos'];
        foreach ($pivos as $p) {
            [$tabDe, $colDe] = $p['de'];
            [$tabA, $colA] = $p['a'];
            $lote = [];
            $vistos = [];
            foreach (DB::table($tabDe)->whereNotNull("{$colDe}_legado")->orderBy('id')->cursor() as $r) {
                $valor = $r->{"{$colDe}_legado"};
                $ids = str_starts_with(trim($valor), '[') ? (array) json_decode($valor, true) : explode(',', $valor);
                foreach ($ids as $alvo) {
                    $alvo = $this->conversorChave($alvo);
                    if ($alvo === null || isset($vistos["{$r->id}|{$alvo}"])) {
                        continue;
                    }
                    $this->linhaAtual = ['tabela' => $this->esquema[$tabDe]['legado'], 'id' => (string) $r->id, 'destino' => $p['tabela'], 'coluna' => $colA, 'empresa' => $r->empresa_id];
                    if (! array_key_exists($alvo, $this->validos[$tabA] ?? [])) {
                        $this->anularFk($p['tabela'], $colA, $alvo, "Lista de ids do legado refere {$tabA} #{$alvo} inexistente: ligação ignorada");

                        continue;
                    }
                    $vistos["{$r->id}|{$alvo}"] = true;
                    $lote[] = [$p['chave'] => $r->id, $colA => $alvo, 'empresa_id' => $r->empresa_id];
                }
            }
            if ($lote) {
                $this->inserir($p['tabela'], $lote);
            }
        }
    }

    // ── 3. validação ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validar(): array
    {
        // Contagens por tabela do legado: lidas = migradas + quarentena
        $destinos = self::TABELAS_FASE1;
        foreach ($this->esquema as $t => $def) {
            if ($def['legado']) {
                $destinos[$def['legado']] = $t;
            }
        }
        $contagens = [];
        $divergencias = 0;
        foreach ($this->lidas as $leg => $lidas) {
            $destino = $destinos[$leg] ?? null;
            $consulta = $destino ? DB::table($destino) : null;
            if ($destino === 'diarios_contabeis' && $this->diariosRecuperacao) {
                $consulta->whereNotIn('id', array_values($this->diariosRecuperacao));   // os diários REC são criados pelo ETL
            }
            $migradas = $consulta ? (int) $consulta->count() : 0;
            $quarentena = $this->registo->quarentenaDe($leg);
            $ok = $lidas === $migradas + $quarentena;
            $divergencias += $ok ? 0 : 1;
            $contagens[$leg] = ['destino' => $destino, 'lidas' => $lidas, 'migradas' => $migradas, 'quarentena' => $quarentena, 'confere' => $ok];
        }

        // Órfãos por FK (antes do COMMIT, para um relatório legível; o COMMIT volta a garantir)
        $orfaos = [];
        foreach (DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS tabela, a.attname AS coluna, c.confrelid::regclass::text AS alvo
            FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
            WHERE c.contype = 'f' AND c.connamespace = 'public'::regnamespace
        SQL) as $fk) {
            $n = DB::selectOne("SELECT count(*) AS n FROM \"{$fk->tabela}\" f WHERE f.\"{$fk->coluna}\" IS NOT NULL
                                AND NOT EXISTS (SELECT 1 FROM \"{$fk->alvo}\" p WHERE p.id = f.\"{$fk->coluna}\")")->n;
            if ($n > 0) {
                $orfaos["{$fk->tabela}.{$fk->coluna}"] = (int) $n;
            }
        }

        // Partidas dobradas: D − C por empresa (decisão: importar como está e reportar)
        $equilibrio = [];
        foreach (DB::select(<<<'SQL'
            SELECT l.empresa_id, e.nome,
                   SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS debito,
                   SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS credito,
                   COUNT(*) AS linhas
            FROM lancamentos_contabeis l JOIN empresas e ON e.id = l.empresa_id
            GROUP BY l.empresa_id, e.nome ORDER BY l.empresa_id
        SQL) as $r) {
            $diferenca = round((float) $r->debito - (float) $r->credito, 2);
            $efeito = round($this->efeitoArredondamento[(int) $r->empresa_id] ?? 0.0, 4);
            $equilibrio[] = ['empresa_id' => (int) $r->empresa_id, 'empresa' => $r->nome, 'linhas' => (int) $r->linhas,
                'debito' => (float) $r->debito, 'credito' => (float) $r->credito, 'diferenca' => $diferenca, 'equilibrada' => abs($diferenca) < 0.005,
                'efeito_arredondamento' => $efeito, 'diferenca_no_legado' => round($diferenca - $efeito, 4)];
        }

        return [
            'resumo' => [
                'linhas_reais_lidas' => array_sum($this->lidas),
                'linhas_ficticias_descartadas' => $this->ficticias,
                'linhas_migradas' => array_sum($this->inseridas),
                'linhas_em_quarentena' => $this->registo->totalQuarentena(),
                'tabelas_com_divergencia_de_contagem' => $divergencias,
                'fks_com_orfaos' => count($orfaos),
                'empresas_desequilibradas' => count(array_filter($equilibrio, fn ($e) => ! $e['equilibrada'])),
                'diarios_recuperacao_criados' => count($this->diariosRecuperacao),
            ],
            'contagens' => $contagens,
            'inseridas_por_tabela_destino' => $this->inseridas,
            'orfaos' => $orfaos,
            'equilibrio_contabilistico' => $equilibrio,
            'ocorrencias' => $this->registo->contadores(),
            'fks_sem_valor_anuladas' => $this->fksVazias,
        ];
    }

    /** setval de todas as sequences/identities de "id" para MAX(id): novos registos continuam a numeração do legado. */
    private function recalibrarSequencias(): void
    {
        foreach (DB::select(<<<'SQL'
            SELECT seq.oid::regclass::text AS sequencia, tab.oid::regclass::text AS tabela
            FROM pg_class seq
            JOIN pg_depend dep ON dep.objid = seq.oid AND dep.classid = 'pg_class'::regclass AND dep.deptype IN ('a', 'i')
            JOIN pg_class tab ON tab.oid = dep.refobjid AND NOT tab.relispartition
            JOIN pg_attribute col ON col.attrelid = tab.oid AND col.attnum = dep.refobjsubid AND col.attname = 'id'
            WHERE seq.relkind = 'S' AND tab.relnamespace = 'public'::regnamespace
        SQL) as $s) {
            $max = DB::selectOne("SELECT MAX(id) AS m FROM {$s->tabela}")->m;
            DB::select('SELECT setval(?::regclass, ?, ?)', [$s->sequencia, $max ?? 1, $max !== null]);
        }
    }

    // ── utilitários ─────────────────────────────────────────────────────────────

    private function conversorChave(mixed $v): ?int
    {
        return ($this->conversor ?? new ConversorTipos(fn () => null))->chave($v);
    }

    private function empresaLegado(array $o): ?int
    {
        foreach (['company_id', 'rh_company_id', 'pos_company_id', 'lav_company_id', 'hotel_company_id', 'crm_company_id', 'orc_company_id',
            'org_company_id', 'pa_company_id', 'ad_company_id', 'fe_company_id', 'report_company_id', 'scope_company_id', 'maint_company_id'] as $c) {
            if (isset($o[$c]) && is_numeric($o[$c])) {
                return (int) $o[$c];
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function normalizacoesUtilizadores(): array
    {
        $mapa = json_decode(file_get_contents(database_path('legado/mapa_de_para.json')), true);
        foreach ($mapa['tabelas'] as $t) {
            if ($t['legado'] === 'users') {
                foreach ($t['colunas'] as $c) {
                    if ($c['legado'] === 'role' && ! empty($c['normalizacao'])) {
                        return $c['normalizacao']['mapa'];
                    }
                }
            }
        }

        return [];
    }

    private function semSegredos(array $o): array
    {
        unset($o['password'], $o['password_hash'], $o['password_salt']);

        return $o;
    }

    private function informar(string $mensagem): void
    {
        ($this->progresso)($mensagem);
    }
}
