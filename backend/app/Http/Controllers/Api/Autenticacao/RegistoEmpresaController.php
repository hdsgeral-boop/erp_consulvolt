<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sistema\EmpresaResource;
use App\Http\Resources\Sistema\UtilizadorResource;
use App\Models\Empresa;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Autenticacao\ServicoAutenticacao;
use App\Services\Sistema\ServicoEmpresas;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RegistoEmpresaController extends Controller
{
    public function __construct(private readonly ContextoTenant $tenants, private readonly ServicoAutenticacao $autenticacao, private readonly ServicoEmpresas $empresas, private readonly ServicoPermissoes $permissoes) {}

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'nome_comercial' => ['required', 'string', 'max:255'], 'nif' => ['required', 'string', 'regex:/^\d{9,14}$/', Rule::unique('empresas', 'nif')],
            'telefone' => ['required', 'string', 'max:30'], 'provincia' => ['required', 'string', 'max:100'],
            'nome_completo' => ['required', 'string', 'max:200'], 'nome_utilizador' => ['required', 'string', 'max:100', Rule::unique('utilizadores', 'nome_utilizador')],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('utilizadores', 'email')], 'palavra_passe' => ['required', 'string', 'min:10', 'max:255'],
            'plano' => ['nullable', 'string', Rule::exists('planos_licenca', 'codigo')->where('ativo', true)],
        ]);
        $resultado = DB::transaction(function () use ($d) {
            $base = Str::limit(Str::slug($d['nome_comercial']) ?: 'empresa', 32, ''); $slug = $base; $n = 1;
            while (Tenant::query()->where('slug', $slug)->exists()) $slug = Str::limit($base, 28, '').'-'.++$n;
            $tenant = Tenant::create(['nome' => $d['nome_comercial'], 'slug' => $slug, 'estado' => Tenant::ESTADO_ATIVO, 'plano' => $d['plano']]);
            return $this->tenants->executarComo((int) $tenant->id, function () use ($d, $tenant) {
                $admin = Utilizador::create(['nome_utilizador' => $d['nome_utilizador'], 'nome_completo' => $d['nome_completo'], 'email' => $d['email'], 'palavra_passe' => $d['palavra_passe'], 'papel' => Utilizador::PAPEL_ADMINISTRADOR, 'ativo' => true]);
                $empresa = Empresa::create(['nome' => $d['nome_comercial'], 'nif' => $d['nif'], 'telefone' => $d['telefone'], 'provincia' => $d['provincia'], 'email' => $d['email'], 'moeda_funcional' => 'AOA', 'licenca_origem' => Empresa::LICENCA_TESTE, 'licenca_inicio' => now()->toDateString(), 'licenca_fim' => now()->addDays(14)->toDateString()]);
                $admin->empresas()->attach($empresa->id);
                return compact('tenant', 'empresa');
            });
        });
        $sessao = $this->autenticacao->entrar($d['nome_utilizador'], $d['palavra_passe'], 'web-registo', $r->ip(), $r->userAgent()); $u = $sessao['utilizador'];
        return RespostaApi::criado(['token' => $sessao['token'], 'tipo_token' => 'Bearer', 'expira_em' => $sessao['expira_em']->toAtomString(), 'inatividade_minutos' => config('erp.sessao.inatividade_minutos'), 'utilizador' => UtilizadorResource::make($u)->resolve(), 'permissoes' => $this->permissoes->efectivas($u), 'perfil_formato_v2' => $this->permissoes->formatoV2($u), 'empresas' => EmpresaResource::collection($this->empresas->acessiveis($u))->resolve(), 'empresa_id' => $resultado['empresa']->id, 'tenant_id' => $resultado['tenant']->id], 'Empresa registada. O período experimental de 14 dias já está activo.');
    }
}
