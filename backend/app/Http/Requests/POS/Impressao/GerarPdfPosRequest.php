<?php

namespace App\Http\Requests\POS\Impressao;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\POS\ServicoPdfA4POS;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Pedido de PDF A4 de um impresso do POS (F7). A permissão depende do tipo de documento: o PDF só se gera para quem já
 * consegue ver/imprimir esse documento no POS.
 */
final class GerarPdfPosRequest extends PedidoPOS
{
    /** Tipo de documento → permissões (qualquer uma serve). */
    public const TIPOS = [
        'sessao' => ['pos_view', 'pos_fecho', 'pos_relatorios_view', 'pos_relatorios', 'pos_prestar'],
        'relatorio' => ['pos_relatorios_view', 'pos_relatorios', 'lav_ordens', 'lav_tabelas'],
        'talao' => ['pos_venda', 'pos_view', 'lav_ordens', 'lav_receber', 'hotel_checkout', 'hotel_estadias'],
        'recibo' => ['pos_venda', 'lav_receber', 'hotel_checkout'],
        'guia_saida' => ['pos_armazem_vender', 'pos_armazem_expedir', 'pos_armazem_picking', 'armazem_guias_emitir'],
    ];

    public function authorize(): bool
    {
        $permissoes = self::TIPOS[(string) $this->input('tipo')] ?? array_merge(...array_values(self::TIPOS));
        if (! Gate::any($permissoes)) {
            throw new AccessDeniedHttpException;
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', 'in:'.implode(',', array_keys(self::TIPOS))],
            'titulo' => ['required', 'string', 'max:150'],
            'subtitulo' => ['nullable', 'string', 'max:200'],
            'html' => ['required', 'string', 'max:'.ServicoPdfA4POS::TAMANHO_MAXIMO_HTML],
            'css' => ['nullable', 'string', 'max:20000'],
            'orientacao' => ['nullable', 'string', 'in:retrato,paisagem'],
            'nome_ficheiro' => ['nullable', 'string', 'max:150'],
        ];
    }
}
