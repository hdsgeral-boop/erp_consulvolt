<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sistema\EmpresaResource;
use App\Http\Resources\Sistema\UtilizadorResource;
use App\Mail\BoasVindasEmpresaMail;
use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Autenticacao\ServicoAutenticacao;
use App\Services\Contabilidade\ServicoPlanoContasModelo;
use App\Services\Sistema\ServicoEmpresas;
use App\Services\Sistema\ServicoPerfis;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DefinicoesPlataforma;
use App\Support\Tenancy\ModulosPlano;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RegistoEmpresaController extends Controller
{
    public function __construct(private readonly ContextoTenant $tenants, private readonly ServicoAutenticacao $autenticacao, private readonly ServicoEmpresas $empresas, private readonly ServicoPermissoes $permissoes, private readonly ServicoPlanoContasModelo $planoContasModelo, private readonly ServicoPerfis $perfis) {}

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'nome_comercial' => ['required', 'string', 'max:255'], 'nif' => ['required', 'string', 'regex:/^\d{9,14}$/', Rule::unique('empresas', 'nif')->whereNull('eliminado_em')],
            'telefone' => ['required', 'string', 'max:30'], 'provincia' => ['required', 'string', 'max:100'],
            'nome_completo' => ['required', 'string', 'max:200'], 'nome_utilizador' => ['required', 'string', 'max:100', Rule::unique('utilizadores', 'nome_utilizador')],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('utilizadores', 'email')], 'palavra_passe' => ['required', 'string', 'min:10', 'max:255'],
            'plano' => ['nullable', 'string', Rule::exists('planos_licenca', 'codigo')->where('ativo', true)],
        ], [
            'nome_comercial.required' => 'O nome comercial da empresa é obrigatório.',
            'nif.required' => 'O NIF da empresa é obrigatório.',
            'nif.regex' => 'O NIF deve conter entre 9 e 14 dígitos.',
            'nif.unique' => 'Este NIF já se encontra registado numa empresa existente.',
            'telefone.required' => 'O telefone de contacto é obrigatório.',
            'provincia.required' => 'A província é obrigatória.',
            'nome_completo.required' => 'O nome completo do administrador é obrigatório.',
            'nome_utilizador.required' => 'O nome de utilizador para login é obrigatório.',
            'nome_utilizador.unique' => 'Este nome de utilizador já se encontra registado. Escolha outro.',
            'email.required' => 'O endereço de e-mail é obrigatório.',
            'email.email' => 'O endereço de e-mail indicado não é válido.',
            'email.unique' => 'Este e-mail já se encontra associado a um utilizador.',
            'palavra_passe.required' => 'A palavra-passe é obrigatória.',
            'palavra_passe.min' => 'A palavra-passe deve conter pelo menos 10 caracteres.',
            'plano.exists' => 'O plano selecionado não é válido.',
        ]);
        $diasTeste = DefinicoesPlataforma::diasTestePadrao();
        $perfilTotal = $this->perfis->perfilAcessoTotal();
        $resultado = DB::transaction(function () use ($d, $diasTeste, $perfilTotal) {
            $base = Str::limit(Str::slug($d['nome_comercial']) ?: 'empresa', 32, ''); $slug = $base; $n = 1;
            while (Tenant::query()->where('slug', $slug)->exists()) $slug = Str::limit($base, 28, '').'-'.++$n;
            $tenant = Tenant::create(['nome' => $d['nome_comercial'], 'slug' => $slug, 'estado' => Tenant::ESTADO_ATIVO, 'plano' => $d['plano'] ?? null]);
            if ($d['plano'] ?? null) {
                // ModulosPlano tem tenant_id => código do plano em cache (ServicoPermissoes nunca consulta `tenants`):
                // sem isto, o tenant novo só ficaria restrito ao seu plano depois do TTL da cache expirar.
                ModulosPlano::invalidar();
            }
            return $this->tenants->executarComo((int) $tenant->id, function () use ($d, $tenant, $diasTeste, $perfilTotal) {
                // Papel ADMINISTRADOR (não SUPER_ADMINISTRADOR, de propósito): um super-administrador fica isento da
                // expiração da licença (ResolverEmpresaAtiva::licenca) e isso anularia o próprio período de
                // demonstração. O acesso completo aos módulos de negócio vem, em vez disso, de um perfil de acesso
                // total (ServicoPermissoes::total()) — sem isto, por omissão um utilizador sem perfil não vê módulo
                // nenhum, pelo que só veria o Início e, no máximo, o que estiver isento de permissão. O próprio acesso
                // total fica ainda capado pelo plano escolhido (ServicoPermissoes::bloqueadaPeloPlano/ModulosPlano).
                $admin = Utilizador::create(['nome_utilizador' => $d['nome_utilizador'], 'nome_completo' => $d['nome_completo'], 'email' => $d['email'], 'palavra_passe' => $d['palavra_passe'], 'papel' => Utilizador::PAPEL_ADMINISTRADOR, 'perfil_utilizador_id' => $perfilTotal->id, 'acesso_todas_empresas' => true, 'ativo' => true]);
                $empresa = Empresa::create(['nome' => $d['nome_comercial'], 'nif' => $d['nif'], 'telefone' => $d['telefone'], 'provincia' => $d['provincia'], 'email' => $d['email'], 'moeda_funcional' => 'AOA', 'licenca_origem' => Empresa::LICENCA_TESTE, 'licenca_inicio' => now()->toDateString(), 'licenca_fim' => now()->addDays($diasTeste)->toDateString()]);
                $admin->empresas()->attach($empresa->id);
                $this->planoContasModelo->semear($empresa->id);
                return compact('tenant', 'empresa');
            });
        });
        $sessao = $this->autenticacao->entrar($d['nome_utilizador'], $d['palavra_passe'], 'web-registo', $r->ip(), $r->userAgent()); $u = $sessao['utilizador'];
        $nomePlano = $d['plano'] ?? null ? PlanoLicenca::query()->where('codigo', $d['plano'])->value('nome') : null;
        Mail::to($d['email'])->send(new BoasVindasEmpresaMail($d['nome_completo'], $d['nome_comercial'], $diasTeste, $nomePlano));

        return RespostaApi::criado(['token' => $sessao['token'], 'tipo_token' => 'Bearer', 'expira_em' => $sessao['expira_em']->toAtomString(), 'inatividade_minutos' => config('erp.sessao.inatividade_minutos'), 'utilizador' => UtilizadorResource::make($u)->resolve(), 'permissoes' => $this->permissoes->efectivas($u), 'perfil_formato_v2' => $this->permissoes->formatoV2($u), 'empresas' => EmpresaResource::collection($this->empresas->acessiveis($u))->resolve(), 'empresa_id' => $resultado['empresa']->id, 'tenant_id' => $resultado['tenant']->id, 'dias_teste' => $diasTeste], "Empresa registada. O período de demonstração de {$diasTeste} dias já está activo.");
    }
}
