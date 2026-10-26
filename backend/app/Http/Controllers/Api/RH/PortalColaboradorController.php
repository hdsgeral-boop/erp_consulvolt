<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\PedidoPortalColaborador;
use App\Services\RH\ServicoAusencias;
use App\Services\RH\ServicoAvaliacao360;
use App\Services\RH\ServicoDocumentosRH;
use App\Services\RH\ServicoEmprestimos;
use App\Services\RH\ServicoPortalColaborador;
use App\Services\RH\ServicoPortalProjetos;
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
    /** Estados de um pedido do portal (filtro da lista do RH). */
    public const ESTADOS_PEDIDO = ['PENDENTE_CHEFIA', 'PENDENTE_RH', 'APROVADO', 'EMITIDO', 'RECUSADO', 'CANCELADO'];

    /** Estados que ainda esperam uma decisão (chefia ou RH). */
    public const ESTADOS_PENDENTES = ['PENDENTE_CHEFIA', 'PENDENTE_RH'];

    /** Valor do filtro «Por decidir»: os dois estados pendentes. */
    public const POR_DECIDIR = 'POR_DECIDIR';

    public function __construct(
        private readonly ServicoPortalColaborador $portal,
        private readonly ServicoDocumentosRH $documentos,
        private readonly ServicoPortalProjetos $projetos,
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

    /** GET /ausencias — as minhas faltas (por omissão as por justificar; ?estado=TODOS para o histórico). */
    public function ausencias(Request $r): JsonResponse
    {
        $f = $r->validate(['estado' => ['nullable', Rule::in(['TODOS', 'POR_JUSTIFICAR', 'PENDENTE_CHEFIA', 'PENDENTE_RH', 'APROVADO', 'RECUSADO', 'CANCELADO'])]]);

        return RespostaApi::sucesso($this->portal->minhasAusencias($f['estado'] ?? null), 'As minhas ausências.');
    }

    public function dependentes(): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->meusDependentes(), 'Os meus dependentes.');
    }

    /** GET /avaliacoes — a minha avaliação (concluídas, acompanhamento, ciclo aberto, 360º e ascendente). */
    public function avaliacoes(ServicoAvaliacao360 $a360): JsonResponse
    {
        return RespostaApi::sucesso($a360->minhaAvaliacao(), 'A minha avaliação.');
    }

    /** GET /utilizadores — utilizadores da empresa activa, para os ligar a colaboradores. */
    public function utilizadores(): JsonResponse
    {
        $this->exigir('rh_portal_gestao_view', 'rh_portal_aprovar');

        return RespostaApi::sucesso($this->portal->utilizadoresEmpresa(), 'Utilizadores da empresa.');
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
            // Versão Actual (portal_ui.js:398): pedido de empréstimo — os limites (máximo de prestações, tecto) estão em ServicoEmprestimos
            'capital' => ['required_if:tipo,EMPRESTIMO', 'nullable', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'prestacoes' => ['required_if:tipo,EMPRESTIMO', 'nullable', 'integer', 'between:1,120'],
            'inicio_desconto' => ['required_if:tipo,EMPRESTIMO', 'nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        $dados = $d['tipo'] === 'AUSENCIA' ? ['tipo' => $d['ausencia_tipo']] + $d : $d;   // nos dados, «tipo» é o tipo de ausência

        return RespostaApi::criado($this->portal->criar($d['tipo'], $dados), 'Pedido enviado.');
    }

    // ───────────── Versão Actual (Fase B): escala, contrato e empréstimos do próprio ─────────────
    // Só o próprio: exigirColaborador no serviço recusa (403) quem não está ligado a um colaborador da empresa.

    public function escala(Request $r): JsonResponse
    {
        $this->portal->exigirColaborador();
        $f = $r->validate(['mes' => ['nullable', 'string', 'max:7']]);

        return RespostaApi::sucesso($this->portal->minhaEscala($f['mes'] ?? now()->format('Y-m')), 'A minha escala.');
    }

    public function contrato(): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->meuContrato(), 'O meu contrato.');
    }

    public function emprestimos(): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->meusEmprestimos(), 'Os meus empréstimos.');
    }

    /** Simulação do plano antes de pedir (o mesmo cálculo que o pedido usa). */
    public function simularEmprestimo(Request $r, ServicoEmprestimos $emprestimos): JsonResponse
    {
        $this->portal->exigirColaborador();
        $d = $r->validate(['capital' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'], 'prestacoes' => ['required', 'integer', 'between:1,120'],
            'inicio_desconto' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return RespostaApi::sucesso(ServicoEmprestimos::plano($d['capital'], (int) $d['prestacoes'], $emprestimos->definicoes()['taxa_juro_padrao'], $d['inicio_desconto']),
            'Simulação do plano.');
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

    /** GET /compras/aprovacoes — pedidos de compra à minha espera (a decisão usa a rota de Compras já existente). */
    public function comprasParaMim(): JsonResponse
    {
        return RespostaApi::sucesso($this->portal->comprasParaMim(), 'Pedidos de compra a aguardar a sua decisão.');
    }

    // ───────────── Projectos (separador do portal, 2026-10-24): registar horas e folha de medição ─────────────

    /** GET /projetos/tarefas — as minhas tarefas (para escolher ao registar horas ou medir). */
    public function tarefasProjeto(): JsonResponse
    {
        return RespostaApi::sucesso($this->projetos->minhasTarefas(), 'As minhas tarefas de projecto.');
    }

    /** POST /projetos/timesheets — registar horas numa das minhas tarefas. */
    public function registarHorasProjeto(Request $r): JsonResponse
    {
        $d = $r->validate(['tarefa_projeto_id' => ['required', 'integer'], 'data' => ['required', 'date_format:Y-m-d'],
            'horas' => ['required', 'numeric', 'gt:0', 'max:24']]);

        return RespostaApi::criado($this->projetos->registarHoras($d), 'Horas registadas.');
    }

    /** GET /projetos/timesheets — as minhas horas lançadas no mês (?mes=AAAA-MM). */
    public function timesheetsProjeto(Request $r): JsonResponse
    {
        $f = $r->validate(['mes' => ['nullable', 'string', 'max:7']]);

        return RespostaApi::sucesso($this->projetos->meusTimesheets($f['mes'] ?? null), 'As minhas horas do mês.');
    }

    /** POST /projetos/tarefas/{tarefa}/medicoes — registar uma medição numa das minhas tarefas com meta. */
    public function registarMedicaoProjeto(Request $r, int $tarefa): JsonResponse
    {
        $d = $r->validate(['quantidade' => ['required', 'numeric', 'between:-1000000000,1000000000'],
            'data' => ['nullable', 'date_format:Y-m-d'], 'nota' => ['nullable', 'string', 'max:500']]);
        $res = $this->projetos->registarMedicao($tarefa, $d);

        return RespostaApi::criado(['tarefa_id' => $res['tarefa']->id, 'realizado' => $res['realizado'], 'pct' => $res['pct']],
            "Registado. Execução: {$res['pct']}%.");
    }

    public function pedidos(Request $r): JsonResponse
    {
        $this->exigir('rh_portal_gestao_view', 'rh_portal_aprovar');
        $f = $r->validate(['estado' => ['nullable', 'string', Rule::in([...self::ESTADOS_PEDIDO, self::POR_DECIDIR])],
            'tipo' => ['nullable', Rule::in(ServicoPortalColaborador::TIPOS)], 'colaborador_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso(PedidoPortalColaborador::query()
            // «Por decidir» (antigo: portal_ui.js:855, estado PENDENTES) junta os dois estados pendentes
            ->when($f['estado'] ?? null, fn ($q, $e) => $e === self::POR_DECIDIR ? $q->whereIn('estado', self::ESTADOS_PENDENTES) : $q->where('estado', $e))
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
