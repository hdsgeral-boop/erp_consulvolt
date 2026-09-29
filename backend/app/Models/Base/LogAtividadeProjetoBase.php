<?php

namespace App\Models\Base;

use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela logs_atividades_projeto (módulo Projectos). Legado: project_activity_log · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LogAtividadeProjeto.
 */
abstract class LogAtividadeProjetoBase extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'logs_atividades_projeto';

    protected $fillable = [
        'empresa_id', 'projeto_id', 'ocorrido_em', 'nome_utilizador', 'acao', 'detalhes',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'projeto_id' => 'integer',
            'ocorrido_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }
}
