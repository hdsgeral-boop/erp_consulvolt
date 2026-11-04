/**
 * Quem vê o botão do assistente de IA no layout principal.
 *
 * ASSUNÇÃO: na produção o chat não exige permissão de ecrã — o controller diz «qualquer utilizador de um cliente com a
 * IA activa pode conversar» e cada ferramenta valida a permissão do ecrã correspondente no servidor. Por isso a
 * constante fica a `null` (sem chave); a disponibilidade vem do `GET /ia/estado` (`ativo`). Se o backend vier a
 * definir uma permissão própria (ex.: 'ia_chat'), basta pôr aqui a chave: o layout passa a exigir `pode(chave)`.
 * Este é o ÚNICO sítio onde a chave existe.
 */
export const PERMISSAO_ASSISTENTE_CHAT: string | null = null;

/** O utilizador pode ver o assistente? (`pode` é o da sessão). */
export function podeVerAssistente(pode: ((...chaves: string[]) => boolean) | undefined): boolean {
  if (PERMISSAO_ASSISTENTE_CHAT === null) return true;
  return !!pode?.(PERMISSAO_ASSISTENTE_CHAT);
}
