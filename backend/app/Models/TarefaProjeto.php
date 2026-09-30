<?php

namespace App\Models;

use App\Models\Base\TarefaProjetoBase;

/**
 * tarefas_projeto — /api/projetos/{projeto}/tarefas.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em TarefaProjetoBase (gerado).
 *
 * `estado` é o código (PENDENTE, EM_CURSO, CONCLUIDA, BLOQUEADA); `estado_original` guarda a coluna do Kanban em que a
 * tarefa está (no legado o estado ERA o id da coluna, que podia ser personalizada — ex.: FAZENDO).
 */
class TarefaProjeto extends TarefaProjetoBase
{
    public const ESTADOS = ['PENDENTE', 'EM_CURSO', 'CONCLUIDA', 'BLOQUEADA'];

    /** Estado efectivo: o código, ou o texto original quando o código não pôde ser gravado (BLOQUEADA, ver ServicoPlaneamentoProjetos). */
    public function estadoEfetivo(): string
    {
        if ($this->estado) {
            return $this->estado;
        }

        return in_array($this->estado_original, self::ESTADOS, true) ? $this->estado_original : 'PENDENTE';
    }
}
