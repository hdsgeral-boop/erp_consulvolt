/**
 * Segundo passo do login com MFA (autenticação de dois factores). O servidor responde ao 1.º passo (utilizador +
 * palavra-passe certos) com `mfa_pendente` e um token LIMITADO, válido 5 minutos, que só serve para
 * POST /autenticacao/mfa/verificar. Esse token vive só na memória do ecrã (nunca no armazenamento do navegador).
 */
export class ErroMfaPendente extends Error {
  constructor(public readonly tokenMfa: string) {
    super('Introduza o código da aplicação de autenticação.');
    this.name = 'ErroMfaPendente';
  }
}
