<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * agt_ligacoes_empresa — ligação de uma empresa à facturação electrónica da AGT (ronda 4, r4_agt).
 * Os segredos (credenciais, chave privada do contribuinte, segredo do webhook) ficam cifrados com a APP_KEY (cast
 * «encrypted») e estão em $hidden: nunca saem na serialização nem nos registos de auditoria.
 */
class LigacaoAgtEmpresa extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'agt_ligacoes_empresa';

    protected string $moduloAuditoria = 'Vendas';

    protected $fillable = [
        'empresa_id', 'ativo', 'ambiente', 'url_base', 'utilizador', 'palavra_passe', 'credenciais_atualizadas_em', 'credenciais_atualizadas_por',
        'chave_privada', 'chave_publica', 'certificado', 'chave_impressao_digital', 'chave_anterior_impressao_digital', 'chave_bits', 'chave_versao',
        'certificado_valido_de', 'certificado_valido_ate', 'chave_atualizada_em', 'chave_atualizada_por',
        'webhook_ativo', 'webhook_token_hash', 'webhook_segredo', 'webhook_atualizado_em', 'webhook_atualizado_por',
        'ultimo_teste_em', 'ultimo_teste_ok', 'ultimo_teste_mensagem', 'atualizado_por',
    ];

    /** Nunca serializados nem auditados. */
    protected $hidden = ['utilizador', 'palavra_passe', 'chave_privada', 'webhook_segredo', 'webhook_token_hash'];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer', 'ativo' => 'boolean', 'webhook_ativo' => 'boolean', 'ultimo_teste_ok' => 'boolean',
            'chave_bits' => 'integer', 'chave_versao' => 'integer',
            'utilizador' => 'encrypted', 'palavra_passe' => 'encrypted', 'chave_privada' => 'encrypted', 'webhook_segredo' => 'encrypted',
            'credenciais_atualizadas_em' => 'datetime', 'chave_atualizada_em' => 'datetime', 'certificado_valido_de' => 'datetime',
            'certificado_valido_ate' => 'datetime', 'webhook_atualizado_em' => 'datetime', 'ultimo_teste_em' => 'datetime',
            'criado_em' => 'datetime', 'atualizado_em' => 'datetime',
        ];
    }
}
