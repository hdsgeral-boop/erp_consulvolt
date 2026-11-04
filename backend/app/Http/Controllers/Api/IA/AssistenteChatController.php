<?php

namespace App\Http\Controllers\Api\IA;

use App\Http\Controllers\Controller;
use App\Services\IA\Agente\ServicoAgenteIA;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/ia/* — chat do assistente IA (empresa activa). Exige a permissão `ia_chat_view` e um cliente com a IA atribuída
 * pela plataforma; cada ferramenta exige ainda a permissão do ecrã correspondente e as escritas só acontecem quando o
 * utilizador confirma (o ecrã chama então o endpoint normal do ERP). Respostas em JSON (sem streaming).
 */
final class AssistenteChatController extends Controller
{
    public function __construct(private readonly ServicoAgenteIA $agente, private readonly ContextoEmpresa $contexto) {}

    public function estado(Request $r): JsonResponse
    {
        return RespostaApi::sucesso($this->agente->estado($r->user(), $this->contexto->obrigatorio()), 'Estado do assistente.');
    }

    public function regras(Request $r): JsonResponse
    {
        $this->exigir('ia_chat_view');

        return RespostaApi::sucesso($this->agente->regras($r->user()), 'Regras obtidas.');
    }

    public function guardarRegras(Request $r, ServicoAuditoria $auditoria): JsonResponse
    {
        $this->exigir('ia_chat_regras');
        $d = $r->validate(['regras' => ['present', 'nullable', 'string', 'max:10000']]);

        $regras = $this->agente->guardarRegras($r->user(), (string) ($d['regras'] ?? ''));
        $auditoria->registar('Assistente IA', 'Regras da IA do cliente', 'Regras do assistente IA do cliente actualizadas ('.mb_strlen($regras['regras']).' caracteres).');

        return RespostaApi::sucesso($regras, 'Regras guardadas.');
    }

    public function conversas(Request $r): JsonResponse
    {
        $this->exigir('ia_chat_view');

        return RespostaApi::sucesso($this->agente->conversas($r->user(), $this->contexto->obrigatorio()), 'Conversas obtidas.');
    }

    public function mensagens(Request $r, int $conversa): JsonResponse
    {
        $this->exigir('ia_chat_view');

        return RespostaApi::sucesso($this->agente->mensagens($r->user(), $this->contexto->obrigatorio(), $conversa), 'Mensagens obtidas.');
    }

    /** POST /ia/mensagens — multipart: texto, conversa_id (opcional), anexos[] (PDF, imagem, Excel/CSV, texto; até 5 × 10 MB). */
    public function enviar(Request $r): JsonResponse
    {
        $this->exigir('ia_chat_view');
        $d = $r->validate([
            'texto' => ['required', 'string', 'max:20000'],
            'conversa_id' => ['nullable', 'integer'],
            'modulo' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_-]+$/i'],
            'ecra' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_-]+$/i'],
            'anexos' => ['nullable', 'array', 'max:5'],
            'anexos.*' => ['file', 'max:10240', 'extensions:pdf,png,jpg,jpeg,gif,webp,xlsx,xls,csv,txt,md,json,xml'],
        ], ['anexos.*.extensions' => 'Use PDF, imagem, Excel, CSV ou texto.', 'anexos.*.max' => 'Cada anexo pode ter até 10 MB.']);
        // o ciclo do agente pode fazer vários pedidos ao fornecedor (só por pedido web: em CLI/testes o limite ficaria para o
        // resto do processo e mataria uma suite longa a meio)
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(300);
        }

        return RespostaApi::sucesso(
            $this->agente->enviar($r->user(), $this->contexto->obrigatorio(), isset($d['conversa_id']) ? (int) $d['conversa_id'] : null, $d['texto'], $r->file('anexos', []), $d['modulo'] ?? null, $d['ecra'] ?? null),
            'Resposta do assistente.'
        );
    }

    public function renomear(Request $r, int $conversa): JsonResponse
    {
        $this->exigir('ia_chat_view');
        $d = $r->validate(['titulo' => ['required', 'string', 'max:200']]);
        $this->agente->renomear($r->user(), $this->contexto->obrigatorio(), $conversa, $d['titulo']);

        return RespostaApi::sucesso(null, 'Conversa renomeada.');
    }

    public function eliminar(Request $r, int $conversa): JsonResponse
    {
        $this->exigir('ia_chat_view');
        $this->agente->eliminar($r->user(), $this->contexto->obrigatorio(), $conversa);

        return RespostaApi::sucesso(null, 'Conversa eliminada.');
    }

    /** POST /ia/accoes/{id}/decisao — {estado: EXECUTADA|REJEITADA|FALHOU, resultado?: {mensagem, ...}} depois de o ecrã executar (ou não). */
    public function decidir(Request $r, int $accao): JsonResponse
    {
        $this->exigir('ia_chat_view');
        $d = $r->validate(['estado' => ['required', 'in:EXECUTADA,REJEITADA,FALHOU'], 'resultado' => ['nullable', 'array']]);

        return RespostaApi::sucesso($this->agente->decidirAccao($r->user(), $this->contexto->obrigatorio(), $accao, $d['estado'], $d['resultado'] ?? null), 'Decisão registada.');
    }
}
