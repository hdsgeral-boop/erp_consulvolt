<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\CatalogoFornecedor;
use App\Models\CategoriaProduto;
use App\Models\Produto;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Cache\CacheComprimida;
use App\Support\Cache\ChaveCache;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Produtos, serviços e categorias. Paridade com saveProduct (js/ui_sales.js:2596):
 *   - nome obrigatório, código único na empresa;
 *   - todas as contas mapeadas têm de ser de movimento (js/db_v2.js:412);
 *   - dados AGT: unidade por omissão "UN"; o motivo de isenção só se aplica a IVA 0% (senão é limpo);
 *   - conta_iva = conta_iva_liquidado (retrocompatibilidade do legado);
 *   - o catálogo de compras (catalogo_fornecedores) acompanha o produto (código, nome, preço).
 * Melhoria: a ficha não altera o stock (só movimentos de inventário — módulo Logística).
 * Ronda 3: as contas contabilísticas definem-se na CATEGORIA (guardarCategoria; o produto herda-as por ContasProduto::de);
 * níveis de stock (mínimo ≤ segurança ≤ ponto de encomenda ≤ máximo, ServicoNiveisStock) e ficha técnica do produto.
 */
final class ServicoProdutos
{
    /** Onde um produto pode estar referenciado (bloqueia a eliminação). */
    private const REFERENCIAS = [
        ['itens_venda', 'produto_id', 'documentos de venda'],
        ['itens_compra', 'produto_id', 'documentos de compra'],
        ['itens_guia_saida', 'produto_id', 'guias e recepções'],
        ['movimentos_inventario', 'produto_id', 'movimentos de inventário'],
        ['stock_armazem', 'produto_id', 'stock em armazém'],
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /** @param  array<string, mixed>  $f */
    public function listar(array $f): LengthAwarePaginator
    {
        return Produto::query()->with(['categoriaProduto' => fn ($q) => $q->withTrashed()])
            ->when($f['categoria_produto_id'] ?? null, fn ($q, $v) => $q->where('categoria_produto_id', $v))
            ->when(isset($f['bloqueado']), fn ($q) => $q->where('bloqueado', (bool) $f['bloqueado']))
            ->when(isset($f['movimenta_stock']), fn ($q) => $q->where('movimenta_stock', (bool) $f['movimenta_stock']))
            ->when($f['pesquisa'] ?? null, function ($q, $p) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $p).'%';
                $q->where(fn ($s) => $s->where('nome', 'ilike', $termo)->orWhere('codigo', 'ilike', $termo));
            })
            ->orderBy('id') // ordem de criação, como o catálogo do legado (db.products por id)
            ->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));
    }

    /**
     * Catálogo de venda activo (produtos não bloqueados), em cache Redis 2 h (comprimido acima de 8 KB) — usado por facturação e POS.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogo(): array
    {
        $empresa = $this->contexto->obrigatorio();

        return CacheComprimida::lembrar(ChaveCache::empresa($empresa, 'logistica', 'catalogo_produtos'), (int) config('erp.cache.ttl.catalogo_produtos'),
            fn () => Produto::query()->where(fn ($q) => $q->whereNull('bloqueado')->orWhere('bloqueado', false))->ordenarPorNome()
                ->get(['id', 'codigo', 'nome', 'preco_unitario', 'taxa_imposto', 'codigo_isencao_fe', 'unidade_fe', 'movimenta_stock', 'e_servico', 'e_quarto', 'categoria_produto_id'])
                ->toArray());
    }

    /** @param  array<string, mixed>  $dados */
    public function guardar(array $dados, ?Produto $produto = null): Produto
    {
        // níveis de stock e ficha técnica: colunas fora do $fillable gerado — validadas aqui e gravadas com forceFill
        $extras = array_intersect_key($dados, array_flip([...Produto::NIVEIS_STOCK, ...Produto::CAMPOS_FICHA]));
        $dados = array_diff_key($dados, $extras);
        $extras = $this->normalizarExtras($extras, $produto);
        $dados = $this->normalizar($dados, $produto);
        if (! empty($dados['codigo']) && Produto::query()->where('codigo', $dados['codigo'])->when($produto, fn ($q) => $q->whereKeyNot($produto->id))->exists()) {
            throw new ErroNegocio("Já existe um produto com o código {$dados['codigo']}.", 'PRODUTO_DUPLICADO', 422);
        }

        return DB::transaction(function () use ($dados, $extras, $produto) {
            $produto ??= new Produto;
            $produto->fill($dados)->forceFill($extras)->save();
            $this->sincronizarCatalogoCompras($produto);

            return $produto;
        });
    }

    public function alternarBloqueio(Produto $produto): Produto
    {
        $produto->update(['bloqueado' => ! $produto->bloqueado]);

        return $produto;
    }

    public function eliminar(Produto $produto): void
    {
        $this->exigirSemReferencias($produto->id, self::REFERENCIAS, 'este produto');
        DB::transaction(function () use ($produto) {
            CatalogoFornecedor::query()->where('produto_id', $produto->id)->delete();
            $produto->delete();
        });
    }

    /**
     * Categoria com as contas contabilísticas dos seus produtos. Cada conta alterada tem de existir, ser de movimento e
     * pertencer às classes da sua natureza (CategoriaProduto::CONTAS); contas não alteradas não são revalidadas (as
     * copiadas do legado pela conversão continuam editáveis no resto da categoria).
     *
     * @param  array<string, mixed>  $dados  nome, descricao?, codigo_conta?, conta_custo?, …
     */
    public function guardarCategoria(array $dados, ?CategoriaProduto $categoria = null): CategoriaProduto
    {
        $nome = trim((string) ($dados['nome'] ?? $categoria?->nome ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Indique o nome da categoria.', 'CATEGORIA_SEM_NOME', 422);
        }
        if (CategoriaProduto::query()->whereRaw('lower(nome) = lower(?)', [$nome])->when($categoria, fn ($q) => $q->whereKeyNot($categoria->id))->exists()) {
            throw new ErroNegocio("Já existe a categoria {$nome}.", 'CATEGORIA_DUPLICADA', 422);
        }
        $extras = [];
        if (array_key_exists('descricao', $dados)) {
            $extras['descricao'] = trim((string) $dados['descricao']) ?: null;
        }
        foreach (CategoriaProduto::CONTAS as $campo => [$rotulo, $classes]) {
            if (! array_key_exists($campo, $dados)) {
                continue;
            }
            $codigo = trim(str_replace(['.', ' '], '', (string) ($dados[$campo] ?? ''))) ?: null;
            if ($codigo !== null && $codigo !== $categoria?->getAttribute($campo)) {
                $this->plano->contaDeMovimento($codigo);
                if (! collect($classes)->contains(fn ($c) => str_starts_with($codigo, $c))) {
                    throw new ErroNegocio("A conta {$codigo} não serve para «{$rotulo}» (classes aceites: ".implode(', ', $classes).').', 'CONTA_CLASSE_INVALIDA', 422,
                        ['campo' => $campo]);
                }
            }
            $extras[$campo] = $codigo;
        }

        return DB::transaction(function () use ($categoria, $nome, $extras) {
            $categoria ??= new CategoriaProduto;
            $categoria->fill(['nome' => $nome])->forceFill($extras)->save();

            return $categoria;
        });
    }

    /**
     * Categoria para a API: nome, descrição, contas e (opcionalmente) o número de produtos.
     *
     * @return array<string, mixed>
     */
    public static function categoriaParaApi(CategoriaProduto $c, ?int $produtos = null): array
    {
        $saida = ['id' => $c->id, 'nome' => $c->nome, 'descricao' => $c->getAttribute('descricao')];
        foreach (array_keys(CategoriaProduto::CONTAS) as $campo) {
            $saida[$campo] = $c->getAttribute($campo);
        }

        return $saida + ($produtos !== null ? ['produtos' => $produtos] : []);
    }

    public function eliminarCategoria(CategoriaProduto $categoria): void
    {
        $this->exigirSemReferencias($categoria->id, [['produtos', 'categoria_produto_id', 'produtos']], 'esta categoria');
        $categoria->delete();
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function normalizar(array $dados, ?Produto $produto): array
    {
        foreach (Produto::CAMPOS_CONTA as $campo) {
            if (! empty($dados[$campo])) {
                $this->plano->contaDeMovimento((string) $dados[$campo]);
            }
        }
        if (array_key_exists('conta_iva_liquidado', $dados)) {
            $dados['conta_iva'] = $dados['conta_iva_liquidado'];   // retrocompatibilidade do legado
        }
        if (array_key_exists('unidade_fe', $dados) || ! $produto) {
            $dados['unidade_fe'] = mb_strtoupper(trim((string) ($dados['unidade_fe'] ?? ''))) ?: 'UN';
        }
        $taxa = (float) ($dados['taxa_imposto'] ?? $produto?->taxa_imposto ?? 0);
        if (! empty($dados['codigo_isencao_fe']) && $taxa != 0.0) {
            $dados['codigo_isencao_fe'] = null;   // o motivo de isenção só se aplica a IVA 0% (paridade)
        }
        if (empty($dados['e_quarto'] ?? $produto?->e_quarto)) {
            $dados['preco_por_hora'] = $dados['preco_por_dia'] = $dados['horas_minimas'] = null;
        }
        if (empty($dados['e_ativo_imobilizado'] ?? $produto?->e_ativo_imobilizado)) {
            $dados['conta_ativo'] = null;
        }

        return $dados;
    }

    /**
     * Níveis de stock e ficha técnica: números não negativos, ordem dos níveis (com os valores actuais nos que não vêm),
     * unidade de dimensão normalizada e características como lista {chave, valor} sem linhas vazias.
     *
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    private function normalizarExtras(array $extras, ?Produto $produto): array
    {
        foreach ([...Produto::NIVEIS_STOCK, 'comprimento', 'largura', 'altura', 'peso_liquido', 'peso_bruto'] as $campo) {
            if (array_key_exists($campo, $extras)) {
                $extras[$campo] = $extras[$campo] === null || $extras[$campo] === '' ? null : number_format((float) $extras[$campo], 3, '.', '');
            }
        }
        $niveis = [];
        foreach (Produto::NIVEIS_STOCK as $campo) {
            $niveis[$campo] = array_key_exists($campo, $extras) ? $extras[$campo] : $produto?->getAttribute($campo);
        }
        ServicoNiveisStock::validarNiveis($niveis);
        foreach (['marca', 'modelo', 'observacoes_tecnicas'] as $campo) {
            if (array_key_exists($campo, $extras)) {
                $extras[$campo] = trim((string) $extras[$campo]) ?: null;
            }
        }
        if (array_key_exists('unidade_dimensao', $extras)) {
            $extras['unidade_dimensao'] = $extras['unidade_dimensao'] ? mb_strtolower(trim((string) $extras['unidade_dimensao'])) : null;
        }
        if (array_key_exists('caracteristicas', $extras)) {
            $lista = [];
            foreach ((array) ($extras['caracteristicas'] ?? []) as $c) {
                $chave = trim((string) ($c['chave'] ?? ''));
                $valor = trim((string) ($c['valor'] ?? ''));
                if ($chave === '' && $valor === '') {
                    continue;
                }
                if ($chave === '') {
                    throw new ErroNegocio("Característica sem nome (valor «{$valor}»).", 'CARACTERISTICA_INVALIDA', 422);
                }
                $lista[] = ['chave' => $chave, 'valor' => $valor];
            }
            $extras['caracteristicas'] = $lista ?: null;
        }

        return $extras;
    }

    private function sincronizarCatalogoCompras(Produto $produto): void
    {
        CatalogoFornecedor::query()->updateOrCreate(['produto_id' => $produto->id],
            ['codigo' => $produto->codigo, 'nome' => $produto->nome, 'preco_unitario' => $produto->preco_unitario]);
    }

    /** @param  list<array{0: string, 1: string, 2: string}>  $referencias */
    private function exigirSemReferencias(int $id, array $referencias, string $alvo): void
    {
        $empresa = $this->contexto->obrigatorio();
        foreach ($referencias as [$tabela, $coluna, $descricao]) {
            $consulta = DB::table($tabela)->where('empresa_id', $empresa)->where($coluna, $id);
            if (Schema::hasColumn($tabela, 'eliminado_em')) {
                $consulta->whereNull('eliminado_em');   // registos eliminados logicamente não bloqueiam
            }
            if ($consulta->exists()) {
                throw new ErroNegocio("Não é possível eliminar {$alvo}: já foi usado em {$descricao}.", 'REGISTO_EM_USO', 422, ['origem' => $tabela]);
            }
        }
    }
}
