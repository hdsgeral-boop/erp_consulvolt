<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\AtivoImobilizado;
use App\Models\BemFracao;
use App\Models\FichaApetrechamento;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Models\ItemFichaApetrechamento;
use App\Models\Produto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Fichas-tipo de apetrechamento e bens da fracção (imobiliario_obras.js do legado, parte do apetrechamento). A ficha diz o que cada género de
 * fracção deve ter; o inventário da fracção diz o que lá está; a diferença é a falta, que dá a ordem de apetrechamento. O inventário
 * comercial é DESTE módulo; a planta e o inventário físico dos bens continuam a ser do Activos (os bens do imobilizado só se TRAZEM, sem os alterar).
 * Correcções (P3): o nome da ficha é único (o legado deixava duplicar), a ficha grava-se com os artigos numa só transacção (o legado podia
 * deixar a ficha sem artigos), a quantidade dos bens é decimal (o legado arredondava 0,5 a 1), editar um bem já não repõe a origem nem
 * perde a ligação ao activo, a ficha em uso por ordens não se apaga (inactiva-se) e as linhas repetidas do mesmo artigo recusam-se.
 */
final class ServicoApetrechamento
{
    // ---------------------------------------------------------------- fichas

    /** @return list<array<string, mixed>> */
    public function fichas(?bool $activo = null): array
    {
        $q = FichaApetrechamento::query()->orderByRaw('lower(nome)');
        if ($activo !== null) {
            $q->where('activo', $activo);
        }
        $fichas = $q->limit(1000)->get();
        $itens = ItemFichaApetrechamento::query()->whereIn('ficha_id', $fichas->pluck('id'))->orderBy('ordem')->orderBy('id')->get()->groupBy('ficha_id');
        $produtos = $this->produtos($itens->flatten(1)->pluck('produto_id')->filter()->unique()->all());

        return $fichas->map(fn ($f) => $f->attributesToArray() + ['itens' => $this->itensDaFicha($itens[$f->id] ?? collect(), $produtos), 'n_itens' => count($itens[$f->id] ?? [])])->all();
    }

    /** @return array<string, mixed> */
    public function ficha(FichaApetrechamento $f): array
    {
        $itens = ItemFichaApetrechamento::query()->where('ficha_id', $f->id)->orderBy('ordem')->orderBy('id')->get();

        return $f->attributesToArray() + ['itens' => $this->itensDaFicha($itens, $this->produtos($itens->pluck('produto_id')->filter()->unique()->all())), 'n_itens' => $itens->count()];
    }

    /** @param  array<string, mixed>  $d  nome, tipologia, finalidade, activo, notas, itens[] (produto_id, descricao, quantidade, obrigatorio, notas) */
    public function gravarFicha(array $d, ?FichaApetrechamento $existente = null): FichaApetrechamento
    {
        $nome = Normaliza::texto($d['nome'] ?? null) ?? throw new ErroNegocio('Dê um nome à ficha de apetrechamento.', 'FICHA_NOME');
        $itens = $this->normalizarItens($d['itens'] ?? []);

        try {
            return DB::transaction(function () use ($d, $existente, $nome, $itens) {
                // o que o pedido não traz mantém-se (editar sem a chave `activo` não reactiva uma ficha inactiva)
                $campos = ['nome' => mb_substr($nome, 0, 120), 'tipologia' => array_key_exists('tipologia', $d) ? Normaliza::texto($d['tipologia']) : $existente?->tipologia,
                    'finalidade' => array_key_exists('finalidade', $d) ? Normaliza::texto($d['finalidade']) : $existente?->finalidade,
                    'activo' => array_key_exists('activo', $d) ? (bool) $d['activo'] : ($existente?->activo ?? true),
                    'notas' => array_key_exists('notas', $d) ? Normaliza::texto($d['notas']) : $existente?->notas, 'atualizado_por' => Autor::nome()];
                if ($existente) {
                    $f = FichaApetrechamento::query()->whereKey($existente->id)->lockForUpdate()->firstOrFail();
                    $f->update($campos);
                } else {
                    $f = FichaApetrechamento::create($campos + ['criado_por' => Autor::nome()]);
                }
                if (array_key_exists('itens', $d) || ! $existente) {
                    ItemFichaApetrechamento::query()->where('ficha_id', $f->id)->delete();
                    foreach ($itens as $i => $it) {
                        ItemFichaApetrechamento::create($it + ['ficha_id' => $f->id, 'ordem' => $i + 1]);
                    }
                }

                return $f->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new ErroNegocio('Já existe uma ficha com esse nome.', 'FICHA_DUPLICADA');
        }
    }

    public function apagarFicha(FichaApetrechamento $f): void
    {
        $usos = DB::table('ordens_trabalho_imo')->where('ficha_id', $f->id)->count();
        if ($usos > 0) {
            throw new ErroNegocio("A ficha foi usada em {$usos} ordem(ns): ponha-a inactiva em vez de a apagar.", 'FICHA_EM_USO');
        }
        $f->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $lista
     * @return list<array<string, mixed>>
     */
    private function normalizarItens(array $lista): array
    {
        $limpos = [];
        $vistos = [];
        foreach ($lista as $x) {
            $produto = isset($x['produto_id']) && $x['produto_id'] !== '' && $x['produto_id'] !== null ? (int) $x['produto_id'] : null;
            $descricao = Normaliza::texto($x['descricao'] ?? null);
            if ($produto === null && $descricao === null) {
                continue;   // linha vazia: descarta-se (como no legado), sem erro
            }
            if ($produto !== null && ! Produto::query()->whereKey($produto)->exists()) {
                throw new ErroNegocio('Um dos artigos da ficha não existe.', 'FICHA_ARTIGO');
            }
            $chave = $produto !== null ? "p{$produto}" : 'd'.CalculadoraObras::chave($descricao);
            if (isset($vistos[$chave])) {
                throw new ErroNegocio('Há um artigo repetido na ficha: some as quantidades numa só linha.', 'FICHA_ITEM_REPETIDO');
            }
            $vistos[$chave] = true;
            $limpos[] = ['produto_id' => $produto, 'descricao' => $produto === null ? mb_substr((string) $descricao, 0, 200) : null,
                'quantidade' => max(1, (int) round((float) ($x['quantidade'] ?? 1))), 'obrigatorio' => ! array_key_exists('obrigatorio', $x) || (bool) $x['obrigatorio'],
                'notas' => Normaliza::texto($x['notas'] ?? null)];
        }

        return $limpos;
    }

    /** @param  iterable<ItemFichaApetrechamento>  $itens  @param  array<int, Produto>  $produtos @return list<array<string, mixed>> */
    private function itensDaFicha(iterable $itens, array $produtos): array
    {
        $r = [];
        foreach ($itens as $i) {
            $p = $i->produto_id ? ($produtos[$i->produto_id] ?? null) : null;
            $r[] = ['id' => (int) $i->id, 'produto_id' => $i->produto_id, 'descricao' => $i->descricao, 'quantidade' => (int) $i->quantidade, 'obrigatorio' => (bool) $i->obrigatorio,
                'notas' => $i->notas, 'nome' => $p ? $this->nomeProduto($p) : ($i->descricao ?: 'Artigo'), 'de_armazem' => $p ? (bool) $p->movimenta_stock : false];
        }

        return $r;
    }

    // ---------------------------------------------------------------- bens

    /** @return array<string, mixed> bens da fracção e os totais */
    public function bens(Fracao $f): array
    {
        $bens = BemFracao::query()->where('fracao_id', $f->id)->orderBy('id')->get();
        $produtos = $this->produtos($bens->pluck('produto_id')->filter()->unique()->all());
        $imovel = Imovel::query()->find($f->imovel_id, ['id', 'codigo', 'nome']);

        return ['fracao' => ['id' => (int) $f->id, 'designacao' => $f->designacao, 'imovel_id' => (int) $f->imovel_id, 'imovel_nome' => $imovel?->nome, 'imovel_codigo' => $imovel?->codigo,
            'ligada_a_porta' => $f->porta_id !== null],
            'bens' => $bens->map(fn ($b) => $b->attributesToArray() + ['nome' => $b->produto_id ? $this->nomeProduto($produtos[$b->produto_id] ?? null, $b->descricao) : ($b->descricao ?: 'Bem')])->all(),
            'estados_que_contam' => array_values(array_diff(BemFracao::ESTADOS, ['DANIFICADO', 'EM_FALTA']))];
    }

    /** @param  array<string, mixed>  $d  produto_id, descricao, quantidade, estado, data, notas */
    public function gravarBem(Fracao $f, array $d, ?BemFracao $existente = null): BemFracao
    {
        $produto = ! empty($d['produto_id']) ? (int) $d['produto_id'] : null;
        $descricao = Normaliza::texto($d['descricao'] ?? null);
        if ($produto === null && $descricao === null) {
            throw new ErroNegocio('Indique o artigo ou descreva o bem.', 'BEM_SEM_ARTIGO');
        }
        if ($produto !== null && ! Produto::query()->whereKey($produto)->exists()) {
            throw new ErroNegocio('O artigo indicado não existe.', 'BEM_ARTIGO');
        }
        $qtd = isset($d['quantidade']) && $d['quantidade'] !== '' ? (float) $d['quantidade'] : 1.0;
        if ($qtd <= 0) {
            throw new ErroNegocio('A quantidade tem de ser maior do que zero.', 'BEM_QUANTIDADE');
        }
        $estado = (string) ($d['estado'] ?? ($existente?->estado ?? 'BOM'));
        if (! in_array($estado, BemFracao::ESTADOS, true)) {
            throw new ErroNegocio('Estado do bem desconhecido.', 'BEM_ESTADO');
        }
        $campos = ['produto_id' => $produto, 'descricao' => $produto === null ? mb_substr((string) $descricao, 0, 200) : null, 'quantidade' => CalculadoraObras::q($qtd), 'estado' => $estado,
            'data' => Normaliza::texto($d['data'] ?? null) ?? ($existente?->data?->format('Y-m-d') ?? now()->toDateString()), 'notas' => Normaliza::texto($d['notas'] ?? null), 'atualizado_por' => Autor::nome()];

        if ($existente) {
            $b = BemFracao::query()->where('fracao_id', $f->id)->findOrFail($existente->id);
            $b->update($campos);   // a origem e o activo ligado não se tocam (o legado repunha-os)

            return $b->refresh();
        }

        return BemFracao::create($campos + ['fracao_id' => $f->id, 'origem' => 'MANUAL', 'criado_por' => Autor::nome()])->refresh();
    }

    public function apagarBem(Fracao $f, BemFracao $b): void
    {
        if ((int) $b->fracao_id !== (int) $f->id) {
            throw new ErroNegocio('Bem não encontrado.', 'BEM_NAO_ENCONTRADO', 404);
        }
        $b->delete();
    }

    /**
     * Traz os bens que o Activos já localiza na porta da fracção (sem os alterar); os já trazidos saltam-se (idempotente por activo).
     *
     * @return array{criados: int, encontrados: int}
     */
    public function trazerDoImobilizado(Fracao $f): array
    {
        if (! $f->porta_id) {
            throw new ErroNegocio('Esta fracção não está ligada a nenhuma porta da planta. Registe os bens à mão ou gere-os pela ficha de apetrechamento.', 'BEM_SEM_PORTA');
        }

        return DB::transaction(function () use ($f) {
            Fracao::query()->whereKey($f->id)->lockForUpdate()->firstOrFail();
            $ativos = AtivoImobilizado::query()->where('porta_id', $f->porta_id)->where(fn ($q) => $q->whereNull('tipo_ativo')->orWhere('tipo_ativo', '<>', 'EDIFICIO'))
                ->where('estado', '<>', AtivoImobilizado::ESTADO_ABATIDO)->orderBy('id')->get();
            $ligados = BemFracao::query()->where('fracao_id', $f->id)->whereNotNull('ativo_id')->pluck('ativo_id')->map(fn ($i) => (int) $i)->all();
            $criados = 0;
            foreach ($ativos as $a) {
                if (in_array((int) $a->id, $ligados, true)) {
                    continue;
                }
                BemFracao::create(['fracao_id' => $f->id, 'descricao' => mb_substr((string) ($a->descricao ?: 'Activo '.($a->codigo ?: $a->id)), 0, 200), 'quantidade' => '1.000', 'ativo_id' => $a->id,
                    'estado' => 'BOM', 'origem' => 'INVENTARIO', 'data' => now()->toDateString(), 'notas' => mb_substr('Activo '.($a->codigo ?: $a->id), 0, 300), 'criado_por' => Autor::nome(),
                    'atualizado_por' => Autor::nome()]);
                $criados++;
            }

            return ['criados' => $criados, 'encontrados' => $ativos->count()];
        });
    }

    /**
     * Compara a fracção com uma ficha: o que precisa, o que tem e o que falta (bens danificados ou em falta não contam).
     *
     * @return array<string, mixed>
     */
    public function comparar(Fracao $f, FichaApetrechamento $ficha): array
    {
        $ficha = $this->ficha($ficha);
        $bens = BemFracao::query()->where('fracao_id', $f->id)->get(['produto_id', 'descricao', 'quantidade', 'estado'])->map(fn ($b) => $b->only(['produto_id', 'descricao', 'quantidade', 'estado']))->all();
        $linhas = CalculadoraObras::comparar($ficha['itens'], $bens);
        $comFalta = array_values(array_filter($linhas, fn ($l) => bccomp($l['falta'], '0', 3) > 0));

        return ['ficha' => ['id' => $ficha['id'], 'nome' => $ficha['nome']], 'linhas' => $linhas, 'faltam' => count($comFalta),
            'faltam_obrigatorios' => count(array_filter($comFalta, fn ($l) => $l['obrigatorio'])), 'completa' => $comFalta === []];
    }

    // ---------------------------------------------------------------- apoio

    /** @param  list<int>  $ids  @return array<int, Produto> */
    private function produtos(array $ids): array
    {
        return $ids === [] ? [] : Produto::query()->whereIn('id', $ids)->get(['id', 'codigo', 'nome', 'movimenta_stock'])->keyBy('id')->all();
    }

    private function nomeProduto(?Produto $p, ?string $alt = null): string
    {
        return $p ? trim(($p->codigo ? "{$p->codigo} — " : '').$p->nome) : ($alt ?: 'Artigo');
    }
}
