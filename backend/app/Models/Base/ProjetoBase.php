<?php

namespace App\Models\Base;

use App\Models\AditamentoAlteracaoProjeto;
use App\Models\AfetacaoAtivoProjeto;
use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ConfiguracaoProjeto;
use App\Models\DocumentoProjeto;
use App\Models\DocumentoTesouraria;
use App\Models\EncomendaCompra;
use App\Models\EquipaProjeto;
use App\Models\FaturacaoProjeto;
use App\Models\FaturaCompra;
use App\Models\FolhaHorasProjeto;
use App\Models\GuiaSaida;
use App\Models\ItemAcrescimoDiferimento;
use App\Models\ItemCompra;
use App\Models\ItemDocumentoTesouraria;
use App\Models\ItemGuiaSaida;
use App\Models\ItemVenda;
use App\Models\LancamentoContabil;
use App\Models\LancamentoEstornado;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\LogAtividadeProjeto;
use App\Models\MarcoProjeto;
use App\Models\ModeloBase;
use App\Models\MovimentoInventario;
use App\Models\NoOrganigramaProjeto;
use App\Models\OrcamentoAnual;
use App\Models\PedidoCompra;
use App\Models\PrevisaoOrcamental;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\ReciboVenda;
use App\Models\RequisicaoMaterialProjeto;
use App\Models\RevisaoMensalProjeto;
use App\Models\TarefaProjeto;
use App\Models\Terceiro;
use App\Models\TransferenciaCentroCustoAtivo;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela projetos (módulo Projectos). Legado: projects · 5 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\Projeto.
 */
abstract class ProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'projetos';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'codigo', 'nome', 'estado', 'tipo', 'unidade_negocio_id', 'centro_custo_id', 'cliente_id', 'encomenda_venda_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'cliente_id' => 'integer',
            'encomenda_venda_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'cliente_id');
    }

    public function encomendaVenda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'encomenda_venda_id');
    }

    public function lancamentosContabeis(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'projeto_id');
    }

    public function lancamentosEstornados(): HasMany
    {
        return $this->hasMany(LancamentoEstornado::class, 'projeto_id');
    }

    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class, 'projeto_id');
    }

    public function itensVenda(): HasMany
    {
        return $this->hasMany(ItemVenda::class, 'projeto_id');
    }

    public function recibosVenda(): HasMany
    {
        return $this->hasMany(ReciboVenda::class, 'projeto_id');
    }

    public function pedidosCompra(): HasMany
    {
        return $this->hasMany(PedidoCompra::class, 'projeto_id');
    }

    public function encomendasCompra(): HasMany
    {
        return $this->hasMany(EncomendaCompra::class, 'projeto_id');
    }

    public function faturasCompra(): HasMany
    {
        return $this->hasMany(FaturaCompra::class, 'projeto_id');
    }

    public function itensCompra(): HasMany
    {
        return $this->hasMany(ItemCompra::class, 'projeto_id');
    }

    public function movimentosInventario(): HasMany
    {
        return $this->hasMany(MovimentoInventario::class, 'projeto_id');
    }

    public function guiasSaida(): HasMany
    {
        return $this->hasMany(GuiaSaida::class, 'projeto_id');
    }

    public function itensGuiaSaida(): HasMany
    {
        return $this->hasMany(ItemGuiaSaida::class, 'projeto_id');
    }

    public function documentosTesouraria(): HasMany
    {
        return $this->hasMany(DocumentoTesouraria::class, 'projeto_id');
    }

    public function itensDocumentoTesouraria(): HasMany
    {
        return $this->hasMany(ItemDocumentoTesouraria::class, 'projeto_id');
    }

    public function transferenciasCentrosCustoAtivos(): HasMany
    {
        return $this->hasMany(TransferenciaCentroCustoAtivo::class, 'projeto_id');
    }

    public function marcosProjeto(): HasMany
    {
        return $this->hasMany(MarcoProjeto::class, 'projeto_id');
    }

    public function tarefasProjeto(): HasMany
    {
        return $this->hasMany(TarefaProjeto::class, 'projeto_id');
    }

    public function equipasProjeto(): HasMany
    {
        return $this->hasMany(EquipaProjeto::class, 'projeto_id');
    }

    public function requisicoesMaterialProjeto(): HasMany
    {
        return $this->hasMany(RequisicaoMaterialProjeto::class, 'projeto_id');
    }

    public function linhasOrcamentoProjeto(): HasMany
    {
        return $this->hasMany(LinhaOrcamentoProjeto::class, 'projeto_id');
    }

    public function razaoAnaliticoProjetos(): HasMany
    {
        return $this->hasMany(RazaoAnaliticoProjeto::class, 'projeto_id');
    }

    public function folhasHorasProjeto(): HasMany
    {
        return $this->hasMany(FolhaHorasProjeto::class, 'projeto_id');
    }

    public function faturacaoProjetos(): HasMany
    {
        return $this->hasMany(FaturacaoProjeto::class, 'projeto_id');
    }

    public function afetacoesAtivosProjeto(): HasMany
    {
        return $this->hasMany(AfetacaoAtivoProjeto::class, 'projeto_id');
    }

    public function aditamentosAlteracoesProjeto(): HasMany
    {
        return $this->hasMany(AditamentoAlteracaoProjeto::class, 'projeto_id');
    }

    public function documentosProjeto(): HasMany
    {
        return $this->hasMany(DocumentoProjeto::class, 'projeto_id');
    }

    public function logsAtividadesProjeto(): HasMany
    {
        return $this->hasMany(LogAtividadeProjeto::class, 'projeto_id');
    }

    public function configuracoesProjetos(): HasMany
    {
        return $this->hasMany(ConfiguracaoProjeto::class, 'projeto_id');
    }

    public function revisoesMensaisProjeto(): HasMany
    {
        return $this->hasMany(RevisaoMensalProjeto::class, 'projeto_id');
    }

    public function nosOrganigramaProjeto(): HasMany
    {
        return $this->hasMany(NoOrganigramaProjeto::class, 'projeto_id');
    }

    public function orcamentosAnuais(): HasMany
    {
        return $this->hasMany(OrcamentoAnual::class, 'projeto_id');
    }

    public function previsoesOrcamentais(): HasMany
    {
        return $this->hasMany(PrevisaoOrcamental::class, 'projeto_id');
    }

    public function itensAcrescimosDiferimentos(): HasMany
    {
        return $this->hasMany(ItemAcrescimoDiferimento::class, 'projeto_id');
    }
}
