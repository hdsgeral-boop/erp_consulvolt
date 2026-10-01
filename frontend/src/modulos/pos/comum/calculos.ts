import { deCentimos, paraCentimos } from '@/utilitarios/decimal';
import type { ProdutoPOS, TipoMeio, ValorApi } from './tipos';

/**
 * Cálculos do POS no cliente (carrinho, totais, pagamentos mistos, troco, contagem do Z, rateio do check-out).
 * Tudo em cêntimos inteiros. São estimativas para o operador: quem calcula, numera e valida é sempre o servidor
 * (ServicoVendasPOS, CalculadoraDocumento::calcularComIva, ServicoSessoesPOS::fechar, ServicoCheckoutHotel::ratear).
 */

export { deCentimos, paraCentimos };

/** Quantidades com até 3 casas decimais (como a API). */
export const r3 = (v: number): number => Math.round(v * 1000) / 1000;

/** Arredondamento «half away from zero» ao inteiro (cêntimos). */
function arredondar(v: number): number {
  const a = Math.abs(v);
  // a tolerância absorve o erro binário (ex.: 2.675 * 100 = 267.49999…)
  const r = Math.floor(a + 0.5 + 1e-7);
  return v < 0 ? -r : r;
}

/** Arredondamento ao cêntimo por excesso (CalculadoraDocumento::excessoCentimo), com valores já em cêntimos. */
function excesso(v: number): number {
  return Math.ceil(v - 1e-7);
}

export interface LinhaCalculo {
  quantidade: number;
  /** Preço com IVA, em cêntimos. */
  preco: number;
  taxa: number;
}

export interface TotaisDocumento {
  /** Soma de quantidade × preço (com IVA), antes do desconto. */
  bruto: number;
  desconto: number;
  liquido: number;
  imposto: number;
  /** Total a cobrar (com IVA, após o desconto). */
  total: number;
}

/**
 * Port de CalculadoraDocumento::calcularComIva: preço com IVA, desconto global em % sobre o total com IVA,
 * base por linha ajustada ao cêntimo para que base + IVA (arredondado por excesso) = valor cobrado.
 */
export function calcularComIva(linhas: LinhaCalculo[], percentagemDesconto = 0): TotaisDocumento {
  const fator = 1 - Math.min(Math.max(percentagemDesconto, 0), 100) / 100;
  let liquido = 0;
  let imposto = 0;
  let bruto = 0;
  for (const l of linhas) {
    const base = l.quantidade * l.preco;
    bruto += arredondar(base);
    const cobrado = arredondar(base * fator);
    const comIva = (b: number) => b + excesso((b * l.taxa) / 100);
    let v = arredondar(cobrado / (1 + l.taxa / 100));
    for (let k = 0; k < 4 && comIva(v) !== cobrado; k++) v += comIva(v) > cobrado ? -1 : 1;
    if (comIva(v) > cobrado) v -= 1;
    if (v < 0) v = 0;
    liquido += v;
    imposto += excesso((v * l.taxa) / 100);
  }
  const total = liquido + imposto;
  return { bruto, desconto: bruto - total, liquido, imposto, total };
}

// ───────────── Carrinho ─────────────

export interface ItemCarrinho {
  produto_id: number;
  codigo: string | null;
  nome: string;
  quantidade: number;
  /** Preço com IVA do catálogo, em cêntimos. */
  preco_catalogo: number;
  /** Preço praticado, em cêntimos (diferente do catálogo exige pos_desconto). */
  preco: number;
  taxa: number;
}

export function itemDeProduto(p: ProdutoPOS, quantidade = 1): ItemCarrinho {
  const preco = paraCentimos(p.preco_unitario);
  return { produto_id: p.id, codigo: p.codigo, nome: p.nome, quantidade: r3(quantidade), preco_catalogo: preco, preco, taxa: Number(p.taxa_imposto ?? 0) || 0 };
}

/** Acrescenta o produto (ou soma à linha existente do mesmo produto). */
export function adicionarProduto(carrinho: ItemCarrinho[], p: ProdutoPOS, quantidade = 1): ItemCarrinho[] {
  if (quantidade <= 0) return carrinho;
  const existente = carrinho.find((i) => i.produto_id === p.id);
  if (existente) return alterarQuantidade(carrinho, p.id, existente.quantidade + quantidade);
  return [...carrinho, itemDeProduto(p, quantidade)];
}

/** Muda a quantidade; 0 (ou menos) retira a linha. */
export function alterarQuantidade(carrinho: ItemCarrinho[], produtoId: number, quantidade: number): ItemCarrinho[] {
  return carrinho.map((i) => (i.produto_id === produtoId ? { ...i, quantidade: r3(Math.max(0, quantidade)) } : i)).filter((i) => i.quantidade > 0);
}

export function alterarPreco(carrinho: ItemCarrinho[], produtoId: number, precoCentimos: number): ItemCarrinho[] {
  return carrinho.map((i) => (i.produto_id === produtoId ? { ...i, preco: Math.max(0, Math.round(precoCentimos)) } : i));
}

export function removerLinha(carrinho: ItemCarrinho[], produtoId: number): ItemCarrinho[] {
  return carrinho.filter((i) => i.produto_id !== produtoId);
}

export function totaisCarrinho(carrinho: ItemCarrinho[], percentagemDesconto = 0): TotaisDocumento {
  return calcularComIva(carrinho.map((i) => ({ quantidade: i.quantidade, preco: i.preco, taxa: i.taxa })), percentagemDesconto);
}

export function totalUnidades(carrinho: ItemCarrinho[]): number {
  return r3(carrinho.reduce((t, i) => t + i.quantidade, 0));
}

/** A venda altera preços ou tem desconto (o servidor exige pos_desconto). */
export function exigeDesconto(carrinho: ItemCarrinho[], percentagemDesconto: number): boolean {
  return percentagemDesconto > 0 || carrinho.some((i) => i.preco !== i.preco_catalogo);
}

/** Linhas no formato do POST /pos/sessoes/{id}/vendas (o preço só segue quando foi alterado). */
export function linhasParaApi(carrinho: ItemCarrinho[]) {
  return carrinho.map((i) => ({
    produto_id: i.produto_id,
    quantidade: i.quantidade,
    ...(i.preco !== i.preco_catalogo ? { preco_unitario: deCentimos(i.preco) } : {}),
  }));
}

// ───────────── Pagamentos mistos e troco ─────────────

export interface Pagamento {
  /** Chave local da linha (permite dois pagamentos com o mesmo meio). */
  chave: string;
  meio_id: string;
  tipo: TipoMeio;
  nome: string;
  /** Valor entregue, em cêntimos. */
  valor: number;
  referencia?: string;
}

export interface ResumoPagamentos {
  recebido: number;
  numerario: number;
  naoNumerario: number;
  /** Quanto falta receber (0 se já chega). */
  falta: number;
  /** Troco a devolver (só sai do numerário). */
  troco: number;
  erros: string[];
  valido: boolean;
}

/**
 * Regras de ServicoVendasPOS::pagamentos: vários meios; troco só em numerário (TPA + transferências ≤ total);
 * transferência exige o n.º do comprovativo; o total recebido tem de cobrir o total.
 */
export function resumirPagamentos(pagamentos: Pagamento[], total: number): ResumoPagamentos {
  let numerario = 0;
  let naoNumerario = 0;
  const erros: string[] = [];
  for (const p of pagamentos) {
    if (!(p.valor > 0)) erros.push(`Indique o valor do pagamento por ${p.nome}.`);
    else if (p.tipo === 'NUMERARIO') numerario += p.valor;
    else naoNumerario += p.valor;
    if (p.tipo === 'TRANSFERENCIA' && !p.referencia?.trim()) erros.push(`Indique o n.º do comprovativo da transferência (${p.nome}).`);
  }
  const recebido = numerario + naoNumerario;
  if (!pagamentos.length) erros.push('Indique o pagamento.');
  if (naoNumerario > total) erros.push('TPA e transferências não podem exceder o total: o troco só se dá em numerário.');
  const falta = Math.max(0, total - recebido);
  if (pagamentos.length && falta > 0) erros.push(`Faltam ${formatarCentimos(falta)} Kz.`);
  const troco = naoNumerario > total ? 0 : Math.max(0, recebido - total);
  return { recebido, numerario, naoNumerario, falta, troco, erros, valido: erros.length === 0 };
}

/** Pagamentos no formato da API (valor entregue; o servidor desconta o troco ao numerário). */
export function pagamentosParaApi(pagamentos: Pagamento[]) {
  return pagamentos.map((p) => ({ meio_id: p.meio_id, valor: deCentimos(p.valor), ...(p.referencia?.trim() ? { referencia: p.referencia.trim() } : {}) }));
}

/**
 * Líquido do troco (como o servidor grava): o troco abate-se ao numerário, do último pagamento em numerário para trás;
 * linhas que ficam a zero desaparecem.
 */
export function liquidarTroco(pagamentos: Pagamento[], total: number): { linhas: Pagamento[]; troco: number } {
  const r = resumirPagamentos(pagamentos, total);
  let falta = r.troco;
  const linhas = pagamentos.map((p) => ({ ...p }));
  for (let i = linhas.length - 1; i >= 0 && falta > 0; i--) {
    if (linhas[i].tipo !== 'NUMERARIO') continue;
    const abate = Math.min(linhas[i].valor, falta);
    linhas[i].valor -= abate;
    falta -= abate;
  }
  return { linhas: linhas.filter((l) => l.valor > 0), troco: r.troco };
}

/** Valores rápidos de numerário (notas) a sugerir para um total. */
export function sugestoesNumerario(total: number): number[] {
  if (total <= 0) return [];
  const notas = [100000, 200000, 500000, 1000000, 2000000, 5000000, 10000000];   // 1 000 a 100 000 Kz, em cêntimos
  const sugestoes = new Set<number>([total]);
  for (const n of notas) {
    const arredondado = Math.ceil(total / n) * n;
    if (arredondado > total) sugestoes.add(arredondado);
    if (sugestoes.size >= 4) break;
  }
  return [...sugestoes].sort((a, b) => a - b);
}

// ───────────── Fecho Z ─────────────

/** Notas e moedas em Kz para a contagem (ServicoSessoesPOS::DENOMINACOES). */
export const DENOMINACOES = [5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1] as const;

/** Total da contagem por notas e moedas, em cêntimos. Quantidades negativas ou fraccionárias são inválidas. */
export function totalContagem(contagens: Record<string, number | null | undefined>): number {
  return DENOMINACOES.reduce((t, d) => t + Math.max(0, Math.floor(Number(contagens[String(d)] ?? 0) || 0)) * d * 100, 0);
}

/** Contagens no formato da API (só denominações com quantidade > 0). */
export function contagensParaApi(contagens: Record<string, number | null | undefined>): Record<string, number> {
  const r: Record<string, number> = {};
  for (const d of DENOMINACOES) {
    const q = Math.floor(Number(contagens[String(d)] ?? 0) || 0);
    if (q > 0) r[String(d)] = q;
  }
  return r;
}

export interface AvaliacaoFecho {
  contado: number;
  esperado: number;
  /** Contado − esperado (positivo = sobra; negativo = falta). */
  desvio: number;
  acimaTolerancia: boolean;
  tpaDifere: boolean;
  exigeJustificacao: boolean;
  /** Como o servidor classifica o desvio no fecho. */
  estadoDesvio: 'SEM_DESVIO' | 'DELIBERADO' | 'PENDENTE';
}

/** Regras do fecho Z: desvio acima da tolerância ou talão TPA diferente do sistema exigem justificação. */
export function avaliarFecho(contado: number, esperado: ValorApi, tolerancia: ValorApi, fechosTpa: { sistema: ValorApi; talao: ValorApi }[] = []): AvaliacaoFecho {
  const e = paraCentimos(esperado);
  const desvio = contado - e;
  const acima = Math.abs(desvio) > paraCentimos(tolerancia);
  const tpaDifere = fechosTpa.some((f) => paraCentimos(f.talao) !== paraCentimos(f.sistema));
  return {
    contado,
    esperado: e,
    desvio,
    acimaTolerancia: acima,
    tpaDifere,
    exigeJustificacao: acima || tpaDifere,
    estadoDesvio: desvio === 0 ? 'SEM_DESVIO' : acima ? 'PENDENTE' : 'DELIBERADO',
  };
}

// ───────────── Rateio do check-out (hotelaria) ─────────────

export interface PagamentoRateio {
  meio_id: string;
  tipo: TipoMeio;
  valor: number;
  referencia?: string;
}

/**
 * Port de ServicoCheckoutHotel::ratear: os pagamentos (líquidos do troco) repartem-se pelas facturas na proporção do total;
 * o acerto do cêntimo vai para o maior pagamento da factura; a última factura recebe o resto e o troco no numerário.
 * Devolve, por factura, os pagamentos com valor > 0.
 */
export function ratearPagamentos(liquidos: PagamentoRateio[], troco: number, totais: number[]): PagamentoRateio[][] {
  const geral = totais.reduce((t, x) => t + x, 0);
  const alocado = liquidos.map(() => 0);
  const ultima = totais.length - 1;
  return totais.map((tg, gi) => {
    const v = liquidos.map((p, i) => (gi === ultima ? p.valor - alocado[i] : geral > 0 ? arredondar((p.valor * tg) / geral) : 0));
    if (gi !== ultima && v.length) {
      const dif = tg - v.reduce((t, x) => t + x, 0);
      if (dif !== 0) {
        const maior = v.indexOf(Math.max(...v));
        v[maior] += dif;
      }
    }
    v.forEach((x, i) => (alocado[i] += x));
    if (gi === ultima && troco > 0) {
      let num = -1;
      liquidos.forEach((p, i) => {
        if (p.tipo === 'NUMERARIO') num = i;
      });
      if (num >= 0) v[num] += troco;
    }
    return liquidos.map((p, i) => ({ ...p, valor: v[i] })).filter((p) => p.valor > 0);
  });
}

// ───────────── Formatação ─────────────

const kz = new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Cêntimos → «1 234,50». */
export function formatarCentimos(c: number): string {
  return kz.format(c / 100);
}

/** Texto introduzido pelo operador («1 234,5», «1234.50») → cêntimos; inválido = 0. */
export function lerValor(texto: string | number | null | undefined): number {
  if (texto === null || texto === undefined) return 0;
  if (typeof texto === 'number') return Math.round(texto * 100);
  const limpo = texto.replace(/\s/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
  const n = Number(limpo);
  return Number.isFinite(n) ? Math.round(n * 100) : 0;
}
