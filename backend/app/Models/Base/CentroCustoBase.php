<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\CotacaoCompra;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\ItemAcrescimoDiferimento;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LancamentoContabil;
use App\Models\LancamentoEstornado;
use App\Models\ModeloBase;
use App\Models\MovimentoCaixa;
use App\Models\OrcamentoAnual;
use App\Models\PedidoCompra;
use App\Models\PrevisaoOrcamental;
use App\Models\Projeto;
use App\Models\RececaoCompra;
use App\Models\ReciboVenda;
use App\Models\TerminalPOS;
use App\Models\TransferenciaCentroCustoAtivo;
use App\Models\UnidadeOrganica;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela centros_custo (módulo Sistema). Legado: cost_centers · 38 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CentroCusto.
 */
abstract class CentroCustoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'centros_custo';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'empresa_id', 'codigo', 'descricao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function lancamentosContabeis(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'centro_custo_id');
    }

    public function lancamentosEstornados(): HasMany
    {
        return $this->hasMany(LancamentoEstornado::class, 'centro_custo_id');
    }

    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class, 'centro_custo_id');
    }

    public function recibosVenda(): HasMany
    {
        return $this->hasMany(ReciboVenda::class, 'centro_custo_id');
    }

    public function pedidosCompra(): HasMany
    {
        return $this->hasMany(PedidoCompra::class, 'centro_custo_id');
    }

    public function cotacoesCompra(): HasMany
    {
        return $this->hasMany(CotacaoCompra::class, 'centro_custo_id');
    }

    public function encomendasCompra(): HasMany
    {
        return $this->hasMany(EncomendaCompra::class, 'centro_custo_id');
    }

    public function rececoesCompra(): HasMany
    {
        return $this->hasMany(RececaoCompra::class, 'centro_custo_id');
    }

    public function faturasCompra(): HasMany
    {
        return $this->hasMany(FaturaCompra::class, 'centro_custo_id');
    }

    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'centro_custo_id');
    }

    public function unidadesOrganicas(): HasMany
    {
        return $this->hasMany(UnidadeOrganica::class, 'centro_custo_id');
    }

    public function itensDocumentoTesouraria(): HasMany
    {
        return $this->hasMany(ItemDocumentoTesouraria::class, 'centro_custo_id');
    }

    public function movimentosCaixa(): HasMany
    {
        return $this->hasMany(MovimentoCaixa::class, 'centro_custo_id');
    }

    public function terminaisPos(): HasMany
    {
        return $this->hasMany(TerminalPOS::class, 'centro_custo_id');
    }

    public function ativosImobilizados(): HasMany
    {
        return $this->hasMany(AtivoImobilizado::class, 'centro_custo_id');
    }

    public function transferenciasCentrosCustoAtivosPorCentroCustoOrigem(): HasMany
    {
        return $this->hasMany(TransferenciaCentroCustoAtivo::class, 'centro_custo_origem_id');
    }

    public function transferenciasCentrosCustoAtivosPorCentroCustoDestino(): HasMany
    {
        return $this->hasMany(TransferenciaCentroCustoAtivo::class, 'centro_custo_destino_id');
    }

    public function projetos(): HasMany
    {
        return $this->hasMany(Projeto::class, 'centro_custo_id');
    }

    public function orcamentosAnuais(): HasMany
    {
        return $this->hasMany(OrcamentoAnual::class, 'centro_custo_id');
    }

    public function previsoesOrcamentais(): HasMany
    {
        return $this->hasMany(PrevisaoOrcamental::class, 'centro_custo_id');
    }

    public function itensAcrescimosDiferimentos(): HasMany
    {
        return $this->hasMany(ItemAcrescimoDiferimento::class, 'centro_custo_id');
    }
}
