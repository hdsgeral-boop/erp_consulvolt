<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empresa (tenant). Legado: companies.
 */
class Empresa extends ModeloBase
{
    use Auditavel;
    use SoftDeletes;

    public const ESTADO_ATIVO = 'ATIVO';

    public const ESTADO_INATIVO = 'INATIVO';

    public const LICENCA_SEM_LIMITE = 'SEM_LIMITE';

    public const LICENCA_TESTE = 'TESTE';

    public const LICENCA_PLANO = 'PLANO';

    /** Código do plano interno da equipa (sem limites, sem custo). */
    public const PLANO_INTERNO = 'interno';

    protected $table = 'empresas';

    protected string $moduloAuditoria = 'Sistema/Empresas';

    protected $fillable = [
        'nome', 'nif', 'endereco', 'provincia', 'municipio', 'comuna', 'telefone', 'email', 'website',
        'numero_registo_comercial', 'rodape_documento', 'logotipo', 'taxa_inss_patronal', 'taxa_inss_trabalhador',
        'regras_ia', 'estado', 'e_consolidacao', 'moeda_consolidacao', 'data_fim_consolidacao',
        'he_percentagem_1', 'he_limite_horas', 'he_percentagem_2', 'moeda_funcional',
        'licenca_origem', 'licenca_inicio', 'licenca_fim',
    ];

    protected $hidden = ['logotipo'];

    protected function casts(): array
    {
        return [
            'taxa_inss_patronal' => 'decimal:2',
            'taxa_inss_trabalhador' => 'decimal:2',
            'e_consolidacao' => 'boolean',
            'data_fim_consolidacao' => 'date',
            'he_percentagem_1' => 'decimal:4',
            'he_limite_horas' => 'decimal:3',
            'he_percentagem_2' => 'decimal:4',
            'licenca_inicio' => 'date',
            'licenca_fim' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // SaaS (ADR-073): uma empresa nova pertence ao tenant do pedido/trabalho que a cria.
        static::creating(function (Empresa $e) {
            if (Schema::hasColumn('empresas', 'tenant_id')) {
                $e->tenant_id ??= app(ContextoTenant::class)->paraNovo();
            }

            // Licença do CLIENTE: uma empresa nova herda a licença do cliente a que pertence (o período de teste é do cliente,
            // criar mais uma empresa não dá outro teste). A empresa guarda só uma cópia, mantida pelo ServicoLicencaCliente.
            if (config('erp.licencas.ativo') && $e->licenca_fim === null && ($e->licenca_origem ?? self::LICENCA_SEM_LIMITE) === self::LICENCA_SEM_LIMITE
                && Schema::hasColumn('tenants', 'licenca_origem') && $e->tenant_id !== null) {
                $c = DB::table('tenants')->where('id', $e->tenant_id)->first(['licenca_origem', 'licenca_inicio', 'licenca_fim']);
                if ($c !== null) {
                    $e->licenca_origem = $c->licenca_origem;
                    $e->licenca_inicio = $c->licenca_inicio;
                    $e->licenca_fim = $c->licenca_fim;
                }
            }
        });
        $invalidar = fn () => app(ServicoEmpresas::class)->invalidarTodos();
        static::saved(fn (Empresa $e) => $e->wasChanged(['estado', 'eliminado_em']) || $e->wasRecentlyCreated ? $invalidar() : null);
        static::deleted($invalidar);
        static::restored($invalidar);
    }

    public function utilizadores(): BelongsToMany
    {
        return $this->belongsToMany(Utilizador::class, 'utilizador_empresa', 'empresa_id', 'utilizador_id')
            ->using(UtilizadorEmpresa::class)->withPivot('colaborador_id');
    }

    /** Licença expirada: tem data de fim e já passou (o último dia é inclusivo). Sem data = sem expiração. */
    public function licencaExpirada(): bool
    {
        return $this->licenca_fim !== null && $this->licenca_fim->lt(now()->startOfDay());
    }

    public function estaAtiva(): bool
    {
        return $this->estado === self::ESTADO_ATIVO && ! $this->trashed();
    }
}
