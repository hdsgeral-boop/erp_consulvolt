<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\NotaDemonstracao;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Notas às demonstrações (nota_demonstracao_id) atribuídas pela conta — E-CON-1.
 *
 * O Balanço e a DR constroem-se pelas notas das linhas (ServicoDemonstracoesFinanceiras); uma linha sem nota fica em
 * «Movimentos por mapear» e desequilibra o Balanço. Este serviço reúne:
 *   - a pesquisa do id da nota pelo código, na empresa activa (códigos comparados sem espaços nem zeros à esquerda, como o
 *     legado — app_v2.js:6073-6074), usada pelas integrações que põem nota como o legado (salários, compras);
 *   - a rotina de manutenção «Sincronizar notas automática» (recoverDataMapping, js/app_v2.js:9208-9250; permissão
 *     config_ferramentas, como no legado — permissoes.js:443), com a mesma tabela de prefixos.
 *
 * Correcções face ao legado (ADR-015/016: correcções explícitas, nunca efeitos de leitura):
 *   - simulação por omissão; só grava com aplicar = true;
 *   - só toca em linhas SEM nota (nunca substitui uma nota escolhida) e nunca em exercícios encerrados (o hook do Dexie
 *     bloqueava a escrita nesses anos — db_v2.js:339-390; aqui excluem-se à partida em vez de falhar a meio);
 *   - transacção única e registo de auditoria com as linhas alteradas por nota (o legado não deixava rasto);
 *   - nota inexistente na empresa: as linhas desse prefixo ficam por tratar e são indicadas (o legado ignorava-as em silêncio).
 */
final class ServicoNotasPorConta
{
    /** Tabela do legado (recoverDataMapping): prefixo da conta → código da nota. Os prefixos não se sobrepõem. */
    public const REGRAS = [
        '11' => '4', '12' => '4', '14' => '4', '18' => '4',
        '13' => '5',
        '15' => '7',
        '2' => '8',
        '31' => '9', '33' => '9', '35' => '9', '37' => '9',
        '43' => '10', '45' => '10',
        '32' => '19', '34' => '21', '36' => '21', '38' => '21',
        '51' => '12',
        '54' => '13', '55' => '13', '56' => '13', '57' => '13',
        '59' => '14',
        '61' => '22', '62' => '22',
        '63' => '23',
        '71' => '27',
        '72' => '28',
        '75' => '29',
        '78' => '35',
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** Código da nota pela tabela do legado (null: conta sem regra). */
    public static function codigoPorConta(string $conta): ?string
    {
        foreach (self::REGRAS as $prefixo => $nota) {
            if (str_starts_with($conta, (string) $prefixo)) {
                return $nota;
            }
        }

        return null;
    }

    /**
     * Ids das notas da empresa activa pelos códigos pedidos (códigos sem espaços nem zeros à esquerda; com códigos repetidos
     * fica a nota mais antiga).
     *
     * @param  list<string>  $codigos
     * @return array<string, int> código => id
     */
    public function ids(array $codigos): array
    {
        $norm = fn (string $c) => ltrim(trim($c), '0');
        $querer = array_flip(array_map($norm, $codigos));
        $saida = [];
        foreach (NotaDemonstracao::query()->orderBy('id')->get(['id', 'codigo']) as $n) {
            $c = $norm((string) $n->codigo);
            if (isset($querer[$c]) && ! isset($saida[$c])) {
                $saida[$c] = (int) $n->id;
            }
        }

        return $saida;
    }

    /**
     * Atribui a nota às linhas de um lançamento que ainda não a têm, por uma regra (conta → código da nota).
     *
     * @param  list<array<string, mixed>>  $linhas
     * @param  callable(string): ?string  $regra
     * @return list<array<string, mixed>>
     */
    public function aplicarALinhas(array $linhas, callable $regra): array
    {
        $codigos = [];
        foreach ($linhas as $l) {
            ($c = $regra((string) $l['codigo_conta'])) !== null && $codigos[$c] = true;
        }
        $ids = $codigos ? $this->ids(array_keys($codigos)) : [];
        foreach ($linhas as &$l) {
            if (empty($l['nota_demonstracao_id']) && ($c = $regra((string) $l['codigo_conta'])) !== null && isset($ids[$c])) {
                $l['nota_demonstracao_id'] = $ids[$c];
            }
        }
        unset($l);

        return $linhas;
    }

    /**
     * Rotina de manutenção: simula (por omissão) ou aplica a nota pela tabela do legado às linhas sem nota da empresa activa.
     *
     * @param  array{aplicar?: bool, data_inicio?: ?string, data_fim?: ?string}  $f
     * @return array<string, mixed>
     */
    public function sincronizar(array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        $aplicar = (bool) ($f['aplicar'] ?? false);

        return DB::transaction(function () use ($empresa, $aplicar, $f) {
            $encerrados = $this->exercicios->anosEncerrados($empresa);
            $base = DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->whereNull('nota_demonstracao_id')
                ->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('data_documento', '>=', $v))
                ->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('data_documento', '<=', $v));
            $foraEncerrados = fn ($q) => $encerrados ? $q->where(fn ($w) => $w->whereNull('data_documento')
                ->orWhereRaw('extract(year from data_documento)::int NOT IN ('.implode(',', array_map('intval', $encerrados)).')')) : $q;

            // (sem FOR UPDATE: não é permitido com GROUP BY; a actualização volta a exigir nota vazia, pelo que nunca substitui uma nota)
            $porConta = $foraEncerrados(clone $base)->selectRaw('codigo_conta, COUNT(*) AS linhas')->groupBy('codigo_conta')->get();
            $emEncerrados = $encerrados ? (clone $base)->whereRaw('extract(year from data_documento)::int IN ('.implode(',', array_map('intval', $encerrados)).')')->count() : 0;
            $ids = $this->ids(array_values(array_unique(self::REGRAS)));
            $descricoes = NotaDemonstracao::query()->whereIn('id', $ids)->pluck('descricao', 'id');

            $porNota = $semRegra = $notaEmFalta = [];
            foreach ($porConta as $c) {
                $codigo = self::codigoPorConta((string) $c->codigo_conta);
                $n = (int) $c->linhas;
                if ($codigo === null) {
                    $classe = substr((string) $c->codigo_conta, 0, 2) ?: '—';
                    $semRegra[$classe] = ($semRegra[$classe] ?? 0) + $n;
                } elseif (! isset($ids[$codigo])) {
                    $notaEmFalta[$codigo] = ($notaEmFalta[$codigo] ?? 0) + $n;
                } else {
                    $porNota[$codigo] ??= ['nota' => $codigo, 'nota_demonstracao_id' => $ids[$codigo], 'descricao' => $descricoes[$ids[$codigo]] ?? null, 'linhas' => 0, 'contas' => []];
                    $porNota[$codigo]['linhas'] += $n;
                    $porNota[$codigo]['contas'][(string) $c->codigo_conta] = $n;
                }
            }

            $alteradas = [];
            if ($aplicar) {
                foreach (self::REGRAS as $prefixo => $codigo) {
                    if (! isset($ids[$codigo])) {
                        continue;
                    }
                    $linhas = $foraEncerrados(clone $base)->where('codigo_conta', 'like', $prefixo.'%')->pluck('id')->all();
                    if ($linhas) {
                        DB::table('lancamentos_contabeis')->whereIn('id', $linhas)->whereNull('nota_demonstracao_id')
                            ->update(['nota_demonstracao_id' => $ids[$codigo], 'atualizado_em' => now()]);
                        $alteradas[$codigo] = array_merge($alteradas[$codigo] ?? [], $linhas);
                    }
                }
                if (! $alteradas) {
                    throw new ErroNegocio('Não há linhas sem nota que a tabela de prefixos consiga mapear.', 'NADA_A_FAZER', 422);
                }
                $total = array_sum(array_map('count', $alteradas));
                $this->auditoria->registar('Contabilidade', 'NOTAS_POR_CONTA', "Notas às demonstrações atribuídas pela conta a {$total} linha(s) sem nota "
                    .'(Sincronizar notas automática).', 'lancamentos_contabeis', null, null,
                    ['filtros' => array_intersect_key($f, ['data_inicio' => 1, 'data_fim' => 1]), 'linhas_por_nota' => $alteradas]);
            }

            ksort($semRegra);
            ksort($notaEmFalta, SORT_NATURAL);
            uksort($porNota, 'strnatcmp');

            return [
                'aplicado' => $aplicar,
                'linhas_a_atribuir' => array_sum(array_column($porNota, 'linhas')),
                'por_nota' => array_values(array_map(fn ($x) => ['contas' => array_map(fn ($k, $v) => ['codigo_conta' => (string) $k, 'linhas' => $v],
                    array_keys($x['contas']), $x['contas'])] + $x, $porNota)),
                'sem_regra' => array_map(fn ($k, $v) => ['prefixo' => (string) $k, 'linhas' => $v], array_keys($semRegra), $semRegra),
                'nota_em_falta' => array_map(fn ($k, $v) => ['nota' => (string) $k, 'linhas' => $v], array_keys($notaEmFalta), $notaEmFalta),
                'exercicios_encerrados' => $encerrados,
                'linhas_em_exercicios_encerrados' => $emEncerrados,
            ];
        });
    }
}
