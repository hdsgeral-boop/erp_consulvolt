<?php

namespace App\Services\Orcamento;

use App\Exceptions\ErroNegocio;
use App\Models\LinhaOrcamento;
use App\Models\LinhaPrevisaoOrcamental;
use App\Models\PedidoExtrapolacaoOrcamento;
use App\Models\PlanoConta;
use App\Models\RubricaOrcamental;
use Illuminate\Support\Facades\DB;

/**
 * Rubricas orçamentais (js/modules/orcamento/orcamento_dados.js:63-160). Paridade: exploração (PROVEITO/CUSTO) e
 * tesouraria (RECEBIMENTO/PAGAMENTO), ligadas ao plano por conta exacta ou prefixo; cada conta pertence a uma só
 * rubrica do mesmo tipo; na tesouraria não se usam 43/45 (são o próprio movimento); controlo de excesso só em custos e
 * pagamentos (NENHUM/AVISAR/APROVACAO/BLOQUEAR, aviso 90 %, limite 100 %, base ACUMULADO/ANO); rubricas base do
 * PGC Angola. Correcção (ADR-044): uma rubrica usada em previsões ou pedidos de excesso também não se elimina
 * (o legado só verificava as linhas de orçamento).
 */
final class ServicoRubricasOrcamentais
{
    public const NATUREZAS = ['EXPLORACAO' => ['PROVEITO', 'CUSTO'], 'TESOURARIA' => ['RECEBIMENTO', 'PAGAMENTO']];

    public const MODOS_CONTROLO = ['NENHUM', 'AVISAR', 'APROVACAO', 'BLOQUEAR'];

    public const BASE = [
        'EXPLORACAO' => [
            ['P01', 'Vendas', 'PROVEITO', 'Proveitos operacionais', '61'], ['P02', 'Prestações de serviços', 'PROVEITO', 'Proveitos operacionais', '62'],
            ['P03', 'Outros proveitos operacionais', 'PROVEITO', 'Proveitos operacionais', '63'], ['P04', 'Variação nos produtos acabados e em vias de fabrico', 'PROVEITO', 'Proveitos operacionais', '64'],
            ['P05', 'Trabalhos para a própria empresa', 'PROVEITO', 'Proveitos operacionais', '65'], ['P06', 'Proveitos e ganhos financeiros', 'PROVEITO', 'Proveitos financeiros e outros', '66'],
            ['P07', 'Proveitos e ganhos em filiais e associadas', 'PROVEITO', 'Proveitos financeiros e outros', '67'], ['P08', 'Outros proveitos e ganhos não operacionais', 'PROVEITO', 'Proveitos financeiros e outros', '68'],
            ['C01', 'Custo das mercadorias vendidas e matérias consumidas', 'CUSTO', 'Custos operacionais', '71'], ['C02', 'Custos com o pessoal', 'CUSTO', 'Custos operacionais', '72'],
            ['C03', 'Amortizações', 'CUSTO', 'Custos operacionais', '73'], ['C04', 'Outros custos e perdas operacionais', 'CUSTO', 'Custos operacionais', '75'],
            ['C05', 'Custos e perdas financeiros', 'CUSTO', 'Custos financeiros e outros', '76'], ['C06', 'Custos e perdas em filiais e associadas', 'CUSTO', 'Custos financeiros e outros', '77'],
            ['C07', 'Outros custos e perdas não operacionais', 'CUSTO', 'Custos financeiros e outros', '78'],
        ],
        'TESOURARIA' => [
            ['R01', 'Recebimentos de clientes', 'RECEBIMENTO', 'Actividades operacionais', '31'], ['R02', 'Recebimentos de outros devedores', 'RECEBIMENTO', 'Actividades operacionais', '37'],
            ['R03', 'Financiamentos obtidos', 'RECEBIMENTO', 'Actividades de financiamento', '33'], ['R04', 'Vendas a dinheiro e outros proveitos', 'RECEBIMENTO', 'Actividades operacionais', '6'],
            ['G01', 'Pagamentos a fornecedores', 'PAGAMENTO', 'Actividades operacionais', '32'], ['G02', 'Pagamentos ao pessoal', 'PAGAMENTO', 'Actividades operacionais', '36'],
            ['G03', 'Pagamentos ao Estado (impostos e segurança social)', 'PAGAMENTO', 'Actividades operacionais', '34'], ['G04', 'Despesas pagas a dinheiro', 'PAGAMENTO', 'Actividades operacionais', '7'],
            ['G05', 'Investimentos (imobilizado)', 'PAGAMENTO', 'Actividades de investimento', '1'], ['G06', 'Reembolso de financiamentos', 'PAGAMENTO', 'Actividades de financiamento', '35'],
        ],
    ];

    public static function abrange(array $entrada, string $conta): bool
    {
        return ! empty($entrada['prefixo']) ? str_starts_with($conta, (string) $entrada['codigo']) : $conta === (string) $entrada['codigo'];
    }

    private static function sobrepoe(array $a, array $b): bool
    {
        if (! empty($a['prefixo']) && ! empty($b['prefixo'])) {
            return str_starts_with($a['codigo'], $b['codigo']) || str_starts_with($b['codigo'], $a['codigo']);
        }
        if (! empty($a['prefixo'])) {
            return str_starts_with($b['codigo'], $a['codigo']);
        }

        return ! empty($b['prefixo']) ? str_starts_with($a['codigo'], $b['codigo']) : $a['codigo'] === $b['codigo'];
    }

    public static function ehDisponibilidade(string $conta): bool
    {
        return str_starts_with($conta, '43') || str_starts_with($conta, '45');
    }

    /** @return array{erros: list<string>, avisos: list<string>, contas: list<array{codigo: string, prefixo: bool}>} */
    public function validarContas(string $tipo, array $contas, ?int $ignorar = null): array
    {
        $erros = $avisos = [];
        $lista = array_values(array_filter(array_map(fn ($c) => ['codigo' => preg_replace('/\s+/', '', (string) ($c['codigo'] ?? '')), 'prefixo' => (bool) ($c['prefixo'] ?? false)], $contas),
            fn ($c) => $c['codigo'] !== ''));
        if (! $lista) {
            $erros[] = 'Associe pelo menos uma conta ou prefixo do plano de contas.';
        }
        $plano = PlanoConta::query()->get(['codigo', 'tipo']);
        $rot = fn ($e) => $e['codigo'].($e['prefixo'] ? '*' : '');
        foreach ($lista as $i => $e) {
            $abr = $plano->filter(fn ($a) => self::abrange($e, (string) $a->codigo));
            if ($abr->isEmpty()) {
                $erros[] = "{$rot($e)}: não existe no plano de contas desta empresa.";
            } elseif (! $e['prefixo'] && $abr->every(fn ($a) => $a->tipo === 'T')) {
                $avisos[] = "{$e['codigo']} é uma conta totalizadora: use-a como prefixo ({$e['codigo']}*) para incluir as subcontas.";
            }
            if ($tipo === 'TESOURARIA' && (self::ehDisponibilidade($e['codigo']) || ($e['prefixo'] && (str_starts_with('43', $e['codigo']) || str_starts_with('45', $e['codigo']))))) {
                $erros[] = "{$rot($e)}: bancos e caixa (43/45) são o próprio movimento de tesouraria; associe as contas de contrapartida.";
            }
            if ($tipo === 'EXPLORACAO' && ! preg_match('/^[67]/', $e['codigo'])) {
                $avisos[] = "{$rot($e)} não é das classes 6 ou 7 (proveitos e custos).";
            }
            foreach (array_slice($lista, $i + 1) as $o) {
                if (self::sobrepoe($e, $o)) {
                    $erros[] = "{$rot($e)} e {$rot($o)} repetem contas na mesma rubrica.";
                }
            }
        }
        foreach (RubricaOrcamental::query()->where('tipo', $tipo)->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar))->get() as $r) {
            foreach ((array) $r->contas as $o) {
                foreach ($lista as $e) {
                    if (self::sobrepoe($e, ['codigo' => (string) $o['codigo'], 'prefixo' => (bool) ($o['prefixo'] ?? false)])) {
                        $erros[] = "{$rot($e)} sobrepõe-se a ".$o['codigo'].(! empty($o['prefixo']) ? '*' : '')." da rubrica {$r->codigo} — {$r->nome}. Cada conta só pode pertencer a uma rubrica.";
                    }
                }
            }
        }

        return ['erros' => array_values(array_unique($erros)), 'avisos' => array_values(array_unique($avisos)), 'contas' => $lista];
    }

    /** @param  array<string, mixed>  $d */
    public function guardar(array $d, ?RubricaOrcamental $r = null): array
    {
        $tipo = $d['tipo'];
        $codigo = preg_replace('/\s+/', '', (string) $d['codigo']);
        if (! in_array($d['natureza'] ?? null, self::NATUREZAS[$tipo], true)) {
            throw new ErroNegocio('Escolha a natureza da rubrica ('.implode(' ou ', self::NATUREZAS[$tipo]).').', 'NATUREZA_INVALIDA', 422);
        }
        if (RubricaOrcamental::query()->where('tipo', $tipo)->whereRaw('lower(codigo) = lower(?)', [$codigo])->when($r, fn ($q) => $q->whereKeyNot($r->id))->exists()) {
            throw new ErroNegocio("Já existe a rubrica {$codigo} neste tipo de orçamento.", 'RUBRICA_DUPLICADA', 422);
        }
        $v = $this->validarContas($tipo, $d['contas'] ?? [], $r?->id);
        if ($v['erros']) {
            throw new ErroNegocio(implode(' ', $v['erros']), 'CONTAS_INVALIDAS', 422, ['erros' => $v['erros']]);
        }
        $dados = ['tipo' => $tipo, 'codigo' => $codigo, 'nome' => trim($d['nome']), 'natureza' => $d['natureza'], 'grupo' => trim((string) ($d['grupo'] ?? '')),
            'contas' => $v['contas'], 'ordem' => (int) ($d['ordem'] ?? 0), 'ativo' => $d['ativo'] ?? true, 'descricao' => $d['descricao'] ?? null];
        if (array_key_exists('controlo', $d) && $d['controlo'] !== null) {
            $c = $d['controlo'];
            $modo = in_array($c['modo'] ?? 'NENHUM', self::MODOS_CONTROLO, true) ? $c['modo'] : 'NENHUM';
            $aviso = max(0, (float) ($c['aviso_pct'] ?? 90));
            $limite = max(0, (float) ($c['limite_pct'] ?? 100));
            if ($modo !== 'NENHUM' && in_array($d['natureza'], ['PROVEITO', 'RECEBIMENTO'], true)) {
                throw new ErroNegocio('O controlo de excesso aplica-se a custos e pagamentos.', 'CONTROLO_INVALIDO', 422);
            }
            if (in_array($modo, ['APROVACAO', 'BLOQUEAR'], true) && $limite < $aviso) {
                throw new ErroNegocio('O limite (aprovação/bloqueio) não pode ser inferior à percentagem de aviso.', 'CONTROLO_INVALIDO', 422);
            }
            $dados['controlo'] = ['modo' => $modo, 'aviso_pct' => $aviso, 'limite_pct' => $limite, 'base' => ($c['base'] ?? 'ACUMULADO') === 'ANO' ? 'ANO' : 'ACUMULADO'];
        }
        foreach (['indutor', 'cambial_pct', 'variavel_pct'] as $k) {
            array_key_exists($k, $d) && $dados[$k] = $d[$k];
        }
        $r ? $r->update($dados) : $r = RubricaOrcamental::create($dados);

        return ['rubrica' => $r->refresh(), 'avisos' => $v['avisos']];
    }

    public function eliminar(RubricaOrcamental $r): void
    {
        $usos = LinhaOrcamento::query()->where('rubrica_orcamental_id', $r->id)->count() + LinhaPrevisaoOrcamental::query()->where('rubrica_orcamental_id', $r->id)->count()
            + PedidoExtrapolacaoOrcamento::query()->where('rubrica_orcamental_id', $r->id)->count();
        if ($usos) {
            throw new ErroNegocio("A rubrica tem {$usos} utilização(ões) em orçamentos, previsões ou pedidos de excesso: desactive-a em vez de a eliminar.", 'REGISTO_EM_USO', 422);
        }
        $r->delete();
    }

    /** Rubricas base do PGC Angola: cria as que não existem e não se sobrepõem. */
    public function criarBase(string $tipo): array
    {
        $criadas = $ignoradas = [];
        $ordem = RubricaOrcamental::query()->where('tipo', $tipo)->count();
        foreach (self::BASE[$tipo] as [$codigo, $nome, $natureza, $grupo, $prefixo]) {
            try {
                DB::transaction(fn () => $this->guardar(['tipo' => $tipo, 'codigo' => $codigo, 'nome' => $nome, 'natureza' => $natureza, 'grupo' => $grupo,
                    'contas' => [['codigo' => $prefixo, 'prefixo' => true]], 'ordem' => ++$ordem]));
                $criadas[] = "{$codigo} {$nome}";
            } catch (ErroNegocio $e) {
                $ignoradas[] = "{$codigo} {$nome}: {$e->getMessage()}";
            }
        }

        return ['criadas' => $criadas, 'ignoradas' => $ignoradas];
    }

    /** Rubrica que abrange uma conta (activas do tipo). */
    public static function rubricaDaConta(iterable $rubricas, string $conta): ?RubricaOrcamental
    {
        foreach ($rubricas as $r) {
            foreach ((array) $r->contas as $e) {
                if (self::abrange(['codigo' => (string) $e['codigo'], 'prefixo' => (bool) ($e['prefixo'] ?? false)], $conta)) {
                    return $r;
                }
            }
        }

        return null;
    }
}
