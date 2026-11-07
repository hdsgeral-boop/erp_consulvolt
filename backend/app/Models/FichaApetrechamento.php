<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** fichas_apetrechamento — ficha-tipo (kit) do que uma fracção de certa tipologia e finalidade deve ter; é contra ela que se mede o que falta. */
class FichaApetrechamento extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'fichas_apetrechamento';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'nome', 'tipologia', 'finalidade', 'activo', 'notas', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'activo' => 'boolean'];
    }
}
