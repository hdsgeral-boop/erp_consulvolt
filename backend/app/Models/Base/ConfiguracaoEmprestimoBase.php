<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DiarioContabil;
use App\Models\InfotipoSalarial;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela configuracoes_emprestimos (módulo RH). Legado: rh_loan_settings · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfiguracaoEmprestimo.
 */
abstract class ConfiguracaoEmprestimoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_emprestimos';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'conta_emprestimo', 'conta_contrapartida', 'infotipo_desconto_id', 'prestacoes_max', 'multiplo_salario_max', 'taxa_juro_padrao', 'exige_chefia', 'diario_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'infotipo_desconto_id' => 'integer',
            'prestacoes_max' => 'integer',
            'multiplo_salario_max' => 'decimal:2',
            'taxa_juro_padrao' => 'decimal:4',
            'exige_chefia' => 'boolean',
            'diario_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function infotipoDesconto(): BelongsTo
    {
        return $this->belongsTo(InfotipoSalarial::class, 'infotipo_desconto_id');
    }

    public function diario(): BelongsTo
    {
        return $this->belongsTo(DiarioContabil::class, 'diario_id');
    }
}
