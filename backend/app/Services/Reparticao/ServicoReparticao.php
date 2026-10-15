<?php

namespace App\Services\Reparticao;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\CriterioReparticaoUN;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Repartição por unidades de negócio (Fase C, ADR-071; legado novo js/modules/configuracoes/reparticao.js e alocacao_un.js).
 *
 * Modelo: o CRITÉRIO guarda só a FORMA de repartir (percentagem, partes iguais, colaboradores, volume de negócios, área,
 * indicador personalizado); o OBJECTO (colaborador, pedido, factura de compra, venda, recibo, linha) guarda que unidades e,
 * quando a forma o pede, o peso de cada uma:
 *     unidades_alocadas = {criterio_id, unidades: [{unidade_negocio_id, peso}]}
 * (o legado grava `business_unit_id`; a leitura aceita as duas chaves).
 *
 * Regras (iguais ao legado, mas em BCMath):
 *   - os pesos normalizam-se a 100 % qualquer que seja a escala do indutor;
 *   - repartir um valor: parcela_i = arred2(valor × indutor_i / Σ indutor); a ÚLTIMA parcela = valor − acumulado, para a
 *     soma ser exactamente o valor (um cêntimo a mais desequilibrava o lançamento);
 *   - validação da alocação: ≥ 2 unidades, sem repetidas, sem pesos negativos, e nas formas manuais pelo menos um peso > 0;
 *   - critérios e unidades são sempre os da empresa activa (âmbito PertenceEmpresa + validação explícita).
 */
final class ServicoReparticao
{
    /** Formas de repartir (reparticao.js:47-78). */
    public const CRITERIOS = [
        'PERCENTAGEM' => ['nome' => 'Percentagem fixa', 'rotulo_peso' => 'Percentagem (%)', 'automatico' => false, 'sem_peso' => false,
            'ajuda' => 'Os pesos são escritos à mão. Use quando a repartição resulta de um acordo ou de uma decisão de gestão.',
            'exemplo' => 'Renda da sede repartida 60% / 40% entre duas unidades.'],
        'PARTES_IGUAIS' => ['nome' => 'Partes iguais', 'rotulo_peso' => '—', 'automatico' => true, 'sem_peso' => true,
            'ajuda' => 'Divide por igual pelas unidades escolhidas. É o critério a usar quando não há indutor que se justifique.',
            'exemplo' => '3 unidades → 33,33% cada.'],
        'COLABORADORES' => ['nome' => 'Número de colaboradores', 'rotulo_peso' => 'Colaboradores', 'automatico' => true, 'sem_peso' => false,
            'ajuda' => 'Peso pelo número de colaboradores afectos a cada unidade, lido das fichas. Indicado para custos de pessoal e de estrutura.',
            'exemplo' => 'Serviços de RH repartidos pelo peso de cada unidade no total do pessoal.'],
        'VOLUME_NEGOCIOS' => ['nome' => 'Volume de negócios', 'rotulo_peso' => 'Vendas do período', 'automatico' => true, 'sem_peso' => false,
            'ajuda' => 'Peso pelas vendas de cada unidade no período indicado. Indicado para custos comerciais e administrativos.',
            'exemplo' => 'Publicidade institucional repartida pelo peso de cada unidade na facturação.'],
        'AREA' => ['nome' => 'Área ocupada (m²)', 'rotulo_peso' => 'Área (m²)', 'automatico' => false, 'sem_peso' => false,
            'ajuda' => 'Peso pela área que cada unidade ocupa. É o critério clássico para renda, limpeza, energia e segurança.',
            'exemplo' => 'Renda de 1 000 m² repartida por 600 m² e 400 m².'],
        'PERSONALIZADO' => ['nome' => 'Indicador personalizado', 'rotulo_peso' => 'Valor do indicador', 'automatico' => false, 'sem_peso' => false,
            'ajuda' => 'Qualquer outro indutor de custo: horas-máquina, nº de documentos, viaturas, pontos de venda. Escreva o nome do indicador e o valor de cada unidade.',
            'exemplo' => 'Manutenção repartida por horas-máquina de cada unidade.'],
    ];

    /**
     * Tabelas/colunas onde um critério pode estar a ser usado (para não o eliminar). `texto` = coluna text; desde a
     * regeneração do esquema (Fase C) todas são jsonb, incluindo vendas.unidades_alocadas.
     */
    private const OBJECTOS = [
        'colaboradores' => false, 'pedidos_compra' => false, 'encomendas_compra' => false, 'faturas_compra' => false,
        'recibos_venda' => false, 'vendas' => false,
    ];

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    // ───────────────────────────── Critérios (R1) ─────────────────────────────

    /** @return Collection<int, CriterioReparticaoUN> */
    public function listar(bool $soActivos = false): Collection
    {
        return CriterioReparticaoUN::query()
            ->when($soActivos, fn ($q) => $q->where(fn ($w) => $w->whereNull('ativo')->orWhere('ativo', true)))
            ->whereNotNull('codigo')->orderBy('codigo')->orderBy('id')->get();
    }

    /**
     * Cria ou altera um critério (reparticao.js:82-108). O código é único na empresa (sem distinguir maiúsculas).
     *
     * @param  array{codigo: string, nome: string, criterio: string, indicador?: ?string, periodo_de?: ?string, periodo_ate?: ?string, notas?: ?string, ativo?: ?bool}  $d
     */
    public function gravar(array $d, ?CriterioReparticaoUN $criterio = null): CriterioReparticaoUN
    {
        $codigo = mb_strtoupper(trim((string) ($d['codigo'] ?? $criterio?->codigo ?? '')));
        if (! preg_match('/^[A-Z0-9._-]{1,20}$/', $codigo)) {
            throw new ErroNegocio('O código só pode ter letras, números, ponto, hífen ou sublinhado (até 20).', 'CODIGO_INVALIDO', 422);
        }
        $forma = $d['criterio'] ?? $criterio?->criterio;
        if (! isset(self::CRITERIOS[$forma])) {
            throw new ErroNegocio('Escolha a forma de repartição.', 'CRITERIO_INVALIDO', 422);
        }
        $de = $d['periodo_de'] ?? null;
        $ate = $d['periodo_ate'] ?? null;
        if ($de && $ate && $de > $ate) {
            throw new ErroNegocio('O período das vendas termina antes de começar.', 'PERIODO_INVALIDO', 422);
        }

        return DB::transaction(function () use ($d, $criterio, $codigo, $forma, $de, $ate) {
            $empresa = $this->contexto->obrigatorio();
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["criterio_reparticao:{$empresa}:{$codigo}"]);
            $repetido = CriterioReparticaoUN::query()->whereRaw('upper(trim(codigo)) = ?', [$codigo])
                ->when($criterio, fn ($q) => $q->where('id', '<>', $criterio->id))->exists();
            if ($repetido) {
                throw new ErroNegocio("Já existe um critério com o código {$codigo}.", 'CODIGO_DUPLICADO', 422);
            }
            $utilizador = Auth::user()?->nome_utilizador;
            $dados = [
                'codigo' => $codigo, 'nome' => trim((string) ($d['nome'] ?? $criterio?->nome)), 'criterio' => $forma,
                'indicador' => $forma === 'PERSONALIZADO' ? (trim((string) ($d['indicador'] ?? $criterio?->indicador ?? '')) ?: null) : null,
                'periodo_de' => $forma === 'VOLUME_NEGOCIOS' ? $de : null, 'periodo_ate' => $forma === 'VOLUME_NEGOCIOS' ? $ate : null,
                'notas' => array_key_exists('notas', $d) ? $d['notas'] : $criterio?->notas,
                'ativo' => array_key_exists('ativo', $d) ? (bool) $d['ativo'] : ($criterio?->ativo ?? true),
                'atualizado_por' => $utilizador,
            ];
            if ($criterio) {
                $criterio->update($dados);

                return $criterio->refresh();
            }

            return CriterioReparticaoUN::create($dados + ['criado_por' => $utilizador]);
        });
    }

    /** Elimina um critério que nenhum registo usa; os que estão em uso desactivam-se (os registos guardam a forma). */
    public function eliminar(CriterioReparticaoUN $criterio): void
    {
        DB::transaction(function () use ($criterio) {
            $usos = $this->usos($criterio->id);
            if ($usos) {
                throw new ErroNegocio("O critério {$criterio->codigo} está a ser usado (".implode(', ', array_map(fn ($t, $n) => "{$t}: {$n}", array_keys($usos), $usos)).
                    '). Desactive-o em vez de o eliminar: os registos que o usam continuam a precisar da forma de repartir.', 'CRITERIO_EM_USO', 422, ['usos' => $usos]);
            }
            $criterio->delete();
        });
    }

    /** @return array<string, int> tabela => n.º de registos que usam o critério */
    public function usos(int $criterioId): array
    {
        $empresa = $this->contexto->obrigatorio();
        $usos = [];
        foreach (self::OBJECTOS as $tabela => $texto) {
            if (! DB::getSchemaBuilder()->hasColumn($tabela, 'unidades_alocadas')) {
                continue;
            }
            $expr = $texto
                ? "CASE WHEN unidades_alocadas ~ '^\\s*\\{' THEN (unidades_alocadas::jsonb ->> 'criterio_id') END"
                : "(unidades_alocadas ->> 'criterio_id')";
            $n = DB::table($tabela)->where('empresa_id', $empresa)->whereNotNull('unidades_alocadas')->whereRaw("{$expr} = ?", [(string) $criterioId])->count();
            if ($n > 0) {
                $usos[$tabela] = $n;
            }
        }

        return $usos;
    }

    // ───────────────────────────── Alocação no objecto (R2) ─────────────────────────────

    /**
     * Regras de validação do campo de alocação (tipo e pertença à empresa activa; as regras de negócio estão em normalizar()).
     *
     * @return array<string, mixed>
     */
    public static function regras(string $campo = 'unidades_alocadas'): array
    {
        $empresa = app(ContextoEmpresa::class)->obrigatorio();

        return [
            $campo => ['nullable', 'array'],
            "{$campo}.criterio_id" => ['nullable', 'integer', Rule::exists('criterios_reparticao_un', 'id')->where('empresa_id', $empresa)],
            "{$campo}.unidades" => ['nullable', 'array', 'max:50'],
            "{$campo}.unidades.*.unidade_negocio_id" => ['required', 'integer', Rule::exists('unidades_negocio', 'id')->where('empresa_id', $empresa)],
            "{$campo}.unidades.*.peso" => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ];
    }

    /**
     * Normaliza e valida a alocação vinda de um formulário para gravar no objecto. Sem critério (ou sem unidades) devolve
     * null: o objecto usa a unidade do campo normal (alocacao_un.js:131).
     *
     * @return array{criterio_id: int, unidades: list<array{unidade_negocio_id: int, peso: ?string}>}|null
     */
    public function normalizar(mixed $alocacao): ?array
    {
        $a = self::ler($alocacao);
        if (! $a || ! $a['criterio_id']) {
            return null;
        }
        $criterio = CriterioReparticaoUN::query()->find($a['criterio_id'])
            ?? throw new ErroNegocio('O critério de repartição não existe nesta empresa.', 'CRITERIO_INEXISTENTE', 422);
        if ($criterio->ativo === false) {
            throw new ErroNegocio("O critério {$criterio->codigo} está inactivo.", 'CRITERIO_INACTIVO', 422);
        }
        $forma = self::CRITERIOS[$criterio->criterio] ?? self::CRITERIOS['PERCENTAGEM'];
        $unidades = $a['unidades'];
        if (count($unidades) < 2) {
            throw new ErroNegocio('Aloque pelo menos duas unidades de negócio.', 'ALOCACAO_INVALIDA', 422);
        }
        $ids = array_column($unidades, 'unidade_negocio_id');
        if (count(array_unique($ids)) !== count($ids)) {
            throw new ErroNegocio('A mesma unidade está alocada mais do que uma vez.', 'ALOCACAO_INVALIDA', 422);
        }
        if (UnidadeNegocio::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw new ErroNegocio('Uma das unidades de negócio não existe nesta empresa.', 'ALOCACAO_INVALIDA', 422);
        }
        foreach ($unidades as $u) {
            if ($u['peso'] !== null && bccomp($u['peso'], '0', 4) < 0) {
                throw new ErroNegocio('Os valores não podem ser negativos.', 'ALOCACAO_INVALIDA', 422);
            }
        }
        if (! $forma['automatico'] && ! array_filter($unidades, fn ($u) => $u['peso'] !== null && bccomp($u['peso'], '0', 4) > 0)) {
            throw new ErroNegocio('Indique '.mb_strtolower($forma['rotulo_peso']).' em pelo menos uma unidade.', 'ALOCACAO_INVALIDA', 422);
        }
        if ($forma['automatico']) {   // nos automáticos o peso é lido dos dados: não se guarda
            $unidades = array_map(fn ($u) => ['unidade_negocio_id' => $u['unidade_negocio_id'], 'peso' => null], $unidades);
        }

        return ['criterio_id' => (int) $criterio->id, 'unidades' => $unidades];
    }

    /**
     * Lê uma alocação gravada (jsonb, texto JSON ou array) — aceita a chave do legado `business_unit_id`.
     *
     * @return array{criterio_id: ?int, unidades: list<array{unidade_negocio_id: int, peso: ?string}>}|null
     */
    public static function ler(mixed $valor): ?array
    {
        if (is_string($valor)) {
            $valor = trim($valor) === '' ? null : json_decode($valor, true);
        }
        if (! is_array($valor)) {
            return null;
        }
        $unidades = [];
        foreach ((array) ($valor['unidades'] ?? []) as $u) {
            $id = is_array($u) ? ($u['unidade_negocio_id'] ?? $u['business_unit_id'] ?? null) : null;
            if (! $id || ! is_numeric($id)) {
                continue;
            }
            $peso = $u['peso'] ?? null;
            $unidades[] = ['unidade_negocio_id' => (int) $id,
                'peso' => $peso === null || $peso === '' || ! is_numeric($peso) ? null : self::decimal((string) $peso, 4)];
        }
        $criterio = $valor['criterio_id'] ?? null;

        return ['criterio_id' => $criterio && is_numeric($criterio) ? (int) $criterio : null, 'unidades' => $unidades];
    }

    /** Alocação sem efeito (sem critério ou sem unidades) — ReparticaoUN.vazia do legado. */
    public static function vazia(mixed $alocacao): bool
    {
        $a = self::ler($alocacao);

        return ! $a || ! $a['criterio_id'] || ! $a['unidades'];
    }

    // ───────────────────────────── Pesos e repartição ─────────────────────────────

    /**
     * Indutor e percentagem (4 casas) de cada unidade alocada, normalizados a 100 % (reparticao.js:131-168).
     *
     * @return list<array{unidade_negocio_id: int, nome: string, indutor: string, percentagem: string}>
     */
    public function pesos(mixed $alocacao): array
    {
        $a = self::ler($alocacao);
        if (! $a || ! $a['unidades']) {
            throw new ErroNegocio('O objecto não tem unidades de negócio alocadas.', 'ALOCACAO_VAZIA', 422);
        }
        $criterio = $a['criterio_id'] ? CriterioReparticaoUN::query()->find($a['criterio_id']) : null;
        if ($a['criterio_id'] && ! $criterio) {
            throw new ErroNegocio("O critério de repartição #{$a['criterio_id']} já não existe: corrija a alocação das unidades de negócio.", 'CRITERIO_INEXISTENTE', 422);
        }
        $forma = $criterio?->criterio ?? 'PERCENTAGEM';
        $ids = array_column($a['unidades'], 'unidade_negocio_id');
        $empresa = $this->contexto->obrigatorio();

        $indutores = match ($forma) {
            'PARTES_IGUAIS' => array_fill_keys($ids, '1'),
            'COLABORADORES' => Colaborador::query()->whereIn('unidade_negocio_id', $ids)
                ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'INACTIVO'))
                ->groupBy('unidade_negocio_id')->selectRaw('unidade_negocio_id, COUNT(*) AS n')->pluck('n', 'unidade_negocio_id')
                ->map(fn ($n) => (string) $n)->all(),
            'VOLUME_NEGOCIOS' => DB::table('vendas')->where('empresa_id', $empresa)->whereIn('unidade_negocio_id', $ids)
                ->whereIn('tipo_documento', ['FT', 'FR', 'NC'])->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
                ->when(self::data($criterio?->periodo_de), fn ($q, $de) => $q->whereDate('data_emissao', '>=', $de))
                ->when(self::data($criterio?->periodo_ate), fn ($q, $ate) => $q->whereDate('data_emissao', '<=', $ate))
                ->groupBy('unidade_negocio_id')
                ->selectRaw("unidade_negocio_id, SUM(CASE WHEN tipo_documento = 'NC' THEN -COALESCE(total_liquido, 0) ELSE COALESCE(total_liquido, 0) END) AS v")
                ->pluck('v', 'unidade_negocio_id')->map(fn ($v) => bccomp(self::decimal((string) $v, 2), '0', 2) > 0 ? self::decimal((string) $v, 2) : '0')->all(),
            default => array_column($a['unidades'], 'peso', 'unidade_negocio_id'),
        };

        $nomes = UnidadeNegocio::query()->whereIn('id', $ids)->get(['id', 'codigo', 'nome'])
            ->mapWithKeys(fn ($u) => [$u->id => trim(($u->codigo ? "{$u->codigo} — " : '').$u->nome)])->all();
        $soma = '0';
        foreach ($ids as $id) {
            $soma = bcadd($soma, (string) ($indutores[$id] ?? '0'), 4);
        }

        return array_map(fn (int $id) => [
            'unidade_negocio_id' => $id, 'nome' => $nomes[$id] ?? "Unidade #{$id}", 'indutor' => self::decimal((string) ($indutores[$id] ?? '0'), 4),
            'percentagem' => bccomp($soma, '0', 4) > 0 ? self::arredondar(bcdiv(bcmul((string) ($indutores[$id] ?? '0'), '100', 12), $soma, 12), 4) : '0.0000',
        ], $ids);
    }

    /**
     * Partes com peso para a contabilização, ou null se a alocação não tem efeito. Sem nenhuma unidade com peso a
     * integração pára e diz porquê (sem repartições escondidas).
     *
     * @return list<array{unidade_negocio_id: int, nome: string, indutor: string, percentagem: string}>|null
     */
    public function partes(mixed $alocacao, string $contexto = ''): ?array
    {
        if (self::vazia($alocacao)) {
            return null;
        }
        $partes = array_values(array_filter($this->pesos($alocacao), fn ($p) => bccomp($p['indutor'], '0', 4) > 0));
        if (! $partes) {
            throw new ErroNegocio(trim("{$contexto} nenhuma das unidades alocadas tem valor — nada a repartir. Verifique o critério e os valores."), 'REPARTICAO_SEM_PESO', 422);
        }

        return $partes;
    }

    /**
     * Divide um valor (2 casas, pode ser negativo) pelos indutores: a última parcela absorve o arredondamento.
     *
     * @param  list<string>  $indutores  todos > 0
     * @return list<string>
     */
    public static function dividir(string $valor, array $indutores): array
    {
        $valor = self::decimal($valor, 2);
        $soma = '0';
        foreach ($indutores as $i) {
            $soma = bcadd($soma, (string) $i, 6);
        }
        if (! $indutores || bccomp($soma, '0', 6) <= 0) {
            throw new ErroNegocio('Nada a repartir: os pesos somam zero.', 'REPARTICAO_SEM_PESO', 422);
        }
        $acumulado = '0.00';
        $parcelas = [];
        $ultimo = count($indutores) - 1;
        foreach (array_values($indutores) as $n => $i) {
            $parcela = $n === $ultimo ? bcsub($valor, $acumulado, 2) : self::arredondar(bcdiv(bcmul($valor, (string) $i, 12), $soma, 12), 2);
            $acumulado = bcadd($acumulado, $parcela, 2);
            $parcelas[] = $parcela;
        }

        return $parcelas;
    }

    /**
     * Reparte um valor pelas unidades alocadas (pré-visualização do componente e «Repartir linha»).
     *
     * @return array{linhas: list<array{unidade_negocio_id: int, nome: string, indutor: string, percentagem: string, valor: string}>, total: string}
     */
    public function repartir(string $valor, mixed $alocacao): array
    {
        $partes = array_values(array_filter($this->pesos($alocacao), fn ($p) => bccomp($p['indutor'], '0', 4) > 0));
        if (! $partes) {
            throw new ErroNegocio('Nenhuma das unidades alocadas tem valor — nada a repartir. Verifique o critério e os valores.', 'REPARTICAO_SEM_PESO', 422);
        }
        $valores = self::dividir($valor, array_column($partes, 'indutor'));
        $linhas = array_map(fn ($p, $v) => $p + ['valor' => $v], $partes, $valores);

        return ['linhas' => $linhas, 'total' => array_reduce($valores, fn ($s, $v) => bcadd($s, $v, 2), '0.00')];
    }

    /**
     * Reparte as linhas de um lançamento (R3). Cada linha repartível é substituída por uma por unidade, com a parcela e a
     * descrição «xx,xx% <unidade>»; o valor em moeda (se houver) reparte-se pelas mesmas proporções. As linhas com
     * `_propria` (unidade escolhida na própria linha do documento — linha › repartição › cabeçalho) ou não repartíveis
     * ficam como estão. Os campos internos (`_propria`, `_repartivel`) são retirados.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @param  list<array{unidade_negocio_id: int, nome: string, indutor: string, percentagem: string}>|null  $partes
     * @param  callable(array<string, mixed>): bool  $repartivel
     * @return list<array<string, mixed>>
     */
    public static function repartirLinhas(array $linhas, ?array $partes, callable $repartivel, ?string $descricaoBase = null): array
    {
        $saida = [];
        foreach ($linhas as $l) {
            $propria = ! empty($l['_propria']);
            unset($l['_propria']);
            if (! $partes || $propria || ! $repartivel($l)) {
                unset($l['_repartivel']);
                $saida[] = $l;

                continue;
            }
            unset($l['_repartivel']);
            $indutores = array_column($partes, 'indutor');
            $valores = self::dividir((string) $l['valor'], $indutores);
            $moedas = isset($l['valor_moeda']) && $l['valor_moeda'] !== null ? self::dividir((string) $l['valor_moeda'], $indutores) : null;
            $base = trim((string) ($l['descricao'] ?? $descricaoBase ?? ''));
            foreach ($partes as $n => $p) {
                if (bccomp($valores[$n], '0', 2) === 0) {
                    continue;
                }
                $pct = number_format((float) $p['percentagem'], 2, ',', '');
                $saida[] = array_merge($l, ['valor' => $valores[$n], 'unidade_negocio_id' => $p['unidade_negocio_id'],
                    'descricao' => mb_substr(($base !== '' ? "{$base} · " : '')."{$pct}% {$p['nome']}", 0, 1000)],
                    $moedas ? ['valor_moeda' => $moedas[$n]] : []);
            }
        }

        return $saida;
    }

    // ───────────────────────────── Auxiliares ─────────────────────────────

    public static function arredondar(string $v, int $casas): string
    {
        $ajuste = '0.'.str_repeat('0', $casas).'5';

        return bccomp($v, '0', 12) >= 0 ? bcadd($v, $ajuste, $casas) : bcsub($v, $ajuste, $casas);
    }

    private static function decimal(string $v, int $casas): string
    {
        $v = trim($v);
        if (! is_numeric($v)) {
            return number_format(0, $casas, '.', '');
        }
        if (stripos($v, 'e') !== false) {
            $v = number_format((float) $v, 12, '.', '');
        }

        return self::arredondar($v, $casas);
    }

    private static function data(?string $v): ?string
    {
        $v = $v ? substr(trim($v), 0, 10) : null;

        return $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
