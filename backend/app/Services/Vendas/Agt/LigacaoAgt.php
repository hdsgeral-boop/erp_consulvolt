<?php

namespace App\Services\Vendas\Agt;

use OpenSSLAsymmetricKey;

/**
 * Ligação efectiva à AGT de uma empresa (resolvida por ServicoLigacaoAgt): ambiente, endereço, credenciais Basic e
 * chave do contribuinte. `origem` diz de onde vêm: «empresa» (configurada no ERP, cifrada na base) ou «servidor»
 * (variáveis AGT_* e ficheiros PEM — o modo anterior, mantido como recurso).
 */
final class LigacaoAgt
{
    public function __construct(
        public readonly int $empresaId,
        public readonly string $origem,
        public readonly string $ambiente,
        public readonly string $url,
        public readonly ?string $utilizador,
        public readonly ?string $palavraPasse,
        public readonly ?OpenSSLAsymmetricKey $chaveContribuinte,
    ) {}

    public function temCredenciais(): bool
    {
        return (string) $this->utilizador !== '' && (string) $this->palavraPasse !== '';
    }
}
