<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoManutencaoDados;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/sistema/manutencao — Manutenção de dados com aprovação dupla (config_manutencao). A aprovação exige, além
 * da consulta do ecrã, um administrador diferente de quem pediu (regra no serviço).
 *
 * Reservado a Super Administradores: a permissão `config_manutencao_view` é partilhada com outros ecrãs de empresa
 * só de leitura (Validações de dados, sincronizar notas às demonstrações), por isso não chega para autorizar este
 * fluxo destrutivo — daqui exigirSuperAdministrador() a cada acção, à parte de self::VER.
 */
final class ManutencaoDadosController extends Controller
{
    private const VER = 'config_manutencao_view';

    public function __construct(private readonly ServicoManutencaoDados $manutencao) {}

    /** GET .../acoes — acções disponíveis e o tratamento das acções do legado não portadas. */
    public function acoes(Request $r): JsonResponse
    {
        $this->exigir(self::VER);
        $this->exigirSuperAdministrador($r);

        return RespostaApi::sucesso($this->manutencao->catalogo(), 'Acções de manutenção de dados.');
    }

    /** POST .../impacto — pré-visualização do impacto de uma acção na empresa activa. */
    public function impacto(Request $r): JsonResponse
    {
        $this->exigir(self::VER);
        $this->exigirSuperAdministrador($r);
        $d = $r->validate(['acao' => ['required', 'string', 'max:60'], 'parametros' => ['nullable', 'array']]);

        return RespostaApi::sucesso($this->manutencao->impacto($d['acao'], $r->input('parametros', [])), 'Impacto calculado.');
    }

    public function index(Request $r): JsonResponse
    {
        $this->exigir(self::VER);
        $this->exigirSuperAdministrador($r);
        $d = $r->validate(['estado' => ['nullable', Rule::in(array_merge(['ACTIVOS', 'TODOS'], ServicoManutencaoDados::ESTADOS))]]);

        return RespostaApi::sucesso($this->manutencao->listar($d['estado'] ?? 'ACTIVOS'), 'Pedidos de manutenção de dados.');
    }

    public function show(Request $r, int $pedido): JsonResponse
    {
        $this->exigir(self::VER);
        $this->exigirSuperAdministrador($r);

        return RespostaApi::sucesso($this->manutencao->apresentar($this->manutencao->obter($pedido), true), 'Pedido de manutenção de dados.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir(self::VER);
        $this->exigirSuperAdministrador($r);
        $d = $r->validate(['acao' => ['required', 'string', 'max:60'], 'parametros' => ['nullable', 'array'], 'justificacao' => ['required', 'string', 'max:2000'],
            'ciente' => ['required', 'boolean']]);
        $p = $this->manutencao->pedir(['parametros' => $r->input('parametros', [])] + $d, $r->user());

        return RespostaApi::criado($this->manutencao->apresentar($p), "Pedido #{$p->id} registado. Tem de ser aprovado por outro administrador antes de poder ser executado.");
    }

    public function aprovar(Request $r, int $pedido): JsonResponse
    {
        $this->exigir(self::VER);
        $this->exigirSuperAdministrador($r);
        $d = $r->validate(['palavra_passe' => ['required', 'string', 'max:200'], 'nota' => ['nullable', 'string', 'max:255']]);
        $p = $this->manutencao->aprovar($pedido, $d['palavra_passe'], $d['nota'] ?? null, $r->user());

        return RespostaApi::sucesso($this->manutencao->apresentar($p), "Pedido #{$p->id} aprovado. Pode ser executado por quem pediu ou por quem aprovou nas próximas "
            .ServicoManutencaoDados::VALIDADE_APROVACAO_HORAS.' horas.');
    }

    public function rejeitar(Request $r, int $pedido): JsonResponse
    {
        $this->exigir(self::VER);
        $this->exigirSuperAdministrador($r);
        $d = $r->validate(['palavra_passe' => ['required', 'string', 'max:200'], 'motivo' => ['required', 'string', 'max:2000']]);
        $p = $this->manutencao->rejeitar($pedido, $d['palavra_passe'], $d['motivo'], $r->user());

        return RespostaApi::sucesso($this->manutencao->apresentar($p), "Pedido #{$p->id} rejeitado.");
    }

    public function executar(Request $r, int $pedido): JsonResponse
    {
        $this->exigir(self::VER);
        $this->exigirSuperAdministrador($r);
        $d = $r->validate(['confirmacao' => ['required', 'string', 'max:30'], 'copia_seguranca_confirmada' => ['required', 'boolean']]);
        $p = $this->manutencao->executar($pedido, $d['confirmacao'], (bool) $d['copia_seguranca_confirmada'], $r->user());

        return RespostaApi::sucesso($this->manutencao->apresentar($p), "Pedido #{$p->id} executado. {$p->resultado}");
    }

    public function cancelar(Request $r, int $pedido): JsonResponse
    {
        $this->exigir(self::VER);
        $this->exigirSuperAdministrador($r);
        $d = $r->validate(['motivo' => ['required', 'string', 'max:2000']]);
        $p = $this->manutencao->cancelar($pedido, $d['motivo'], $r->user());

        return RespostaApi::sucesso($this->manutencao->apresentar($p), "Pedido #{$p->id} cancelado.");
    }

    private function exigirSuperAdministrador(Request $r): void
    {
        if (! $r->user()?->eSuperAdministrador()) {
            throw new ErroNegocio(
                'A manutenção de dados é reservada a Super Administradores. Para um pedido deste tipo, abra um ticket de suporte a indicar o que precisa.',
                'RESERVADO_SUPER_ADMINISTRADOR',
                403,
            );
        }
    }
}
