<?php

namespace App\Models;

use App\Models\Base\FolhaHorasProjetoBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * folhas_horas_projeto — /api/projectos/timesheets.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em FolhaHorasProjetoBase (gerado).
 *
 * Fase E (Versão Actual): `membro_equipa_projeto_id` (legado novo: team_member_id, ui_projects.js:5068-5125) faz parte
 * do esquema gerado (a migração 2026_10_14_200100_fe_projectos_metas_execucao só a cria numa base anterior à regeneração).
 * O colaborador só é preenchido quando o membro é interno: as horas de uma subempreitada ou de um externo contam para
 * a carga e para o mapa de horas, mas nunca para o subsídio ou para a imputação salarial de ninguém.
 */
class FolhaHorasProjeto extends FolhaHorasProjetoBase
{
    protected function casts(): array
    {
        return parent::casts() + ['membro_equipa_projeto_id' => 'integer'];
    }

    public function membroEquipaProjeto(): BelongsTo
    {
        return $this->belongsTo(MembroEquipaProjeto::class, 'membro_equipa_projeto_id');
    }
}
