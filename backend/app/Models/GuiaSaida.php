<?php

namespace App\Models;

use App\Models\Base\GuiaSaidaBase;

/**
 * guias_saida — /api/logistica/guias-saida e /api/pos/armazem/vendas.
 * Tipos geridos no sistema novo: CONSUMO (interno) e a venda ao balcão do POS de armazém (ADR-050). Enquanto o domínio
 * do tipo não tiver VENDA_BALCAO, a venda ao balcão grava-se como tipo VENDA com tipo_original VENDA_BALCAO; as guias
 * VENDA/BACK_TO_BACK do legado (tipo_original = texto do legado) ficam só para consulta.
 * A estrutura está em GuiaSaidaBase (gerado).
 */
class GuiaSaida extends GuiaSaidaBase
{
    public const VENDA_BALCAO = 'VENDA_BALCAO';

    /** Motivo por que a venda ao balcão ficou por contabilizar na emissão (não é gravado). */
    public ?string $avisoContabilizacao = null;

    /** Venda ao balcão do POS de armazém emitida no sistema novo (não as guias de venda migradas). */
    public function eVendaBalcao(): bool
    {
        return $this->tipo === self::VENDA_BALCAO || ($this->tipo === 'VENDA' && $this->tipo_original === self::VENDA_BALCAO);
    }

    /** Guias geridas pelo sistema novo: contabilizam-se, descontabilizam-se e anulam-se aqui. */
    public function eGerida(): bool
    {
        return $this->tipo === 'CONSUMO' || $this->eVendaBalcao();
    }
}
