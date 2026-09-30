<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LiquidacaoPOS;
use App\Models\ModeloBase;
use App\Models\MovimentoCaixa;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela sessoes_caixa (módulo Tesouraria). Legado: cash_sessions · 12 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\SessaoCaixa.
 */
abstract class SessaoCaixaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'sessoes_caixa';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'codigo_conta', 'operador', 'data_abertura', 'data_fecho', 'saldo_abertura', 'saldo_fecho', 'saldo_fisico', 'estado', 'estado_original', 'codigo_moeda', 'numeros_lan_contabilizacao', 'fechado_por', 'contabilizado_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data_abertura' => 'date',
            'data_fecho' => 'date',
            'saldo_abertura' => 'decimal:2',
            'saldo_fecho' => 'decimal:2',
            'saldo_fisico' => 'decimal:2',
            'numeros_lan_contabilizacao' => 'array',
            'contabilizado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function movimentosCaixa(): HasMany
    {
        return $this->hasMany(MovimentoCaixa::class, 'sessao_caixa_id');
    }

    public function liquidacoesPos(): HasMany
    {
        return $this->hasMany(LiquidacaoPOS::class, 'sessao_caixa_id');
    }
}
