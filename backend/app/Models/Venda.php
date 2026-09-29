<?php

namespace App\Models;

use App\Exceptions\ErroNegocio;
use App\Models\Base\VendaBase;

/**
 * vendas — documentos comerciais: FT, FR, NC (fiscais, AGT) e OR, PF, NE, GR, GD (não fiscais). /api/vendas/documentos.
 *
 * Selagem (paridade com js/db_v2.js:861-906, alargada a todos os documentos fiscais): depois de selado
 * (fe_selado_em), um FT/FR/NC não pode mudar os campos fiscais nem ser eliminado — corrige-se com nota de crédito.
 */
class Venda extends VendaBase
{
    public const FISCAIS = ['FT', 'FR', 'NC'];

    public const NAO_FISCAIS = ['OR', 'PF', 'NE', 'GR', 'GD'];

    /** Campos imutáveis de um documento selado (db_v2.js:874). */
    public const CAMPOS_SELADOS = [
        'empresa_id', 'tipo_documento', 'numero_documento', 'data_emissao', 'cliente_id', 'total_liquido', 'total_imposto', 'total_bruto',
        'codigo_moeda', 'taxa_cambio', 'total_liquido_moeda', 'total_imposto_moeda', 'total_bruto_moeda', 'desconto', 'motivo_nota_credito',
        'fe_regime', 'fe_tipo', 'serie_faturacao_eletronica_id', 'fe_serie', 'fe_numero', 'fe_data_entrada_sistema', 'fe_selado_em', 'fe_documento', 'saft_hash', 'saft_hash_controlo',
    ];

    /**
     * Só ServicoSelagemAgt::revalidar liga isto: refaz o documento electrónico (fe_documento) de um documento
     * selado depois de corrigidos os dados de origem; os restantes campos fiscais continuam imutáveis.
     */
    public bool $permitirRevalidacao = false;

    protected static function booted(): void
    {
        static::updating(function (Venda $v) {
            $selados = $v->permitirRevalidacao ? array_diff(self::CAMPOS_SELADOS, ['fe_documento']) : self::CAMPOS_SELADOS;
            if ($v->getOriginal('fe_selado_em') !== null && $v->isDirty($selados)) {
                throw new ErroNegocio('Documento fiscal selado: não pode ser alterado. Emita uma nota de crédito.', 'DOCUMENTO_SELADO', 422,
                    ['campos' => array_keys(array_intersect_key($v->getDirty(), array_flip($selados)))]);
            }
            if ($v->getOriginal('fe_selado_em') !== null && $v->estado === 'ANULADO') {
                throw new ErroNegocio('Um documento fiscal selado não se anula: emita uma nota de crédito.', 'DOCUMENTO_SELADO', 422);
            }
        });
        static::deleting(function (Venda $v) {
            if ($v->fe_selado_em !== null || in_array($v->tipo_documento, self::FISCAIS, true)) {
                throw new ErroNegocio('Documentos fiscais não se eliminam: emita uma nota de crédito.', 'DOCUMENTO_SELADO', 422);
            }
        });
    }

    public function eFiscal(): bool
    {
        return in_array($this->tipo_documento, self::FISCAIS, true);
    }
}
