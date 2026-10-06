<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela utilizacoes_assistente_ia (módulo Contabilidade). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\UtilizacaoAssistenteIA.
 */
abstract class UtilizacaoAssistenteIABase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'utilizacoes_assistente_ia';

    protected string $moduloAuditoria = 'Contabilidade';

    protected $fillable = [
        'empresa_id', 'utilizador_id', 'nome_utilizador', 'motor', 'modelo', 'estado', 'codigo_erro', 'propostas', 'caracteres_texto', 'tipo_ficheiro', 'tamanho_ficheiro_kb', 'tokens_entrada', 'tokens_saida', 'custo_estimado_usd', 'duracao_ms',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'utilizador_id' => 'integer',
            'propostas' => 'integer',
            'caracteres_texto' => 'integer',
            'tamanho_ficheiro_kb' => 'integer',
            'tokens_entrada' => 'integer',
            'tokens_saida' => 'integer',
            'custo_estimado_usd' => 'decimal:6',
            'duracao_ms' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
