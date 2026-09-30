<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\FechoMensalAssiduidade;
use App\Models\ModeloBase;
use App\Models\PedidoPortalColaborador;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela ausencias_faltas_colaboradores (módulo RH). Legado: rh_absences · 34 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AusenciaFaltaColaborador.
 */
abstract class AusenciaFaltaColaboradorBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'ausencias_faltas_colaboradores';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'colaborador_id', 'tipo', 'data_inicio', 'data_fim', 'dias_uteis', 'horas_falta', 'ocorrencia', 'estado', 'estado_original', 'detectada', 'fecho_mensal_assiduidade_id', 'mes', 'criado_por', 'dias', 'horas', 'pendente_no_fecho', 'motivo', 'documento_url', 'remunerada', 'avisos', 'pedido_portal_colaborador_id', 'justificada_em', 'justificada_por', 'decidido_em', 'decidido_por', 'nota_decisao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_id' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'dias_uteis' => 'integer',
            'horas_falta' => 'decimal:3',
            'detectada' => 'boolean',
            'fecho_mensal_assiduidade_id' => 'integer',
            'dias' => 'integer',
            'horas' => 'decimal:3',
            'pendente_no_fecho' => 'boolean',
            'avisos' => 'array',
            'pedido_portal_colaborador_id' => 'integer',
            'justificada_em' => 'datetime',
            'decidido_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function fechoMensalAssiduidade(): BelongsTo
    {
        return $this->belongsTo(FechoMensalAssiduidade::class, 'fecho_mensal_assiduidade_id');
    }

    public function pedidoPortalColaborador(): BelongsTo
    {
        return $this->belongsTo(PedidoPortalColaborador::class, 'pedido_portal_colaborador_id');
    }

    public function pedidosPortalColaborador(): HasMany
    {
        return $this->hasMany(PedidoPortalColaborador::class, 'ausencia_falta_id');
    }
}
