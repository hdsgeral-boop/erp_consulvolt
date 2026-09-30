<?php

namespace App\Models\Base;

use App\Models\AusenciaFaltaColaborador;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PlanoFeriasColaborador;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela pedidos_portal_colaborador (módulo RH). Legado: rh_portal_requests · 9 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PedidoPortalColaborador.
 */
abstract class PedidoPortalColaboradorBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'pedidos_portal_colaborador';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'colaborador_id', 'tipo', 'tipo_original', 'dados', 'etapas', 'estado', 'estado_original', 'criado_por', 'plano_ferias_colaborador_id', 'decidido_em', 'documento', 'ausencia_falta_id', 'cancelado_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_id' => 'integer',
            'dados' => 'array',
            'etapas' => 'array',
            'plano_ferias_colaborador_id' => 'integer',
            'decidido_em' => 'datetime',
            'documento' => 'array',
            'ausencia_falta_id' => 'integer',
            'cancelado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function planoFeriasColaborador(): BelongsTo
    {
        return $this->belongsTo(PlanoFeriasColaborador::class, 'plano_ferias_colaborador_id');
    }

    public function ausenciaFalta(): BelongsTo
    {
        return $this->belongsTo(AusenciaFaltaColaborador::class, 'ausencia_falta_id');
    }

    public function planoFeriasColaboradores(): HasMany
    {
        return $this->hasMany(PlanoFeriasColaborador::class, 'pedido_portal_colaborador_id');
    }

    public function ausenciasFaltasColaboradores(): HasMany
    {
        return $this->hasMany(AusenciaFaltaColaborador::class, 'pedido_portal_colaborador_id');
    }
}
