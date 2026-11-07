<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * contratos_arrendamento — contrato de arrendamento (a empresa é senhoria), de subarrendamento ou tomado (a empresa é inquilina).
 * Tabela nova (migração 2026_12_17_090000), escrita à mão. O estado muda por ServicoContratos (activar, suspender, terminar…),
 * que deixa trilho em historico_contrato_imo.
 */
class ContratoArrendamento extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const TIPOS = ['ARRENDAMENTO', 'SUBARRENDAMENTO', 'TOMADA'];

    public const ESTADOS = ['RASCUNHO', 'ACTIVO', 'SUSPENSO', 'TERMINADO', 'DENUNCIADO'];

    /** Estados em que o contrato ocupa as fracções (conflitos de datas). */
    public const ESTADOS_VIVOS = ['RASCUNHO', 'ACTIVO', 'SUSPENSO'];

    protected $table = 'contratos_arrendamento';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'numero', 'tipo', 'posicao', 'estado', 'ambito', 'imovel_id', 'fracao_id', 'terceiro_id', 'contrato_pai_id', 'permite_sub',
        'finalidade_fiscal', 'inicio', 'fim', 'fim_real', 'motivo_saida', 'periodicidade', 'antecipada', 'dia_vencimento', 'renda', 'pro_rata', 'actualizacao',
        'actualizacao_valor', 'actualizacao_meses', 'actualizacao_base', 'ultima_actualizacao', 'renda_anterior', 'caucao', 'caucao_recebida', 'caucao_devolvida',
        'caucao_retida', 'retencao_ip', 'isencao_ip', 'selo_encargo', 'doc_tipo', 'renovacao', 'renovacao_meses', 'aviso_dias', 'conta_custo', 'conta_retencao',
        'conta_financeira', 'mora_mensal', 'registo_agt_data', 'outra_parte_morada', 'outra_parte_doc', 'outra_parte_contacto', 'documentos', 'notas', 'criado_por',
        'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'imovel_id' => 'integer', 'fracao_id' => 'integer', 'terceiro_id' => 'integer', 'contrato_pai_id' => 'integer',
            'permite_sub' => 'boolean', 'antecipada' => 'boolean', 'pro_rata' => 'boolean', 'retencao_ip' => 'boolean', 'dia_vencimento' => 'integer',
            'actualizacao_meses' => 'integer', 'renovacao_meses' => 'integer', 'aviso_dias' => 'integer', 'renda' => 'float', 'renda_anterior' => 'float',
            'actualizacao_valor' => 'float', 'caucao' => 'float', 'caucao_recebida' => 'float', 'caucao_devolvida' => 'float', 'caucao_retida' => 'float',
            'mora_mensal' => 'float', 'inicio' => 'date:Y-m-d', 'fim' => 'date:Y-m-d', 'fim_real' => 'date:Y-m-d', 'actualizacao_base' => 'date:Y-m-d',
            'ultima_actualizacao' => 'date:Y-m-d', 'registo_agt_data' => 'date:Y-m-d', 'documentos' => 'array'];
    }

    public function posicaoDoTipo(): string
    {
        return $this->tipo === 'TOMADA' ? 'INQUILINO' : 'SENHORIO';
    }
}
