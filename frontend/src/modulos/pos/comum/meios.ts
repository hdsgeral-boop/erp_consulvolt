import type { MeioPagamento, TipoMeio } from './tipos';

/** Meios por omissão de um terminal novo (ServicoTerminaisPOS::meiosPadrao), sem contas. */
export function meiosPadrao(): MeioPagamento[] {
  return [
    { id: 'pm_num', tipo: 'NUMERARIO', nome: 'Numerário', ativo: true, conta_transitoria: null, conta_liquidacao: null },
    { id: 'pm_tpa', tipo: 'TPA', nome: 'Multicaixa (TPA)', ativo: true, conta_transitoria: null, conta_liquidacao: null, codigo_tpa: null, comissao_pct: 0, conta_comissao: null, comissao_deduzida: true },
    { id: 'pm_trf', tipo: 'TRANSFERENCIA', nome: 'Transferência bancária', ativo: true, conta_transitoria: null, conta_liquidacao: null },
  ];
}

/** Classe da conta de liquidação: numerário em caixa (45), TPA e transferência em bancos (43). */
export function classeLiquidacao(tipo: TipoMeio): '45' | '43' {
  return tipo === 'NUMERARIO' ? '45' : '43';
}

/**
 * Validação local dos meios (as mesmas regras de ServicoTerminaisPOS::validarMeios, para avisar antes de gravar):
 * activo com transitória e liquidação; liquidação na classe certa; transitória ≠ liquidação e sem repetição;
 * TPA com comissão exige conta da comissão; pelo menos um meio activo.
 */
export function validarMeios(meios: MeioPagamento[]): string[] {
  const erros: string[] = [];
  const transitorias = new Set<string>();
  meios.forEach((m, n) => {
    const onde = `Meio ${n + 1}${m.nome ? ` (${m.nome})` : ''}:`;
    if (!m.nome?.trim()) erros.push(`${onde} indique o nome.`);
    const pct = Number(m.comissao_pct ?? 0);
    if (pct < 0 || pct >= 100) erros.push(`${onde} percentagem de comissão inválida.`);
    if (!m.ativo) return;
    const t = m.conta_transitoria?.trim();
    const l = m.conta_liquidacao?.trim();
    if (!t || !l) {
      erros.push(`${onde} indique a conta transitória e a conta de liquidação.`);
      return;
    }
    if (!l.startsWith(classeLiquidacao(m.tipo))) erros.push(`${onde} a conta de liquidação tem de ser da classe ${classeLiquidacao(m.tipo)}.`);
    if (t === l) erros.push(`${onde} a conta transitória tem de ser diferente da conta de liquidação.`);
    if (transitorias.has(t)) erros.push(`${onde} a conta transitória ${t} já é usada por outro meio.`);
    transitorias.add(t);
    if (m.tipo === 'TPA' && pct > 0 && !m.conta_comissao?.trim()) erros.push(`${onde} com comissão, indique a conta da comissão.`);
  });
  if (!meios.some((m) => m.ativo)) erros.push('O terminal tem de ter pelo menos um meio de pagamento activo.');
  return erros;
}

/** Normaliza para a API: campos de TPA só nos TPA; textos aparados; vazio = null. */
export function meiosParaApi(meios: MeioPagamento[]): MeioPagamento[] {
  const txt = (v: string | null | undefined) => (v?.trim() ? v.trim() : null);
  return meios.map((m) => ({
    ...(m.id ? { id: m.id } : {}),
    tipo: m.tipo,
    nome: m.nome.trim(),
    ativo: !!m.ativo,
    conta_transitoria: txt(m.conta_transitoria),
    conta_liquidacao: txt(m.conta_liquidacao),
    ...(m.tipo === 'TPA'
      ? { codigo_tpa: txt(m.codigo_tpa), comissao_pct: Number(m.comissao_pct ?? 0), conta_comissao: txt(m.conta_comissao), comissao_deduzida: m.comissao_deduzida ?? true }
      : {}),
  }));
}
