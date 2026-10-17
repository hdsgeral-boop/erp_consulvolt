<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\Produto;
use App\Models\TerminalPOS;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de cada POS filtrado pelo serviço (tipo do terminal), no servidor.
 *
 * Legado: o POS mostrava todos os produtos, excepto os quartos (is_room) fora do POS de hotelaria
 * (renderPOSProductsGrid, ui_sales.js:7014-7017); a lavandaria tinha o seu catálogo próprio (lav_group, lavandaria.js:97).
 * Novo: cada produto indica em `produtos.servicos_pos` os serviços (tipos de terminal) onde aparece:
 *   - NULL  → todos os serviços (produtos existentes: nada muda até serem marcados);
 *   - lista → só nesses serviços ([] = em nenhum POS).
 * Regras fixas, independentes da marcação: os quartos (e_quarto) só aparecem no POS de hotelaria; os serviços de
 * lavandaria/alfaiataria (lavandaria_grupo) aparecem sempre no POS de lavandaria e, sem marcação, só nesse.
 * A venda ao balcão volta a verificar a regra (POSController::vender), para um pedido feito à mão não contornar o filtro.
 */
final class ServicoCatalogoPOS
{
    public const SERVICOS = ServicoTerminaisPOS::TIPOS;

    public const ROTULOS = ['LOJA' => 'Loja (POS geral)', 'RESTAURANTE' => 'Restaurante', 'HOTELARIA' => 'Hotelaria', 'LAVANDARIA' => 'Lavandaria e Alfaiataria'];

    private const COLUNAS = ['id', 'codigo', 'nome', 'preco_unitario', 'taxa_imposto', 'codigo_isencao_fe', 'unidade_fe', 'movimenta_stock', 'e_servico',
        'e_quarto', 'categoria_produto_id', 'lavandaria_grupo', 'servicos_pos'];

    /**
     * Produtos activos (não bloqueados) que o terminal pode vender, com os mesmos campos do catálogo comum
     * (GET /logistica/produtos/catalogo) e a marcação `servicos_pos`.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogo(TerminalPOS $t): array
    {
        $tipo = (string) ($t->tipo ?: 'LOJA');

        return Produto::query()
            ->where(fn ($q) => $q->whereNull('bloqueado')->orWhere('bloqueado', false))
            ->where(function ($q) use ($tipo) {
                // resto: pela marcação; quartos: só na hotelaria (e sempre lá); serviços de lavandaria: sempre na lavandaria e,
                // sem marcação, só lá (nos outros POS só se forem marcados)
                $q->where(fn ($x) => $x->where(fn ($y) => $y->whereNull('e_quarto')->orWhere('e_quarto', false))
                    ->where(fn ($y) => $y->where(fn ($z) => $z->whereNull('servicos_pos')->whereNull('lavandaria_grupo'))
                        ->orWhereJsonContains('servicos_pos', $tipo)));
                if ($tipo === 'HOTELARIA') {
                    $q->orWhere('e_quarto', true);
                }
                if ($tipo === 'LAVANDARIA') {
                    $q->orWhere(fn ($x) => $x->whereNotNull('lavandaria_grupo')->where(fn ($y) => $y->whereNull('e_quarto')->orWhere('e_quarto', false)));
                }
            })
            ->ordenarPorNome()
            ->get(self::COLUNAS)
            ->map(fn (Produto $p) => array_merge($p->toArray(), ['servicos_pos' => self::servicosDe($p)]))
            ->all();
    }

    /** O produto pode ser vendido num terminal deste tipo? (a mesma regra do catálogo) */
    public static function permitido(Produto $p, string $tipo): bool
    {
        if ($p->e_quarto) {
            return $tipo === 'HOTELARIA';
        }
        if ($tipo === 'LAVANDARIA' && $p->lavandaria_grupo) {
            return true;
        }
        $servicos = self::servicosDe($p);

        return $servicos === null ? ! $p->lavandaria_grupo : in_array($tipo, $servicos, true);
    }

    /**
     * Recusa linhas com produtos não marcados para o serviço do terminal.
     *
     * @param  list<array{produto_id: int|string}>  $linhas
     */
    public function exigirDoServico(array $linhas, TerminalPOS $t): void
    {
        $tipo = (string) ($t->tipo ?: 'LOJA');
        $produtos = Produto::query()->whereIn('id', array_map(fn ($l) => (int) $l['produto_id'], $linhas))->get();
        foreach ($produtos as $p) {
            if (! self::permitido($p, $tipo)) {
                throw new ErroNegocio("O produto {$p->codigo} — {$p->nome} não está marcado para o serviço ".(self::ROTULOS[$tipo] ?? $tipo).'.',
                    'PRODUTO_FORA_DO_SERVICO', 422, ['produto_id' => $p->id, 'servico' => $tipo]);
            }
        }
    }

    /** @return list<string>|null null = todos os serviços */
    public static function servicosDe(Produto $p): ?array
    {
        $v = $p->getAttributes()['servicos_pos'] ?? null;
        if ($v === null) {
            return null;
        }
        $lista = is_array($v) ? $v : json_decode((string) $v, true);

        return is_array($lista) ? array_values(array_filter($lista, 'is_string')) : null;
    }

    /**
     * Marcação de todos os produtos (para o ecrã de Produtos): id → lista ou null (todos).
     *
     * @return array<string, list<string>|null>
     */
    public function marcacoes(): array
    {
        $saida = [];
        foreach (Produto::query()->orderBy('id')->get(['id', 'servicos_pos']) as $p) {
            $saida[(string) $p->id] = self::servicosDe($p);
        }

        return $saida;
    }

    /**
     * Grava a marcação do produto. null = todos os serviços; a lista é normalizada (sem repetidos, pela ordem de SERVICOS).
     *
     * @param  list<string>|null  $servicos
     * @return list<string>|null
     */
    public function definir(Produto $produto, ?array $servicos): ?array
    {
        if ($servicos !== null) {
            $desconhecidos = array_diff($servicos, self::SERVICOS);
            if ($desconhecidos) {
                throw new ErroNegocio('Serviço POS desconhecido: '.implode(', ', $desconhecidos).'.', 'SERVICO_POS_INVALIDO', 422);
            }
            $servicos = array_values(array_intersect(self::SERVICOS, $servicos));
        }

        return DB::transaction(function () use ($produto, $servicos) {
            $p = Produto::query()->lockForUpdate()->findOrFail($produto->id);
            // forceFill: a coluna é nova e o modelo-base (gerado) ainda não a tem nos fillable; o «saved» do Produto
            // invalida a cache do catálogo comum depois do commit
            $p->forceFill(['servicos_pos' => $servicos === null ? null : json_encode($servicos)])->save();

            return $servicos;
        });
    }
}
