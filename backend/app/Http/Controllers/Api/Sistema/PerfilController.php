<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Models\PerfilUtilizador;
use App\Services\Sistema\CatalogoPermissoes;
use App\Services\Sistema\ServicoPerfis;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/sistema/perfis — config_perfis (consulta) e config_perfis_gerir (editor v2, modelos). */
final class PerfilController extends Controller
{
    private const VER = ['config_perfis_view', 'config_perfis_gerir'];

    public function __construct(private readonly ServicoPerfis $perfis) {}

    public function index(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->perfis->listar(), 'Perfis obtidos com sucesso.');
    }

    public function show(int $perfil): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->perfis->obter(PerfilUtilizador::query()->findOrFail($perfil)), 'Perfil obtido com sucesso.');
    }

    /** GET /api/sistema/perfis/catalogo — módulos, ecrãs, tarefas (sensíveis), segregação e perfis-modelo. */
    public function catalogo(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(CatalogoPermissoes::completo(), 'Catálogo de permissões.');
    }

    public function matriz(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->perfis->matriz(), 'Matriz de perfis e permissões.');
    }

    /** POST /api/sistema/perfis/avaliar — contagem e conflitos de segregação de um conjunto de permissões (sem gravar). */
    public function avaliar(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['permissoes' => ['present', 'array', 'max:1000'], 'permissoes.*' => ['string', 'max:100']]);

        return RespostaApi::sucesso($this->perfis->contar(array_values($d['permissoes'])), 'Avaliação do perfil.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('config_perfis_gerir');
        $res = $this->perfis->guardar($this->validar($r, true), null, $r->user());

        return RespostaApi::criado($this->perfis->obter($res['perfil']) + ['avisos' => $res['avisos']], 'Perfil criado com sucesso.');
    }

    public function update(Request $r, int $perfil): JsonResponse
    {
        $this->exigir('config_perfis_gerir');
        $res = $this->perfis->guardar($this->validar($r, false), PerfilUtilizador::query()->findOrFail($perfil), $r->user());

        return RespostaApi::sucesso($this->perfis->obter($res['perfil']) + ['avisos' => $res['avisos']],
            'Perfil gravado. Os utilizadores com este perfil têm as novas permissões a partir do próximo pedido.');
    }

    public function duplicar(Request $r, int $perfil): JsonResponse
    {
        $this->exigir('config_perfis_gerir');

        return RespostaApi::criado($this->perfis->obter($this->perfis->duplicar(PerfilUtilizador::query()->findOrFail($perfil), $r->user())), 'Perfil duplicado.');
    }

    public function destroy(Request $r, int $perfil): JsonResponse
    {
        $this->exigir('config_perfis_gerir');
        $this->perfis->eliminar(PerfilUtilizador::query()->findOrFail($perfil), $r->user());

        return RespostaApi::sucesso(null, 'Perfil eliminado.');
    }

    /** POST /api/sistema/perfis/modelos — cria os perfis-modelo em falta (simular: só lista). */
    public function criarModelos(Request $r): JsonResponse
    {
        $this->exigir('config_perfis_gerir');
        $simular = (bool) ($r->validate(['simular' => ['nullable', 'boolean']])['simular'] ?? false);
        $nomes = $this->perfis->criarModelos($simular);

        return RespostaApi::sucesso(['perfis' => $nomes], $simular ? count($nomes).' perfil(is)-modelo por criar.' : count($nomes).' perfil(is)-modelo criado(s).');
    }

    /** POST /api/sistema/perfis/modelos/actualizar — acrescenta aos perfis-modelo as permissões em falta (nunca retira). */
    public function actualizarModelos(Request $r): JsonResponse
    {
        $this->exigir('config_perfis_gerir');
        $simular = (bool) ($r->validate(['simular' => ['nullable', 'boolean']])['simular'] ?? false);
        $alteracoes = $this->perfis->actualizarModelos($simular);

        return RespostaApi::sucesso(['alteracoes' => $alteracoes], ($simular ? 'Por actualizar: ' : 'Actualizados: ').count($alteracoes).' perfil(is).');
    }

    /** @return array<string, mixed> */
    private function validar(Request $r, bool $criar): array
    {
        return $r->validate([
            'nome' => [$criar ? 'required' : 'sometimes', 'string', 'max:100'],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'acesso_total' => ['sometimes', 'boolean'],
            'permissoes' => [$criar ? 'required_unless:acesso_total,true' : 'sometimes', 'array', 'max:1000'],
            'permissoes.*' => ['string', 'max:100'],
            'confirmar_conflitos' => ['sometimes', 'boolean'],
        ], [], ['nome' => 'nome do perfil', 'permissoes' => 'permissões']);
    }
}
