<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autenticacao\RegistarEmpresaRequest;
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
use App\Support\Autenticacao\CookiesSessao;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DefinicoesPlataforma;
use App\Support\Tenancy\DominioPedido;
use App\Support\Tenancy\ModulosPlano;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RegistoEmpresaController extends Controller
{
    public function __construct(private readonly ContextoTenant $tenants, private readonly ServicoAutenticacao $autenticacao, private readonly ServicoEmpresas $empresas, private readonly ServicoPermissoes $permissoes, private readonly ServicoPlanoContasModelo $planoContasModelo, private readonly ServicoPerfis $perfis) {}

    public function store(RegistarEmpresaRequest $r): JsonResponse
    {
        $d = $r->validated();
        $diasTeste = DefinicoesPlataforma::diasTestePadrao();
        $perfilTotal = $this->perfis->perfilAcessoTotal();
        $nomePlano = ($d['plano'] ?? null) ? PlanoLicenca::query()->where('codigo', $d['plano'])->value('nome') : null;
        try {
            $resultado = $this->criarTudo($d, $diasTeste, $perfilTotal, $nomePlano);
        } catch (UniqueConstraintViolationException $e) {
            // A RLS esconde as empresas e contas de outros clientes, por isso a duplicação só se descobre na base: devolve-se
            // como erro do campo (422), sem dizer de quem é o registo existente.
            $campo = self::campoDuplicado($e);
            if ($campo === null) {
                throw $e;
            }
            throw ValidationException::withMessages($campo);
        }
        // Registo feito num endereço que já é de OUTRO cliente (ex.: consulvolt.<base>), da plataforma ou do portal: a conta nova
        // pertence ao seu próprio cliente e esses endereços recusariam a sessão (403 «nome de utilizador ou palavra-passe
        // incorrectos» logo a seguir ao registo, com a empresa já criada). Cria-se, não se abre sessão aqui e devolve-se o
        // endereço próprio da empresa para entrar. No endereço principal (ou sem domínios por cliente) tudo fica como era.
        $area = DominioPedido::de($r)->area;
        if (in_array($area, [DominioPedido::CLIENTE, DominioPedido::PLATAFORMA, DominioPedido::PORTAL], true)) {
            $base = strtolower(trim((string) config('erp.dominio.base')));
            $endereco = $r->getScheme().'://'.$resultado['tenant']->slug.'.'.$base.'/app';

            return RespostaApi::criado(['sem_sessao' => true, 'endereco_entrada' => $endereco, 'nome_utilizador' => $d['nome_utilizador'], 'dias_teste' => $diasTeste], "Empresa registada. O período de demonstração de {$diasTeste} dias já está activo. Entre no endereço da sua empresa com o utilizador e a palavra-passe que acabou de criar.");
        }
        $dispositivo = CookiesSessao::dispositivoDoPedido($r);
        $sessao = $this->autenticacao->entrar($d['nome_utilizador'], $d['palavra_passe'], $dispositivo['id'], $r->ip(), $r->userAgent());
        $u = $sessao['utilizador'];

        // Fase D: o token vai só no cookie HttpOnly (CookiesSessao), nunca no corpo — tal como em AutenticacaoController::entrar.
        return CookiesSessao::anexar(RespostaApi::criado(['tipo_token' => 'Bearer', 'expira_em' => $sessao['expira_em']->toAtomString(), 'inatividade_minutos' => config('erp.sessao.inatividade_minutos'), 'utilizador' => UtilizadorResource::make($u)->resolve(), 'permissoes' => $this->permissoes->efectivas($u), 'perfil_formato_v2' => $this->permissoes->formatoV2($u), 'empresas' => EmpresaResource::collection($this->empresas->acessiveis($u))->resolve(), 'empresa_id' => $resultado['empresa']->id, 'tenant_id' => $resultado['tenant']->id, 'dias_teste' => $diasTeste], "Empresa registada. O período de demonstração de {$diasTeste} dias já está activo."), $sessao['token'], $sessao['expira_em'], $dispositivo['id'], $r);
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{tenant: Tenant, empresa: Empresa}
     */
    private function criarTudo(array $d, int $diasTeste, mixed $perfilTotal, mixed $nomePlano): array
    {
        return DB::transaction(function () use ($d, $diasTeste, $perfilTotal, $nomePlano) {
            $base = Str::limit(Str::slug($d['nome_comercial']) ?: 'empresa', 32, '');
            $slug = $base;
            $n = 1;
            // Nenhum cliente pode reclamar um slug reservado (admin, portal, …): colidiria com a área desse endereço (DominioPedido).
            $reservado = fn (string $s) => in_array($s, (array) config('erp.dominio.slugs_reservados'), true);
            while (Tenant::query()->where('slug', $slug)->exists() || $reservado($slug)) {
                $slug = Str::limit($base, 28, '').'-'.++$n;
            }
            $tenant = Tenant::create(['nome' => $d['nome_comercial'], 'slug' => $slug, 'estado' => Tenant::ESTADO_ATIVO, 'plano' => $d['plano'] ?? null]);
            if ($d['plano'] ?? null) {
                // ModulosPlano tem tenant_id => código do plano em cache (ServicoPermissoes nunca consulta `tenants`):
                // sem isto, o tenant novo só ficaria restrito ao seu plano depois do TTL da cache expirar.
                ModulosPlano::invalidar();
            }

            return $this->tenants->executarComo((int) $tenant->id, function () use ($d, $tenant, $diasTeste, $perfilTotal, $nomePlano) {
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
                // Outbox (ADR-080): o e-mail de boas-vindas nasce na mesma transacção do registo — se esta falhar não há e-mail, se fizer COMMIT nunca se perde
                Outbox::email(BoasVindasEmpresaMail::class, $d['email'], ['nomeAdministrador' => $d['nome_completo'], 'nomeEmpresa' => $d['nome_comercial'], 'diasTeste' => $diasTeste, 'nomePlano' => $nomePlano], "email:boas-vindas:empresa:{$empresa->id}");

                return compact('tenant', 'empresa');
            });
        });
    }

    /**
     * Qual campo causou a violação de unicidade (procura em toda a cadeia: a transacção abortada esconde a causa em «previous»).
     *
     * @return array<string, string>|null
     */
    private static function campoDuplicado(\Throwable $e): ?array
    {
        for ($a = $e; $a !== null; $a = $a->getPrevious()) {
            if (! $a instanceof UniqueConstraintViolationException) {
                continue;
            }
            $m = $a->getMessage();
            if (str_contains($m, 'uq_empresas_nif_ativas')) {
                return ['nif' => 'Este NIF já se encontra registado numa empresa existente.'];
            }
            if (str_contains($m, 'utilizadores_nome_utilizador_unique')) {
                return ['nome_utilizador' => 'Este nome de utilizador já se encontra registado. Escolha outro.'];
            }
        }

        return null;
    }
}
