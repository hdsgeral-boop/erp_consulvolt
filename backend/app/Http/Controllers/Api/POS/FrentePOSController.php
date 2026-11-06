<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\POS\ServicoFrentePOS;
use App\Services\POS\ServicoTalaoPOS;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * /api/pos/frente — dados de apoio da frente de caixa e dos Terminais POS (paridade com o legado, verificacao_visual
 * 3.1.5, 3.1.9, 3.3.2 e 3.3.5): stock no armazém do terminal, sessões abertas desde dias anteriores e série de cada
 * terminal. Só leitura; a empresa vem do contexto (escopo global dos modelos) e cada pedido exige permissão do POS.
 */
final class FrentePOSController extends Controller
{
    public function __construct(private readonly ServicoFrentePOS $frente, private readonly ServicoTalaoPOS $talao) {}

    public function stock(int $terminal): JsonResponse
    {
        $this->exigir('pos_view', 'pos_venda');

        return RespostaApi::sucesso($this->frente->stock(TerminalPOS::query()->findOrFail($terminal)), 'Stock da frente de caixa.');
    }

    public function sessoesAntigas(): JsonResponse
    {
        $this->exigir('pos_view', 'pos_venda', 'pos_terminais_view', 'pos_terminais_gerir');
        $antigas = $this->frente->sessoesAntigas();

        return RespostaApi::sucesso(['sessoes' => $antigas, 'aviso' => ServicoFrentePOS::textoAviso($antigas)], 'Sessões abertas desde dias anteriores.');
    }

    public function series(): JsonResponse
    {
        $this->exigir('pos_view', 'pos_venda', 'pos_terminais_view', 'pos_terminais_gerir');

        return RespostaApi::sucesso($this->frente->series(), 'Séries dos terminais.');
    }

    /** Elementos fiscais do talão de uma venda do POS (QR da AGT, série, cliente, coordenadas bancárias): ver ServicoTalaoPOS. */
    public function talao(int $venda): JsonResponse
    {
        $this->exigir('pos_view', 'pos_venda', 'pos_relatorios_view', 'hotel_checkout', 'lav_ordens', 'lav_receber');
        $v = Venda::query()->where(fn ($q) => $q->whereNotNull('sessao_pos_id')->orWhereNotNull('terminal_pos_id')->orWhereNotNull('pedido_lavandaria_id'))->findOrFail($venda);

        return RespostaApi::sucesso($this->talao->dados($v), 'Dados do talão.');
    }
}
