<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\Banco;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Services\RH\ServicoColaboradores;
use App\Support\Tenancy\ContextoEmpresa;

/**
 * Dados bancários das contas 42/43 do plano (Fase A, legado novo app_v2.js:9050-9163: CONTAS_BANCARIAS_PREFIXOS,
 * _accCamposBancarios, _accPreencherBancarios, _accSugerirBancos).
 *   - só as contas 42 (depósitos a prazo) e 43 (depósitos à ordem) de MOVIMENTO guardam banco, balcão, n.º de conta,
 *     IBAN, SWIFT e titular; se a conta deixar de ser bancária (código ou tipo), os dados limpam-se (saveAccount);
 *   - correcção face ao legado (que gravava o texto tal como vinha): o IBAN é normalizado e validado (ISO 13616, mod 97;
 *     o NIB angolano de 21 dígitos passa a AO06…) com o mesmo validador dos colaboradores, e o SWIFT/BIC tem de ter 8 ou 11
 *     caracteres;
 *   - leitura simples para as cartas de pagamento (Fase D): titular vazio = nome da empresa; sem IBAN a conta não emite cartas
 *     (cartas_pagamento.js:134 «contaCompleta»).
 * As escritas passam pelo model PlanoConta, que invalida a cache do plano agora e depois do commit (InvalidacaoCache).
 */
final class ServicoContasBancarias
{
    /** BIC (ISO 9362): banco (4 letras) + país (2 letras) + localidade (2) + balcão opcional (3). */
    private const PADRAO_SWIFT = '/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/';

    /** Comprimentos máximos (migração 2026_10_13_220100_fa_tesouraria_dados_bancarios_plano). */
    public const COMPRIMENTOS = ['banco_nome' => 100, 'banco_balcao' => 100, 'banco_numero_conta' => 40, 'banco_iban' => 34, 'banco_swift' => 11, 'banco_titular' => 150];

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * Prepara os dados bancários de uma gravação do plano de contas.
     * Conta bancária de movimento: normaliza e valida os campos enviados (os ausentes ficam como estão).
     * Outra conta: todos os campos bancários a null (limpam-se se a conta deixou de ser bancária).
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function normalizar(array $dados, string $codigo, string $tipo): array
    {
        if ($tipo === PlanoConta::TIPO_TOTALIZADORA || ! PlanoConta::eCodigoBancario($codigo)) {
            return array_merge($dados, array_fill_keys(PlanoConta::CAMPOS_BANCARIOS, null));
        }
        foreach (PlanoConta::CAMPOS_BANCARIOS as $campo) {
            if (! array_key_exists($campo, $dados)) {
                continue;
            }
            $valor = trim(preg_replace('/\s+/u', ' ', (string) ($dados[$campo] ?? '')));
            $dados[$campo] = $valor === '' ? null : $valor;
        }
        if (! empty($dados['banco_iban'])) {
            $iban = ServicoColaboradores::normalizarIban($dados['banco_iban']);
            if (! ServicoColaboradores::ibanValido($iban)) {
                throw new ErroNegocio('IBAN inválido: indique AO + 23 dígitos (ou os 21 dígitos do NIB), ou um IBAN estrangeiro válido.', 'IBAN_INVALIDO', 422, ['campo' => 'banco_iban']);
            }
            $dados['banco_iban'] = $iban;
        }
        if (! empty($dados['banco_swift'])) {
            $swift = strtoupper(str_replace(' ', '', $dados['banco_swift']));
            if (! preg_match(self::PADRAO_SWIFT, $swift)) {
                throw new ErroNegocio('SWIFT/BIC inválido: tem de ter 8 ou 11 caracteres (ex.: BAIPAOLU).', 'SWIFT_INVALIDO', 422, ['campo' => 'banco_swift']);
            }
            $dados['banco_swift'] = $swift;
        }

        return $dados;
    }

    /**
     * Contas bancárias (42/43 de movimento) da empresa activa, com os dados bancários.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        $empresa = $this->contexto->obrigatorio();
        $nomeEmpresa = $this->nomeEmpresa($empresa);

        return PlanoConta::query()->where('empresa_id', $empresa)
            ->where(fn ($q) => $q->where('tipo', PlanoConta::TIPO_MOVIMENTO)->orWhereNull('tipo'))
            ->where(function ($q) {
                foreach (PlanoConta::PREFIXOS_BANCARIOS as $p) {
                    $q->orWhere('codigo', 'like', "{$p}%");
                }
            })
            ->orderBy('codigo')->get()
            ->map(fn (PlanoConta $c) => $this->formatar($c, $nomeEmpresa))->values()->all();
    }

    /**
     * Dados bancários de uma conta (leitura para o formulário do plano e para as cartas de pagamento).
     *
     * @return array<string, mixed>
     */
    public function dados(string $codigo): array
    {
        $empresa = $this->contexto->obrigatorio();
        $conta = PlanoConta::query()->where('empresa_id', $empresa)->where('codigo', $codigo)->first()
            ?? throw new ErroNegocio("A conta {$codigo} não existe no plano de contas.", 'CONTA_INEXISTENTE', 404, ['codigo_conta' => $codigo]);
        if (! $conta->eBancaria()) {
            throw new ErroNegocio("A conta {$codigo} não é uma conta bancária de movimento (42 depósitos a prazo ou 43 depósitos à ordem).", 'CONTA_NAO_BANCARIA', 422, ['codigo_conta' => $codigo]);
        }

        return $this->formatar($conta, $this->nomeEmpresa($empresa));
    }

    /**
     * Nomes dos bancos registados (sugestões do campo «Banco», _accSugerirBancos do legado).
     *
     * @return list<string>
     */
    public function sugestoesBancos(): array
    {
        return Banco::query()->where('empresa_id', $this->contexto->obrigatorio())->whereNotNull('nome')
            ->ordenarPorNome()->pluck('nome')->map(fn ($n) => trim((string) $n))->filter()->unique()->values()->all();
    }

    /** @return array<string, mixed> */
    private function formatar(PlanoConta $c, ?string $nomeEmpresa): array
    {
        $titular = $c->banco_titular ?: null;

        return [
            'id' => $c->id, 'codigo' => $c->codigo, 'descricao' => $c->descricao, 'codigo_moeda' => $c->codigo_moeda ?: 'AOA',
            'banco_nome' => $c->banco_nome, 'banco_balcao' => $c->banco_balcao, 'banco_numero_conta' => $c->banco_numero_conta,
            'banco_iban' => $c->banco_iban, 'banco_swift' => $c->banco_swift, 'banco_titular' => $titular,
            // «Deixe vazio para usar o nome da empresa» (formulário do legado novo)
            'titular_efectivo' => $titular ?? $nomeEmpresa,
            // sem IBAN a conta não emite cartas de ordem de pagamento (cartas_pagamento.js:134)
            'completa' => trim((string) $c->banco_iban) !== '',
        ];
    }

    private function nomeEmpresa(int $empresa): ?string
    {
        return Empresa::query()->whereKey($empresa)->value('nome');
    }
}
