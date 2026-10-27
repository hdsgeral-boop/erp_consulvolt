<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\Banco;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;

/**
 * Conta-cartão de tesouraria (classe 3 marcada pelo utilizador como cartão de crédito/débito da empresa) — espelha
 * `ServicoContasBancarias`, mas para contas de dívida a terceiros em vez de contas bancárias 42/43. Um cartão não é
 * dinheiro disponível, é uma dívida ao emissor; liquida-se pelo banco, por isso entra na Tesouraria ao lado de
 * 42/43/45, abatido nas disponibilidades (saldo credor, soma-se com sinal).
 *   - só uma conta de movimento da classe 3 (código a começar por «3») pode ser marcada;
 *   - o banco associado é texto livre sugerido a partir de `bancos`, como nas contas 42/43 (sem FK nova);
 *   - o número do cartão guarda-se só com os últimos dígitos — o PAN completo nunca deve ficar registado.
 */
final class ServicoContasCartao
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * Prepara os dados do cartão de uma gravação do plano de contas.
     * Conta marcada como cartão: normaliza os campos enviados e exige o banco associado.
     * Outra conta (ou flag desligada): os campos do cartão limpam-se.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function normalizar(array $dados, string $codigo, string $tipo): array
    {
        $marcada = (bool) ($dados['cartao_tesouraria'] ?? false);
        if (! $marcada) {
            return array_merge($dados, ['cartao_tesouraria' => false], array_fill_keys(PlanoConta::CAMPOS_CARTAO, null));
        }
        if ($tipo === PlanoConta::TIPO_TOTALIZADORA) {
            throw new ErroNegocio('Só uma conta de movimento pode ser marcada como cartão de tesouraria.', 'CARTAO_CONTA_TOTALIZADORA', 422);
        }
        if (! str_starts_with($codigo, PlanoConta::PREFIXO_CARTAO_ELEGIVEL)) {
            throw new ErroNegocio('Só uma conta da classe 3 (dívida a terceiros) pode ser marcada como cartão de tesouraria.', 'CARTAO_CLASSE_INVALIDA', 422);
        }
        $banco = trim((string) ($dados['cartao_banco'] ?? ''));
        if ($banco === '') {
            throw new ErroNegocio('Indique o banco associado ao cartão.', 'CARTAO_SEM_BANCO', 422, ['campo' => 'cartao_banco']);
        }
        $dados['cartao_banco'] = $banco;
        $numero = trim((string) ($dados['cartao_numero'] ?? ''));
        $dados['cartao_numero'] = $numero === '' ? null : $numero;

        return $dados;
    }

    /**
     * Contas-cartão (classe 3 marcadas) da empresa activa, para os grupos «Cartões — Classe 3» dos selectores.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        return PlanoConta::query()->where('empresa_id', $this->contexto->obrigatorio())->where('cartao_tesouraria', true)
            ->where(fn ($q) => $q->where('tipo', PlanoConta::TIPO_MOVIMENTO)->orWhereNull('tipo'))
            ->orderBy('codigo')->get()->map(fn (PlanoConta $c) => $this->formatar($c))->values()->all();
    }

    /**
     * Dados do cartão de uma conta (leitura para o formulário do plano e para os ecrãs de Tesouraria).
     *
     * @return array<string, mixed>
     */
    public function dados(string $codigo): array
    {
        $empresa = $this->contexto->obrigatorio();
        $conta = PlanoConta::query()->where('empresa_id', $empresa)->where('codigo', $codigo)->first()
            ?? throw new ErroNegocio("A conta {$codigo} não existe no plano de contas.", 'CONTA_INEXISTENTE', 404, ['codigo_conta' => $codigo]);
        if (! $conta->eCartaoTesouraria()) {
            throw new ErroNegocio("A conta {$codigo} não é uma conta-cartão de tesouraria.", 'CONTA_NAO_CARTAO', 422, ['codigo_conta' => $codigo]);
        }

        return $this->formatar($conta);
    }

    /** @return list<string> códigos das contas marcadas como cartão, da empresa activa */
    public function codigos(): array
    {
        return PlanoConta::query()->where('empresa_id', $this->contexto->obrigatorio())->where('cartao_tesouraria', true)
            ->pluck('codigo')->map(fn ($c) => (string) $c)->values()->all();
    }

    public function ehCodigoCartao(string $codigo): bool
    {
        return in_array(trim($codigo), $this->codigos(), true);
    }

    /** 42/43/45 por prefixo OU cartão — a usar em vez de regex/LIKE só de 4x. */
    public function ehCodigoDisponibilidade(string $codigo): bool
    {
        $codigo = trim($codigo);

        return str_starts_with($codigo, '42') || str_starts_with($codigo, '43') || str_starts_with($codigo, '45') || $this->ehCodigoCartao($codigo);
    }

    /**
     * Nomes dos bancos registados (sugestões do campo «Banco associado»), o mesmo catálogo das contas 42/43.
     *
     * @return list<string>
     */
    public function sugestoesBancos(): array
    {
        return Banco::query()->where('empresa_id', $this->contexto->obrigatorio())->whereNotNull('nome')
            ->ordenarPorNome()->pluck('nome')->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
    }

    /** @return array<string, mixed> */
    private function formatar(PlanoConta $c): array
    {
        return ['id' => $c->id, 'codigo' => $c->codigo, 'descricao' => $c->descricao, 'codigo_moeda' => $c->codigo_moeda ?: 'AOA',
            'cartao_numero' => $c->cartao_numero, 'cartao_banco' => $c->cartao_banco];
    }
}
