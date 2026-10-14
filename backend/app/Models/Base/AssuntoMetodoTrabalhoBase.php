<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\HistoricoAssuntoMetodo;
use App\Models\ModeloBase;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela assuntos_metodo_trabalho (módulo Projectos). Legado: mt_assuntos · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AssuntoMetodoTrabalho.
 */
abstract class AssuntoMetodoTrabalhoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'assuntos_metodo_trabalho';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'titulo', 'descricao', 'responsaveis', 'responsavel_principal', 'responsavel_principal_ref', 'data_inicio', 'data_fim', 'local_tipo', 'unidade_negocio_id', 'cliente_id', 'encomenda', 'morada', 'fundamentacao', 'causa_raiz', 'recursos', 'custo_estimado', 'codigo_moeda', 'gerar_pedido_compra', 'anexos', 'atualizado_por', 'numero', 'estado', 'criado_por', 'metodo_resolucao', 'projeto_id', 'projeto_modo', 'deliberacao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'responsaveis' => 'array',
            'responsavel_principal_ref' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'unidade_negocio_id' => 'integer',
            'cliente_id' => 'integer',
            'custo_estimado' => 'decimal:2',
            'gerar_pedido_compra' => 'boolean',
            'anexos' => 'array',
            'projeto_id' => 'integer',
            'deliberacao' => 'array',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'cliente_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function projetos(): HasMany
    {
        return $this->hasMany(Projeto::class, 'assunto_metodo_id');
    }

    public function historicoAssuntosMetodo(): HasMany
    {
        return $this->hasMany(HistoricoAssuntoMetodo::class, 'assunto_id');
    }
}
