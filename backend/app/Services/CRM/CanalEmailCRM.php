<?php

namespace App\Services\CRM;

/**
 * Ponto de extensão do envio de emails do CRM. Por decisão do utilizador (crm_dados.js:6) o ERP não guarda
 * palavras-passe de email: a implementação por omissão (CanalEmailCRMRegisto) não envia nada — devolve a ligação
 * mailto: para o programa de email do utilizador e o CRM regista a actividade. Um envio real (SMTP, API de um fornecedor)
 * implementa esta interface e é registado no contentor: `$this->app->bind(CanalEmailCRM::class, CanalEmailCRMSmtp::class)`.
 */
interface CanalEmailCRM
{
    /**
     * @param  array{para: string, bcc?: ?string, assunto: string, corpo: string}  $mensagem
     * @return array{estado: string, resultado: string, mailto: ?string} estado: ABERTO_NO_CLIENTE | ENVIADO | FALHOU
     */
    public function enviar(array $mensagem): array;
}
