<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * configuracoes_impressao_pos — configuração de impressão do POS por empresa (terminal_pos_id nulo) e sobreposição por
 * posto/terminal (campos nulos herdam da empresa). Tabela criada pela migração 2026_12_12_090000 (F7).
 */
class ConfiguracaoImpressaoPOS extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_impressao_pos';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = ['empresa_id', 'terminal_pos_id', 'formato', 'largura', 'automatico', 'consulta', 'rodape', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'terminal_pos_id' => 'integer', 'largura' => 'integer', 'automatico' => 'boolean'];
    }
}
