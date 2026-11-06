<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** planos_estrategicos — plano estratégico da empresa (ciclo de 1 a 15 anos). Estados: RASCUNHO, APROVADO, EM_EXECUCAO, ENCERRADO. */
class PlanoEstrategico extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'planos_estrategicos';

    protected string $moduloAuditoria = 'Estratégia';

    protected $fillable = ['empresa_id', 'nome', 'ano_inicio', 'ano_fim', 'missao', 'visao', 'valores', 'estado', 'versao', 'autor_id', 'editado_por_id', 'aprovado_em', 'aprovado_por', 'aprovado_por_id', 'aprovacao_excepcao', 'execucao_iniciada_em', 'execucao_iniciada_por', 'encerrado_em', 'encerrado_por', 'motivo_reabertura', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'ano_inicio' => 'integer', 'ano_fim' => 'integer', 'versao' => 'integer', 'autor_id' => 'integer', 'editado_por_id' => 'integer', 'aprovado_por_id' => 'integer', 'aprovado_em' => 'datetime', 'execucao_iniciada_em' => 'datetime', 'encerrado_em' => 'datetime'];
    }

    public function perspectivas(): HasMany
    {
        return $this->hasMany(PerspectivaEstrategica::class, 'plano_estrategico_id')->orderBy('ordem')->orderBy('id');
    }

    public function objectivos(): HasMany
    {
        return $this->hasMany(ObjectivoEstrategico::class, 'plano_estrategico_id')->orderBy('ordem')->orderBy('id');
    }

    public function indicadores(): HasMany
    {
        return $this->hasMany(IndicadorEstrategico::class, 'plano_estrategico_id')->orderBy('ordem')->orderBy('id');
    }
}
