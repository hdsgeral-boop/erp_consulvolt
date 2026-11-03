<?php

namespace App\Models;

use App\Models\Base\ExecucaoEntregavelBase;

/**
 * execucoes_entregavel — entregas registadas num entregável (Fase J).
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ExecucaoEntregavelBase.
 */
class ExecucaoEntregavel extends ExecucaoEntregavelBase
{
    public const ORIGENS = ['MANUAL', 'EXCEL', 'PORTAL', 'CRM'];

    public const VALIDACOES = ['PENDENTE', 'VALIDADA', 'DEVOLVIDA', 'RECUSADA'];

    /** Rótulos da validação (VALIDACAO, estrutura_tarefas.js:808). */
    public const ROTULOS_VALIDACAO = ['PENDENTE' => 'Por validar', 'VALIDADA' => 'Validada', 'DEVOLVIDA' => 'Devolvida para correcção', 'RECUSADA' => 'Recusada'];

    /** Recusada e devolvida não são entrega aceite: não entram no realizado, na avaliação nem nos atrasos. */
    public function contaComoEntregue(): bool
    {
        return in_array($this->validacao, ['PENDENTE', 'VALIDADA'], true);
    }

    public function convertida(): bool
    {
        return $this->periodo_produtividade_id !== null;
    }
}
