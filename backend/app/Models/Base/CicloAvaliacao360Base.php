<?php

namespace App\Models\Base;

use App\Models\AvaliacaoDesempenhoRH;
use App\Models\BonificacaoAvaliacaoRH;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ConfirmacaoAvaliacaoRH;
use App\Models\FeedbackAvaliacao360;
use App\Models\ModeloBase;
use App\Models\ParticipanteAvaliacao360;
use App\Models\RespostaAvaliacao360;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela ciclos_avaliacao_360 (módulo RH). Legado: rh_eval_cycles · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CicloAvaliacao360.
 */
abstract class CicloAvaliacao360Base extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'ciclos_avaliacao_360';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'nome', 'ano', 'periodo', 'data_inicio', 'data_fim', 'estado', 'estado_original', 'prazos', 'pesos', 'minimo_anonimato', 'max_pares', 'feedback', 'bonificacao', 'comunicado', 'atualizado_por', 'criado_por', 'participantes', 'criterios', 'aberto_em', 'aberto_por', 'fechado_em', 'fechado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ano' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'prazos' => 'array',
            'pesos' => 'array',
            'minimo_anonimato' => 'integer',
            'max_pares' => 'integer',
            'feedback' => 'array',
            'bonificacao' => 'array',
            'comunicado' => 'array',
            'participantes' => 'array',
            'criterios' => 'array',
            'aberto_em' => 'datetime',
            'fechado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function avaliacoesDesempenhoRh(): HasMany
    {
        return $this->hasMany(AvaliacaoDesempenhoRH::class, 'ciclo_avaliacao_id');
    }

    public function participantesAvaliacao360(): HasMany
    {
        return $this->hasMany(ParticipanteAvaliacao360::class, 'ciclo_avaliacao_id');
    }

    public function respostasAvaliacao360(): HasMany
    {
        return $this->hasMany(RespostaAvaliacao360::class, 'ciclo_avaliacao_id');
    }

    public function feedbacksAvaliacao360(): HasMany
    {
        return $this->hasMany(FeedbackAvaliacao360::class, 'ciclo_avaliacao_id');
    }

    public function bonificacoesAvaliacaoRh(): HasMany
    {
        return $this->hasMany(BonificacaoAvaliacaoRH::class, 'ciclo_avaliacao_id');
    }

    public function confirmacoesAvaliacaoRh(): HasMany
    {
        return $this->hasMany(ConfirmacaoAvaliacaoRH::class, 'ciclo_avaliacao_id');
    }
}
