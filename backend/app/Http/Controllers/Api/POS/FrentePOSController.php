<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Pos\AnularMovimentoCaixaRequest;
use App\Http\Requests\POS\Pos\MovimentoCaixaRequest;
use App\Http\Resources\POS\VendaPOSResource;
use App\Models\MovimentoCaixaPOS;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\POS\ServicoConsultaVendasPOS;
use App\Services\POS\ServicoFrentePOS;
use App\Services\POS\ServicoMovimentosCaixaPOS;
use App\Services\POS\ServicoTalaoPOS;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    // ───────────── F1b: vendas da sessão, suprimentos e sangrias ─────────────

    public function vendasDaSessao(Request $r, int $sessao, ServicoConsultaVendasPOS $consulta): JsonResponse
    {
        $this->exigir('pos_view', 'pos_venda', 'pos_relatorios_view');
        $q = $r->validate(['q' => ['nullable', 'string', 'max:60']])['q'] ?? null;

        return RespostaApi::sucesso($consulta->listar(SessaoPOS::query()->findOrFail($sessao), $q), 'Vendas da sessão.');
    }

    public function vendaDaSessao(int $sessao, int $venda, ServicoConsultaVendasPOS $consulta): JsonResponse
    {
        $this->exigir('pos_view', 'pos_venda', 'pos_relatorios_view');

        return RespostaApi::sucesso(new VendaPOSResource($consulta->detalhe(SessaoPOS::query()->findOrFail($sessao), $venda)), 'Venda da sessão.');
    }

    public function movimentos(int $sessao, ServicoMovimentosCaixaPOS $mov): JsonResponse
    {
        $this->exigir('pos_view', 'pos_venda', 'pos_relatorios_view', 'pos_movimento_caixa');

        return RespostaApi::sucesso($mov->listar(SessaoPOS::query()->findOrFail($sessao)), 'Suprimentos e sangrias da sessão.');
    }

    public function registarMovimento(MovimentoCaixaRequest $r, int $sessao, ServicoMovimentosCaixaPOS $mov): JsonResponse
    {
        $d = $r->validated();
        $m = $mov->registar(SessaoPOS::query()->findOrFail($sessao), $d['tipo'], $d['valor'], $d['motivo']);

        return RespostaApi::criado($m, $m->tipo === 'SANGRIA' ? 'Sangria registada.' : 'Suprimento registado.');
    }

    public function anularMovimento(AnularMovimentoCaixaRequest $r, int $sessao, int $movimento, ServicoMovimentosCaixaPOS $mov): JsonResponse
    {
        $d = $r->validated();
        $s = SessaoPOS::query()->findOrFail($sessao);

        return RespostaApi::sucesso($mov->anular($s, MovimentoCaixaPOS::query()->where('sessao_pos_id', $s->id)->findOrFail($movimento), $d['motivo']), 'Movimento anulado.');
    }
}
