<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Mail\NovoUtilizadorMail;
use App\Mail\PalavraPasseRedefinidaMail;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoUtilizadores;
use App\Support\Api\RespostaApi;
use App\Support\Email\EnviarEmail;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/sistema/utilizadores — config_utilizadores (consulta) e config_util_gerir (criar, editar, eliminar). */
final class UtilizadorController extends Controller
{
    private const VER = ['config_utilizadores_view', 'config_util_gerir'];

    public function __construct(private readonly ServicoUtilizadores $utilizadores) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['pesquisa' => ['nullable', 'string', 'max:100'], 'ativo' => ['nullable', 'boolean'], 'perfil_utilizador_id' => ['nullable', 'integer'],
            'empresa_id' => ['nullable', 'integer'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500']]);

        return RespostaApi::paginado($this->utilizadores->listar($f, $r->user()), null, 'Utilizadores obtidos com sucesso.');
    }

    public function show(Request $r, int $utilizador): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->utilizadores->obter($utilizador, $r->user()), 'Utilizador obtido com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('config_util_gerir');
        $d = $this->validar($r, true);
        $u = $this->utilizadores->criar($d, $r->user());
        // e-mail de boas-vindas: sistema do cliente, empresas, utilizador e a palavra-passe provisória (pedido de 2026-10-22;
        // o primeiro acesso pede para a alterar)
        EnviarEmail::para($u->email, new NovoUtilizadorMail($u->nome_completo ?: $u->nome_utilizador, $u->nome_utilizador,
            EnviarEmail::nomeSistemaDe($u), EnviarEmail::empresasDe($u), EnviarEmail::enderecoDe($u), (string) $d['palavra_passe']));

        return RespostaApi::criado($this->utilizadores->apresentar($u->load('perfil'), $r->user()), 'Utilizador criado com sucesso.');
    }

    public function update(Request $r, int $utilizador): JsonResponse
    {
        $this->exigir('config_util_gerir');
        $u = $this->utilizadores->atualizar(Utilizador::query()->findOrFail($utilizador), $this->validar($r, false), $r->user());

        return RespostaApi::sucesso($this->utilizadores->apresentar($u->load('perfil'), $r->user()), 'Utilizador actualizado com sucesso.');
    }

    public function estado(Request $r, int $utilizador): JsonResponse
    {
        $this->exigir('config_util_gerir');
        $d = $r->validate(['ativo' => ['required', 'boolean']]);
        $u = $this->utilizadores->definirEstado(Utilizador::query()->findOrFail($utilizador), (bool) $d['ativo'], $r->user());

        return RespostaApi::sucesso($this->utilizadores->apresentar($u->load('perfil'), $r->user()), $d['ativo'] ? 'Utilizador activado.' : 'Utilizador desactivado.');
    }

    public function reporPalavraPasse(Request $r, int $utilizador): JsonResponse
    {
        $this->exigir('config_util_gerir');
        $d = $r->validate(['palavra_passe' => ['required', 'string', 'min:'.ServicoUtilizadores::MINIMO_PALAVRA_PASSE, 'max:200', 'confirmed']],
            [], ['palavra_passe' => 'palavra-passe']);
        $u = $this->utilizadores->reporPalavraPasse(Utilizador::query()->findOrFail($utilizador), $d['palavra_passe'], $r->user());
        // aviso ao próprio: se não foi pedido por ele, sabe que deve falar com o administrador
        EnviarEmail::para($u->email, new PalavraPasseRedefinidaMail($u->nome_completo ?: $u->nome_utilizador, $u->nome_utilizador, EnviarEmail::nomeClienteDe($u)));

        return RespostaApi::sucesso(null, 'Palavra-passe reposta. As sessões abertas do utilizador foram terminadas.');
    }

    public function destroy(Request $r, int $utilizador): JsonResponse
    {
        $this->exigir('config_util_gerir');
        $this->utilizadores->eliminar(Utilizador::query()->findOrFail($utilizador), $r->user());

        return RespostaApi::sucesso(null, 'Utilizador eliminado.');
    }

    /** GET /api/sistema/utilizadores/colaboradores — colaboradores da empresa activa ligáveis a um utilizador. */
    public function colaboradores(Request $r, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('config_util_gerir');
        $d = $r->validate(['utilizador_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso($this->utilizadores->colaboradoresLigaveis($contexto->obrigatorio(), isset($d['utilizador_id']) ? (int) $d['utilizador_id'] : null),
            'Colaboradores da empresa activa.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $r, bool $criar): array
    {
        return $r->validate([
            'nome_utilizador' => [$criar ? 'required' : 'sometimes', 'string', 'max:100'],
            'nome_completo' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'palavra_passe' => [$criar ? 'required' : 'sometimes', 'nullable', 'string', 'min:'.ServicoUtilizadores::MINIMO_PALAVRA_PASSE, 'max:200'],
            'perfil_utilizador_id' => [$criar ? 'required' : 'sometimes', 'integer'],
            'papel' => ['sometimes', Rule::in([Utilizador::PAPEL_SUPER_ADMINISTRADOR, Utilizador::PAPEL_ADMINISTRADOR, Utilizador::PAPEL_UTILIZADOR])],
            'acesso_todas_empresas' => ['sometimes', 'boolean'],
            'ativo' => ['sometimes', 'boolean'],
            // criar com o nome de um utilizador eliminado do mesmo cliente: reactiva essa conta com estes dados
            'reactivar' => ['sometimes', 'boolean'],
            'empresas' => ['sometimes', 'array', 'max:500'],
            'empresas.*.empresa_id' => ['required', 'integer'],
            'empresas.*.colaborador_id' => ['nullable', 'integer'],
        ], [],
            ['nome_utilizador' => 'nome de utilizador', 'palavra_passe' => 'palavra-passe', 'perfil_utilizador_id' => 'perfil']);
    }
}
