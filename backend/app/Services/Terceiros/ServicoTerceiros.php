<?php

namespace App\Services\Terceiros;

use App\Exceptions\ErroNegocio;
use App\Models\EnderecoTerceiro;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Terceiros (clientes e fornecedores). Paridade com saveCustomer (js/ui_sales.js:2156), saveSupplier
 * (js/ui_compras_v2.js:2517) e, na Versão Actual, saveThirdParty (js/ui_aux.js:579-659):
 *   - nome obrigatório; fornecedor exige também NIF;
 *   - cada papel tem a sua conta (contas_terceiro.js): conta_cliente (classe 31) e conta_fornecedor (32 ou 37.0),
 *     obrigatória para o papel gravado, da classe certa, existente no plano e de movimento (sem contas por omissão);
 *     o `codigo_conta` enviado pelas fichas antigas é lido como a conta do papel da ficha;
 *   - NIF único na empresa: se já existir, usa-se o terceiro existente (não se cria outro);
 *   - um fornecedor que passa a cliente (ou vice-versa) fica com os dois papéis; nas Tabelas auxiliares o tipo
 *     escolhido substitui o anterior (incl. «Cliente e Fornecedor» e «Colaborador»);
 *   - endereço estruturado (Rua, Nº Porta, Bairro, Município, Província, País) em enderecos_terceiros pelo NIF + empresa,
 *     com o texto `endereco` (usado nos documentos) recomposto a partir dele;
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

    /** Papéis aceites na gravação. */
    public const PAPEIS = [Terceiro::CLIENTE, Terceiro::FORNECEDOR, Terceiro::CLIENTE_FORNECEDOR, Terceiro::COLABORADOR];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /**
     * Lista por papel. Clientes = papel de cliente E também quem tem documentos de venda ou recibos (4.4.2 do relatório de
     * verificação visual: na empresa 18 havia fornecedores com facturas de venda que não apareciam na lista de clientes);
     * vêm com `tem_documentos_venda` e, ao gravar a ficha como cliente, ganham o papel (CLIENTE_FORNECEDOR).
     * `estrito` (seletores do POS, do Armazém e das fichas — ADR-071): só pelo papel, como ehCliente/ehFornecedor do legado.
     * Cada linha traz o n.º de contas bancárias e o banco da predefinida (coluna «Banco», ui_aux.js:178-198).
     *
     * @param  array<string, mixed>  $f
     */
    public function listar(array $f): LengthAwarePaginator
    {
        $docsVenda = fn (string $tabela) => fn ($s) => $s->from($tabela)->selectRaw('1')
            ->whereColumn("{$tabela}.cliente_id", 'terceiros.id')->whereColumn("{$tabela}.empresa_id", 'terceiros.empresa_id');

        return Terceiro::query()
            ->select('terceiros.*')
            ->selectRaw('(SELECT COUNT(*) FROM contas_bancarias_terceiros cb WHERE cb.terceiro_id = terceiros.id AND cb.empresa_id = terceiros.empresa_id) AS contas_bancarias')
            ->selectRaw('(SELECT COALESCE(cb.banco, cb.iban) FROM contas_bancarias_terceiros cb WHERE cb.terceiro_id = terceiros.id AND cb.empresa_id = terceiros.empresa_id'
                .' ORDER BY cb.predefinida DESC, cb.id LIMIT 1) AS banco_predefinido')
            ->when(($f['papel'] ?? null) === Terceiro::CLIENTE, fn ($q) => $q
                ->selectRaw('(EXISTS (SELECT 1 FROM vendas WHERE vendas.cliente_id = terceiros.id AND vendas.empresa_id = terceiros.empresa_id)'
                    .' OR EXISTS (SELECT 1 FROM recibos_venda WHERE recibos_venda.cliente_id = terceiros.id AND recibos_venda.empresa_id = terceiros.empresa_id)) AS tem_documentos_venda')
                ->where(fn ($w) => $w->whereIn('terceiros.tipo', Terceiro::TIPOS_CLIENTE)
                    ->when(! filter_var($f['estrito'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($x) => $x->orWhere(fn ($x) => $x->where('terceiros.tipo', '<>', Terceiro::COLABORADOR)
                        ->where(fn ($y) => $y->whereExists($docsVenda('vendas'))->orWhereExists($docsVenda('recibos_venda')))))))
            ->when(($f['papel'] ?? null) === Terceiro::FORNECEDOR, fn ($q) => $q->whereIn('tipo', Terceiro::TIPOS_FORNECEDOR))
            ->when(($f['papel'] ?? null) === Terceiro::COLABORADOR, fn ($q) => $q->where('tipo', Terceiro::COLABORADOR))
            ->when($f['pesquisa'] ?? null, function ($q, $p) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $p).'%';
                $q->where(fn ($s) => $s->where('nome', 'ilike', $termo)->orWhere('nif', 'ilike', $termo)->orWhere('codigo_conta', 'like', $termo)
                    ->orWhere('conta_cliente', 'like', $termo)->orWhere('conta_fornecedor', 'like', $termo));
            })
            ->ordenarPorNome('terceiros.nome')->orderBy('terceiros.id')   // ordem portuguesa, sem espaços a mais (ADR-071)
            ->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));
    }

    /**
     * Cria o terceiro com o papel indicado — ou, se o NIF já existir, acrescenta-lhe o papel (paridade:
     * o legado recusava criar um segundo registo com o mesmo NIF e mandava seleccionar o existente).
     * $substituirTipo: o tipo passa a ser exactamente $papel (Tabelas auxiliares › Terceiros, ui_aux.js:626).
     * $dados['morada']: endereço estruturado (rua, numero_porta, bairro, municipio, provincia, pais).
     *
     * @param  array<string, mixed>  $dados
     */
    public function guardar(string $papel, array $dados, ?Terceiro $existente = null, bool $substituirTipo = false): Terceiro
    {
        if (! in_array($papel, self::PAPEIS, true)) {
            throw new ErroNegocio("Tipo de terceiro desconhecido: {$papel}.", 'TIPO_TERCEIRO_INVALIDO', 422);
        }
        $morada = array_key_exists('morada', $dados) ? (array) ($dados['morada'] ?? []) : null;
        unset($dados['morada']);
        $dados = $this->contaDaFichaAntiga($papel, $dados);
        $this->validarContas($dados);

        $nif = array_key_exists('nif', $dados) ? (isset($dados['nif']) ? trim((string) $dados['nif']) : null) : $existente?->nif;
        if (ContaTerceiro::temPapel($papel, ContaTerceiro::FORNECEDOR) && ($nif === null || $nif === '')) {
            throw new ErroNegocio('O NIF do fornecedor é obrigatório.', 'NIF_OBRIGATORIO', 422);
        }

        if ($nif !== null && $nif !== '') {
            $outro = Terceiro::query()->where('nif', $nif)->when($existente, fn ($q) => $q->whereKeyNot($existente->id))->first();
            if ($outro !== null) {
                throw new ErroNegocio("Já existe outra entidade com este NIF ({$outro->nome}). Seleccione-a em vez de criar uma nova.",
                    'NIF_DUPLICADO', 422, ['terceiro_id' => $outro->id, 'nome' => $outro->nome]);
            }
        }
        $this->exigirContasDoPapel($papel, $dados, $existente);

        return DB::transaction(function () use ($papel, $dados, $existente, $substituirTipo, $morada, $nif) {
            $nifAnterior = $existente?->nif;
            $dados['tipo'] = $substituirTipo ? $papel : $this->combinarTipo($existente?->tipo, $papel);
            $dados['codigo_moeda'] = $dados['codigo_moeda'] ?? $existente?->codigo_moeda ?? 'AOA';
            if ($morada !== null) {
                $texto = EnderecoTerceiro::textoDe($morada);
                if ($texto !== '') {
                    $dados['endereco'] = mb_substr($texto, 0, 1000);   // o texto dos documentos acompanha o endereço estruturado
                }
            }
            if ($existente) {
                $existente->update($dados);
                $terceiro = $existente;
            } else {
                $terceiro = Terceiro::create($dados);
            }
            if ($morada !== null) {
                $this->gravarMorada((string) $nif, $nifAnterior, $morada);
            } elseif ($nifAnterior && $nif && $nifAnterior !== $nif) {
                EnderecoTerceiro::query()->where('nif', $nifAnterior)->update(['nif' => $nif]);   // o endereço segue o NIF
            }

            return $terceiro;
        });
    }

    /** Endereço estruturado do terceiro (por NIF + empresa activa), ou null. */
    public function morada(Terceiro $terceiro): ?EnderecoTerceiro
    {
        $nif = trim((string) $terceiro->nif);

        return $nif === '' ? null : EnderecoTerceiro::query()->where('nif', $nif)->orderBy('id')->first();
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

    /** Papéis: CLIENTE + FORNECEDOR = CLIENTE_FORNECEDOR (legado: "FORNECEDOR, CLIENTE"; comPapel, contas_terceiro.js:50-56). */
    private function combinarTipo(?string $atual, string $papel): string
    {
        if ($atual === null || $atual === $papel || $atual === Terceiro::CLIENTE_FORNECEDOR && $papel !== Terceiro::COLABORADOR) {
            return $atual ?? $papel;
        }
        $comerciais = [Terceiro::CLIENTE, Terceiro::FORNECEDOR, Terceiro::CLIENTE_FORNECEDOR];
        if (in_array($atual, $comerciais, true) && in_array($papel, $comerciais, true)) {
            return Terceiro::CLIENTE_FORNECEDOR;
        }

        throw new ErroNegocio("Esta entidade está registada como {$atual}: não pode ser também {$papel}. Crie uma ficha própria.", 'TIPO_TERCEIRO_INCOMPATIVEL', 422);
    }

    /**
     * As fichas antigas (e importações/integrações anteriores) enviam uma só `codigo_conta`: passa a ser a conta do papel da
     * ficha (cliente → conta_cliente, fornecedor → conta_fornecedor). O codigo_conta gravado fica intacto (registo antigo).
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function contaDaFichaAntiga(string $papel, array $dados): array
    {
        if (! array_key_exists('codigo_conta', $dados) || $papel === Terceiro::COLABORADOR) {
            return $dados;
        }
        $conta = $dados['codigo_conta'];
        unset($dados['codigo_conta']);
        if ($papel === Terceiro::CLIENTE && ! array_key_exists('conta_cliente', $dados)) {
            $dados['conta_cliente'] = $conta;
        } elseif ($papel === Terceiro::FORNECEDOR && ! array_key_exists('conta_fornecedor', $dados)) {
            $dados['conta_fornecedor'] = $conta;
        }

        return $dados;
    }

    /**
     * Classe certa para cada papel; a conta tem de existir no plano da empresa e ser de movimento (nunca totalizadora).
     *
     * @param  array<string, mixed>  $dados
     */
    private function validarContas(array $dados): void
    {
        foreach (['conta_cliente' => ContaTerceiro::CLIENTE, 'conta_fornecedor' => ContaTerceiro::FORNECEDOR] as $campo => $papel) {
            $c = trim((string) ($dados[$campo] ?? ''));
            if ($c !== '' && ! ContaTerceiro::classeCorrecta($c, $papel)) {
                throw new ErroNegocio($papel === ContaTerceiro::CLIENTE
                    ? "A conta de cliente {$c} não é da classe 31."
                    : "A conta de fornecedor {$c} não é da classe 32 nem 37.0.", 'CONTA_CLASSE_ERRADA', 422, ['campo' => $campo, 'codigo_conta' => $c]);
            }
        }
        foreach (['conta_cliente', 'conta_fornecedor', 'codigo_conta', 'conta_compra_transitoria'] as $campo) {
            if (! empty($dados[$campo])) {
                $this->plano->contaDeMovimento((string) $dados[$campo]);
            }
        }
    }

    /**
     * A conta do papel gravado é obrigatória (saveThirdParty: «Indique a conta de cliente (classe 31).»). Conta a que já
     * está gravada (incl. o codigo_conta antigo da classe certa) quando o pedido não a altera.
     *
     * @param  array<string, mixed>  $dados
     */
    private function exigirContasDoPapel(string $papel, array $dados, ?Terceiro $existente): void
    {
        $atual = $existente ? $existente->only(['codigo_conta', 'conta_cliente', 'conta_fornecedor']) : [];
        $final = array_merge($atual, array_intersect_key($dados, array_flip(['conta_cliente', 'conta_fornecedor'])));
        if (ContaTerceiro::temPapel($papel, ContaTerceiro::CLIENTE) && ContaTerceiro::cliente($final) === '') {
            throw new ErroNegocio('Indique a conta de cliente (classe 31).', 'CONTA_CLIENTE_OBRIGATORIA', 422, ['campo' => 'conta_cliente']);
        }
        if (ContaTerceiro::temPapel($papel, ContaTerceiro::FORNECEDOR) && ContaTerceiro::fornecedor($final) === '') {
            throw new ErroNegocio('Indique a conta de fornecedor (classe 32 ou 37.0).', 'CONTA_FORNECEDOR_OBRIGATORIA', 422, ['campo' => 'conta_fornecedor']);
        }
    }

    /**
     * Endereço estruturado ligado pelo NIF + empresa (saveThirdParty, ui_aux.js:641-649). Sem NIF não há ligação possível:
     * fica só o texto em terceiros.endereco.
     *
     * @param  array<string, mixed>  $morada
     */
    private function gravarMorada(string $nif, ?string $nifAnterior, array $morada): void
    {
        $nif = trim($nif);
        if ($nif === '') {
            return;
        }
        $valores = [];
        foreach (EnderecoTerceiro::CAMPOS as $campo) {
            $v = trim((string) ($morada[$campo] ?? ''));
            $valores[$campo] = $v === '' ? null : mb_substr($v, 0, $campo === 'numero_porta' ? 50 : 150);
        }
        $registo = EnderecoTerceiro::query()->where('nif', $nif)->orderBy('id')->first()
            ?? ($nifAnterior && $nifAnterior !== $nif ? EnderecoTerceiro::query()->where('nif', $nifAnterior)->orderBy('id')->first() : null);
        if ($registo) {
            $registo->update(['nif' => $nif] + $valores);
        } elseif (array_filter($valores, fn ($v) => $v !== null)) {
            EnderecoTerceiro::create(['nif' => $nif] + $valores);
        }
    }
}
