<?php

namespace App\Services\Logistica;

use App\Models\CategoriaProduto;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\RegistoConversaoContasProduto;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;

/**
 * Conversão das contas dos produtos para categorias (ronda 3) — chamada pelo comando erp:produtos:converter-contas-categorias,
 * dentro de uma transacção (o comando desfaz tudo com --simular). Para a empresa activa:
 *   - cada produto ainda não convertido (sem registo em registos_conversao_contas_produtos) é avaliado pelas suas contas
 *     EFECTIVAS (ContasProduto::de: categoria › produto) — a conversão nunca muda a contabilização de um produto;
 *   - por categoria actual, as combinações de contas distintas: a mais frequente fica na própria categoria (se a categoria ainda
 *     não tem contas e todos os seus produtos estão nesta conversão) e cada outra combinação recebe uma categoria nova com o
 *     nome da categoria + sufixo «(2)», «(3)», …; produtos sem categoria vão para «Geral» (com o mesmo critério);
 *   - categorias criadas guardam uma chave (categoria de origem + combinação) e são reutilizadas numa nova execução;
 *   - cada produto fica registado (categoria anterior, nova, contas, avisos) — idempotente e relatório da conversão.
 * As contas gravadas no produto NÃO são apagadas (ficam como contas de recurso; nada se perde).
 */
final class ServicoConversaoContasCategorias
{
    private const BASE_SEM_CATEGORIA = 'Geral';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /**
     * @return array{empresa_id: int, produtos_analisados: int, ja_convertidos: int, produtos_movidos: int,
     *     categorias_actualizadas: list<array<string, mixed>>, categorias_criadas: list<array<string, mixed>>,
     *     produtos: list<array<string, mixed>>, avisos: list<string>}
     */
    public function converter(?string $executadoPor = null): array
    {
        $empresa = $this->contexto->obrigatorio();
        $registados = RegistoConversaoContasProduto::query()->pluck('produto_id')->flip();
        $todos = Produto::query()->withTrashed()->orderBy('id')->get();
        $pendentes = $todos->reject(fn (Produto $p) => $registados->has($p->id))->values();
        $categorias = CategoriaProduto::query()->withTrashed()->get()->keyBy('id');
        $relatorio = ['empresa_id' => $empresa, 'produtos_analisados' => $pendentes->count(), 'ja_convertidos' => $registados->count(),
            'produtos_movidos' => 0, 'categorias_actualizadas' => [], 'categorias_criadas' => [], 'produtos' => [], 'avisos' => []];

        foreach ($pendentes->groupBy(fn (Produto $p) => (int) ($p->categoria_produto_id ?? 0)) as $baseId => $grupo) {
            $base = $baseId ? $categorias->get($baseId) : null;
            $baseActiva = $base && ! $base->trashed();
            // combinações de contas efectivas, da mais frequente para a menos frequente (empate: pela chave, estável)
            $combos = $grupo->groupBy(fn (Produto $p) => self::chaveCombo(self::contasEfectivas($p)))
                ->sortBy(fn (Collection $ps, string $k) => sprintf('%08d|%s', 99999999 - $ps->count(), $k));
            $ordem = 1;
            $derivadas = 0;
            foreach ($combos as $chave => $produtos) {
                $contas = json_decode((string) $chave, true, flags: JSON_THROW_ON_ERROR);   // calculadas antes de qualquer alteração
                $destino = null;
                $criada = false;
                if ($baseActiva && self::mesmasContas($base, $contas)) {
                    $destino = $base;   // a categoria já tem exactamente estas contas (ou nenhuma e o produto também não)
                } elseif ($baseActiva && $ordem === 1 && self::semContas($base) && $this->todosNaConversao($base, $grupo)) {
                    $base->forceFill($contas)->save();   // a categoria recebe a combinação mais frequente dos seus produtos
                    $relatorio['categorias_actualizadas'][] = ['id' => $base->id, 'nome' => $base->nome, 'contas' => $contas];
                    $destino = $base;
                } else {
                    $derivadas++;   // «Categoria (2)», «(3)», …; sem categoria activa, a primeira fica com o nome da base
                    [$destino, $criada] = $this->categoriaDerivada($baseId, $base?->nome ?? self::BASE_SEM_CATEGORIA, $contas, $baseActiva ? $derivadas + 1 : $derivadas);
                    if ($criada) {
                        $relatorio['categorias_criadas'][] = ['id' => $destino->id, 'nome' => $destino->nome, 'origem' => $base?->nome, 'contas' => $contas];
                    }
                }
                $ordem++;
                $avisos = $this->avisos($contas);
                foreach ($avisos as $a) {
                    $relatorio['avisos'][] = "Categoria {$destino->nome}: {$a}";
                }
                foreach ($produtos as $p) {
                    $anterior = $p->categoria_produto_id ? (int) $p->categoria_produto_id : null;
                    if ($anterior !== $destino->id) {
                        $p->forceFill(['categoria_produto_id' => $destino->id])->save();
                        $relatorio['produtos_movidos']++;
                    }
                    RegistoConversaoContasProduto::create(['produto_id' => $p->id, 'categoria_anterior_id' => $anterior, 'categoria_nova_id' => $destino->id,
                        'categoria_criada' => $criada, 'contas' => $contas, 'avisos' => $avisos ?: null, 'executado_por' => $executadoPor]);
                    $relatorio['produtos'][] = ['id' => $p->id, 'codigo' => $p->codigo, 'categoria_anterior' => $base?->nome, 'categoria_nova' => $destino->nome,
                        'eliminado' => $p->trashed()];
                }
            }
        }
        $relatorio['avisos'] = array_values(array_unique($relatorio['avisos']));

        return $relatorio;
    }

    /** @return array<string, string|null> contas efectivas do produto, pelo nome da coluna */
    public static function contasEfectivas(Produto $p): array
    {
        return ContasProduto::de($p)->porColuna();
    }

    private static function chaveCombo(array $contas): string
    {
        return json_encode($contas, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function mesmasContas(CategoriaProduto $c, array $contas): bool
    {
        foreach ($contas as $coluna => $valor) {
            if ((trim((string) $c->getAttribute($coluna)) ?: null) !== $valor) {
                return false;
            }
        }

        return true;
    }

    private static function semContas(CategoriaProduto $c): bool
    {
        return collect(array_keys(CategoriaProduto::CONTAS))->every(fn ($k) => trim((string) $c->getAttribute($k)) === '');
    }

    /** Só se preenchem as contas de uma categoria se TODOS os seus produtos entram nesta conversão (nenhum muda de contas). */
    private function todosNaConversao(CategoriaProduto $c, Collection $grupo): bool
    {
        return Produto::query()->withTrashed()->where('categoria_produto_id', $c->id)->count() === $grupo->count();
    }

    /** @return array{0: CategoriaProduto, 1: bool} categoria (reutilizada pela chave de conversão ou criada) e se foi criada */
    private function categoriaDerivada(int $baseId, string $nomeBase, array $contas, int $numero): array
    {
        $chave = hash('sha256', $baseId.'|'.self::chaveCombo($contas));
        $existente = CategoriaProduto::query()->where('chave_conversao', $chave)->first();
        if ($existente) {
            return [$existente, false];
        }
        $nome = $numero > 1 ? "{$nomeBase} ({$numero})" : $nomeBase;
        for ($n = max(2, $numero + 1); CategoriaProduto::query()->whereRaw('lower(nome) = lower(?)', [$nome])->exists(); $n++) {
            $nome = "{$nomeBase} ({$n})";
        }
        $c = new CategoriaProduto;
        $c->fill(['nome' => mb_substr($nome, 0, 255)])->forceFill($contas + ['chave_conversao' => $chave,
            'descricao' => 'Criada pela conversão das contas dos produtos para categorias'.($baseId ? " (a partir de «{$nomeBase}»)." : '.')])->save();

        return [$c, true];
    }

    /** @return list<string> contas inexistentes ou totalizadoras (copiadas sem alteração: nada se perde) */
    private function avisos(array $contas): array
    {
        $plano = $this->plano->todas();
        $saida = [];
        foreach ($contas as $coluna => $codigo) {
            if ($codigo === null) {
                continue;
            }
            $conta = $plano[$codigo] ?? null;
            if ($conta === null) {
                $saida[] = "{$coluna} {$codigo} não existe no plano de contas";
            } elseif ($conta['tipo'] === PlanoConta::TIPO_TOTALIZADORA) {
                $saida[] = "{$coluna} {$codigo} é totalizadora";
            }
        }

        return $saida;
    }
}
