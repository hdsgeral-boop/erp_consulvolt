<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EmprestimoColaborador;
use App\Models\LinhaFolhaSalarial;
use App\Models\ModeloBase;
use App\Models\PeriodoProcessamentoSalarial;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela prestacoes_emprestimos (módulo RH). Legado: rh_loan_instalments · 23 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PrestacaoEmprestimo.
 */
abstract class PrestacaoEmprestimoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'prestacoes_emprestimos';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'emprestimo_id', 'colaborador_id', 'estado', 'numero', 'mes_ano', 'capital', 'juro', 'total', 'saldo', 'linha_folha_salarial_id', 'descontado_em', 'periodo_processamento_salarial_id', 'descontado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'emprestimo_id' => 'integer',
            'colaborador_id' => 'integer',
            'numero' => 'integer',
            'capital' => 'decimal:2',
            'juro' => 'decimal:2',
            'total' => 'decimal:2',
            'saldo' => 'decimal:2',
            'linha_folha_salarial_id' => 'integer',
            'descontado_em' => 'datetime',
            'periodo_processamento_salarial_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function emprestimo(): BelongsTo
    {
        return $this->belongsTo(EmprestimoColaborador::class, 'emprestimo_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function linhaFolhaSalarial(): BelongsTo
    {
        return $this->belongsTo(LinhaFolhaSalarial::class, 'linha_folha_salarial_id');
    }

    public function periodoProcessamentoSalarial(): BelongsTo
    {
        return $this->belongsTo(PeriodoProcessamentoSalarial::class, 'periodo_processamento_salarial_id');
    }
}
