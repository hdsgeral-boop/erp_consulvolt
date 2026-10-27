<?php

namespace App\Models;

use App\Models\Base\TarefaProjetoBase;

/**
 * tarefas_projeto — /api/projetos/{projeto}/tarefas.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em TarefaProjetoBase (gerado).
 *
 * `estado` é o código (PENDENTE, EM_CURSO, CONCLUIDA, BLOQUEADA); `estado_original` guarda a coluna do Kanban em que a
 * tarefa está (no legado o estado ERA o id da coluna, que podia ser personalizada — ex.: FAZENDO).
 *
 * Fase E (Versão Actual): meta por quantidade (meta_unidade, meta_quantidade, meta_realizado), histórico das medições
 * em `meta_execucoes` = [{q, data, nota, por, em}] (projectos_metas.js:372-393), dependência da tarefa predecessora
 * (FS/SS/FF + desfasamento, projectos_hierarquia.js:35-41) e data de conclusão `concluida_em`.
 */
class TarefaProjeto extends TarefaProjetoBase
{
    public const ESTADOS = ['PENDENTE', 'EM_CURSO', 'CONCLUIDA', 'BLOQUEADA'];

    public const TIPOS_DEPENDENCIA = ['FS', 'SS', 'FF'];

    /** Bandeira de atenção manual (tarefas e subtarefas, mesma tabela): marcação do utilizador, não calculada. */
    public const FLAGS = ['verde', 'amarelo', 'vermelho', 'preto'];

    /** Estado efectivo: o código, ou o texto original quando o código não pôde ser gravado (BLOQUEADA, ver ServicoPlaneamentoProjetos). */
    public function estadoEfetivo(): string
    {
        if ($this->estado) {
            return $this->estado;
        }

        return in_array($this->estado_original, self::ESTADOS, true) ? $this->estado_original : 'PENDENTE';
    }

    /** Tem meta por quantidade (temMeta, projectos_metas.js:45). */
    public function temMeta(): bool
    {
        return (float) $this->meta_quantidade > 0;
    }

    /** % de execução pela meta: clamp(round(realizado ÷ meta × 100), 0, 100) (pctDaMeta, projectos_metas.js:46). */
    public function pctDaMeta(): ?int
    {
        return $this->temMeta() ? max(0, min(100, (int) round((float) $this->meta_realizado / (float) $this->meta_quantidade * 100))) : null;
    }

    public function unidadeMeta(): string
    {
        return trim((string) $this->meta_unidade) ?: 'un';
    }

    /** @return list<array{q: float, data: string, nota: string, por: string, em: string}> */
    public function medicoes(): array
    {
        return array_values(array_filter((array) ($this->meta_execucoes ?? []), 'is_array'));
    }
}
