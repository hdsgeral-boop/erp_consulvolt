<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\CargoFuncao;
use App\Models\Colaborador;
use App\Models\PostoTrabalho;
use App\Models\UnidadeOrganica;
use App\Services\RH\ServicoEstruturaOrg;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** /api/rh/estrutura — unidades orgânicas, postos, afectação e chefia; /api/rh/cargos — funções e categorias. */
final class EstruturaController extends Controller
{
    public function __construct(private readonly ServicoEstruturaOrg $estrutura) {}

    public function arvore(): JsonResponse
    {
        $this->exigir('est_estrutura_view', 'colaboradores_view');

        return RespostaApi::sucesso($this->estrutura->arvore(), 'Estrutura orgânica.');
    }

    /** GET /estrutura/mapa — mapa de pessoal por unidade e por cargo; a massa salarial só com est_ver_salarios (ADR-064). */
    public function mapa(): JsonResponse
    {
        $this->exigir('est_mapa_view', 'est_mapa', 'est_estrutura_view');

        return RespostaApi::sucesso($this->estrutura->mapaPessoal(Gate::any(['est_ver_salarios'])), 'Mapa de pessoal.');
    }

    public function guardarUnidade(Request $r, ?int $unidade = null): JsonResponse
    {
        $this->exigir('est_editar');
        $e = app(ContextoEmpresa::class)->obrigatorio();
        $existe = fn (string $t) => Rule::exists($t, 'id')->where('empresa_id', $e);
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'codigo' => ['nullable', 'string', 'max:50'], 'tipo' => ['nullable', Rule::in(ServicoEstruturaOrg::TIPOS_UNIDADE)],
            'unidade_organica_pai_id' => ['nullable', 'integer'], 'colaborador_responsavel_id' => ['nullable', 'integer', $existe('colaboradores')],
            'missao' => ['nullable', 'string', 'max:5000'], 'atribuicoes' => ['nullable', 'string', 'max:5000'], 'centro_custo_id' => ['nullable', 'integer', $existe('centros_custo')],
            'unidade_negocio_id' => ['nullable', 'integer', $existe('unidades_negocio')], 'ordem' => ['nullable', 'integer'], 'ativo' => ['nullable', 'boolean'],
            'apoio' => ['nullable', 'boolean'], 'cor' => ['nullable', 'string', 'max:20']]);
        $u = $unidade ? UnidadeOrganica::query()->findOrFail($unidade) : null;
        $res = $this->estrutura->guardarUnidade($d, $u);

        return $u ? RespostaApi::sucesso($res, 'Unidade actualizada.') : RespostaApi::criado($res, 'Unidade criada.');
    }

    public function eliminarUnidade(int $unidade): JsonResponse
    {
        $this->exigir('est_eliminar');
        $this->estrutura->eliminarUnidade(UnidadeOrganica::query()->findOrFail($unidade));

        return RespostaApi::sucesso(null, 'Unidade eliminada.');
    }

    public function guardarPosto(Request $r, ?int $posto = null): JsonResponse
    {
        $this->exigir('est_editar');
        $d = $r->validate(['unidade_organica_id' => ['required', 'integer'], 'cargo_funcao_id' => ['nullable', 'integer'], 'titulo' => ['nullable', 'string', 'max:255'],
            'vagas' => ['nullable', 'integer', 'min:0', 'max:9999'], 'posto_superior_id' => ['nullable', 'integer'], 'responsabilidades' => ['nullable', 'string', 'max:5000'],
            'chefia' => ['nullable', 'boolean'], 'ordem' => ['nullable', 'integer']]);
        $p = $posto ? PostoTrabalho::query()->findOrFail($posto) : null;
        $res = $this->estrutura->guardarPosto($d, $p);

        return $p ? RespostaApi::sucesso($res, 'Posto actualizado.') : RespostaApi::criado($res, 'Posto criado.');
    }

    public function eliminarPosto(int $posto): JsonResponse
    {
        $this->exigir('est_eliminar');
        $this->estrutura->eliminarPosto(PostoTrabalho::query()->findOrFail($posto));

        return RespostaApi::sucesso(null, 'Posto eliminado.');
    }

    /** POST /afectacao — um ou vários colaboradores (em massa não se altera o gestor, salvo se indicado). */
    public function afectar(Request $r): JsonResponse
    {
        $this->exigir('est_editar');
        $d = $r->validate(['colaboradores' => ['required', 'array', 'min:1', 'max:500'], 'colaboradores.*' => ['integer'], 'unidade_organica_id' => ['sometimes', 'nullable', 'integer'],
            'posto_trabalho_id' => ['sometimes', 'nullable', 'integer'], 'colaborador_gestor_id' => ['sometimes', 'nullable', 'integer']]);
        $campos = array_intersect_key($d, array_flip(['unidade_organica_id', 'posto_trabalho_id', 'colaborador_gestor_id']));
        $res = DB::transaction(fn () => collect($d['colaboradores'])->map(fn ($id) => $this->estrutura->afectar(Colaborador::query()->findOrFail($id), $campos))->all());

        return RespostaApi::sucesso(['colaboradores' => array_column($res, 'colaborador'), 'avisos' => array_values(array_unique(array_merge(...array_column($res, 'avisos'))))],
            count($res).' colaborador(es) afectado(s).');
    }

    public function chefia(int $colaborador): JsonResponse
    {
        $this->exigir('est_estrutura_view', 'colaboradores_view');
        $chefe = $this->estrutura->chefiaDe($colaborador);

        return RespostaApi::sucesso(['colaborador_id' => $colaborador, 'chefia_colaborador_id' => $chefe, 'equipa_directa' => $this->estrutura->equipaDirecta($colaborador)],
            'Chefia directa e equipa.');
    }

    // ───────────── Cargos ─────────────

    public function cargos(): JsonResponse
    {
        $this->exigir('funcoes_view', 'colaboradores_view', 'est_estrutura_view');

        return RespostaApi::sucesso(CargoFuncao::query()->orderBy('nome')->get(), 'Cargos e funções.');
    }

    public function guardarCargo(Request $r, ?int $cargo = null): JsonResponse
    {
        $this->exigir('rh_funcoes_gerir', 'est_editar');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'descricao' => ['nullable', 'string', 'max:5000']]);
        $c = $cargo ? CargoFuncao::query()->findOrFail($cargo) : null;
        $res = $this->estrutura->guardarCargo($d, $c);

        return $c ? RespostaApi::sucesso($res, 'Cargo actualizado.') : RespostaApi::criado($res, 'Cargo criado.');
    }

    public function eliminarCargo(int $cargo): JsonResponse
    {
        $this->exigir('rh_funcao_del', 'est_eliminar');
        $this->estrutura->eliminarCargo(CargoFuncao::query()->findOrFail($cargo));

        return RespostaApi::sucesso(null, 'Cargo eliminado.');
    }
}
