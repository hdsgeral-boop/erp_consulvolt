<?php

namespace App\Models;

use App\Models\Base\AssuntoMetodoTrabalhoBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * assuntos_metodo_trabalho — /api/metodo/assuntos (Fase G, Método de Trabalho 5W1H).
 * Regras de negócio em App\Services\Metodo\ServicoAssuntosMetodo; a estrutura está em AssuntoMetodoTrabalhoBase (gerado).
 * As colunas da migration 2026_10_14_600100 (pedido_compra_id, centro_custo_id, encomenda_venda_id, linhas_pedido_compra,
 * concluido_*, cancelado_*, motivo_cancelamento) fazem parte do esquema gerado (glossario.mjs / COLUNAS_NOVAS); a migration
 * só as cria numa base anterior à regeneração.
 */
class AssuntoMetodoTrabalho extends AssuntoMetodoTrabalhoBase
{
    public const ESTADOS = ['RASCUNHO', 'EM_CURSO', 'CONCLUIDO', 'CANCELADO'];

    public const TRANSICOES = ['RASCUNHO' => ['EM_CURSO', 'CANCELADO'], 'EM_CURSO' => ['CONCLUIDO', 'CANCELADO'], 'CONCLUIDO' => [], 'CANCELADO' => ['RASCUNHO']];

    public const TIPOS_RESPONSAVEL = ['COLABORADOR', 'EXTERNO', 'FORNECEDOR'];

    public const LOCAIS = ['INTERNO', 'EXTERNO'];

    public const METODOS = ['ACCAO', 'PROJECTO'];

    public const MODOS_PROJETO = ['NOVO', 'EXISTENTE'];

    protected function casts(): array
    {
        return parent::casts() + [
            'pedido_compra_id' => 'integer',
            'centro_custo_id' => 'integer',
            'encomenda_venda_id' => 'integer',
            'linhas_pedido_compra' => 'array',
            'concluido_em' => 'datetime',
            'cancelado_em' => 'datetime',
        ];
    }

    public function pedidoCompra(): BelongsTo
    {
        return $this->belongsTo(PedidoCompra::class, 'pedido_compra_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function encomendaVenda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'encomenda_venda_id');
    }

    /** Chave do responsável «TIPO:ref» (ou «TIPO:nome» sem referência) — chaveResp do legado (:100). */
    public static function chaveResponsavel(array $r): string
    {
        $ref = $r['ref_id'] ?? null;

        return ($r['tipo'] ?? '').':'.($ref !== null && $ref !== '' ? $ref : trim((string) ($r['nome'] ?? '')));
    }

    /** Nome do responsável principal (nomePrincipal, :466-469). */
    public function nomePrincipal(): string
    {
        foreach ((array) $this->responsaveis as $r) {
            if (self::chaveResponsavel((array) $r) === (string) $this->responsavel_principal) {
                return (string) ($r['nome'] ?? '');
            }
        }
        $partes = explode(':', (string) $this->responsavel_principal, 2);

        return $partes[1] ?? '';
    }
}
