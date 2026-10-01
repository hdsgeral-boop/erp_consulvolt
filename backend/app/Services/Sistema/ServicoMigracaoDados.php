<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\Banco;
use App\Models\CargoFuncao;
use App\Models\DiarioContabil;
use App\Models\InfotipoSalarial;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Logistica\ServicoProdutos;
use App\Services\RH\ServicoCadastrosRH;
use App\Services\RH\ServicoEstruturaOrg;
use App\Services\Terceiros\ServicoTerceiros;
use App\Support\Cache\ChaveCache;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Centro de migração de dados (config_migração: renderMigração, js/app_v2.js:11521-11630) — modelos (templates) e
 * importação em massa dos dados mestre; e edição em massa de clientes, fornecedores e produtos (js/mapeamento_massa.js).
 *
 * Importações portadas (as que existiam no centro de migração e nas tabelas auxiliares), com as regras do legado
 * (ImportMestre: chave por entidade, nunca duplica; existentes IGNORAR ou ACTUALIZAR; linhas repetidas no ficheiro
 * contadas e ignoradas; linhas incompletas rejeitadas):
 *   plano_contas  (importChartOfAccountsExcel, app_v2.js:8643)  Conta | Descrição | Tipo (M/T)        chave: conta
 *   diarios       (importAuxExcel 'journals', ui_aux.js:843)    Código | Descrição                    chave: código
 *   terceiros     (importAuxExcel 'third_parties')              NIF | Nome | Tipo | Conta             chave: NIF
 *   bancos        (importBanksExcel, app_v2.js:9772)            Nome | Código | NIF | Endereço | Conta chave: código (ou nome)
 *   cargos        (importSimpleExcel 'roles', app_v2.js:9609)   Nome | Descrição                      chave: nome
 *   infotipos     (importInfotypesExcel, app_v2.js:9727)        Nome | Tipo | INSS | IRT              chave: nome
 * Cada entidade exige a tarefa do seu módulo (nota do catálogo: "as importações continuam sujeitas às tarefas de cada
 * módulo") e grava pelos serviços desse módulo (as mesmas validações da ficha).
 *
 * Correcções face ao legado:
 *  - importação TRANSACCIONAL: todas as linhas são gravadas numa transacção; se alguma falhar nada fica gravado e o
 *    relatório indica a linha e o motivo (o legado gravava linha a linha e parava a meio, deixando importações parciais);
 *  - a simulação corre exactamente as mesmas validações (dentro de uma transacção desfeita no fim);
 *  - terceiros: a conta contabilística passou a obrigatória (ADR-028) — coluna "Conta" ou conta por omissão no pedido;
 *    o tipo desconhecido é rejeitado (o legado gravava qualquer texto); o legado criava como CLIENTE por omissão;
 *  - plano de contas: actualizar o tipo de uma conta com movimentos para T é recusado (regra do plano de contas).
 * Não portadas aqui: colaboradores, contratos, variáveis do processamento e diário de tesouraria (importações
 * operacionais dos módulos RH/Tesouraria) e notas/centros de custo (tabelas auxiliares da Contabilidade).
 */
final class ServicoMigracaoDados
{
    public const ENTIDADES = [
        'plano_contas' => ['rotulo' => 'Plano de contas', 'permissao' => 'contab_plano_gerir', 'chave' => 'codigo',
            'colunas' => [['Conta', 'codigo', true, 'Código da conta', '3111'], ['Descrição', 'descricao', true, 'Nome da conta', 'Clientes nacionais'],
                ['Tipo', 'tipo', false, 'M (movimento, por omissão) ou T (totalizadora)', 'M']]],
        'diarios' => ['rotulo' => 'Diários', 'permissao' => 'aux_gerir', 'chave' => 'codigo',
            'colunas' => [['Código', 'codigo', true, 'Abreviatura do diário (letras, números e hífen)', 'COMP'], ['Descrição', 'descricao', true, 'Nome por extenso', 'Diário de Compras']]],
        'terceiros' => ['rotulo' => 'Terceiros', 'permissao' => 'aux_gerir', 'chave' => 'nif',
            'colunas' => [['NIF', 'nif', true, 'Identificação fiscal ou interna', '5412345678'], ['Nome', 'nome', true, 'Razão social ou nome', 'Fornecedor X, Lda'],
                ['Tipo', 'tipo', false, 'Cliente (por omissão), Fornecedor ou Colaborador', 'Fornecedor'],
                ['Conta', 'codigo_conta', false, 'Conta de movimento do terceiro (obrigatória se o pedido não indicar a conta por omissão)', '3211']]],
        'bancos' => ['rotulo' => 'Bancos', 'permissao' => 'rh_bancario_gerir', 'chave' => 'codigo',
            'colunas' => [['Nome', 'nome', true, 'Nome do banco', 'Banco BAI'], ['Código', 'codigo', true, 'Código do banco', '0040'], ['NIF', 'nif', false, 'NIF do banco', ''],
                ['Endereço', 'endereco', false, 'Morada', ''], ['Conta', 'codigo_conta', false, 'Conta de movimento (classe 43)', '4311']]],
        'cargos' => ['rotulo' => 'Funções (cargos)', 'permissao' => 'rh_funcoes_gerir', 'chave' => 'nome',
            'colunas' => [['Nome', 'nome', true, 'Nome da função', 'Contabilista'], ['Descrição', 'descricao', false, 'Descrição da função', '']]],
        'infotipos' => ['rotulo' => 'Rubricas (infotipos)', 'permissao' => 'rh_infotipos_gerir', 'chave' => 'nome',
            'colunas' => [['Nome', 'nome', true, 'Nome da rubrica', 'Subsídio de transporte'], ['Tipo', 'tipo', false, 'VENCIMENTO (por omissão) ou DESCONTO', 'VENCIMENTO'],
                ['INSS', 'sujeito_inss', false, 'Sim ou Não', 'Sim'], ['IRT', 'irt', false, 'Sim, Não ou "Até 30.000 Kz Isento"', 'Até 30.000 Kz Isento']]],
    ];

    /** Edição em massa (js/mapeamento_massa.js:16-60): campos de dados e de contas por entidade. */
    public const EDICAO_MASSA = [
        'clientes' => ['permissao' => 'vendas_clientes_gerir', 'dados' => ['codigo_moeda'], 'contas' => ['codigo_conta']],
        'fornecedores' => ['permissao' => 'compras_forn_gerir', 'dados' => ['codigo_moeda'], 'contas' => ['codigo_conta', 'conta_compra_transitoria']],
        'produtos' => ['permissao' => 'vendas_produtos_gerir', 'dados' => ['taxa_imposto', 'categoria_produto_id', 'movimenta_stock', 'e_servico', 'bloqueado'],
            'contas' => ['codigo_conta', 'conta_custo', 'conta_compra', 'conta_inventario', 'conta_iva_liquidado', 'conta_iva_dedutivel', 'conta_quebra', 'conta_sobra', 'conta_ativo']],
    ];

    public const RETIRAR = '__RETIRAR__';

    public function __construct(
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoTerceiros $terceiros,
        private readonly ServicoProdutos $produtos,
        private readonly ServicoCadastrosRH $cadastrosRH,
        private readonly ServicoEstruturaOrg $estrutura,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @return list<array<string, mixed>> */
    public function modelos(): array
    {
        return array_map(fn ($k, $e) => ['entidade' => $k, 'rotulo' => $e['rotulo'], 'permissao' => $e['permissao'],
            'colunas' => array_map(fn ($c) => ['cabecalho' => $c[0], 'campo' => $c[1], 'obrigatorio' => $c[2], 'instrucao' => $c[3], 'exemplo' => $c[4]], $e['colunas'])],
            array_keys(self::ENTIDADES), self::ENTIDADES);
    }

    /** Modelo Excel (createTemplateWithInstructions, app_v2.js:9816): folha "Template" e folha "Como_Preencher". */
    public function modeloExcel(string $entidade, string $caminho): void
    {
        $e = self::ENTIDADES[$entidade] ?? throw new ErroNegocio('Entidade de importação desconhecida.', 'ENTIDADE_DESCONHECIDA', 404);
        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet()->setTitle('Template');
        $folha->fromArray([array_column($e['colunas'], 0), array_column($e['colunas'], 4)]);
        $ajuda = $livro->createSheet()->setTitle('Como_Preencher');
        $ajuda->fromArray(array_merge([['Campo', 'O que preencher', 'Obrigatório']], array_map(fn ($c) => [$c[0], $c[3], $c[2] ? 'Sim' : 'Não'], $e['colunas'])));
        foreach (['A' => 25, 'B' => 70, 'C' => 14] as $col => $largura) {
            $ajuda->getColumnDimension($col)->setWidth($largura);
        }
        (new Xlsx($livro))->save($caminho);
    }

    /**
     * Importação em massa. As linhas trazem os cabeçalhos do modelo (ou os nomes dos campos).
     *
     * @param  list<array<string, mixed>>  $linhas
     * @param  array{conta_omissao?: ?string}  $opcoes
     * @return array<string, mixed>
     */
    public function importar(string $entidade, array $linhas, string $decisao, bool $simular, array $opcoes = []): array
    {
        $def = self::ENTIDADES[$entidade] ?? throw new ErroNegocio('Entidade de importação desconhecida.', 'ENTIDADE_DESCONHECIDA', 404);
        $r = ['entidade' => $entidade, 'simulacao' => $simular, 'novos' => 0, 'existentes' => [], 'repetidos' => 0, 'rejeitadas' => [],
            'criados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'erros' => []];
        $existentes = $this->existentes($entidade);
        $vistos = [];
        $validas = [];
        foreach ($linhas as $i => $bruta) {
            $n = $i + 2;
            $l = $this->normalizarLinha($def, $bruta);
            if (array_filter($l, fn ($v) => $v !== null && $v !== '') === []) {
                continue;
            }
            $falta = array_values(array_filter($def['colunas'], fn ($c) => $c[2] && ($l[$c[1]] ?? '') === ''));
            if ($falta) {
                $r['rejeitadas'][] = ['linha' => $n, 'motivo' => 'falta '.implode(', ', array_column($falta, 0))];

                continue;
            }
            $chave = mb_strtolower(trim((string) $l[$def['chave']]));
            if (isset($vistos[$chave])) {
                $r['repetidos']++;

                continue;
            }
            $vistos[$chave] = true;
            $existente = $existentes[$chave] ?? ($entidade === 'bancos' ? ($existentes['nome:'.mb_strtolower((string) $l['nome'])] ?? null) : null);
            $existente ? $r['existentes'][] = (string) $l[$def['chave']] : $r['novos']++;
            $validas[] = ['linha' => $n, 'dados' => $l, 'existente' => $existente];
        }

        DB::beginTransaction();
        try {
            foreach ($validas as $v) {
                if ($v['existente'] && $decisao !== 'ACTUALIZAR') {
                    $r['ignorados']++;

                    continue;
                }
                try {
                    DB::transaction(fn () => $this->gravarLinha($entidade, $v['dados'], $v['existente'], $opcoes));
                    $v['existente'] ? $r['actualizados']++ : $r['criados']++;
                } catch (ErroNegocio $e) {
                    $r['erros'][] = ['linha' => $v['linha'], 'motivo' => $e->getMessage()];
                } catch (QueryException $e) {
                    $r['erros'][] = ['linha' => $v['linha'], 'motivo' => 'Registo recusado pela base de dados (valor demasiado longo, duplicado ou inválido).'];
                }
            }
            if ($simular || $r['erros']) {
                DB::rollBack();
                // a simulação pode ter passado pelas caches (plano de contas, catálogo): nada do que foi desfeito pode lá ficar
                $empresa = app(ContextoEmpresa::class)->obrigatorio();
                Cache::forget(ChaveCache::empresa($empresa, 'contabilidade', 'plano_contas'));
                Cache::forget(ChaveCache::empresa($empresa, 'logistica', 'catalogo_produtos'));
            } else {
                $this->auditoria->registar('Sistema/Migração de dados', 'Importação em massa', "{$def['rotulo']}: {$r['criados']} criado(s), {$r['actualizados']} actualizado(s), "
                    ."{$r['ignorados']} ignorado(s), {$r['repetidos']} repetido(s), ".count($r['rejeitadas']).' rejeitada(s).');
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (! $simular && $r['erros']) {
            throw new ErroNegocio('Nada foi importado: '.count($r['erros']).' linha(s) com erro. Corrija o ficheiro e importe de novo.', 'IMPORTACAO_COM_ERROS', 422,
                ['linhas' => $r['erros'], 'rejeitadas' => $r['rejeitadas']]);
        }

        return $r;
    }

    /**
     * Edição em massa (mapeamento_massa.js:166-245): campos de dados, contas (ou "__RETIRAR__") e, nos produtos, o preço
     * (DEFINIR / PERCENTAGEM / SOMAR, nunca negativo). Tudo ou nada.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $dados
     * @param  array<string, string>  $contas
     * @param  array{modo: string, valor: float}|null  $preco
     * @return array{alterados: int, avisos: list<string>}
     */
    public function editarEmMassa(string $entidade, array $ids, array $dados, array $contas, ?array $preco): array
    {
        $def = self::EDICAO_MASSA[$entidade] ?? throw new ErroNegocio('Entidade desconhecida.', 'ENTIDADE_DESCONHECIDA', 404);
        $dados = array_intersect_key($dados, array_flip($def['dados']));
        $contas = array_intersect_key($contas, array_flip($def['contas']));
        if (! $dados && ! $contas && ! $preco) {
            throw new ErroNegocio('Altere pelo menos um campo.', 'SEM_ALTERACOES', 422);
        }
        foreach ($contas as $campo => $codigo) {
            if ($codigo === self::RETIRAR) {
                if ($campo === 'codigo_conta' && $entidade !== 'produtos') {
                    throw new ErroNegocio('A conta contabilística do terceiro é obrigatória: não pode ser retirada.', 'CONTA_OBRIGATORIA', 422);
                }
            } else {
                $this->plano->contaDeMovimento((string) $codigo);
            }
        }
        $avisos = [];

        return DB::transaction(function () use ($entidade, $ids, $dados, $contas, $preco, &$avisos) {
            $registos = $entidade === 'produtos' ? Produto::query()->whereIn('id', $ids)->lockForUpdate()->get()
                : Terceiro::query()->whereIn('id', $ids)->whereIn('tipo', $entidade === 'clientes' ? Terceiro::TIPOS_CLIENTE : Terceiro::TIPOS_FORNECEDOR)->lockForUpdate()->get();
            if ($registos->count() !== count(array_unique($ids))) {
                throw new ErroNegocio('Há registos seleccionados que não existem nesta empresa (ou não são '.$entidade.').', 'REGISTOS_INVALIDOS', 422);
            }
            if ($entidade === 'produtos' && ($dados['movimenta_stock'] ?? null) === false) {
                $comStock = $registos->filter(fn ($p) => (float) $p->quantidade_stock > 0)->count();
                if ($comStock) {
                    $avisos[] = "{$comStock} produto(s) têm stock e deixam de ser controlados em armazém.";
                }
            }
            foreach ($registos as $reg) {
                $alt = $dados;
                foreach ($contas as $campo => $codigo) {
                    $alt[$campo] = $codigo === self::RETIRAR ? null : $codigo;
                }
                if ($entidade === 'produtos') {
                    if ($preco) {
                        $actual = (float) $reg->preco_unitario;
                        $novo = match ($preco['modo']) {
                            'DEFINIR' => (float) $preco['valor'],
                            'PERCENTAGEM' => $actual * (1 + (float) $preco['valor'] / 100),
                            default => $actual + (float) $preco['valor'],
                        };
                        $alt['preco_unitario'] = number_format(max(0, round($novo, 2)), 2, '.', '');
                    }
                    $this->produtos->guardar($alt, $reg);
                } else {
                    $this->terceiros->guardar($entidade === 'clientes' ? Terceiro::CLIENTE : Terceiro::FORNECEDOR, $alt + ['nif' => $reg->nif], $reg);
                }
            }
            $this->auditoria->registar('Sistema/Migração de dados', 'Edição em massa', "{$entidade}: ".$registos->count().' registo(s) — '
                .implode(', ', array_keys($dados + $contas + ($preco ? ['preco_unitario' => 1] : []))).'.', $entidade === 'produtos' ? 'produtos' : 'terceiros', null,
                null, ['ids' => $registos->pluck('id')->all(), 'dados' => $dados, 'contas' => $contas, 'preco' => $preco]);

            return ['alterados' => $registos->count(), 'avisos' => $avisos];
        });
    }

    // ───────────── regras internas ─────────────

    /** @return array<string, mixed> chave normalizada => registo existente */
    private function existentes(string $entidade): array
    {
        $r = [];
        $chave = fn ($v) => mb_strtolower(trim((string) $v));
        match ($entidade) {
            'plano_contas' => PlanoConta::query()->get()->each(function ($c) use (&$r, $chave) {
                $r[$chave($c->codigo)] = $c;
            }),
            'diarios' => DiarioContabil::query()->get()->each(function ($d) use (&$r, $chave) {
                $r[$chave($d->codigo)] = $d;
            }),
            'terceiros' => Terceiro::query()->whereNotNull('nif')->get()->each(function ($t) use (&$r, $chave) {
                $r[$chave($t->nif)] = $t;
            }),
            'bancos' => Banco::query()->get()->each(function ($b) use (&$r, $chave) {
                if ($b->codigo) {
                    $r[$chave($b->codigo)] = $b;
                }
                $r['nome:'.$chave($b->nome)] = $b;
            }),
            'cargos' => CargoFuncao::query()->get()->each(function ($c) use (&$r, $chave) {
                $r[$chave($c->nome)] = $c;
            }),
            'infotipos' => InfotipoSalarial::query()->get()->each(function ($i) use (&$r, $chave) {
                $r[$chave($i->nome)] = $i;
            }),
        };

        return $r;
    }

    /** Lê a linha pelos cabeçalhos do modelo (sem acentos/maiúsculas) ou pelos nomes dos campos. */
    private function normalizarLinha(array $def, array $bruta): array
    {
        $porNome = [];
        foreach ($bruta as $k => $v) {
            $porNome[ServicoPermissoes::norm((string) $k)] = is_string($v) ? trim($v) : $v;
        }
        $l = [];
        foreach ($def['colunas'] as [$cabecalho, $campo]) {
            $v = $porNome[ServicoPermissoes::norm($cabecalho)] ?? $porNome[$campo] ?? null;
            $l[$campo] = $v === null ? '' : (is_bool($v) ? $v : trim((string) $v));
        }

        return $l;
    }

    private function gravarLinha(string $entidade, array $l, mixed $existente, array $opcoes): void
    {
        switch ($entidade) {
            case 'plano_contas':
                $tipo = strtoupper((string) $l['tipo']);
                $tipo = in_array($tipo, ['M', 'T'], true) ? $tipo : 'M';
                $existente ? $this->plano->atualizar($existente, ['descricao' => $l['descricao'], 'tipo' => $tipo])
                    : $this->plano->criar(['codigo' => $l['codigo'], 'descricao' => $l['descricao'], 'tipo' => $tipo]);
                break;
            case 'diarios':
                $codigo = mb_strtoupper($l['codigo']);
                if (! preg_match('/^[A-Za-z0-9-]{1,20}$/', $codigo)) {
                    throw new ErroNegocio("Código de diário inválido: {$l['codigo']}.", 'CODIGO_INVALIDO', 422);
                }
                $existente ? $existente->update(['descricao' => $l['descricao']]) : DiarioContabil::create(['codigo' => $codigo, 'descricao' => $l['descricao']]);
                break;
            case 'terceiros':
                $tipo = match (ServicoPermissoes::norm((string) $l['tipo'])) {
                    '', 'cliente', 'clientes' => Terceiro::CLIENTE,
                    'fornecedor', 'fornecedores' => Terceiro::FORNECEDOR,
                    'colaborador', 'colaboradores' => Terceiro::COLABORADOR,
                    default => throw new ErroNegocio("Tipo de terceiro desconhecido: {$l['tipo']} (use Cliente, Fornecedor ou Colaborador).", 'TIPO_TERCEIRO_INVALIDO', 422),
                };
                $conta = ($l['codigo_conta'] ?? '') !== '' ? $l['codigo_conta'] : ($opcoes['conta_omissao'] ?? null);
                if (! $existente && ! $conta) {
                    throw new ErroNegocio('Associe a conta contabilística do terceiro (coluna Conta ou conta por omissão).', 'CONTA_OBRIGATORIA', 422);
                }
                $this->terceiros->guardar($tipo, ['nif' => $l['nif'], 'nome' => $l['nome']] + ($conta ? ['codigo_conta' => $conta] : []), $existente);
                break;
            case 'bancos':
                $dados = ['nome' => $l['nome'], 'codigo' => $l['codigo']];
                foreach (['nif', 'endereco', 'codigo_conta'] as $c) {
                    if (($l[$c] ?? '') !== '') {
                        $dados[$c] = $l[$c];
                    }
                }
                if ($existente) {
                    unset($dados['codigo']);
                }
                $this->cadastrosRH->guardarBanco($dados, $existente);
                break;
            case 'cargos':
                $this->estrutura->guardarCargo(['nome' => $l['nome']] + (($l['descricao'] ?? '') !== '' ? ['descricao' => $l['descricao']] : []), $existente);
                break;
            case 'infotipos':
                $dados = ['nome' => $l['nome']];
                if ($l['tipo'] !== '') {
                    $tipo = strtoupper((string) $l['tipo']);
                    if (! in_array($tipo, ['VENCIMENTO', 'DESCONTO'], true)) {
                        throw new ErroNegocio("Tipo de rubrica inválido: {$l['tipo']}.", 'TIPO_RUBRICA_INVALIDO', 422);
                    }
                    $dados['tipo'] = $tipo;
                }
                if ($l['sujeito_inss'] !== '') {
                    $dados['sujeito_inss'] = in_array(ServicoPermissoes::norm((string) $l['sujeito_inss']), ['sim', 'true', '1'], true) || $l['sujeito_inss'] === true;
                }
                if ($l['irt'] !== '') {
                    $irt = ServicoPermissoes::norm((string) $l['irt']);
                    $dados['irt'] = in_array($irt, ['ate 30.000 kz isento', 'conditional_30k'], true) ? 'conditional_30k'
                        : (in_array($irt, ['sim', 'true', '1'], true) || $l['irt'] === true ? 'true' : 'false');
                }
                if (! $existente) {
                    $dados += ['tipo' => 'VENCIMENTO', 'sujeito_inss' => false, 'irt' => 'false'];
                }
                $this->cadastrosRH->guardarInfotipo($dados, $existente);
                break;
        }
    }
}
