<?php

namespace App\Http\Requests\POS\Impressao;

use App\Http\Requests\POS\PedidoPOS;

/** Leitura da configuração de impressão do POS (qualquer utilizador com acesso ao POS: o talão imprime-se em todos os postos). */
final class ConsultarConfigImpressaoRequest extends PedidoPOS
{
    public const LEITURA = ['pos_view', 'pos_venda', 'pos_print_config', 'pos_terminais_gerir', 'pos_fecho', 'pos_relatorios_view', 'lav_ordens', 'lav_receber',
        'hotel_checkout', 'hotel_estadias', 'pos_armazem_vender', 'pos_armazem_expedir', 'pos_armazem_picking'];

    protected function permissoes(): array
    {
        return self::LEITURA;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['terminal_pos_id' => ['nullable', 'integer', 'min:1']];
    }
}
