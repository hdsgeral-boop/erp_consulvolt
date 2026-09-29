<?php

namespace App\Services\Terceiros;

use App\Exceptions\ErroNegocio;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Terceiros (clientes e fornecedores). Paridade com saveCustomer (js/ui_sales.js:2156) e saveSupplier
 * (js/ui_compras_v2.js:2517):
 *   - nome obrigatório; fornecedor exige também NIF;
 *   - conta contabilística obrigatória e de movimento (sem contas por omissão);
 *   - NIF único na empresa: se já existir, usa-se o terceiro existente (não se cria outro);
 *   - um fornecedor que passa a cliente (ou vice-versa) fica com os dois papéis;
 *   - não se elimina quem tem documentos, lançamentos ou movimentos associados.
 */
final class ServicoTerceiros
{
    /** Onde um terceiro pode estar referenciado (bloqueia a eliminação). */
    private const REFERENCIAS = [
        ['vendas', 'cliente_id', 'documentos de venda'],
        ['recibos_venda', 'cliente_id', 'recibos'],
        ['cotacoes_compra', 'fornecedor_id', 'propostas de compra'],
        ['encomendas_compra', 'fornecedor_id', 'encomendas a fornecedores'],
        ['faturas_compra', 'fornecedor_id', 'facturas de fornecedor'],
        ['lancamentos_contabeis', 'terceiro_id', 'lançamentos contabilísticos'],
        ['itens_documento_tesouraria', 'terceiro_id', 'documentos de tesouraria'],
        ['movimentos_caixa', 'terceiro_id', 'movimentos de caixa'],
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /** @param  array<string, mixed>  $f */
    public function listar(array $f): LengthAwarePaginator
    {
        return Terceiro::query()
            ->when(($f['papel'] ?? null) === Terceiro::CLIENTE, fn ($q) => $q->whereIn('tipo', Terceiro::TIPOS_CLIENTE))
            ->when(($f['papel'] ?? null) === Terceiro::FORNECEDOR, fn ($q) => $q->whereIn('tipo', Terceiro::TIPOS_FORNECEDOR))
            ->when(($f['papel'] ?? null) === Terceiro::COLABORADOR, fn ($q) => $q->where('tipo', Terceiro::COLABORADOR))
            ->when($f['pesquisa'] ?? null, function ($q, $p) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $p).'%';
                $q->where(fn ($s) => $s->where('nome', 'ilike', $termo)->orWhere('nif', 'ilike', $termo)->orWhere('codigo_conta', 'like', $termo));
            })
            ->orderBy('nome')
            ->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));
    }

    /**
     * Cria o terceiro com o papel indicado — ou, se o NIF já existir, acrescenta-lhe o papel (paridade:
     * o legado recusava criar um segundo registo com o mesmo NIF e mandava seleccionar o existente).
     *
     * @param  array<string, mixed>  $dados
     */
    public function guardar(string $papel, array $dados, ?Terceiro $existente = null): Terceiro
    {
        $this->validarContas($dados);
        $nif = isset($dados['nif']) ? trim((string) $dados['nif']) : null;
        if ($papel === Terceiro::FORNECEDOR && ($nif === null || $nif === '')) {
            throw new ErroNegocio('O NIF do fornecedor é obrigatório.', 'NIF_OBRIGATORIO', 422);
        }

        if ($nif !== null && $nif !== '') {
            $outro = Terceiro::query()->where('nif', $nif)->when($existente, fn ($q) => $q->whereKeyNot($existente->id))->first();
            if ($outro !== null) {
                throw new ErroNegocio("Já existe outra entidade com este NIF ({$outro->nome}). Seleccione-a em vez de criar uma nova.",
                    'NIF_DUPLICADO', 422, ['terceiro_id' => $outro->id, 'nome' => $outro->nome]);
            }
        }

        return DB::transaction(function () use ($papel, $dados, $existente) {
            $dados['tipo'] = $this->combinarTipo($existente?->tipo, $papel);
            $dados['codigo_moeda'] = $dados['codigo_moeda'] ?? $existente?->codigo_moeda ?? 'AOA';
            if ($existente) {
                $existente->update($dados);

                return $existente;
            }

            return Terceiro::create($dados);
        });
    }

    public function eliminar(Terceiro $terceiro): void
    {
        $empresa = $this->contexto->obrigatorio();
        foreach (self::REFERENCIAS as [$tabela, $coluna, $descricao]) {
            if (DB::table($tabela)->where('empresa_id', $empresa)->where($coluna, $terceiro->id)->exists()) {
                throw new ErroNegocio("Não é possível eliminar: existem {$descricao} associados a esta entidade.", 'TERCEIRO_EM_USO', 422, ['origem' => $tabela]);
            }
        }
        $terceiro->delete();   // eliminação lógica
    }

    /** Papéis: CLIENTE + FORNECEDOR = CLIENTE_FORNECEDOR (legado: "FORNECEDOR, CLIENTE"). */
    private function combinarTipo(?string $atual, string $papel): string
    {
        if ($atual === null || $atual === $papel || $atual === Terceiro::CLIENTE_FORNECEDOR && $papel !== Terceiro::COLABORADOR) {
            return $atual ?? $papel;
        }
        if (in_array($atual, [Terceiro::CLIENTE, Terceiro::FORNECEDOR], true) && in_array($papel, [Terceiro::CLIENTE, Terceiro::FORNECEDOR], true)) {
            return Terceiro::CLIENTE_FORNECEDOR;
        }

        throw new ErroNegocio("Esta entidade está registada como {$atual}: não pode ser também {$papel}. Crie uma ficha própria.", 'TIPO_TERCEIRO_INCOMPATIVEL', 422);
    }

    /** @param  array<string, mixed>  $dados */
    private function validarContas(array $dados): void
    {
        foreach (['codigo_conta', 'conta_compra_transitoria'] as $campo) {
            if (! empty($dados[$campo])) {
                $this->plano->contaDeMovimento((string) $dados[$campo]);
            }
        }
    }
}
