<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Banco;
use App\Models\CargoFuncao;
use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PeriodoProdutividadeRH;
use App\Models\RegistoProdutividadeRH;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeNegocio;
use App\Models\UnidadeOrganica;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoPermissoes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Importações Excel do RH (ronda 2 — lacunas A-08, A-09 e M-13), com os modelos do legado:
 *   calculo       (importCalculoExcel, app_v2.js:10128)           NIF/Colaborador | Rubrica | Valor | Horas | Dias trabalhados
 *   colaboradores (modules/rh/importar_colaboradores.js)           folha «Colaboradores»: dados principais, ficha, UN/CC, banco/IBAN
 *   contratos     (importContractsExcel, Template_Contratos_Vertical) NIF Colaborador | Infotipo | Valor_Mensal | Dias_Mes | Horas_Dia | Data_Inicio | Data_Fim | Estado
 *   produtividade (prodImportar, produtividade_ui.js:241)          NIF | Código do item | Quantidade | Data | Observações
 * Regras comuns (iguais às do centro de migração): leitura no servidor (ServicoLeituraFolha), simulação com as mesmas
 * validações numa transacção desfeita, importação TUDO OU NADA (uma linha com erro e nada é gravado; o relatório indica
 * a linha e o motivo), linhas repetidas no ficheiro contadas e ignoradas, auditoria. Cada gravação passa pelo serviço do
 * módulo (as mesmas regras da ficha). Correcções face ao legado: o legado gravava linha a linha e parava a meio; o cálculo
 * importava valores ≤ 0 em silêncio e por nome do colaborador; nos contratos ignorava a sobreposição de datas.
 */
final class ServicoImportacaoRH
{
    /** [cabeçalho, campo, obrigatório, instrução, exemplo, aliases] */
    public const MODELOS = [
        'calculo' => ['rotulo' => 'Lançamentos do cálculo', 'colunas' => [
            ['NIF', 'nif', true, 'NIF do colaborador (ou o nome completo, exactamente como na ficha)', '000000000LA000', ['colaborador', 'nome', 'funcionario']],
            ['Rubrica', 'rubrica', true, 'Nome exacto da rubrica (folha Rubricas)', 'Salário Base', ['infotipo', 'nome da rubrica']],
            ['Valor', 'valor', false, 'Valor em Kz (obrigatório, excepto nas rubricas por hora com Horas)', '150000', ['quantia', 'montante', 'valor a pagar']],
            ['Horas', 'horas', false, 'Só nas rubricas por hora (horas extra ou de falta no mês)', '', []],
            ['Dias trabalhados', 'dias_trabalhados', false, 'Opcional, para a proporção dos vencimentos (ex.: 22)', '22', ['dias', 'dias trab']],
        ]],
        'colaboradores' => ['rotulo' => 'Colaboradores', 'folha' => 'Colaboradores', 'colunas' => [
            ['Nome', 'nome_completo', true, 'Nome completo do colaborador', 'Nome do Colaborador', ['nome completo']],
            ['NIF', 'nif', true, 'Identifica o colaborador: se já existir na empresa, a ficha é actualizada (as células vazias não apagam dados)', '000000000LA000', []],
            ['INSS', 'numero_inss', false, 'Número de inscrição na Segurança Social', '', ['numero inss', 'n inss']],
            ['Função', 'cargo_funcao', false, 'Nome exacto de uma função registada', '', ['funcao']],
            ['Tipo de Órgão', 'tipo_organizacao', false, 'Nome exacto de um tipo de órgão registado (vazio num novo = o primeiro da lista)', '', ['tipo de organizacao', 'tipo de orgao']],
            ['Unidade de Negócio', 'unidade_negocio', false, 'Código da unidade de negócio', '', ['un']],
            ['Centro de Custo', 'centro_custo', false, 'Código do centro de custo', '', ['cc']],
            ['Estado', 'estado', false, 'ACTIVO, SUSPENSO ou INACTIVO (vazio num novo = ACTIVO)', 'ACTIVO', []],
            ['Dias de Trabalho', 'dias_uteis_mes', false, 'Dias de trabalho por mês, de 1 a 31 (vazio num novo = 22)', '22', []],
            ['Aposentado', 'reformado', false, 'Sim ou Não (isento de INSS)', 'Não', ['reformado']],
            ['Avençado', 'avencado', false, 'Sim ou Não (sem INSS, IRT Grupo B)', 'Não', []],
            ['Sexo', 'sexo', false, 'M ou F', '', []],
            ['Data de Nascimento', 'data_nascimento', false, 'DD/MM/AAAA ou data do Excel', '', []],
            ['Estado Civil', 'estado_civil', false, 'Solteiro(a), Casado(a), União de facto, Divorciado(a) ou Viúvo(a)', '', []],
            ['Nacionalidade', 'nacionalidade', false, 'Vazio num novo = Angolana', '', []],
            ['Naturalidade', 'naturalidade', false, 'Local de nascimento', '', []],
            ['Província de Naturalidade', 'provincia_naturalidade', false, '', '', []],
            ['Nº BI / Passaporte', 'documento_identificacao', false, 'Número do documento de identificação', '', ['bi', 'n bi', 'documento']],
            ['Validade do Documento', 'documento_validade', false, 'DD/MM/AAAA', '', []],
            ['Data de Admissão', 'data_admissao', false, 'DD/MM/AAAA (base das férias proporcionais do 1.º ano)', '', []],
            ['Endereço', 'endereco', false, '', '', ['morada']],
            ['Bairro', 'bairro', false, '', '', []],
            ['Município', 'municipio', false, '', '', []],
            ['Província', 'provincia', false, 'Província de residência', '', []],
            ['Telefone', 'telefone', false, '', '', []],
            ['Telefone Alternativo', 'telefone_alternativo', false, '', '', []],
            ['E-mail', 'email', false, 'Endereço de e-mail válido', '', ['email']],
            ['Emergência — Nome', 'emergencia_nome', false, '', '', []],
            ['Emergência — Telefone', 'emergencia_telefone', false, '', '', []],
            ['Emergência — Parentesco', 'emergencia_parentesco', false, '', '', []],
            ['Habilitação Máxima', 'habilitacao_maxima', false, 'Nível (ex.: Licenciatura)', '', []],
            ['Unidade Orgânica', 'unidade_organica', false, 'Código ou nome da unidade orgânica', '', []],
            ['Banco', 'banco', false, 'Código ou nome do banco. Obrigatório com IBAN', '', []],
            ['IBAN', 'iban', false, 'IBAN da conta de pagamento do salário', '', []],
        ]],
        'contratos' => ['rotulo' => 'Contratos (formato vertical)', 'colunas' => [
            ['NIF Colaborador', 'nif', true, 'NIF do colaborador já registado (repita a linha para cada rubrica)', '000000000LA000', ['nif']],
            ['Infotipo', 'rubrica', true, 'Nome exacto da rubrica de vencimento', 'Salário Base', ['rubrica']],
            ['Valor_Mensal', 'valor_mes', true, 'Valor MENSAL da rubrica (o valor dia e o valor hora são apurados)', '150000', ['valor mensal', 'valor']],
            ['Dias_Mes', 'dias_contrato_mes', false, 'Dias contratados por mês (por omissão 22)', '22', ['dias mes']],
            ['Horas_Dia', 'horas_por_dia', false, 'Horas de trabalho por dia (por omissão 8)', '8', ['horas dia']],
            ['Data_Inicio', 'data_inicio', false, 'Data de início (DD/MM/AAAA, DDMMAAAA ou data do Excel); obrigatória na 1.ª linha de cada NIF', '01/01/2026', ['data inicio']],
            ['Data_Fim', 'data_fim', false, 'Data de fim (vazio = sem fim)', '', ['data fim']],
            ['Estado', 'estado', false, 'ACTIVO (por omissão) ou SUSPENSO', 'ACTIVO', []],
        ]],
        'produtividade' => ['rotulo' => 'Registos de produtividade', 'folha' => 'Produtividade', 'colunas' => [
            ['NIF', 'nif', true, 'NIF do colaborador (tem de ter o item no contrato activo)', '000000000LA000', []],
            ['Código do item', 'item', true, 'Código do item de produtividade', 'P01', ['codigo do item', 'item']],
            ['Quantidade', 'quantidade', true, 'Quantidade medida na unidade do item (em % nos objectivos)', '10', []],
            ['Data', 'data', false, 'Opcional, dentro da janela de medição (sem data = total do período)', '', []],
            ['Observações', 'observacoes', false, 'Opcional', '', ['observacoes']],
        ]],
    ];

    public function __construct(
        private readonly ServicoFolhaSalarial $folha,
        private readonly ServicoColaboradores $colaboradores,
        private readonly ServicoContratosTrabalho $contratos,
        private readonly ServicoProdutividade $produtividade,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Modelos ─────────────

    /** Modelo Excel: folha de dados (cabeçalhos + exemplo), «Como_Preencher» e uma folha de referência. */
    public function modeloExcel(string $entidade, string $caminho): void
    {
        $def = self::MODELOS[$entidade] ?? throw new ErroNegocio('Modelo de importação desconhecido.', 'ENTIDADE_DESCONHECIDA', 404);
        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet()->setTitle($def['folha'] ?? 'Template');
        $folha->fromArray([array_column($def['colunas'], 0), array_column($def['colunas'], 4)]);
        foreach (range(1, count($def['colunas'])) as $i) {
            $folha->getColumnDimensionByColumn($i)->setWidth(20);
        }
        $ajuda = $livro->createSheet()->setTitle('Como_Preencher');
        $ajuda->fromArray(array_merge([['Campo', 'O que preencher', 'Obrigatório']], array_map(fn ($c) => [$c[0], $c[3], $c[2] ? 'Sim' : 'Não'], $def['colunas']),
            [['Nota', 'Uma linha com erro e nada é gravado: corrija o ficheiro e importe de novo. Use primeiro «Simular».', '']]));
        foreach (['A' => 28, 'B' => 80, 'C' => 14] as $col => $largura) {
            $ajuda->getColumnDimension($col)->setWidth($largura);
        }
        $ref = match ($entidade) {
            'calculo' => ['Rubricas', [['Rubrica', 'Tipo']], InfotipoSalarial::query()->orderBy('nome')->get()->map(fn ($i) => [$i->nome, $i->tipo])->all()],
            'contratos' => ['Infotipos_Validos', [['Rubrica (vencimento)']], InfotipoSalarial::query()->where('tipo', 'VENCIMENTO')->orderBy('nome')->pluck('nome')->map(fn ($n) => [$n])->all()],
            'produtividade' => ['Itens', [['Código', 'Descrição', 'Unidade', 'Preço (Kz)']], ItemProdutividadeRH::query()->where('ativo', true)->orderBy('codigo')->get()
                ->map(fn ($i) => [$i->codigo, $i->descricao, $i->unidade, (float) $i->preco_unitario])->all()],
            default => ['Listas', [['Funções', 'Tipos de órgão', 'Bancos']], $this->listasColaboradores()],
        };
        $livro->createSheet()->setTitle($ref[0])->fromArray(array_merge($ref[1], $ref[2]));
        (new Xlsx($livro))->save($caminho);
    }

    // ───────────── Cálculo (A-08) ─────────────

    /** @param  list<array<string, mixed>>  $linhas */
    public function importarCalculo(PeriodoProcessamentoSalarial $p, array $linhas, bool $simular): array
    {
        $colabs = Colaborador::query()->get();
        $porNif = $colabs->filter(fn ($c) => $c->nif)->keyBy(fn ($c) => ServicoColaboradores::normalizarNif((string) $c->nif));
        $porNome = $colabs->keyBy(fn ($c) => self::chave($c->nome_completo));
        $rubricas = InfotipoSalarial::query()->get()->keyBy(fn ($i) => self::chave($i->nome));
        $validas = [];
        $r = self::relatorio();
        foreach ($this->normalizar('calculo', $linhas, $r) as [$n, $l]) {
            $c = $porNif[ServicoColaboradores::normalizarNif((string) $l['nif'])] ?? $porNome[self::chave($l['nif'])] ?? null;
            $i = $rubricas[self::chave($l['rubrica'])] ?? null;
            $horas = $l['horas'] !== '' ? self::numero($l['horas']) : null;
            $valor = $l['valor'] !== '' ? self::numero($l['valor']) : null;
            $erro = match (true) {
                ! $c => "colaborador «{$l['nif']}» não encontrado",
                ! $i => "rubrica «{$l['rubrica']}» não encontrada",
                $horas === null && ($valor === null || $valor <= 0) => 'indique um valor maior que zero (ou as horas, nas rubricas por hora)',
                default => null,
            };
            if ($erro) {
                $r['rejeitadas'][] = ['linha' => $n, 'motivo' => $erro];

                continue;
            }
            $k = "{$c->id}|{$i->id}";
            if (isset($validas[$k])) {
                $r['repetidos']++;

                continue;
            }
            $validas[$k] = ['linha' => $n, 'dados' => ['colaborador_id' => $c->id, 'infotipo_salarial_id' => $i->id, 'valor' => $horas !== null ? 0 : $valor, 'horas' => $horas,
                'dias_trabalhados' => $l['dias_trabalhados'] !== '' ? self::numero($l['dias_trabalhados']) : null, 'origem' => 'EXCEL']];
        }

        return $this->executar($r, $validas, $simular, "Cálculo {$p->mes_ano}", function (array $d) use ($p) {
            $this->folha->gravarLancamento($p, $d);

            return 'criados';
        });
    }

    // ───────────── Colaboradores (A-09) ─────────────

    /** @param  list<array<string, mixed>>  $linhas */
    public function importarColaboradores(array $linhas, string $decisao, bool $simular): array
    {
        $existentes = Colaborador::query()->get()->filter(fn ($c) => $c->nif)->keyBy(fn ($c) => ServicoColaboradores::normalizarNif((string) $c->nif));
        $ref = [
            'cargo' => CargoFuncao::query()->get()->keyBy(fn ($x) => self::chave($x->nome)),
            'tipo' => TipoOrganizacaoRH::query()->orderBy('id')->get(),
            'un' => UnidadeNegocio::query()->get()->keyBy(fn ($x) => self::chave($x->codigo)),
            'cc' => CentroCusto::query()->get()->keyBy(fn ($x) => self::chave($x->codigo)),
            'uo' => UnidadeOrganica::query()->get(),
            'banco' => Banco::query()->get(),
        ];
        $r = self::relatorio();
        $validas = [];
        foreach ($this->normalizar('colaboradores', $linhas, $r) as [$n, $l]) {
            $nif = ServicoColaboradores::normalizarNif((string) $l['nif']);
            if (isset($validas[$nif])) {
                $r['repetidos']++;

                continue;
            }
            $existente = $existentes[$nif] ?? null;
            try {
                $dados = $this->dadosColaborador($l, $ref, $existente);
            } catch (ErroNegocio $e) {
                $r['rejeitadas'][] = ['linha' => $n, 'motivo' => $e->getMessage()];

                continue;
            }
            $existente ? $r['existentes'][] = $l['nif'] : $r['novos']++;
            $validas[$nif] = ['linha' => $n, 'dados' => $dados, 'existente' => $existente];
        }

        return $this->executar($r, $validas, $simular, 'Colaboradores', function (array $d, $existente) use ($decisao) {
            if ($existente && $decisao !== 'ACTUALIZAR') {
                return 'ignorados';
            }
            $banco = $d['_banco'] ?? null;
            $iban = $d['_iban'] ?? null;
            unset($d['_banco'], $d['_iban']);
            $c = $this->colaboradores->guardar($existente ? array_merge(['nif' => $existente->nif], $d) : $d, $existente);
            if ($iban) {
                $this->colaboradores->gravarCoordenada($c, $banco, $iban);
            }

            return $existente ? 'actualizados' : 'criados';
        });
    }

    // ───────────── Contratos (A-09) ─────────────

    /** @param  list<array<string, mixed>>  $linhas */
    public function importarContratos(array $linhas, bool $simular): array
    {
        $porNif = Colaborador::query()->get()->filter(fn ($c) => $c->nif)->keyBy(fn ($c) => ServicoColaboradores::normalizarNif((string) $c->nif));
        $rubricas = InfotipoSalarial::query()->get()->keyBy(fn ($i) => self::chave($i->nome));
        $r = self::relatorio();
        $grupos = [];
        foreach ($this->normalizar('contratos', $linhas, $r) as [$n, $l]) {
            $c = $porNif[ServicoColaboradores::normalizarNif((string) $l['nif'])] ?? null;
            $i = $rubricas[self::chave($l['rubrica'])] ?? null;
            $valor = self::numero($l['valor_mes']);
            $inicio = self::data($l['data_inicio']);
            $erro = match (true) {
                ! $c => "colaborador com o NIF {$l['nif']} não encontrado",
                ! $i => "rubrica «{$l['rubrica']}» não encontrada",
                $i->tipo !== 'VENCIMENTO' => "a rubrica {$i->nome} não é um vencimento",
                $valor === null || $valor <= 0 => 'valor mensal inválido ou zero',
                ! isset($grupos[$c->id]) && ! $inicio => 'data de início em falta ou inválida',
                default => null,
            };
            if ($erro) {
                $r['rejeitadas'][] = ['linha' => $n, 'motivo' => $erro];

                continue;
            }
            if (! isset($grupos[$c->id])) {
                $fim = $l['data_fim'] !== '' ? self::data($l['data_fim']) : null;
                $grupos[$c->id] = ['linha' => $n, 'existente' => null, 'dados' => ['colaborador_id' => $c->id, 'data_inicio' => $inicio, 'data_fim' => $fim,
                    'dias_contrato_mes' => $l['dias_contrato_mes'] !== '' ? (int) self::numero($l['dias_contrato_mes']) : 22,
                    'horas_por_dia' => $l['horas_por_dia'] !== '' ? self::numero($l['horas_por_dia']) : 8,
                    'estado' => in_array(strtoupper((string) $l['estado']), ['ACTIVO', 'SUSPENSO'], true) ? strtoupper((string) $l['estado']) : 'ACTIVO', 'remuneracoes' => []]];
                $r['novos']++;
            }
            if (collect($grupos[$c->id]['dados']['remuneracoes'])->contains('infotipo_salarial_id', $i->id)) {
                $r['repetidos']++;

                continue;
            }
            $grupos[$c->id]['dados']['remuneracoes'][] = ['infotipo_salarial_id' => $i->id, 'valor_mes' => $valor];
        }

        return $this->executar($r, $grupos, $simular, 'Contratos', function (array $d) {
            $this->contratos->guardar($d);

            return 'criados';
        });
    }

    // ───────────── Produtividade (M-13) ─────────────

    /** @param  list<array<string, mixed>>  $linhas */
    public function importarProdutividade(PeriodoProdutividadeRH $p, array $linhas, string $decisao, bool $simular): array
    {
        $porNif = Colaborador::query()->get()->filter(fn ($c) => $c->nif)->keyBy(fn ($c) => ServicoColaboradores::normalizarNif((string) $c->nif));
        $itens = ItemProdutividadeRH::query()->get()->keyBy(fn ($i) => self::chave($i->codigo));
        $r = self::relatorio();
        $validas = [];
        foreach ($this->normalizar('produtividade', $linhas, $r) as [$n, $l]) {
            $c = $porNif[ServicoColaboradores::normalizarNif((string) $l['nif'])] ?? null;
            $i = $itens[self::chave($l['item'])] ?? null;
            $q = self::numero($l['quantidade']);
            $data = $l['data'] !== '' ? self::data($l['data']) : null;
            $erro = match (true) {
                ! $c => "colaborador com o NIF {$l['nif']} não encontrado",
                ! $i => "item «{$l['item']}» não encontrado",
                $q === null || $q < 0 => 'quantidade inválida',
                $l['data'] !== '' && ! $data => 'data inválida',
                default => null,
            };
            if ($erro) {
                $r['rejeitadas'][] = ['linha' => $n, 'motivo' => $erro];

                continue;
            }
            $k = "{$c->id}|{$i->id}|{$data}";
            if (isset($validas[$k])) {
                $r['repetidos']++;

                continue;
            }
            $existente = RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->where('colaborador_id', $c->id)->where('item_produtividade_id', $i->id)
                ->when($data, fn ($x) => $x->where('data', $data), fn ($x) => $x->whereNull('data'))->first();
            $existente ? $r['existentes'][] = "{$l['nif']} · {$l['item']}" : $r['novos']++;
            $validas[$k] = ['linha' => $n, 'existente' => $existente, 'dados' => ['colaborador_id' => $c->id, 'item_produtividade_id' => $i->id, 'quantidade' => $q,
                'data' => $data, 'observacoes' => $l['observacoes'] ?: null]];
        }

        return $this->executar($r, $validas, $simular, "Produtividade {$p->mes}", function (array $d, $existente) use ($p, $decisao) {
            if ($existente && $decisao !== 'ACTUALIZAR') {
                return 'ignorados';
            }
            $this->produtividade->gravarRegisto($p, $d, $existente, 'IMPORTACAO');

            return $existente ? 'actualizados' : 'criados';
        });
    }

    // ───────────── Auxiliares ─────────────

    private static function relatorio(): array
    {
        return ['novos' => 0, 'existentes' => [], 'repetidos' => 0, 'rejeitadas' => [], 'criados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'erros' => []];
    }

    /**
     * Linhas normalizadas pelos cabeçalhos do modelo (sem acentos/maiúsculas) e pelos aliases do legado; linhas vazias
     * saltam; as que não têm os campos obrigatórios vão para as rejeitadas.
     *
     * @return list<array{0: int, 1: array<string, string>}>
     */
    private function normalizar(string $entidade, array $linhas, array &$r): array
    {
        $saida = [];
        foreach ($linhas as $idx => $bruta) {
            $n = $idx + 2;
            $porNome = [];
            foreach ((array) $bruta as $k => $v) {
                $porNome[ServicoPermissoes::norm((string) $k)] = is_string($v) ? trim($v) : $v;
            }
            $l = [];
            foreach (self::MODELOS[$entidade]['colunas'] as [$cab, $campo, , , , $aliases]) {
                $v = $porNome[ServicoPermissoes::norm($cab)] ?? $porNome[$campo] ?? null;
                foreach ($aliases as $a) {
                    $v ??= $porNome[ServicoPermissoes::norm($a)] ?? null;
                }
                $l[$campo] = $v === null ? '' : trim((string) $v);
            }
            if (array_filter($l, fn ($v) => $v !== '') === []) {
                continue;
            }
            $falta = array_values(array_filter(self::MODELOS[$entidade]['colunas'], fn ($c) => $c[2] && $l[$c[1]] === ''));
            if ($falta) {
                $r['rejeitadas'][] = ['linha' => $n, 'motivo' => 'falta '.implode(', ', array_column($falta, 0))];

                continue;
            }
            $saida[] = [$n, $l];
        }

        return $saida;
    }

    /**
     * Grava tudo numa transacção; desfaz na simulação ou se alguma linha falhar (e então recusa com o relatório).
     *
     * @param  array<array-key, array{linha: int, dados: array, existente?: mixed}>  $validas
     */
    private function executar(array $r, array $validas, bool $simular, string $rotulo, callable $gravar): array
    {
        if ($r['rejeitadas'] && ! $simular) {
            throw new ErroNegocio('Nada foi importado: '.count($r['rejeitadas']).' linha(s) com erro. Corrija o ficheiro e importe de novo.', 'IMPORTACAO_COM_ERROS', 422,
                ['rejeitadas' => $r['rejeitadas']]);
        }
        DB::beginTransaction();
        try {
            foreach ($validas as $v) {
                try {
                    $resultado = DB::transaction(fn () => $gravar($v['dados'], $v['existente'] ?? null));
                    $r[$resultado]++;
                } catch (ErroNegocio $e) {
                    $r['erros'][] = ['linha' => $v['linha'], 'motivo' => $e->getMessage()];
                } catch (QueryException) {
                    $r['erros'][] = ['linha' => $v['linha'], 'motivo' => 'Registo recusado pela base de dados (valor demasiado longo, duplicado ou inválido).'];
                }
            }
            if ($simular || $r['erros']) {
                DB::rollBack();
            } else {
                $this->auditoria->registar('RH', 'Importação Excel', "{$rotulo}: {$r['criados']} criado(s), {$r['actualizados']} actualizado(s), {$r['ignorados']} ignorado(s), "
                    ."{$r['repetidos']} repetido(s).");
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

        return $r + ['simulacao' => $simular];
    }

    /** @return array<string, mixed> dados para ServicoColaboradores::guardar (só os campos preenchidos num existente) */
    private function dadosColaborador(array $l, array $ref, ?Colaborador $existente): array
    {
        $d = [];
        foreach (['nome_completo', 'nif', 'numero_inss', 'nacionalidade', 'naturalidade', 'provincia_naturalidade', 'documento_identificacao', 'endereco', 'bairro', 'municipio',
            'provincia', 'telefone', 'telefone_alternativo', 'email', 'emergencia_nome', 'emergencia_telefone', 'emergencia_parentesco', 'habilitacao_maxima'] as $campo) {
            if ($l[$campo] !== '') {
                $d[$campo] = $l[$campo];
            }
        }
        foreach (['data_nascimento', 'documento_validade', 'data_admissao'] as $campo) {
            if ($l[$campo] !== '') {
                $d[$campo] = self::data($l[$campo]) ?? throw new ErroNegocio("data inválida em {$campo}", 'DADOS_INVALIDOS', 422);
            }
        }
        foreach (['reformado', 'avencado'] as $campo) {
            if ($l[$campo] !== '') {
                $d[$campo] = in_array(self::chave($l[$campo]), ['sim', 's', '1', 'true', 'yes'], true);
            }
        }
        if ($l['estado'] !== '') {
            // o legado usava «Não ACTIVO» para os inactivos
            $d['estado'] = ['activo' => 'ACTIVO', 'ativo' => 'ACTIVO', 'inactivo' => 'INACTIVO', 'inativo' => 'INACTIVO', 'naoactivo' => 'INACTIVO', 'suspenso' => 'SUSPENSO'][self::chave($l['estado'])]
                ?? throw new ErroNegocio("estado «{$l['estado']}» inválido", 'DADOS_INVALIDOS', 422);
        }
        if ($l['dias_uteis_mes'] !== '') {
            $d['dias_uteis_mes'] = (int) self::numero($l['dias_uteis_mes']);
        }
        if ($l['sexo'] !== '') {
            $d['sexo'] = strtoupper(substr($l['sexo'], 0, 1));
        }
        if ($l['estado_civil'] !== '') {
            $mapa = ['solteiro' => 'SOLTEIRO', 'solteiroa' => 'SOLTEIRO', 'casado' => 'CASADO', 'casadoa' => 'CASADO', 'uniaodefacto' => 'UNIAO_FACTO', 'uniaofacto' => 'UNIAO_FACTO',
                'divorciado' => 'DIVORCIADO', 'divorciadoa' => 'DIVORCIADO', 'viuvo' => 'VIUVO', 'viuvoa' => 'VIUVO'];
            $d['estado_civil'] = $mapa[self::chave($l['estado_civil'])] ?? throw new ErroNegocio("estado civil «{$l['estado_civil']}» inválido", 'DADOS_INVALIDOS', 422);
        }
        if ($l['cargo_funcao'] !== '') {
            $d['cargo_funcao_id'] = ($ref['cargo'][self::chave($l['cargo_funcao'])] ?? null)?->id ?? throw new ErroNegocio("função «{$l['cargo_funcao']}» não registada", 'DADOS_INVALIDOS', 422);
        }
        if ($l['tipo_organizacao'] !== '') {
            $d['tipo_organizacao_id'] = $ref['tipo']->first(fn ($t) => self::chave($t->nome) === self::chave($l['tipo_organizacao']))?->id
                ?? throw new ErroNegocio("tipo de órgão «{$l['tipo_organizacao']}» não registado", 'DADOS_INVALIDOS', 422);
        } elseif (! $existente) {
            $d['tipo_organizacao_id'] = $ref['tipo']->first()?->id ?? throw new ErroNegocio('não há tipos de órgão registados', 'DADOS_INVALIDOS', 422);
        }
        if ($l['unidade_negocio'] !== '') {
            $d['unidade_negocio_id'] = ($ref['un'][self::chave($l['unidade_negocio'])] ?? null)?->id ?? throw new ErroNegocio("unidade de negócio «{$l['unidade_negocio']}» não encontrada", 'DADOS_INVALIDOS', 422);
        }
        if ($l['centro_custo'] !== '') {
            $d['centro_custo_id'] = ($ref['cc'][self::chave($l['centro_custo'])] ?? null)?->id ?? throw new ErroNegocio("centro de custo «{$l['centro_custo']}» não encontrado", 'DADOS_INVALIDOS', 422);
        }
        if ($l['unidade_organica'] !== '') {
            $d['unidade_organica_id'] = $ref['uo']->first(fn ($u) => self::chave($u->codigo) === self::chave($l['unidade_organica']) || self::chave($u->nome) === self::chave($l['unidade_organica']))?->id
                ?? throw new ErroNegocio("unidade orgânica «{$l['unidade_organica']}» não encontrada", 'DADOS_INVALIDOS', 422);
        }
        if ($l['iban'] !== '') {
            $banco = $ref['banco']->first(fn ($b) => $l['banco'] !== '' && (self::chave($b->codigo) === self::chave($l['banco']) || self::chave($b->nome) === self::chave($l['banco'])));
            $d['_banco'] = $banco?->id ?? throw new ErroNegocio('banco em falta ou não registado (obrigatório com IBAN)', 'DADOS_INVALIDOS', 422);
            $d['_iban'] = $l['iban'];
        }
        $v = Validator::make($d, ['nome_completo' => [$existente ? 'sometimes' : 'required', 'string', 'max:255'], 'nif' => ['required', 'string', 'max:30'],
            'email' => ['sometimes', 'email:rfc', 'max:255'], 'dias_uteis_mes' => ['sometimes', 'integer', 'min:1', 'max:31'], 'sexo' => ['sometimes', 'in:M,F'],
            'habilitacao_maxima' => ['sometimes', 'in:'.implode(',', ServicoColaboradores::NIVEIS)], 'data_nascimento' => ['sometimes', 'date', 'before_or_equal:today']]);
        if ($v->fails()) {
            throw new ErroNegocio($v->errors()->first(), 'DADOS_INVALIDOS', 422);
        }

        return $d;
    }

    private function listasColaboradores(): array
    {
        $a = CargoFuncao::query()->orderBy('nome')->pluck('nome')->all();
        $b = TipoOrganizacaoRH::query()->orderBy('id')->pluck('nome')->all();
        $c = Banco::query()->orderBy('nome')->get()->map(fn ($x) => trim(($x->codigo ? "{$x->codigo} — " : '').$x->nome))->all();
        $n = max(count($a), count($b), count($c));

        return array_map(fn ($i) => [$a[$i] ?? '', $b[$i] ?? '', $c[$i] ?? ''], $n ? range(0, $n - 1) : []);
    }

    public static function chave(?string $v): string
    {
        return preg_replace('/[^a-z0-9]/', '', ServicoPermissoes::norm((string) $v)) ?? '';
    }

    /** Número em formato português ou inglês (1.234,56 / 1,234.56 / 1234.56). */
    public static function numero(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^\d.,-]/', '', (string) $v) ?? '';
        if ($s === '' || $s === '-') {
            return null;
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    /** Data AAAA-MM-DD a partir de AAAA-MM-DD, DD/MM/AAAA, DD-MM-AAAA ou DDMMAAAA (o leitor já converte as datas do Excel). */
    public static function data(mixed $v): ?string
    {
        $s = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) || preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $s, $n) || preg_match('/^(\d{2})(\d{2})(\d{4})$/', $s, $o)) {
            [$a, $me, $d] = isset($m[1]) ? [$m[1], $m[2], $m[3]] : (isset($n[1]) ? [$n[3], $n[2], $n[1]] : [$o[3], $o[2], $o[1]]);

            return checkdate((int) $me, (int) $d, (int) $a) ? sprintf('%04d-%02d-%02d', $a, $me, $d) : null;
        }

        return null;
    }
}
