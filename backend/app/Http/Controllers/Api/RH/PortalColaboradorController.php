<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\PedidoPortalColaborador;
use App\Services\RH\ServicoAusencias;
use App\Services\RH\ServicoDocumentosRH;
use App\Services\RH\ServicoPortalColaborador;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * /api/rh/portal — o próprio colaborador (utilizador ligado a um colaborador: acesso automático, como no legado) e a
 * gestão dos pedidos pelo RH (rh_portal_aprovar) e dos modelos de documentos (rh_portal_modelos).
 */
final class PortalColaboradorController extends Controller
{
    public function __construct(
        private readonly ServicoPortalColaborador $portal,
        private readonly ServicoDocumentosRH $documentos,
    ) {}

    // ───────────── Colaborador ─────────────

    public function resumo(): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->resumo(), 'Portal do colaborador.');
    }

    public function meusPedidos(): JsonResponse
    {
        $c = $this->portal->exigirColaborador();

        return RespostaApi::sucesso(PedidoPortalColaborador::query()->where('colaborador_id', $c->id)->orderByDesc('id')->get(), 'Os meus pedidos.');
    }

    public function recibos(): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->recibos(), 'Os meus recibos de vencimento.');
    }

    public function criarPedido(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tipo' => ['required', Rule::in(ServicoPortalColaborador::TIPOS)],
            'data_inicio' => ['required_if:tipo,FERIAS', 'nullable', 'date_format:Y-m-d'], 'data_fim' => ['required_if:tipo,FERIAS', 'nullable', 'date_format:Y-m-d'],
            'ausencia_tipo' => ['required_if:tipo,AUSENCIA', 'nullable', Rule::in(array_keys(ServicoAusencias::CATALOGO))], 'ausencia_id' => ['nullable', 'integer'],
            'motivo' => ['nullable', 'string', 'max:1000'], 'documento_url' => ['nullable', 'string', 'max:1000'], 'horas' => ['nullable', 'numeric', 'gt:0'],
            'documento' => ['required_if:tipo,DOCUMENTO', 'nullable', 'string', 'max:50'], 'finalidade' => ['nullable', 'string', 'max:500'],
            'destinatario' => ['nullable', 'string', 'max:255'], 'observacoes' => ['nullable', 'string', 'max:2000'],
            'dependentes' => ['present_if:tipo,AGREGADO', 'nullable', 'array', 'max:30'], 'dependentes.*.nome' => ['required', 'string', 'max:255'],
            'dependentes.*.parentesco' => ['nullable', 'string', 'max:30'], 'dependentes.*.data_nascimento' => ['nullable', 'date_format:Y-m-d'],
            'dependentes.*.sexo' => ['nullable', 'in:M,F'], 'dependentes.*.dependente_fiscal' => ['nullable', 'boolean'],
        ]);
        $dados = $d['tipo'] === 'AUSENCIA' ? ['tipo' => $d['ausencia_tipo']] + $d : $d;   // nos dados, «tipo» é o tipo de ausência

        return RespostaApi::criado($this->portal->criar($d['tipo'], $dados), 'Pedido enviado.');
    }

    public function cancelar(int $pedido): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->cancelar(PedidoPortalColaborador::query()->findOrFail($pedido)), 'Pedido cancelado.');
    }

    // ───────────── Aprovações ─────────────

    public function paraMim(): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->pendentesParaMim(), 'Pedidos a aguardar a sua decisão.');
    }

    public function pedidos(Request $r): JsonResponse
    {
        $this->exigir('rh_portal_gestao_view', 'rh_portal_aprovar');
        $f = $r->validate(['estado' => ['nullable', 'string'], 'tipo' => ['nullable', Rule::in(ServicoPortalColaborador::TIPOS)], 'colaborador_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso(PedidoPortalColaborador::query()->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))->when($f['colaborador_id'] ?? null, fn ($q, $c) => $q->where('colaborador_id', $c))
            ->orderByDesc('id')->get(), 'Pedidos do portal.');
    }

    public function decidir(Request $r, int $pedido): JsonResponse
    {
        $d = $r->validate(['decisao' => ['required', 'in:APROVADO,RECUSADO'], 'nota' => ['nullable', 'string', 'max:1000'], 'remunerada' => ['nullable', 'in:SIM,NAO']]);
        $p = $this->portal->decidir(PedidoPortalColaborador::query()->findOrFail($pedido), $d['decisao'], $d['nota'] ?? null, $d['remunerada'] ?? null);

        return RespostaApi::sucesso($p, $p->estado === 'APROVADO' ? 'Pedido aprovado.' : ($p->estado === 'RECUSADO' ? 'Pedido recusado.' : 'Aprovado: segue para o RH.'));
    }

    public function propostaDocumento(int $pedido): JsonResponse
    {
        $this->exigir('rh_portal_aprovar');
        $p = PedidoPortalColaborador::query()->where('tipo', 'DOCUMENTO')->findOrFail($pedido);

        return RespostaApi::sucesso($this->documentos->proposta($p), 'Texto proposto do documento.');
    }

    public function emitir(Request $r, int $pedido): JsonResponse
    {
        $d = $r->validate(['texto' => ['nullable', 'string', 'max:20000'], 'titulo' => ['nullable', 'string', 'max:255'], 'assinante' => ['nullable', 'string', 'max:255'],
            'cargo_assinante' => ['nullable', 'string', 'max:255'], 'local' => ['nullable', 'string', 'max:255']]);
        $res = $this->portal->emitir(PedidoPortalColaborador::query()->findOrFail($pedido), $d);

        return RespostaApi::sucesso($res, $res['emitido'] ? "Documento {$res['pedido']->documento['numero']} emitido." : 'Reveja o texto do documento antes de o emitir.');
    }

    public function ligar(Request $r): JsonResponse
    {
        $this->exigir('rh_portal_aprovar');
        $d = $r->validate(['utilizador_id' => ['required', 'integer'], 'colaborador_id' => ['nullable', 'integer']]);
        $this->portal->ligar((int) $d['utilizador_id'], $d['colaborador_id'] ?? null);

        return RespostaApi::sucesso(null, $d['colaborador_id'] ?? null ? 'Utilizador ligado ao colaborador.' : 'Ligação retirada.');
    }

    // ───────────── Modelos de documentos ─────────────

    public function modelos(): JsonResponse
    {
        return RespostaApi::sucesso(['modelos' => $this->documentos->modelos(! Gate::any(['rh_portal_modelos'])),
            'variaveis' => ServicoDocumentosRH::VARIAVEIS], 'Modelos de documentos.');
    }

    public function gravarModelo(Request $r): JsonResponse
    {
        $this->exigir('rh_portal_modelos');
        $d = $r->validate(['codigo' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'], 'nome' => ['required', 'string', 'max:255'], 'titulo' => ['required', 'string', 'max:255'],
            'texto' => ['required', 'string', 'min:30', 'max:20000'], 'ativo' => ['nullable', 'boolean'], 'auto_emitir' => ['nullable', 'boolean'],
            'assinante' => ['nullable', 'string', 'max:255'], 'cargo_assinante' => ['nullable', 'string', 'max:255'], 'local' => ['nullable', 'string', 'max:255']]);

        return RespostaApi::sucesso($this->documentos->gravarModelo($d), 'Modelo gravado.');
    }

    public function reporModelo(string $codigo): JsonResponse
    {
        $this->exigir('rh_portal_modelos');
        $this->documentos->reporModelo($codigo);

        return RespostaApi::sucesso(null, 'Modelo reposto.');
    }
}
