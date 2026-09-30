<?php

namespace App\Models;

use App\Models\Base\AmortizacaoAtivoBase;

/**
 * amortizacoes_ativos — /api/ativos/amortizacoes (ServicoAmortizacoes).
 * Uma quota por activo e período ("MM-AAAA"): rascunho (contabilizado = false) ou integrada no diário AM.
 */
class AmortizacaoAtivo extends AmortizacaoAtivoBase
{
    public const TIPO_ORIGEM = 'AMORTIZACOES';

    public const DIARIO = 'AM';

    public const NOME_DIARIO = 'Amortizações de Imobilizados';

    /** N.º do documento do lançamento de um período (paridade com o legado: "AM-MM-AAAA", ui_assets.js:1492). */
    public static function numeroDocumento(string $periodo): string
    {
        return 'AM-'.$periodo;
    }
}
