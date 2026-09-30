<?php

namespace App\Services\CRM;

/** Canal por omissão: não envia — prepara a ligação mailto: (paridade com CRM.mailto, crm_dados.js:353). */
final class CanalEmailCRMRegisto implements CanalEmailCRM
{
    public function enviar(array $mensagem): array
    {
        $bcc = ! empty($mensagem['bcc']) ? 'bcc='.rawurlencode($mensagem['bcc']).'&' : '';

        return ['estado' => 'ABERTO_NO_CLIENTE', 'resultado' => 'Aberto no programa de email',
            'mailto' => 'mailto:'.rawurlencode($mensagem['para'] ?? '').'?'.$bcc.'subject='.rawurlencode($mensagem['assunto'] ?? '').'&body='.rawurlencode($mensagem['corpo'] ?? '')];
    }
}
