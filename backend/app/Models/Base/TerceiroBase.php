<?php

namespace App\Models\Base;

use App\Models\AbateVendaAtivo;
use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ContaCRM;
use App\Models\ContratoFornecedor;
use App\Models\CotacaoCompra;
use App\Models\EncomendaCompra;
use App\Models\EstadiaHotel;
use App\Models\FaturaCompra;
use App\Models\GuiaSaida;
use App\Models\ItemAcrescimoDiferimento;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LancamentoContabil;
use App\Models\LancamentoEstornado;
use App\Models\LinhaRevisaoProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\ModeloBase;
use App\Models\MovimentoCaixa;
use App\Models\MovimentoInventario;
use App\Models\PagamentoLavandaria;
use App\Models\PedidoLavandaria;
use App\Models\Projeto;
use App\Models\ReciboVenda;
use App\Models\ReclamacaoLavandaria;
use App\Models\TerminalPOS;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela terceiros (módulo Terceiros). Legado: third_parties · 6545 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\Terceiro.
 */
abstract class TerceiroBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'terceiros';

    protected string $moduloAuditoria = 'Terceiros';

    protected $fillable = [
        'empresa_id', 'nif', 'nome', 'tipo', 'tipo_original', 'endereco', 'codigo_conta', 'conta_compra_transitoria', 'email', 'codigo_moeda', 'telefone', 'fe_pais',
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
        return $this->hasMany(LancamentoContabil::class, 'terceiro_id');
    }

    public function lancamentosEstornados(): HasMany
    {
        return $this->hasMany(LancamentoEstornado::class, 'terceiro_id');
    }

    public function vendasPorCliente(): HasMany
    {
        return $this->hasMany(Venda::class, 'cliente_id');
    }

    public function vendasPorTerceiro(): HasMany
    {
        return $this->hasMany(Venda::class, 'terceiro_id');
    }

    public function recibosVenda(): HasMany
    {
        return $this->hasMany(ReciboVenda::class, 'cliente_id');
    }

    public function cotacoesCompra(): HasMany
    {
        return $this->hasMany(CotacaoCompra::class, 'fornecedor_id');
    }

    public function encomendasCompra(): HasMany
    {
        return $this->hasMany(EncomendaCompra::class, 'fornecedor_id');
    }

    public function faturasCompra(): HasMany
    {
        return $this->hasMany(FaturaCompra::class, 'fornecedor_id');
    }

    public function contratosFornecedores(): HasMany
    {
        return $this->hasMany(ContratoFornecedor::class, 'fornecedor_id');
    }

    public function movimentosInventario(): HasMany
    {
        return $this->hasMany(MovimentoInventario::class, 'terceiro_id');
    }

    public function guiasSaida(): HasMany
    {
        return $this->hasMany(GuiaSaida::class, 'terceiro_id');
    }

    public function itensDocumentoTesouraria(): HasMany
    {
        return $this->hasMany(ItemDocumentoTesouraria::class, 'terceiro_id');
    }

    public function movimentosCaixa(): HasMany
    {
        return $this->hasMany(MovimentoCaixa::class, 'terceiro_id');
    }

    public function terminaisPos(): HasMany
    {
        return $this->hasMany(TerminalPOS::class, 'cliente_padrao_id');
    }

    public function estadiasHotel(): HasMany
    {
        return $this->hasMany(EstadiaHotel::class, 'cliente_hospede_id');
    }

    public function pedidosLavandaria(): HasMany
    {
        return $this->hasMany(PedidoLavandaria::class, 'cliente_id');
    }

    public function pagamentosLavandaria(): HasMany
    {
        return $this->hasMany(PagamentoLavandaria::class, 'cliente_id');
    }

    public function reclamacoesLavandaria(): HasMany
    {
        return $this->hasMany(ReclamacaoLavandaria::class, 'cliente_id');
    }

    public function ativosImobilizados(): HasMany
    {
        return $this->hasMany(AtivoImobilizado::class, 'fornecedor_id');
    }

    public function abatesVendasAtivos(): HasMany
    {
        return $this->hasMany(AbateVendaAtivo::class, 'terceiro_id');
    }

    public function projetos(): HasMany
    {
        return $this->hasMany(Projeto::class, 'cliente_id');
    }

    public function membrosEquipaProjeto(): HasMany
    {
        return $this->hasMany(MembroEquipaProjeto::class, 'terceiro_id');
    }

    public function linhasRevisaoProjeto(): HasMany
    {
        return $this->hasMany(LinhaRevisaoProjeto::class, 'terceiro_id');
    }

    public function itensAcrescimosDiferimentos(): HasMany
    {
        return $this->hasMany(ItemAcrescimoDiferimento::class, 'terceiro_id');
    }

    public function contasCrm(): HasMany
    {
        return $this->hasMany(ContaCRM::class, 'terceiro_id');
    }
}
