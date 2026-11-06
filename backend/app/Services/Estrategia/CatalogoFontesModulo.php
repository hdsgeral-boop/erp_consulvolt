<?php

namespace App\Services\Estrategia;

use App\Exceptions\ErroNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lista FECHADA das fontes da origem «Dados de um módulo» (EST-C-11), só leitura. O cliente nunca envia nomes de tabela nem de
 * coluna: envia o id da fonte, o id do campo e os ids dos filtros; tudo o que toca na BD (tabela, coluna da empresa, da data e da
 * unidade, expressões) são constantes deste ficheiro. Nesta onda existem as duas fontes que o sistema novo sabe medir por
 * unidade: entregáveis e colaboradores. As restantes fontes do legado (vendas, compras, CRM, projectos…) entram com o painel.
 */
final class CatalogoFontesModulo
{
    /** @var array<string, array<string, mixed>> */
    private const FONTES = [
        'entregaveis' => [
            'nome' => 'Entregáveis das unidades', 'tabela' => 'execucoes_entregavel', 'data' => 'data', 'unidade' => 'unidade_organica_id', 'apagado' => null,
            'campos' => ['quantidade' => 'Quantidade entregue'],
            'filtros' => [
                'validacao' => ['nome' => 'Validação', 'tipo' => 'texto', 'opcoes' => ['PENDENTE', 'VALIDADA', 'DEVOLVIDA', 'RECUSADA']],
                'unidade_organica_id' => ['nome' => 'Unidade orgânica', 'tipo' => 'numero'],
                'colaborador_id' => ['nome' => 'Colaborador', 'tipo' => 'numero'],
                'entregavel_id' => ['nome' => 'Entregável', 'tipo' => 'numero'],
            ],
        ],
        'colaboradores' => [
            'nome' => 'Colaboradores (admissões)', 'tabela' => 'colaboradores', 'data' => 'data_admissao', 'unidade' => 'unidade_organica_id', 'apagado' => 'eliminado_em',
            'campos' => [],
            'filtros' => [
                'estado' => ['nome' => 'Estado', 'tipo' => 'texto', 'opcoes' => ['ACTIVO', 'INACTIVO']],
                'unidade_organica_id' => ['nome' => 'Unidade orgânica', 'tipo' => 'numero'],
            ],
        ],
    ];

    /** O catálogo para o ecrã (sem nomes de tabela nem de coluna). @return list<array<string, mixed>> */
    public static function catalogo(): array
    {
        $saida = [];
        foreach (self::FONTES as $id => $f) {
            $saida[] = [
                'id' => $id, 'nome' => $f['nome'], 'mede_por_unidade' => true,
                'campos' => array_map(fn ($cid, $nome) => ['id' => $cid, 'nome' => $nome], array_keys($f['campos']), $f['campos']),
                'filtros' => array_map(fn ($fid, $d) => ['id' => $fid, 'nome' => $d['nome'], 'tipo' => $d['tipo'], 'opcoes' => $d['opcoes'] ?? null], array_keys($f['filtros']), $f['filtros']),
            ];
        }

        return $saida;
    }

    /**
     * Valida e normaliza a configuração da origem MODULO. Devolve só chaves conhecidas.
     *
     * @param  array<string, mixed>  $fonte
     * @return array<string, mixed>
     */
    public static function validar(array $fonte): array
    {
        $id = (string) ($fonte['modulo'] ?? '');
        $def = self::FONTES[$id] ?? null;
        if ($def === null) {
            throw new ErroNegocio('Escolha de que dados do módulo vem o valor.', 'FONTE_INVALIDA');
        }
        $operacao = (string) ($fonte['operacao'] ?? '');
        if (! isset(Vocabulario::OPERACOES[$operacao])) {
            throw new ErroNegocio('Escolha a operação: contar, somar ou calcular a média.', 'FONTE_SEM_OPERACAO');
        }
        $campo = null;
        if ($operacao !== 'CONTAGEM') {
            $campo = (string) ($fonte['campo'] ?? '');
            if (! isset($def['campos'][$campo])) {
                throw new ErroNegocio($def['campos'] === [] ? 'Esta fonte não tem campos numéricos: só se podem contar registos.' : 'Escolha o campo a somar ou a calcular a média.', 'FONTE_SEM_CAMPO');
            }
        }
        $filtros = [];
        foreach ((array) ($fonte['filtros'] ?? []) as $f) {
            $fid = (string) ($f['campo'] ?? '');
            $op = (string) ($f['operador'] ?? '');
            $fd = $def['filtros'][$fid] ?? null;
            if ($fd === null || ! isset(Vocabulario::OPERADORES[$op])) {
                throw new ErroNegocio('Há um filtro com um campo ou operador que não existe nesta fonte.', 'FONTE_FILTRO_INVALIDO');
            }
            $valor = isset($f['valor']) ? trim((string) $f['valor']) : '';
            $semValor = in_array($op, ['PREENCHIDO', 'VAZIO'], true);
            if (! $semValor && $valor === '') {
                throw new ErroNegocio("Indique o valor do filtro «{$fd['nome']}».", 'FONTE_FILTRO_SEM_VALOR');
            }
            if (! $semValor && ($fd['tipo'] === 'numero' || in_array($op, ['MAIOR', 'MENOR'], true)) && CalculadoraDesempenho::numero($valor) === null) {
                throw new ErroNegocio("O valor do filtro «{$fd['nome']}» tem de ser um número.", 'FONTE_FILTRO_NUMERO');
            }
            if (! $semValor && $fd['tipo'] === 'numero' && $op === 'CONTEM') {
                throw new ErroNegocio("O filtro «{$fd['nome']}» é numérico: «contém» não se aplica.", 'FONTE_FILTRO_OPERADOR');
            }
            $filtros[] = ['campo' => $fid, 'operador' => $op, 'valor' => $semValor ? null : $valor];
        }

        return ['modulo' => $id, 'operacao' => $operacao, 'campo' => $campo, 'filtros' => $filtros];
    }

    /**
     * EST-C-11: mede a fonte num período (registos com a data no intervalo e que passam TODOS os filtros).
     * CONTAGEM = n; SOMA = Σ campo; MEDIA = Σ/n (sem registos: sem valor).
     *
     * @param  array<string, mixed>  $fonte  já validada
     * @return array{valor: ?string, detalhe: string}
     */
    public static function medir(array $fonte, string $periodo, ?int $unidadeId = null): array
    {
        $def = self::FONTES[$fonte['modulo']];
        [$ini, $fim] = Periodos::intervalo($periodo);
        $q = DB::table($def['tabela'])->where('empresa_id', app(ContextoEmpresa::class)->id())->whereBetween($def['data'], [$ini, $fim]);
        if ($def['apagado'] !== null) {
            $q->whereNull($def['apagado']);
        }
        if ($unidadeId !== null) {
            $q->where($def['unidade'], $unidadeId);
        }
        foreach ($fonte['filtros'] as $f) {
            self::aplicarFiltro($q, $f['campo'], $f['operador'], $f['valor'], $def['filtros'][$f['campo']]['tipo']);
        }
        $n = (int) (clone $q)->count();
        if ($fonte['operacao'] === 'CONTAGEM') {
            return ['valor' => (string) $n, 'detalhe' => "{$n} registo(s) em ".Periodos::rotulo($periodo)];
        }
        if ($n === 0) {
            return ['valor' => $fonte['operacao'] === 'SOMA' ? '0' : null, 'detalhe' => 'Sem registos em '.Periodos::rotulo($periodo)];
        }
        $coluna = $fonte['campo'];   // constante do catálogo (validada contra a lista fechada)
        $soma = (string) ((clone $q)->selectRaw("COALESCE(SUM({$coluna}), 0)::numeric(30,4) as s")->value('s') ?? '0');
        if ($fonte['operacao'] === 'SOMA') {
            return ['valor' => CalculadoraDesempenho::arredondar($soma, 4), 'detalhe' => "Soma de {$n} registo(s) em ".Periodos::rotulo($periodo)];
        }

        return ['valor' => CalculadoraDesempenho::arredondar(bcdiv($soma, (string) $n, 8), 4), 'detalhe' => "Média de {$n} registo(s) em ".Periodos::rotulo($periodo)];
    }

    private static function aplicarFiltro(Builder $q, string $coluna, string $op, ?string $valor, string $tipo): void
    {
        switch ($op) {
            case 'IGUAL':
                $q->where($coluna, '=', $tipo === 'numero' ? $valor + 0 : $valor);
                break;
            case 'DIFERENTE':
                $q->where(fn ($w) => $w->where($coluna, '<>', $tipo === 'numero' ? $valor + 0 : $valor)->orWhereNull($coluna));
                break;
            case 'CONTEM':
                $q->where($coluna, 'ILIKE', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $valor).'%');
                break;
            case 'MAIOR':
                $q->where($coluna, '>', $valor + 0);
                break;
            case 'MENOR':
                $q->where($coluna, '<', $valor + 0);
                break;
            case 'PREENCHIDO':
                $q->whereNotNull($coluna);
                if ($tipo === 'texto') {
                    $q->where($coluna, '<>', '');
                }
                break;
            case 'VAZIO':
                $q->where(fn ($w) => $tipo === 'texto' ? $w->whereNull($coluna)->orWhere($coluna, '=', '') : $w->whereNull($coluna));
                break;
        }
    }
}
