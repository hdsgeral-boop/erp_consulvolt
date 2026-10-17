<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Services\Compras\ServicoProcessoCompras;
use App\Support\Cache\CacheComprimida;
use App\Support\Cache\ChaveCache;
use App\Support\Cache\InvalidacaoCache;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Níveis de stock e regras de notificação (ronda 3). No legado o «stock baixo» era um limite fixo (≤ 5 un., js/ui_dashboard.js:105,
 * js/ui_sales.js:4208) sem mínimo, máximo nem reposição. Aqui, por produto que movimenta stock (stock somado de todos os armazéns):
 *   - CRITICO   — stock físico abaixo do mínimo ou do stock de segurança (ou esgotado);
 *   - REPOSICAO — posição de stock (físico + em encomenda) no ponto de encomenda ou abaixo: sugere-se repor até ao máximo;
 *   - EXCESSO   — stock físico acima do máximo;
 *   - NORMAL    — o resto (não é devolvido nas listas).
 * «Em encomenda» = linhas de pedidos de compra em curso (PENDENTE/APROVADO) + o que falta receber das encomendas abertas
 * (EM_PROCESSAMENTO/PARCIAL): evita sugerir duas vezes a mesma reposição. Quantidade sugerida = alvo − posição, com
 * alvo = máximo (ou, na falta, ponto de encomenda › segurança › mínimo).
 *
 * Os níveis são do PRODUTO (para toda a empresa) e não por armazém: as compras são da empresa (o pedido de compra não tem
 * armazém), stock_armazem é uma tabela de saldos mantida pelos movimentos (criada à primeira entrada) e a maioria das
 * empresas tem um só armazém. Níveis por armazém exigiriam uma tabela própria de parâmetros (produto × armazém) — fica
 * como evolução, sem mudar estas regras.
 *
 * Cálculo no servidor, em cache Redis por empresa (TTL curto). Invalidação agora e depois do commit (InvalidacaoCache) quando
 * muda o stock (StockArmazem), um produto ou uma categoria, e ao gerar o pedido de compra por aqui.
 */
final class ServicoNiveisStock
{
    public const CRITICO = 'CRITICO';

    public const REPOSICAO = 'REPOSICAO';

    public const EXCESSO = 'EXCESSO';

    public const NORMAL = 'NORMAL';

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    public static function chaveCache(int $empresa): string
    {
        return ChaveCache::empresa($empresa, 'logistica', 'niveis_stock');
    }

    /**
     * Classificação de um produto (função pura, testável).
     *
     * @param  array{stock_minimo?: ?string, stock_seguranca?: ?string, ponto_encomenda?: ?string, stock_maximo?: ?string}  $n
     * @return array{estado: string, motivo: string|null, quantidade_sugerida: string}
     */
    public static function classificar(string $stock, string $emEncomenda, array $n): array
    {
        $v = fn (string $k) => isset($n[$k]) && $n[$k] !== null && $n[$k] !== '' ? number_format((float) $n[$k], 3, '.', '') : null;
        [$min, $seg, $pe, $max] = [$v('stock_minimo'), $v('stock_seguranca'), $v('ponto_encomenda'), $v('stock_maximo')];
        $posicao = bcadd($stock, $emEncomenda, 3);
        $alvo = $max ?? $pe ?? $seg ?? $min;
        $sugerida = fn () => $alvo !== null && bccomp($alvo, $posicao, 3) > 0 ? bcsub($alvo, $posicao, 3) : '0.000';

        if ($min !== null && bccomp($stock, $min, 3) < 0) {
            return ['estado' => self::CRITICO, 'motivo' => 'Abaixo do stock mínimo', 'quantidade_sugerida' => $sugerida()];
        }
        if ($seg !== null && bccomp($stock, $seg, 3) < 0) {
            return ['estado' => self::CRITICO, 'motivo' => 'Abaixo do stock de segurança', 'quantidade_sugerida' => $sugerida()];
        }
        if ($alvo !== null && bccomp($stock, '0', 3) <= 0) {
            return ['estado' => self::CRITICO, 'motivo' => 'Sem stock', 'quantidade_sugerida' => $sugerida()];
        }
        if ($pe !== null && bccomp($posicao, $pe, 3) <= 0) {
            return ['estado' => self::REPOSICAO, 'motivo' => 'Atingiu o ponto de encomenda', 'quantidade_sugerida' => $sugerida()];
        }
        if ($max !== null && bccomp($stock, $max, 3) > 0) {
            return ['estado' => self::EXCESSO, 'motivo' => 'Acima do stock máximo (excesso de '.rtrim(rtrim(bcsub($stock, $max, 3), '0'), '.').')', 'quantidade_sugerida' => '0.000'];
        }

        return ['estado' => self::NORMAL, 'motivo' => null, 'quantidade_sugerida' => '0.000'];
    }

    /**
     * Valida a ordem dos níveis preenchidos: mínimo ≤ segurança ≤ ponto de encomenda ≤ máximo (e não negativos).
     *
     * @param  array<string, mixed>  $n
     */
    public static function validarNiveis(array $n): void
    {
        $rotulos = ['stock_minimo' => 'stock mínimo', 'stock_seguranca' => 'stock de segurança', 'ponto_encomenda' => 'ponto de encomenda', 'stock_maximo' => 'stock máximo'];
        $anterior = null;
        foreach (Produto::NIVEIS_STOCK as $campo) {
            $x = $n[$campo] ?? null;
            if ($x === null || $x === '') {
                continue;
            }
            if ((float) $x < 0) {
                throw new ErroNegocio("O {$rotulos[$campo]} não pode ser negativo.", 'NIVEIS_STOCK_INVALIDOS', 422, ['campo' => $campo]);
            }
            if ($anterior && (float) $x < (float) $anterior[1]) {
                throw new ErroNegocio("O {$rotulos[$campo]} não pode ser inferior ao {$rotulos[$anterior[0]]} (regra: mínimo ≤ segurança ≤ ponto de encomenda ≤ máximo).",
                    'NIVEIS_STOCK_INVALIDOS', 422, ['campo' => $campo]);
            }
            $anterior = [$campo, $x];
        }
    }

    /**
     * Avaliação de todos os produtos com níveis definidos (em cache). Só devolve os que precisam de atenção.
     *
     * @return array{resumo: array{criticos: int, reposicao: int, excesso: int, valor_sugerido: string}, linhas: list<array<string, mixed>>}
     */
    public function avaliar(): array
    {
        $empresa = $this->contexto->obrigatorio();

        return CacheComprimida::lembrar(self::chaveCache($empresa), (int) config('erp.cache.ttl.niveis_stock', 600), fn () => $this->calcular($empresa));
    }

    /** @return list<array<string, mixed>> só as linhas de um estado (CRITICO, REPOSICAO, EXCESSO) ou todas */
    public function linhas(?string $estado = null): array
    {
        $linhas = $this->avaliar()['linhas'];

        return array_values($estado ? array_filter($linhas, fn ($l) => $l['estado'] === $estado) : $linhas);
    }

    /**
     * Gera um pedido de compra (fluxo existente: ServicoProcessoCompras::criarPedido — numeração PC, deliberação) com as
     * sugestões de reposição escolhidas. A quantidade pode ser corrigida pelo utilizador; o preço é o custo médio.
     *
     * @param  list<array{produto_id: int, quantidade: float|string}>  $linhas
     */
    public function gerarPedidoCompra(array $linhas, ?string $descricao = null, ?string $dataEntrega = null): PedidoCompra
    {
        $empresa = $this->contexto->obrigatorio();
        $ids = array_map(fn ($l) => (int) $l['produto_id'], $linhas);
        if (count($ids) !== count(array_unique($ids))) {
            throw new ErroNegocio('O mesmo produto aparece em mais de uma linha.', 'LINHAS_REPETIDAS', 422);
        }
        $produtos = Produto::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $p = $produtos[$id] ?? throw new ErroNegocio('Um dos produtos não existe.', 'PRODUTO_INEXISTENTE', 422);
            if (! $p->movimenta_stock) {
                throw new ErroNegocio("O produto {$p->codigo} não movimenta stock: não tem reposição.", 'PRODUTO_SEM_STOCK', 422);
            }
        }
        $utilizador = Auth::user();

        $pedido = app(ServicoProcessoCompras::class)->criarPedido([
            'nome_requerente' => mb_substr((string) ($utilizador?->nome_completo ?: $utilizador?->nome_utilizador ?: 'Reposição de stock'), 0, 255),
            'descricao' => $descricao ?: 'Reposição de stock — sugestões dos níveis de stock (ponto de encomenda / mínimo)',
            'data_entrega' => $dataEntrega,
            'linhas' => array_map(fn ($l) => ['produto_id' => (int) $l['produto_id'], 'quantidade' => (string) $l['quantidade'],
                'preco_unitario' => number_format((float) ($produtos[(int) $l['produto_id']]->custo_medio ?? 0), 2, '.', '')], $linhas),
        ]);
        InvalidacaoCache::esquecer(self::chaveCache($empresa));   // a posição de stock passa a contar com o pedido

        return $pedido;
    }

    /** @return array{resumo: array<string, mixed>, linhas: list<array<string, mixed>>} */
    private function calcular(int $empresa): array
    {
        $stock = DB::table('stock_armazem')->where('empresa_id', $empresa)->groupBy('produto_id')
            ->selectRaw('produto_id, SUM(COALESCE(quantidade_stock, 0)) AS q');
        $pedidos = DB::table('itens_compra as i')->join('pedidos_compra as pc', 'pc.id', '=', 'i.pedido_compra_id')
            ->where('i.empresa_id', $empresa)->where('i.tipo_documento_origem', 'PEDIDO')->whereIn('pc.estado', ['PENDENTE', 'APROVADO'])->whereNull('pc.anulado_em')
            ->groupBy('i.produto_id')->selectRaw('i.produto_id, SUM(COALESCE(i.quantidade, 0)) AS q');
        $encomendas = DB::table('itens_compra as i')->join('encomendas_compra as ec', 'ec.id', '=', 'i.encomenda_compra_id')
            ->where('i.empresa_id', $empresa)->where('i.tipo_documento_origem', 'ENCOMENDA')->whereIn('ec.estado', ['EM_PROCESSAMENTO', 'PARCIAL'])->whereNull('ec.anulado_em')
            ->groupBy('i.produto_id')->selectRaw('i.produto_id, SUM(GREATEST(COALESCE(i.quantidade, 0) - COALESCE(i.quantidade_recebida, 0), 0)) AS q');

        $produtos = DB::table('produtos as p')->where('p.empresa_id', $empresa)->whereNull('p.eliminado_em')
            ->where('p.movimenta_stock', true)->where(fn ($q) => $q->whereNull('p.bloqueado')->orWhere('p.bloqueado', false))
            ->where(fn ($q) => $q->where('p.stock_minimo', '>', 0)->orWhere('p.stock_seguranca', '>', 0)->orWhere('p.ponto_encomenda', '>', 0)->orWhere('p.stock_maximo', '>', 0))
            ->leftJoinSub($stock, 's', 's.produto_id', '=', 'p.id')
            ->leftJoinSub($pedidos, 'pd', 'pd.produto_id', '=', 'p.id')
            ->leftJoinSub($encomendas, 'en', 'en.produto_id', '=', 'p.id')
            ->leftJoin('categorias_produtos as c', 'c.id', '=', 'p.categoria_produto_id')
            ->ordenarPorNome('p.nome')
            ->get(['p.id', 'p.codigo', 'p.nome', 'p.unidade_fe', 'p.custo_medio', 'p.stock_minimo', 'p.stock_seguranca', 'p.ponto_encomenda', 'p.stock_maximo',
                'c.nome as categoria', DB::raw('COALESCE(s.q, 0) AS stock'), DB::raw('COALESCE(pd.q, 0) + COALESCE(en.q, 0) AS em_encomenda')]);

        $f = fn ($x) => $x === null ? null : number_format((float) $x, 3, '.', '');
        $linhas = [];
        $resumo = ['criticos' => 0, 'reposicao' => 0, 'excesso' => 0, 'valor_sugerido' => '0.00'];
        foreach ($produtos as $p) {
            $niveis = ['stock_minimo' => $f($p->stock_minimo), 'stock_seguranca' => $f($p->stock_seguranca), 'ponto_encomenda' => $f($p->ponto_encomenda), 'stock_maximo' => $f($p->stock_maximo)];
            $stockQ = $f($p->stock);
            $emEncomenda = $f($p->em_encomenda);
            $c = self::classificar($stockQ, $emEncomenda, $niveis);
            if ($c['estado'] === self::NORMAL) {
                continue;
            }
            $custo = number_format((float) ($p->custo_medio ?? 0), 2, '.', '');
            $valor = number_format(round((float) bcmul($c['quantidade_sugerida'], $custo, 6), 2), 2, '.', '');
            $linhas[] = ['produto_id' => (int) $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'categoria' => $p->categoria, 'unidade' => $p->unidade_fe ?: 'UN',
                'stock' => $stockQ, 'em_encomenda' => $emEncomenda, 'posicao' => bcadd($stockQ, $emEncomenda, 3)] + $niveis + $c
                + ['custo_medio' => $custo, 'valor_sugerido' => $valor];
            $resumo[match ($c['estado']) {
                self::CRITICO => 'criticos', self::REPOSICAO => 'reposicao', default => 'excesso'
            }]++;
            $resumo['valor_sugerido'] = bcadd($resumo['valor_sugerido'], $valor, 2);
        }
        // críticos primeiro, depois reposição e excesso; dentro de cada estado, o mais longe do alvo primeiro
        $ordem = [self::CRITICO => 0, self::REPOSICAO => 1, self::EXCESSO => 2];
        usort($linhas, fn ($a, $b) => [$ordem[$a['estado']], -(float) $a['quantidade_sugerida']] <=> [$ordem[$b['estado']], -(float) $b['quantidade_sugerida']]);

        return ['resumo' => $resumo, 'linhas' => $linhas, 'calculado_em' => now()->toIso8601String()];
    }
}
