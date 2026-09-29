<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela enderecos_terceiros (módulo Terceiros). Legado: third_party_addresses · 54 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\EnderecoTerceiro.
 */
abstract class EnderecoTerceiroBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'enderecos_terceiros';

    protected string $moduloAuditoria = 'Terceiros';

    protected $fillable = [
        'empresa_id', 'nif', 'rua', 'numero_porta', 'bairro', 'municipio', 'provincia', 'pais',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
