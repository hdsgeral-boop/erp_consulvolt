<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PedidoPortalColaborador;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela plano_ferias_colaboradores (módulo RH). Legado: rh_vacations · 4 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PlanoFeriasColaborador.
 */
abstract class PlanoFeriasColaboradorBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'plano_ferias_colaboradores';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'colaborador_id', 'ano', 'data_inicio', 'data_fim', 'dias', 'direito', 'estado', 'estado_original', 'observacoes', 'pedido_portal_colaborador_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_id' => 'integer',
            'ano' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'dias' => 'integer',
            'direito' => 'integer',
            'pedido_portal_colaborador_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function pedidoPortalColaborador(): BelongsTo
    {
        return $this->belongsTo(PedidoPortalColaborador::class, 'pedido_portal_colaborador_id');
    }

    public function pedidosPortalColaborador(): HasMany
    {
        return $this->hasMany(PedidoPortalColaborador::class, 'plano_ferias_colaborador_id');
    }
}
