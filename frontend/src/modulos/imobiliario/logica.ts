import type { EstadoFracao, Fracao, Opcao } from './api';

/** Cores dos estados da fracção (tokens do legado). O estado nunca se distingue só pela cor: mostra-se sempre o texto. */
export const ESTADOS_FRACAO: Readonly<Record<EstadoFracao, { rotulo: string; cor: string; fundo: string; ocupa: boolean }>> = {
  DISPONIVEL: { rotulo: 'Disponível', cor: '#15803d', fundo: '#dcfce7', ocupa: false },
  RESERVADA: { rotulo: 'Reservada', cor: '#854d0e', fundo: '#fef9c3', ocupa: false },
  ARRENDADA: { rotulo: 'Arrendada', cor: '#1d4ed8', fundo: '#dbeafe', ocupa: true },
  VENDIDA: { rotulo: 'Vendida', cor: '#475569', fundo: '#f1f5f9', ocupa: true },
  EM_OBRA: { rotulo: 'Em obra', cor: '#b45309', fundo: '#ffedd5', ocupa: false },
  INDISPONIVEL: { rotulo: 'Indisponível', cor: '#b91c1c', fundo: '#fee2e2', ocupa: false },
};

/** Estados que exigem motivo ao sair (arrendada/vendida) ou ao chegar (indisponível) — as mesmas regras do servidor. */
export const exigeMotivo = (de: EstadoFracao, para: EstadoFracao): boolean => de === 'ARRENDADA' || de === 'VENDIDA' || para === 'INDISPONIVEL';

/** Estados para onde a fracção pode passar (o servidor tem a última palavra). */
export const transicoesDe = (estado: EstadoFracao, transicoes: Record<string, string[]>): EstadoFracao[] => (transicoes[estado] ?? []) as EstadoFracao[];

export const rotuloDe = (lista: Opcao[] | undefined, valor: string | null | undefined): string => (valor ? (lista?.find((o) => o.valor === valor)?.rotulo ?? valor) : '—');

/** «1 234 567,50 Kz» (pt-AO, 2 casas). */
export const formatarKz = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : `${new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)} Kz`);

export const formatarArea = (v: number | null | undefined): string => (v ? `${new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 2 }).format(v)} m²` : '—');

export const formatarPercentagem = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : `${v} %`);

/** Uma fracção «principal» é a que se arrenda ou vende (não é vaga nem arrecadação). */
export const principal = (f: Pick<Fracao, 'natureza'>): boolean => f.natureza === 'HABITACIONAL';

export interface GrupoPiso { andar: string; piso: number | null; fracoes: Fracao[]; ocupadas: number; area: number }

/** Agrupa as fracções por andar (sem andar: «—»), do piso mais baixo para o mais alto; dentro do andar pela ordem e pelo código. */
export function agruparPorPiso(fracoes: Fracao[]): GrupoPiso[] {
  const mapa = new Map<string, GrupoPiso>();
  for (const f of fracoes) {
    const andar = (f.andar ?? '').trim() || '—';
    const g = mapa.get(andar) ?? { andar, piso: f.piso, fracoes: [], ocupadas: 0, area: 0 };
    g.fracoes.push(f);
    if (ESTADOS_FRACAO[f.estado]?.ocupa && principal(f)) g.ocupadas += 1;
    if (principal(f)) g.area = Math.round((g.area + Number(f.area_bruta || 0)) * 100) / 100;
    if (g.piso === null && f.piso !== null) g.piso = f.piso;
    mapa.set(andar, g);
  }
  const grupos = [...mapa.values()];
  grupos.forEach((g) => g.fracoes.sort((a, b) => a.ordem - b.ordem || a.codigo.localeCompare(b.codigo, 'pt', { numeric: true })));
  return grupos.sort((a, b) => (a.piso ?? 999) - (b.piso ?? 999) || a.andar.localeCompare(b.andar, 'pt'));
}

/** Taxa de ocupação (principais ARRENDADA ou VENDIDA sobre o total de principais), inteiro; null sem fracções. */
export function taxaOcupacao(fracoes: Pick<Fracao, 'natureza' | 'estado'>[]): number | null {
  const principais = fracoes.filter(principal);
  if (principais.length === 0) return null;
  return Math.round((principais.filter((f) => ESTADOS_FRACAO[f.estado]?.ocupa).length / principais.length) * 100);
}

/** Insere `{{chave}}` na posição do cursor (ou no fim) e devolve o novo texto e a nova posição do cursor. */
export function inserirVariavel(texto: string, chave: string, inicio: number | null, fim: number | null): { texto: string; cursor: number } {
  const marca = `{{${chave}}}`;
  const a = inicio ?? texto.length;
  const b = fim ?? a;
  return { texto: texto.slice(0, a) + marca + texto.slice(b), cursor: a + marca.length };
}

/** Taxa efectiva do Imposto Predial sobre a renda (taxa × base ÷ 100, 4 casas): 25 e 60 dão 15. */
export const ipEfectivo = (taxa: number, base: number): number => Math.round(taxa * base * 100) / 10000;

/** Grupos de campos das taxas, com os rótulos do ecrã do legado (ecrã «Modelos e taxas»). */
export const GRUPOS_TAXAS: { titulo: string; nota?: string; campos: { chave: string; rotulo: string; ajuda?: string; unidade: '%' | 'Kz' | 'dias' | 'meses' }[] }[] = [
  { titulo: 'Arrendamento', campos: [
    { chave: 'ip_taxa', rotulo: 'Imposto Predial (%)', unidade: '%' },
    { chave: 'ip_base', rotulo: 'Incide sobre (% da renda)', unidade: '%', ajuda: 'A taxa efectiva é a taxa × a base.' },
    { chave: 'selo_habitacao', rotulo: 'Selo — habitação (%)', unidade: '%' },
    { chave: 'selo_outro', rotulo: 'Selo — outros fins (%)', unidade: '%' },
    { chave: 'iva_arrendamento', rotulo: 'IVA arrendamento (%)', unidade: '%' },
    { chave: 'iva_alojamento', rotulo: 'IVA alojamento (%)', unidade: '%' },
    { chave: 'mora_mensal', rotulo: 'Juro de mora mensal (%)', unidade: '%', ajuda: 'Só se o contrato o previr.' },
    { chave: 'prazo_registo', rotulo: 'Prazo de registo (dias)', unidade: 'dias', ajuda: 'Cópia do contrato na Repartição Fiscal.' },
  ] },
  { titulo: 'Transmissão (compra e venda)', campos: [
    { chave: 'ip_transmissao', rotulo: 'Imposto Predial da transmissão (%)', unidade: '%' },
    { chave: 'selo_transmissao', rotulo: 'Selo da transmissão (%)', unidade: '%' },
    { chave: 'escritura_fixo', rotulo: 'Escritura (Kz)', unidade: 'Kz' },
    { chave: 'isencao_ate', rotulo: 'Habitação isenta até (Kz)', unidade: 'Kz', ajuda: '0 = sem isenção este ano.' },
    { chave: 'isencao_metade_ate', rotulo: 'Redução de 50% até (Kz)', unidade: 'Kz' },
  ] },
  { titulo: 'Detenção (pagar por ter o imóvel)', campos: [
    { chave: 'det_escalao1_ate', rotulo: '1.º escalão até (Kz)', unidade: 'Kz' },
    { chave: 'det_escalao1_pct', rotulo: '1.º escalão (%)', unidade: '%' },
    { chave: 'det_escalao2_ate', rotulo: '2.º escalão até (Kz)', unidade: 'Kz' },
    { chave: 'det_escalao2_fixo', rotulo: '2.º escalão — valor fixo (Kz)', unidade: 'Kz' },
    { chave: 'det_escalao3_pct', rotulo: '3.º escalão (%) sobre o excesso', unidade: '%' },
    { chave: 'det_terreno_pct', rotulo: 'Terrenos (%)', unidade: '%' },
    { chave: 'det_agravamento_pct', rotulo: 'Agravamento por desocupação (%)', unidade: '%' },
    { chave: 'det_agravamento_meses', rotulo: 'Desocupado a partir de (meses)', unidade: 'meses' },
  ] },
];
