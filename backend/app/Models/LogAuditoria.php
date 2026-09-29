<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/**
 * Registo de auditoria (imutável). Legado: audit_logs. Tabela particionada por ano.
 * A empresa é opcional (ex.: autenticação acontece antes de escolher empresa).
 */
class LogAuditoria extends ModeloBase
{
    use PertenceEmpresa;

    /** A data do evento é `ocorrido_em`, preenchida explicitamente. */
    public $timestamps = false;

    protected bool $empresaOpcional = true;

    protected $table = 'logs_auditoria';

    protected $fillable = [
        'empresa_id', 'utilizador_id', 'nome_utilizador', 'ocorrido_em', 'modulo', 'acao', 'tabela',
        'registo_id', 'detalhes', 'dados_anteriores', 'dados_novos', 'endereco_ip', 'agente_utilizador',
    ];

    protected function casts(): array
    {
        return [
            'ocorrido_em' => 'datetime',
            'dados_anteriores' => 'array',
            'dados_novos' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Registos de auditoria não se alteram nem se apagam pela aplicação.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
