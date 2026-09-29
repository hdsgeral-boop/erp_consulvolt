<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ConfigAcrescimoDiferimento;
use App\Models\LancamentoContabil;
use App\Models\LancamentoEstornado;
use App\Models\ModeloBase;
use App\Models\PeriodoLancamentoAcrescimo;
use App\Models\SessaoPOS;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela diarios_contabeis (módulo Contabilidade). Legado: journals · 436 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\DiarioContabil.
 */
abstract class DiarioContabilBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'diarios_contabeis';

    protected string $moduloAuditoria = 'Contabilidade';

    protected $fillable = [
        'empresa_id', 'codigo', 'descricao', 'nome',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function lancamentosContabeis(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'diario_id');
    }

    public function lancamentosEstornados(): HasMany
    {
        return $this->hasMany(LancamentoEstornado::class, 'diario_id');
    }

    public function sessoesPos(): HasMany
    {
        return $this->hasMany(SessaoPOS::class, 'diario_contabilizacao_id');
    }

    public function configuracoesAcrescimosDiferimentos(): HasMany
    {
        return $this->hasMany(ConfigAcrescimoDiferimento::class, 'diario_id');
    }

    public function periodosLancamentoAcrescimos(): HasMany
    {
        return $this->hasMany(PeriodoLancamentoAcrescimo::class, 'diario_id');
    }
}
