<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\MesaPOS;
use App\Models\ModeloBase;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela contas_mesa_pos (módulo POS). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ContaMesaPOS.
 */
abstract class ContaMesaPOSBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'contas_mesa_pos';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'mesa_pos_id', 'terminal_pos_id', 'sessao_pos_id', 'estado', 'linhas', 'percentagem_desconto', 'cliente_id', 'observacoes', 'operador', 'versao', 'venda_id', 'aberta_em', 'fechada_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'mesa_pos_id' => 'integer',
            'terminal_pos_id' => 'integer',
            'sessao_pos_id' => 'integer',
            'linhas' => 'array',
            'percentagem_desconto' => 'decimal:4',
            'cliente_id' => 'integer',
            'versao' => 'integer',
            'venda_id' => 'integer',
            'aberta_em' => 'datetime',
            'fechada_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function mesaPos(): BelongsTo
    {
        return $this->belongsTo(MesaPOS::class, 'mesa_pos_id');
    }

    public function terminalPos(): BelongsTo
    {
        return $this->belongsTo(TerminalPOS::class, 'terminal_pos_id');
    }

    public function sessaoPos(): BelongsTo
    {
        return $this->belongsTo(SessaoPOS::class, 'sessao_pos_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'cliente_id');
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_id');
    }
}
