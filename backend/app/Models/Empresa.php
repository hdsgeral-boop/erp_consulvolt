<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Services\Sistema\ServicoEmpresas;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    /**
     * Estados comerciais da licença (MaquinaEstadosLicenca — extensão SaaS, secção 4.2): separados do estado
     * administrativo acima (ESTADO_ATIVO/INATIVO), que é sobre a empresa em si, não sobre a subscrição.
     */
    public const LICENCA_PENDENTE_ACTIVACAO = 'PENDENTE_ACTIVACAO';

    public const LICENCA_TRIAL = 'TRIAL';

    public const LICENCA_ACTIVA = 'ACTIVA';

    public const LICENCA_PAGAMENTO_PENDENTE = 'PAGAMENTO_PENDENTE';

    public const LICENCA_TOLERANCIA = 'TOLERANCIA';

    public const LICENCA_SUSPENSA = 'SUSPENSA';

    public const LICENCA_CANCELADA = 'CANCELADA';

    public const LICENCA_EM_ENCERRAMENTO = 'EM_ENCERRAMENTO';

    public const LICENCA_ENCERRADA = 'ENCERRADA';

    /** Motivos de suspensão (secção 10): só têm sentido quando estado_licenca = LICENCA_SUSPENSA. */
    public const MOTIVO_INCUMPRIMENTO = 'INCUMPRIMENTO';

    public const MOTIVO_ADMINISTRATIVA = 'ADMINISTRATIVA';

    public const MOTIVO_SEGURANCA = 'SEGURANCA';

    public const MOTIVO_VOLUNTARIA = 'VOLUNTARIA';

    protected $table = 'empresas';

    protected string $moduloAuditoria = 'Sistema/Empresas';

    protected $fillable = [
        'nome', 'nif', 'endereco', 'provincia', 'municipio', 'comuna', 'telefone', 'email', 'website',
        'numero_registo_comercial', 'rodape_documento', 'logotipo', 'taxa_inss_patronal', 'taxa_inss_trabalhador',
        'regras_ia', 'estado', 'e_consolidacao', 'moeda_consolidacao', 'data_fim_consolidacao',
        'he_percentagem_1', 'he_limite_horas', 'he_percentagem_2', 'moeda_funcional',
        'licenca_origem', 'licenca_inicio', 'licenca_fim',
        'estado_licenca', 'motivo_suspensao', 'estado_licenca_desde',
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
            'estado_licenca_desde' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // SaaS (ADR-073): uma empresa nova pertence ao tenant do pedido/trabalho que a cria.
        static::creating(function (Empresa $e) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('empresas', 'tenant_id')) {
                $e->tenant_id ??= app(\App\Support\Tenancy\ContextoTenant::class)->paraNovo();
            }

            // Licença (ADR-074): com as licenças activas, uma empresa nova começa com o período de demonstração.
            if (\App\Support\Tenancy\DefinicoesPlataforma::licencasAtivas() && ($e->licenca_fim === null) && ($e->licenca_origem ?? self::LICENCA_SEM_LIMITE) === self::LICENCA_SEM_LIMITE) {
                $e->licenca_origem = self::LICENCA_TESTE;
                $e->licenca_inicio = now()->toDateString();
                // Fase 8 da extensão SaaS: o plano do tenant pode ter um período experimental próprio; sem um
                // valor definido (plano sem override, ou tenant sem plano), usa-se o global.
                $diasTeste = ($e->tenant_id ? PlanoLicenca::diasTestePara($e->tenant_id) : null) ?? \App\Support\Tenancy\DefinicoesPlataforma::diasTestePadrao();
                $e->licenca_fim = now()->addDays($diasTeste)->toDateString();
            }

            // Máquina de estados da licença (Fase 1 da extensão SaaS): toda a empresa nova nasce com um estado
            // explícito, coerente com a licença acabada de definir acima — calculado pela mesma regra usada para
            // sincronizar (MaquinaEstadosLicenca::calcular()), para que uma empresa criada já com `licenca_fim` no
            // passado (ex.: fixtures de teste) nasça correctamente em TOLERANCIA/SUSPENSA, nunca em ACTIVA.
            if ($e->estado_licenca === null) {
                $e->estado_licenca = app(\App\Services\Plataforma\MaquinaEstadosLicenca::class)->calcular($e);
                // Sem isto, uma suspensão "de nascença" ficaria sem motivo e calcular() passaria a tratá-la como
                // manual (nunca recalculável) — ver MaquinaEstadosLicenca::calcular().
                $e->motivo_suspensao ??= $e->estado_licenca === self::LICENCA_SUSPENSA ? self::MOTIVO_INCUMPRIMENTO : null;
            }
            $e->estado_licenca_desde ??= now();
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
