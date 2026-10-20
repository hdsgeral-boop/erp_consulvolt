<?php

namespace App\Models;

use App\Services\Sistema\ServicoEmpresas;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Ligação utilizador ↔ empresa (e colaborador associado nessa empresa).
 * Qualquer alteração invalida a cache das empresas acessíveis do utilizador.
 */
class UtilizadorEmpresa extends Pivot
{
    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $table = 'utilizador_empresa';

    /**
     * O AsPivot herda as colunas de data do modelo pai (Utilizador: atualizado_em), que esta tabela não tem: ao alterar o
     * colaborador de uma ligação existente o UPDATE incluía "atualizado_em" e falhava (500 ao editar um utilizador).
     */
    public function getCreatedAtColumn()
    {
        return self::CREATED_AT;
    }

    public function getUpdatedAtColumn()
    {
        return null;
    }

    protected static function booted(): void
    {
        $invalidar = fn (UtilizadorEmpresa $p) => app(ServicoEmpresas::class)->invalidarUtilizador((int) $p->utilizador_id);
        static::saved($invalidar);
        static::deleted($invalidar);
    }
}
