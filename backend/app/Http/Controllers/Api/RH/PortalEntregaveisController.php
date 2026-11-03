<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\ImpedimentoEntregavel;
use App\Models\PedidoCompra;
use App\Models\PedidoPortalColaborador;
use App\Services\Compras\ServicoDeliberacaoCompras;
use App\Services\Estrutura\ServicoEntregaveis;
use App\Services\RH\ServicoPortalColaborador;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/rh/portal — Fase J (ADR-078): os meus entregáveis (registar entregas, reenviar, impedimentos), as aprovações do responsável
 * de unidade (entregas por validar, impedimentos, atrasos), a equipa directa e as suas férias, o painel «Analisar» de um pedido e
 * a decisão de pedidos de compra a partir do portal. Como o resto do portal: exige colaborador ligado, sem permissão adicional —
 * as regras de quem pode o quê estão nos serviços.
 */
final class PortalEntregaveisController extends Controller
{
    public function __construct(
        private readonly ServicoPortalColaborador $portal,
        private readonly ServicoEntregaveis $entregaveis,
        private readonly ServicoDeliberacaoCompras $compras,
    ) {}

    // ───────────── Os meus entregáveis ─────────────

    public function entregaveis(): JsonResponse
    {
        $c = $this->portal->exigirColaborador();

        return RespostaApi::sucesso(['entregaveis' => $this->entregaveis->meusEntregaveis($c->id), 'contadores' => $this->entregaveis->contadoresPortal($c->id)], 'Os meus entregáveis.');
    }

    public function registarEntrega(Request $r, int $entregavel): JsonResponse
    {
        $c = $this->portal->exigirColaborador();
        $d = $r->validate(['quantidade' => ['required', 'numeric', 'gt:0'], 'data' => ['nullable', 'date_format:Y-m-d'], 'observacoes' => ['nullable', 'string', 'max:2000'],
            'url_evidencia' => ['nullable', 'string', 'max:500'], 'colaborador_id' => ['nullable', 'integer']]);

        return RespostaApi::criado($this->entregaveis->registarEntregaPortal(EntregavelTarefaUnidade::query()->findOrFail($entregavel), $d, $c->id), 'Entrega registada: fica por validar.');
    }

    public function reenviar(int $execucao): JsonResponse
    {
        $this->portal->exigirColaborador();

        return RespostaApi::sucesso($this->entregaveis->reenviar(ExecucaoEntregavel::query()->findOrFail($execucao)), 'Entrega reenviada para validação.');
    }

    public function registarImpedimento(Request $r, int $entregavel): JsonResponse
    {
        $c = $this->portal->exigirColaborador();
        $d = $r->validate(['periodo_de' => ['required', 'date_format:Y-m-d'], 'prazo' => ['nullable', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'max:2000'], 'url_documento' => ['nullable', 'string', 'max:500']]);

        return RespostaApi::criado($this->entregaveis->registarImpedimento(EntregavelTarefaUnidade::query()->findOrFail($entregavel), $d, $c->id), 'Impedimento registado: aguarda o responsável.');
    }

    // ───────────── Aprovações do responsável de unidade ─────────────

    public function entregasParaValidar(): JsonResponse
    {
        $c = $this->portal->exigirColaborador();

        return RespostaApi::sucesso($this->entregaveis->entregasParaValidar($c->id), 'Entregas à espera da sua validação.');
    }

    public function validar(Request $r, int $execucao): JsonResponse
    {
        $this->portal->exigirColaborador();
        $d = $r->validate(['decisao' => ['required', Rule::in(ServicoEntregaveis::DECISOES)], 'motivo' => ['nullable', 'string', 'max:2000']]);
        $x = $this->entregaveis->validar(ExecucaoEntregavel::query()->findOrFail($execucao), $d['decisao'], $d['motivo'] ?? null);

        return RespostaApi::sucesso($x, ['VALIDADA' => 'Entrega validada.', 'DEVOLVIDA' => 'Entrega devolvida para correcção.', 'RECUSADA' => 'Entrega recusada.'][$d['decisao']]);
    }

    public function impedimentosParaDecidir(): JsonResponse
    {
        $c = $this->portal->exigirColaborador();

        return RespostaApi::sucesso($this->entregaveis->impedimentosParaDecidir($c->id), 'Impedimentos à espera da sua decisão.');
    }

    public function decidirImpedimento(Request $r, int $impedimento): JsonResponse
    {
        $this->portal->exigirColaborador();
        $d = $r->validate(['estado' => ['required', Rule::in(['ACEITE', 'RECUSADO'])], 'nota' => ['nullable', 'string', 'max:2000']]);
        $i = $this->entregaveis->decidirImpedimento(ImpedimentoEntregavel::query()->findOrFail($impedimento), $d['estado'], $d['nota'] ?? null);

        return RespostaApi::sucesso($i, $d['estado'] === 'ACEITE' ? 'Impedimento aceite: o período fica justificado.' : 'Impedimento recusado.');
    }

    public function atrasos(): JsonResponse
    {
        $c = $this->portal->exigirColaborador();

        return RespostaApi::sucesso($this->entregaveis->atrasosDasMinhasUnidades($c->id), 'Entregáveis em atraso nas suas unidades.');
    }

    // ───────────── Equipa, análise do pedido e compras ─────────────

    public function equipa(): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->equipa(), 'A minha equipa directa.');
    }

    public function feriasEquipa(Request $r): JsonResponse
    {
        $f = $r->validate(['ano' => ['nullable', 'integer', 'between:2000,2100']]);

        return RespostaApi::sucesso($this->portal->feriasEquipa(isset($f['ano']) ? (int) $f['ano'] : null), 'Férias da minha equipa.');
    }

    public function analisePedido(int $pedido): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->analisePedido(PedidoPortalColaborador::query()->findOrFail($pedido)), 'Análise do pedido.');
    }

    /** POST /compras/{pedido}/decidir — as mesmas regras de /compras/pedidos/{id}/decidir (ServicoDeliberacaoCompras). */
    public function decidirCompra(Request $r, int $pedido): JsonResponse
    {
        $p = PedidoCompra::query()->findOrFail($pedido);
        $this->compras->exigirPodeDecidir($p);   // antes da validação (OWASP A01)
        $d = $r->validate(['decisao' => ['required', Rule::in(['APROVAR', 'RECUSAR'])], 'nota' => ['nullable', 'string', 'max:1000']]);
        $p = $this->compras->decidir($p, $d['decisao'] === 'APROVAR', $d['nota'] ?? null);

        return RespostaApi::sucesso(['id' => (int) $p->id, 'numero_pedido' => $p->numero_pedido, 'estado' => $p->estado, 'deliberacao' => $p->deliberacao],
            $p->estado === 'APROVADO' ? 'Pedido aprovado.' : ($p->estado === 'REJEITADO' ? 'Pedido recusado.' : 'Etapa aprovada.'));
    }
}
