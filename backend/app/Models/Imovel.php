<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * imoveis — cadastro de imóveis do módulo Gestão Imobiliária (próprios, arrendados a terceiros ou em mediação).
 * Tabela nova (migração 2026_12_15_090000), escrita à mão como as do POS; não vem do dicionário legado.
 */
class Imovel extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const PAPEIS = ['PROPRIO', 'ARRENDADO', 'MEDIACAO'];

    public const ESTADOS = ['ACTIVO', 'INACTIVO', 'ALIENADO'];

    protected $table = 'imoveis';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'codigo', 'nome', 'tipo', 'papel', 'n_pisos', 'piso_inicial', 'n_habitacoes', 'proprietario_terceiro_id', 'ativo_edificio_id',
        'morada', 'municipio', 'provincia', 'coordenadas', 'matriz_predial', 'registo_predial', 'licenca_utilizacao', 'area_terreno', 'area_bruta', 'area_util',
        'valor_patrimonial', 'valor_patrimonial_data', 'isencao_detencao', 'valor_aquisicao', 'data_aquisicao', 'estado', 'documentos', 'notas', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'n_pisos' => 'integer', 'piso_inicial' => 'integer', 'n_habitacoes' => 'integer', 'proprietario_terceiro_id' => 'integer',
            'ativo_edificio_id' => 'integer', 'area_terreno' => 'float', 'area_bruta' => 'float', 'area_util' => 'float', 'valor_patrimonial' => 'float',
            'valor_aquisicao' => 'float', 'valor_patrimonial_data' => 'date:Y-m-d', 'data_aquisicao' => 'date:Y-m-d', 'documentos' => 'array'];
    }
}
