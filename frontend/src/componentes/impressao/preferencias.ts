import type { OpcoesDocumento, Orientacao, Papel, PreferenciaPagina } from './tipos';

/**
 * Preferência de página escolhida pelo utilizador no botão de impressão («Automática», «Vertical», «Horizontal» e o
 * papel A4/A3), lembrada por documento no armazenamento local do navegador (só uma conveniência: sem ela, ou se o
 * armazenamento estiver bloqueado, volta-se à escolha automática do motor).
 */

const PREFIXO = 'erp.impressao.pagina:';
export const PREFERENCIA_AUTOMATICA: PreferenciaPagina = { orientacao: 'auto', papel: 'auto' };

const ORIENTACOES: Orientacao[] = ['auto', 'retrato', 'paisagem'];
const PAPEIS: Papel[] = ['auto', 'A4', 'A3'];

/**
 * Chave estável de um documento: sem números/identificadores (o recibo «RC 2026/15» e o «RC 2026/16» partilham a
 * preferência), em minúsculas e sem acentos.
 */
export function chaveDocumento(base: string): string {
  return base
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .split(/[\s/|]+/)
    .filter((p) => p && !/\d/.test(p) && p !== '#')
    .join(' ')
    .replace(/[^a-z0-9 ()._-]/g, '')
    .trim()
    .slice(0, 120);
}

/** Chave por omissão de um botão: o caminho do ecrã (sem identificadores numéricos) e o texto do botão. */
export function chavePorOmissao(caminho: string, texto?: string): string {
  const ecra = caminho.split('/').filter((s) => s && !/^\d+$/.test(s)).join('/');
  return chaveDocumento(`${ecra}|${texto ?? ''}`) || 'documento';
}

function armazenamento(): Storage | null {
  try {
    return typeof window !== 'undefined' ? window.localStorage : null;
  } catch {
    return null;
  }
}

export function lerPreferencia(chave: string): PreferenciaPagina {
  try {
    const bruto = armazenamento()?.getItem(PREFIXO + chave);
    if (!bruto) return PREFERENCIA_AUTOMATICA;
    const v = JSON.parse(bruto) as Partial<PreferenciaPagina>;
    return {
      orientacao: ORIENTACOES.includes(v.orientacao as Orientacao) ? (v.orientacao as Orientacao) : 'auto',
      papel: PAPEIS.includes(v.papel as Papel) ? (v.papel as Papel) : 'auto',
    };
  } catch {
    return PREFERENCIA_AUTOMATICA;
  }
}

export function gravarPreferencia(chave: string, p: PreferenciaPagina): void {
  try {
    const a = armazenamento();
    if (!a) return;
    if (p.orientacao === 'auto' && p.papel === 'auto') a.removeItem(PREFIXO + chave);
    else a.setItem(PREFIXO + chave, JSON.stringify(p));
  } catch {
    /* armazenamento indisponível (janela privada, bloqueado): fica só para esta sessão do ecrã */
  }
}

/**
 * Aplica a escolha do utilizador ao pedido: «Automática» mantém o que o ecrã pediu (ou a decisão do motor); «Vertical»
 * e «Horizontal» impõem a orientação (o papel do ecrã mantém-se, salvo escolha explícita de A4/A3).
 */
export function aplicarPreferencia<T extends Pick<OpcoesDocumento, 'orientacao' | 'papel'>>(pedido: T, p: PreferenciaPagina): T {
  const r = { ...pedido };
  if (p.orientacao !== 'auto') r.orientacao = p.orientacao;
  if (p.papel !== 'auto') r.papel = p.papel;
  return r;
}

export const ROTULOS_ORIENTACAO: Record<Orientacao, string> = { auto: 'Automática', retrato: 'Vertical', paisagem: 'Horizontal' };
export const ROTULOS_PAPEL: Record<Papel, string> = { auto: 'Papel automático', A4: 'A4', A3: 'A3' };

/** Texto curto do botão (ex.: «Horizontal · A3»). */
export function descreverPreferencia(p: PreferenciaPagina): string {
  return `${ROTULOS_ORIENTACAO[p.orientacao]}${p.papel !== 'auto' ? ` · ${p.papel}` : ''}`;
}
