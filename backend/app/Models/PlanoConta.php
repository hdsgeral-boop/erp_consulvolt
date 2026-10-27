<?php

namespace App\Models;

use App\Models\Base\PlanoContaBase;
use App\Support\Cache\ChaveCache;
use App\Support\Cache\InvalidacaoCache;

/**
 * plano_contas — /api/contabilidade/plano-contas.
 * Tipo 'M' = conta de movimento (aceita lançamentos); 'T' = totalizadora (só agrega).
 * A leitura é servida pela cache Redis (ServicoPlanoContas); qualquer escrita invalida-a.
 */
class PlanoConta extends PlanoContaBase
{
    public const TIPO_MOVIMENTO = 'M';

    public const TIPO_TOTALIZADORA = 'T';

    /**
     * Contas que emitem cartas e movimentam a tesouraria (42 depósitos a prazo, 43 depósitos à ordem) —
     * CONTAS_BANCARIAS_PREFIXOS do legado novo (app_v2.js:9052). A caixa (45) não tem banco nem IBAN.
     */
    public const PREFIXOS_BANCARIOS = ['42', '43'];

    /** Dados bancários da conta (bank_* em chart_of_accounts do legado novo); só nas contas 42/43 de movimento. */
    public const CAMPOS_BANCARIOS = ['banco_nome', 'banco_balcao', 'banco_numero_conta', 'banco_iban', 'banco_swift', 'banco_titular'];

    /** Prefixo da classe 3 (dívida a terceiros) elegível para ser marcada como cartão de tesouraria. */
    public const PREFIXO_CARTAO_ELEGIVEL = '3';

    /** Dados do cartão (conta-cartão de tesouraria, classe 3); só quando `cartao_tesouraria` está ligado. */
    public const CAMPOS_CARTAO = ['cartao_numero', 'cartao_banco'];

    protected static function booted(): void
    {
        $invalidar = fn (PlanoConta $conta) => InvalidacaoCache::esquecer(ChaveCache::empresa((int) $conta->empresa_id, 'contabilidade', 'plano_contas'));   // agora e depois do commit (R2)
        static::saved($invalidar);
        static::deleted($invalidar);
        static::restored($invalidar);
    }

    /** O gerador tipou banco_iban como inteiro (backup sem valores); um IBAN é texto (a migração fa_tesouraria alarga a coluna). */
    protected function casts(): array
    {
        return ['banco_iban' => 'string'] + parent::casts();
    }

    public static function eCodigoBancario(?string $codigo): bool
    {
        $codigo = trim((string) $codigo);

        foreach (self::PREFIXOS_BANCARIOS as $p) {
            if (str_starts_with($codigo, $p)) {
                return true;
            }
        }

        return false;
    }

    /** Conta de bancos (42/43) de movimento: a única que guarda dados bancários e movimenta pagamentos/recebimentos. */
    public function eBancaria(): bool
    {
        return $this->eMovimento() && self::eCodigoBancario($this->codigo);
    }

    public function eMovimento(): bool
    {
        return $this->tipo !== self::TIPO_TOTALIZADORA;
    }

    /**
     * Conta-cartão de tesouraria (classe 3 marcada pelo utilizador): dívida ao emissor do cartão, não dinheiro
     * disponível — por isso não é reconhecida por prefixo como 42/43/45, só pela marcação explícita na conta.
     */
    public function eCartaoTesouraria(): bool
    {
        return $this->eMovimento() && (bool) $this->cartao_tesouraria;
    }

    /** Conta de disponibilidades: bancos (42/43), caixa (45) ou cartão de tesouraria (classe 3 marcada). */
    public function eDisponibilidade(): bool
    {
        return $this->eBancaria() || ($this->eMovimento() && str_starts_with((string) $this->codigo, '45')) || $this->eCartaoTesouraria();
    }
}
