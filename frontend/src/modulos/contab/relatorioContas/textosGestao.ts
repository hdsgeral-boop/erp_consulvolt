/**
 * M-10 — Relatório de Gestão do Relatório e Contas: secções e textos automáticos (porte dos geradores do legado,
 * js/relatorio_contas.js:70-83 e 384-502). Os números vêm do servidor (ServicoRelatorioContas::calcular/indicadores);
 * os textos são apresentação e geram-se no cliente (ADR-055). O utilizador pode editá-los (auto = false) e repô-los.
 *
 * Formato: texto simples com parágrafos separados por linha e listas com «• » no início da linha. Guarda-se também o
 * HTML equivalente (escapado), compatível com os registos migrados do legado ({ html, auto }), sem HTML livre do
 * utilizador (sem risco de XSS no ecrã nem na impressão).
 */

export type Ind = Record<string, string | number | null | undefined>;

export interface DadosGestao {
  ano: number;
  ano_anterior: number;
  colaboradores?: number;
  n: { indicadores: Ind };
  n1: { indicadores: Ind };
}

export interface ConfigGestao {
  nome?: string;
  nif?: string;
  sede?: string;
  objecto?: string;
  forma?: string;
  capital?: number | string;
  sector?: string;
  pct_reservas?: number | string;
  pct_transitados?: number | string;
  pct_dividendos?: number | string;
  [k: string]: unknown;
}

export interface TextoGravado {
  html?: string;
  texto?: string;
  auto?: boolean;
  copiado?: number;
}

export interface Seccao {
  id: string;
  titulo: string;
  grupo?: string;
  /** Texto só do utilizador (o automático é um modelo a completar). */
  livre?: boolean;
  /** Tabela de indicadores a mostrar por baixo do texto. */
  tabela?: 'liquidez' | 'estrutura' | 'rentabilidade' | 'aplicacao' | 'analiseDR' | 'analiseBalanco';
}

export const SECCOES: Seccao[] = [
  { id: 'economia', titulo: 'A Evolução da Economia', livre: true },
  { id: 'enquadramento', titulo: 'Enquadramento nos Sectores de Actividade em que Opera', livre: true },
  { id: 'apresentacao', titulo: 'Apresentação da Empresa' },
  { id: 'acontecimentos', titulo: 'Acontecimentos Relevantes', tabela: 'analiseDR' },
  { id: 'analise_balanco', titulo: 'Análise do Balanço', tabela: 'analiseBalanco' },
  { id: 'recursos_humanos', titulo: 'Recursos Humanos' },
  { id: 'liquidez', titulo: 'Rácios de Liquidez', grupo: 'Análise do Desempenho Económico-Financeiro', tabela: 'liquidez' },
  { id: 'estrutura', titulo: 'Indicadores de Estrutura Financeira', tabela: 'estrutura' },
  { id: 'rentabilidade', titulo: 'Indicadores de Rentabilidade', tabela: 'rentabilidade' },
  { id: 'aplicacao', titulo: 'Proposta de Aplicação de Resultados', tabela: 'aplicacao' },
  { id: 'finais', titulo: 'Considerações Finais', livre: true },
];

// ───────────── formatação (como fKz/pc/rx do legado) ─────────────

const num = (v: unknown): number => {
  const n = typeof v === 'number' ? v : Number(v ?? 0);
  return Number.isFinite(n) ? n : 0;
};
const fin = (v: unknown): number | null => {
  if (v === null || v === undefined || v === '') return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
};
export const fKz = (v: unknown) => `${num(v).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} Kz`;
export const pc = (v: number | null) => (v === null || !Number.isFinite(v) ? 'n.a.' : `${(v * 100).toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`);
export const rx = (v: number | null) => (v === null || !Number.isFinite(v) ? 'n.a.' : v.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const div = (a: number, b: number): number | null => (Math.abs(b) < 0.005 ? null : a / b);
const varPct = (a: number, b: number): number | null => (Math.abs(b) < 0.005 ? null : (a - b) / Math.abs(b));
const variacaoTxt = (a: number, b: number) => {
  const v = varPct(a, b);
  return v === null ? 'n.a.' : `${v >= 0 ? '+' : ''}${pc(v)}`;
};
const lista = (itens: string[]) => itens.map((t) => `• ${t}`).join('\n');
const pcAbs = (v: number | null) => (v === null ? '' : pc(Math.abs(v)));

/** Indicadores do servidor com os nomes curtos do legado (números). */
function ind(i: Ind) {
  const g = (k: string) => num(i[k]);
  return {
    rl: g('rl'), fin: g('financeiros'), ebit: g('ebit'), vendasPrest: g('vendas_prestacoes'), cmvmc: g('cmvmc'), margemBruta: g('margem_bruta'),
    ganhosOp: g('ganhos_op'), ebitda: g('ebitda'), ebitdaAj: g('ebitda_ajustado'), imob: g('imobilizado'), anc: g('activo_nao_corrente'),
    activo: g('activo'), receber: g('contas_receber'), disp: g('disponibilidades'), outrosAC: g('outros_activos_correntes'), transitados: g('transitados'),
    cp: g('capital_proprio'), capital: g('capital'), pnc: g('passivo_nao_corrente'), pagar: g('contas_pagar'), outrosPC: g('outros_passivos_correntes'),
    passivo: g('passivo'), pc: g('passivo_corrente'), pessoal: g('pessoal'),
    liqGeral: fin(i.liquidez_geral), liqReduzida: fin(i.liquidez_reduzida), liqImediata: fin(i.liquidez_imediata),
    autonomia: fin(i.autonomia_financeira), solvabilidade: fin(i.solvabilidade), estruturaEndiv: fin(i.estrutura_endividamento),
    roe: fin(i.roe), roa: fin(i.roa), ros: fin(i.ros), margemOp: fin(i.margem_operacional),
  };
}

type Gerador = (d: DadosGestao, c: ConfigGestao) => string;

const GERADORES: Record<string, Gerador> = {
  economia: (d) =>
    `Economia internacional\n[Descreva a evolução da economia internacional em ${d.ano}: inflação, política monetária, crescimento mundial e principais riscos.]\nEconomia nacional\n[Descreva a evolução da economia angolana em ${d.ano}: contas fiscais, sector petrolífero e diamantífero, sector não petrolífero, emissão de títulos, inflação e taxa de juro.]`,
  enquadramento: (_d, c) => `A ${c.nome ?? ''} opera no sector de ${c.sector || '[indique o sector]'}. [Descreva o enquadramento da empresa nos sectores de actividade em que opera e a evolução do mercado.]`,
  apresentacao: (_d, c) =>
    `A empresa ${c.nome ?? ''}, é uma empresa de direito angolano, com o número de identificação fiscal ${c.nif ?? ''}, com sede social em ${c.sede || '[sede]'}. A sociedade tem como objecto social ${c.objecto ?? ''}. A ${c.nome ?? ''} é uma ${c.forma ?? ''}, com capital social de ${fKz(c.capital)}.`,
  acontecimentos: (d) => {
    const N = ind(d.n.indicadores), P = ind(d.n1.indicadores), a = d.ano, b = d.ano_anterior, out: string[] = [];
    if (P.rl < 0 && N.rl >= 0) out.push(`A empresa saiu de um prejuízo de ${fKz(P.rl)} em ${b} para um lucro de ${fKz(N.rl)} em ${a}.`);
    else if (P.rl >= 0 && N.rl < 0) out.push(`A empresa passou de um lucro de ${fKz(P.rl)} em ${b} para um prejuízo de ${fKz(N.rl)} em ${a}.`);
    else if (N.rl >= 0) out.push(`A empresa registou um resultado líquido positivo de ${fKz(N.rl)} em ${a}, face a ${fKz(P.rl)} em ${b} (${variacaoTxt(N.rl, P.rl)}).`);
    else out.push(`A empresa registou um prejuízo de ${fKz(N.rl)} em ${a}, face a ${fKz(P.rl)} em ${b}.`);
    if (N.rl >= 0 && N.fin < 0 && Math.abs(N.fin) > 0.3 * Math.abs(N.ebit)) out.push('Apesar da carga financeira, o bom desempenho operacional permitiu absorver os encargos e gerar lucro.');
    const vv = varPct(N.vendasPrest, P.vendasPrest);
    if (N.vendasPrest || P.vendasPrest) {
      out.push(
        `A empresa apresentou ${vv !== null ? (vv >= 0 ? `um crescimento de ${pc(vv)}` : `uma redução de ${pc(Math.abs(vv))}`) : 'o início da actividade comercial'} na venda de bens e prestação de serviços, passando de ${fKz(P.vendasPrest)} em ${b} para ${fKz(N.vendasPrest)} em ${a}.`,
      );
      if (vv !== null && vv > 0.5) out.push('Este crescimento indica expansão comercial significativa, resultado provável de maior penetração no mercado, aumento do volume de vendas ou revisão dos preços.');
    }
    if (P.cmvmc > 0 && vv !== null) {
      const vc = varPct(N.cmvmc, P.cmvmc) ?? 0;
      out.push(`O CMVMC ${vc >= 0 ? 'aumentou' : 'diminuiu'} ${pc(Math.abs(vc))}, ${vc <= vv ? 'num ritmo inferior ao crescimento da receita, o que é positivo' : 'num ritmo superior ao da receita, pressionando as margens'}.`);
    }
    const mbN = div(N.margemBruta, N.ganhosOp), mbP = div(P.margemBruta, P.ganhosOp);
    if (mbN !== null && mbP !== null)
      out.push(
        `A margem bruta ${mbN >= mbP ? 'passou' : 'desceu'} de ${pc(mbP)} para ${pc(mbN)}, ${mbN >= mbP ? 'evidenciando melhoria na eficiência operacional e no controlo dos custos de aquisição ou produção' : 'o que exige atenção aos custos de aquisição e à política de preços'}.`,
      );
    const ve = varPct(N.ebitda, P.ebitda);
    out.push(
      `O EBITDA passou de ${fKz(P.ebitda)} para ${fKz(N.ebitda)}${ve !== null ? `, uma variação de ${ve >= 0 ? '+' : ''}${pc(ve)}` : ''}, ${N.ebitda >= P.ebitda ? 'mostrando melhoria da capacidade de gerar caixa pelas operações' : 'reflectindo menor capacidade de gerar caixa pelas operações'}.`,
    );
    out.push(
      `O EBITDA ajustado, que incorpora os resultados não operacionais e extraordinários, ficou em ${fKz(N.ebitdaAj)}, comparado com ${fKz(P.ebitdaAj)} em ${b}${N.ebitdaAj > 0 && P.ebitdaAj <= 0 ? ', reforçando que a recuperação operacional é real' : ''}.`,
    );
    return out.join('\n');
  },
  analise_balanco: (d) => {
    const N = ind(d.n.indicadores), P = ind(d.n1.indicadores), a = d.ano, it: string[] = [];
    const vImob = varPct(N.imob, P.imob);
    if (N.imob || P.imob)
      it.push(`As imobilizações ${N.imob >= P.imob ? 'aumentaram' : 'diminuíram'} ${pcAbs(vImob)}, de ${fKz(P.imob)} para ${fKz(N.imob)}${N.imob > P.imob ? ', o que poderá estar associado à aquisição de novos equipamentos ou melhoria das infra-estruturas' : ''}.`);
    const pesoANC = div(N.anc, N.activo);
    if (pesoANC !== null) it.push(`Os activos não correntes representam ${pc(pesoANC)} do total do activo${pesoANC < 0.2 ? ', o que indica pouco capital imobilizado e um perfil muito operacional e circulante' : ''}.`);
    const pesoRec = div(N.receber, N.activo), vRec = varPct(N.receber, P.receber);
    if (N.receber || P.receber) {
      it.push(`As contas a receber ${N.receber >= P.receber ? 'aumentaram' : 'diminuíram'} ${pcAbs(vRec)}, alcançando ${fKz(N.receber)}${pesoRec !== null ? `, ${pc(pesoRec)} do activo total` : ''}.`);
      if (pesoRec !== null && pesoRec > 0.5)
        it.push(`A estrutura do activo está excessivamente concentrada em contas a receber (${pc(pesoRec)}), o que aumenta o risco de incobrabilidade e exige uma política urgente de cobrança e controlo de crédito.`);
    }
    const vDisp = varPct(N.disp, P.disp);
    if (N.disp || P.disp) it.push(`As disponibilidades ${N.disp >= P.disp ? 'aumentaram' : 'caíram'} ${pcAbs(vDisp)}, fixando-se em ${fKz(N.disp)}${N.disp < P.disp ? ', o que pode indicar maior pressão de tesouraria' : ''}.`);
    if (N.outrosAC && !P.outrosAC) it.push(`Aparecem outros activos correntes no valor de ${fKz(N.outrosAC)}, que podem representar valores a receber não comerciais, adiantamentos ou custos diferidos.`);
    if (N.transitados < 0) it.push(`Os resultados transitados acumulam prejuízos de ${fKz(N.transitados)}, agravando o capital próprio.`);
    it.push(`O resultado líquido de ${a} foi ${N.rl >= 0 ? 'positivo' : 'negativo'} (${fKz(N.rl)}), o que ${N.rl >= 0 ? 'melhorou' : 'agravou'} o capital próprio${N.cp < 0 ? `, embora este permaneça negativo em ${fKz(N.cp)}` : `, que se fixou em ${fKz(N.cp)}`}.`);
    if (N.capital > 0 && N.activo > 0 && N.capital / N.activo < 0.01) it.push(`O capital subscrito de ${fKz(N.capital)} é simbólico e insuficiente face à dimensão das operações, o que fragiliza a estrutura de capital.`);
    if (N.cp < 0) it.push('A empresa apresenta capitais próprios negativos, o que tecnicamente representa falência técnica. É urgente reforçar os capitais próprios através de aumento do capital social, conversão de suprimentos ou capitalização de lucros futuros.');
    if (Math.abs(N.pnc) < 0.005) it.push('Não foram registados passivos de médio ou longo prazo. Isto é favorável à estrutura de liquidez, mas pode significar ausência de financiamentos estruturados, obrigando a empresa a depender do curto prazo.');
    const vPag = varPct(N.pagar, P.pagar);
    if (N.pagar || P.pagar) it.push(`As contas a pagar ${N.pagar >= P.pagar ? 'subiram' : 'caíram'} ${pcAbs(vPag)}, de ${fKz(P.pagar)} para ${fKz(N.pagar)}.`);
    const vOPC = varPct(N.outrosPC, P.outrosPC);
    if (N.outrosPC || P.outrosPC) {
      it.push(`Os outros passivos correntes ${N.outrosPC >= P.outrosPC ? 'subiram' : 'desceram'} de ${fKz(P.outrosPC)} para ${fKz(N.outrosPC)}.`);
      if (vOPC !== null && vOPC > 1) it.push('Este aumento requer análise detalhada, podendo incluir obrigações fiscais em atraso, salários e encargos a pagar, adiantamentos de clientes, proveitos diferidos ou provisões de curto prazo.');
    }
    const vPass = varPct(N.passivo, P.passivo), pesoPC = div(N.pc, N.passivo);
    if (N.passivo || P.passivo)
      it.push(
        `O passivo total ${N.passivo >= P.passivo ? 'aumentou' : 'diminuiu'} ${pcAbs(vPass)}${pesoPC !== null ? ` e está ${pc(pesoPC)} concentrado no curto prazo` : ''}${pesoPC !== null && pesoPC > 0.8 ? ', o que representa elevado risco de liquidez e necessidade de reestruturação da dívida ou do fundo de maneio' : ''}.`,
      );
    return lista(it.map((t) => t.replace(/\s+,/g, ',').replace(/\s{2,}/g, ' ')));
  },
  recursos_humanos: (d) => {
    const N = ind(d.n.indicadores), P = ind(d.n1.indicadores);
    return [
      `As despesas com o pessoal atingiram em ${d.ano} a soma de ${fKz(N.pessoal)} e em ${d.ano_anterior} ${fKz(P.pessoal)} (${variacaoTxt(N.pessoal, P.pessoal)}).`,
      d.colaboradores ? `No final do exercício a empresa contava com ${d.colaboradores} colaboradores activos.` : '',
      '[Descreva a caracterização do quadro de pessoal, formação e política de recursos humanos.]',
    ]
      .filter(Boolean)
      .join('\n');
  },
  liquidez: (d, c) => {
    const N = ind(d.n.indicadores), nome = c.nome ?? '';
    if (!(N.pc > 0)) return `A ${nome} não apresenta passivo corrente em 31 de Dezembro de ${d.ano}, pelo que os rácios de liquidez não são aplicáveis.`;
    return [
      `Fruto do exercício da sua actividade, a ${nome} tem compromissos a realizar num determinado período de tempo e, na perspectiva de um equilíbrio financeiro, deve ter meios para lhes fazer face.`,
      `Quanto à cobertura das dívidas exigíveis num horizonte inferior a um ano com os activos correntes, a ${nome} ${(N.liqGeral ?? 0) >= 1 ? `consegue cobrir tais dívidas, apresentando ${rx(N.liqGeral)} Kz de activo corrente para cada 1 Kz de passivo corrente` : `não consegue cobrir tais dívidas, apresentando uma capacidade de ${rx(N.liqGeral)} Kz para cada 1 Kz de passivo corrente`}.`,
      `Retirando o efeito das existências, a liquidez reduzida é de ${rx(N.liqReduzida)}, ${(N.liqReduzida ?? 0) >= 1 ? 'mantendo-se a capacidade de cobertura das dívidas de curto prazo' : 'o que evidencia que as disponibilidades e as contas a receber são insuficientes para cobrir as dívidas de curto prazo'}.`,
      `O rácio de liquidez imediata (${rx(N.liqImediata)}) demonstra que as disponibilidades em 31 de Dezembro de ${d.ano} ${(N.liqImediata ?? 0) >= 1 ? 'cobrem' : 'não cobrem'} a totalidade do passivo corrente${(N.liqImediata ?? 0) < 1 ? ', evidenciando reduzida capacidade de honrar os compromissos de curto prazo apenas com as disponibilidades' : ''}.`,
    ].join('\n');
  },
  estrutura: (d, c) => {
    const N = ind(d.n.indicadores), nome = c.nome ?? '', out: string[] = [];
    out.push(N.cp < 0 ? `Em termos de autonomia financeira, o activo da ${nome} é financiado a 100% por capitais alheios, uma vez que os capitais próprios são negativos.` : `Em termos de autonomia financeira, ${pc(N.autonomia)} do activo da ${nome} é financiado por capitais próprios.`);
    const s = N.solvabilidade ?? 0;
    out.push(`O rácio de solvabilidade é de ${pc(N.solvabilidade)}, ${s < 0 ? 'ou seja, a empresa não tem capacidade de pagar o seu passivo com recursos próprios' : s < 1 ? 'pelo que os capitais próprios cobrem apenas parcialmente o passivo' : 'pelo que os capitais próprios cobrem a totalidade do passivo'}.`);
    if (N.passivo > 0)
      out.push(
        `Os capitais alheios presentes na estrutura de financiamento são ${(N.estruturaEndiv ?? 0) >= 0.5 ? 'maioritariamente de curto prazo, sendo maior a pressão sobre a tesouraria e o risco de cumprimento das obrigações de curto prazo' : 'maioritariamente de médio e longo prazo, o que alivia a pressão sobre a tesouraria'}.`,
      );
    return out.join('\n');
  },
  rentabilidade: (d) => {
    const N = ind(d.n.indicadores), P = ind(d.n1.indicadores), out: string[] = [];
    if (N.cp <= 0)
      out.push(`Com capitais próprios negativos, a rendibilidade dos capitais próprios (ROE ${pc(N.roe)}) não tem leitura económica directa: ${N.rl >= 0 ? 'o lucro do exercício contribui para a recuperação da situação líquida' : 'o prejuízo agrava a situação líquida negativa'}.`);
    else out.push(`A rendibilidade dos capitais próprios (ROE) foi de ${pc(N.roe)} em ${d.ano}, face a ${pc(P.roe)} em ${d.ano_anterior}${(N.roe ?? 0) > 0 ? `, ou seja, por cada 100 Kz de capital próprio a empresa gerou ${Math.round((N.roe ?? 0) * 100)} Kz de resultado` : ''}.`);
    out.push(`A rendibilidade do activo (ROA) passou de ${pc(P.roa)} para ${pc(N.roa)}, ${(N.roa ?? 0) >= (P.roa ?? 0) ? 'demonstrando maior eficiência na gestão dos recursos que detém' : 'traduzindo menor eficiência na utilização dos activos'}.`);
    out.push(`A rendibilidade das vendas e prestações de serviços (ROS) foi de ${pc(N.ros)}: cada 100 Kz vendidos geraram ${N.ros !== null ? Math.round(N.ros * 100) : '-'} Kz de resultado líquido. A margem operacional (EBIT/Vendas) situou-se em ${pc(N.margemOp)}.`);
    return out.join('\n');
  },
  aplicacao: (d) => {
    const rl = num(d.n.indicadores.rl);
    if (rl <= 0) return `No exercício de ${d.ano} a empresa obteve um resultado negativo de ${fKz(rl)}, que será transferido para a conta de resultados transitados.`;
    return `No exercício de ${d.ano} a empresa obteve um resultado positivo de ${fKz(rl)}. De acordo com a Lei das Sociedades Comerciais, propõe-se a seguinte aplicação:`;
  },
  finais: () =>
    'Perspectivamos melhorar a oferta de produtos e serviços o mais ajustadamente possível às necessidades e especificidades dos nossos clientes, potenciais e actuais. Estamos conscientes de que nos próximos tempos teremos factores adversos a enfrentar e, por isso, precisamos de foco para enfrentar o futuro. Somos profundamente gratos a todos os que manifestaram confiança e preferência, em particular aos Clientes e Fornecedores, porque a eles se deve muito do crescimento e desenvolvimento das nossas actividades.',
};

/** Texto automático de uma secção. */
export function gerarTexto(id: string, d: DadosGestao, c: ConfigGestao): string {
  return GERADORES[id]?.(d, c) ?? '';
}

const escapar = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Texto (parágrafos por linha, «• » para listas) → HTML escapado (para guardar e imprimir). */
export function textoParaHtml(texto: string): string {
  const linhas = texto.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  let html = '';
  let emLista = false;
  for (const l of linhas) {
    const item = /^[•\-*]\s+/.test(l);
    if (item && !emLista) html += '<ul>';
    if (!item && emLista) html += '</ul>';
    emLista = item;
    html += item ? `<li>${escapar(l.replace(/^[•\-*]\s+/, ''))}</li>` : `<p>${escapar(l)}</p>`;
  }
  return emLista ? `${html}</ul>` : html;
}

/** HTML guardado (registos do legado) → texto editável, sem interpretar o HTML (DOMParser não executa scripts). */
export function htmlParaTexto(html: string): string {
  if (!html) return '';
  if (typeof DOMParser === 'undefined') return html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
  const partes: string[] = [];
  const percorrer = (no: Node) => {
    for (const filho of Array.from(no.childNodes)) {
      if (filho.nodeType === 3) {
        const t = (filho.textContent ?? '').replace(/\s+/g, ' ');
        if (t.trim()) partes.push(t);
      } else if (filho instanceof Element) {
        const tag = filho.tagName.toLowerCase();
        if (tag === 'li') partes.push('\n• ');
        else if (['p', 'div', 'br', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'table', 'tr'].includes(tag)) partes.push('\n');
        percorrer(filho);
        if (['p', 'div', 'h1', 'h2', 'h3', 'h4'].includes(tag)) partes.push('\n');
      }
    }
  };
  percorrer(doc.body.firstChild ?? doc.body);
  return partes
    .join('')
    .split('\n')
    .map((l) => l.replace(/\s+/g, ' ').trim())
    .filter((l) => l && l !== '•')
    .join('\n');
}

/** Texto em vigor de uma secção: o editado (auto = false) ou o automático actual. */
export function textoActual(id: string, gravado: TextoGravado | undefined, d: DadosGestao, c: ConfigGestao): { texto: string; auto: boolean; copiado?: number } {
  if (gravado && gravado.auto === false) return { texto: gravado.texto ?? htmlParaTexto(gravado.html ?? ''), auto: false, copiado: gravado.copiado };
  return { texto: gerarTexto(id, d, c), auto: true };
}

/** Linhas da tabela de indicadores de cada secção ([rótulo, N, N-1] já formatados). */
export function tabelaSeccao(tabela: Seccao['tabela'], d: DadosGestao, c: ConfigGestao): [string, string, string][] {
  const N = d.n.indicadores, P = d.n1.indicadores;
  const r = (k: string) => [rx(fin(N[k])), rx(fin(P[k]))] as const;
  const p = (k: string) => [pc(fin(N[k])), pc(fin(P[k]))] as const;
  const k = (k: string) => [fKz(N[k]), fKz(P[k])] as const;
  switch (tabela) {
    case 'liquidez':
      return [['Liquidez geral', ...r('liquidez_geral')], ['Liquidez reduzida', ...r('liquidez_reduzida')], ['Liquidez imediata', ...r('liquidez_imediata')]];
    case 'estrutura':
      return [['Autonomia financeira', ...p('autonomia_financeira')], ['Solvabilidade', ...p('solvabilidade')], ['Endividamento', ...p('endividamento_total')], ['Estrutura do endividamento', ...p('estrutura_endividamento')]];
    case 'rentabilidade':
      return [['Rendibilidade do capital próprio (ROE)', ...p('roe')], ['Rendibilidade do activo (ROA)', ...p('roa')], ['Rendibilidade das vendas (ROS)', ...p('ros')], ['Margem operacional', ...p('margem_operacional')]];
    case 'analiseDR':
      return [['Vendas e prestações de serviços', ...k('vendas_prestacoes')], ['Margem bruta', ...k('margem_bruta')], ['EBITDA', ...k('ebitda')], ['EBIT', ...k('ebit')], ['Resultado líquido', ...k('rl')]];
    case 'analiseBalanco':
      return [['Activo não corrente', ...k('activo_nao_corrente')], ['Activo corrente', ...k('activo_corrente')], ['Capital próprio', ...k('capital_proprio')], ['Passivo não corrente', ...k('passivo_nao_corrente')], ['Passivo corrente', ...k('passivo_corrente')]];
    case 'aplicacao': {
      const rl = num(N.rl);
      if (rl <= 0) return [];
      const parte = (pct: unknown) => fKz((rl * num(pct)) / 100);
      return [
        [`Reservas legais (${num(c.pct_reservas)} %)`, parte(c.pct_reservas), ''],
        [`Resultados transitados (${num(c.pct_transitados)} %)`, parte(c.pct_transitados), ''],
        [`Dividendos (${num(c.pct_dividendos)} %)`, parte(c.pct_dividendos), ''],
      ];
    }
    default:
      return [];
  }
}
