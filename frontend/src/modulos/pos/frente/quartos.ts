/** Modo hotelaria da frente de caixa (legado js/hotelaria.js): mapa de quartos como mesas. Regras puras, testáveis. */
import { itemDeProduto, type ItemCarrinho } from '../comum/calculos';
import type { ProdutoPOS, Terminal } from '../comum/tipos';
import type { ConsumoEstadia, Quarto, RegrasHotel } from '../hotelaria/tipos';
import { paraCentimos } from '@/utilitarios/decimal';

/** Regras do terminal com as predefinições do legado (regras(), hotelaria.js:39-46). */
export function regrasHotel(t: Partial<Pick<Terminal, 'hotel_hora_entrada' | 'hotel_hora_saida' | 'hotel_tolerancia_atraso_min' | 'hotel_bloco_horas' | 'hotel_bloco_horas_de' | 'hotel_bloco_horas_ate'>> | null | undefined): RegrasHotel {
  const hhmm = (v: string | null | undefined, padrao: string) => (v ? v.slice(0, 5) : padrao);
  return {
    entrada: hhmm(t?.hotel_hora_entrada, '14:00'),
    saida: hhmm(t?.hotel_hora_saida, '12:00'),
    tolerancia: t?.hotel_tolerancia_atraso_min != null ? Number(t.hotel_tolerancia_atraso_min) || 0 : 60,
    bloqueio: t?.hotel_bloco_horas !== false,
    de: hhmm(t?.hotel_bloco_horas_de, '21:00'),
    ate: hhmm(t?.hotel_bloco_horas_ate, '08:00'),
  };
}

const minutos = (hhmm: string) => {
  const [h, m] = String(hhmm || '0:0').split(':').map(Number);
  return (h || 0) * 60 + (m || 0);
};

/** Venda à hora bloqueada no intervalo do terminal (horaBloqueada, hotelaria.js:49-56). */
export function horaBloqueada(data: Date, rg: RegrasHotel): boolean {
  if (!rg.bloqueio) return false;
  const m = data.getHours() * 60 + data.getMinutes();
  const de = minutos(rg.de);
  const ate = minutos(rg.ate);
  if (de === ate) return false;
  return de > ate ? m >= de || m < ate : m >= de && m < ate;
}

/** Saída prevista (saidaPrevista, hotelaria.js:58-65): à hora soma as horas; à diária, N dias depois à hora de saída. */
export function saidaPrevista(entrada: Date, modo: 'DIA' | 'HORA', qtd: number, rg: RegrasHotel): Date {
  if (modo === 'HORA') return new Date(entrada.getTime() + (Number(qtd) || 0) * 3_600_000);
  const s = new Date(entrada.getFullYear(), entrada.getMonth(), entrada.getDate() + Math.max(1, Math.round(Number(qtd) || 0)));
  const [h, m] = rg.saida.split(':').map(Number);
  s.setHours(h || 0, m || 0, 0, 0);
  return s;
}

export const unidadeEstadia = (modo: 'DIA' | 'HORA', q: number | string) => (modo === 'HORA' ? `${Number(q) || 0} hora(s)` : `${Number(q) || 0} diária(s)`);

/** Contagens da barra do mapa (decorarEcra, hotelaria.js:243-245). */
export function contagemQuartos(quartos: Pick<Quarto, 'estadia'>[]): { livres: number; ocupados: number; atrasados: number } {
  const ocupados = quartos.filter((q) => q.estadia);
  return { livres: quartos.length - ocupados.length, ocupados: ocupados.length, atrasados: ocupados.filter((q) => q.estadia!.atrasado).length };
}

/** Estado visual do cartão do quarto (decorarEcra, hotelaria.js:227-238). */
export function estadoQuarto(q: Pick<Quarto, 'produto_id' | 'estadia'>, activo: number | null): { activo: boolean; situacao: 'LIVRE' | 'OCUPADO' | 'ATRASADO' } {
  return { activo: q.produto_id === activo, situacao: !q.estadia ? 'LIVRE' : q.estadia.atrasado ? 'ATRASADO' : 'OCUPADO' };
}

/**
 * Consumos gravados na estadia → carrinho (carregarEstadias, hotelaria.js:84-89). Os produtos que já não estão no catálogo
 * do POS ficam no carrinho com a descrição e o preço gravados (o legado fazia o mesmo), para não se perderem ao gravar.
 */
export function carrinhoDosConsumos(itens: ConsumoEstadia[] | null | undefined, produtos: ProdutoPOS[]): ItemCarrinho[] {
  const porId = new Map(produtos.map((p) => [p.id, p]));
  const carrinho: ItemCarrinho[] = [];
  for (const c of itens ?? []) {
    const quantidade = Number(c.quantidade) || 0;
    if (quantidade <= 0) continue;
    const preco = paraCentimos(c.preco_unitario ?? 0);
    const p = porId.get(Number(c.produto_id));
    if (p) carrinho.push({ ...itemDeProduto(p, quantidade), preco });
    else carrinho.push({ produto_id: Number(c.produto_id), codigo: null, nome: c.descricao ?? `Produto #${c.produto_id}`, quantidade, preco_catalogo: preco, preco, taxa: Number(c.taxa_imposto ?? 0) || 0 });
  }
  return carrinho;
}

/** Carrinho → corpo do PUT /pos/hotelaria/estadias/{id}/consumos (o preço só segue quando difere do catálogo, como na venda). */
export function consumosDoCarrinho(carrinho: ItemCarrinho[]): { produto_id: number; quantidade: number; preco_unitario?: number }[] {
  return carrinho.map((i) => ({ produto_id: i.produto_id, quantidade: i.quantidade, ...(i.preco !== i.preco_catalogo ? { preco_unitario: i.preco / 100 } : {}) }));
}

export interface DadosCheckin {
  modo: 'DIA' | 'HORA';
  entrada: Date | null;
  quantidade: number;
  preco: number;
}

/** Pré-visualização do check-in (hotelPrever, hotelaria.js:322-342): texto da saída e do alojamento, avisos e bloqueio. */
export function preverCheckin(d: DadosCheckin, quarto: Pick<Quarto, 'horas_minimas'>, rg: RegrasHotel): { saida: Date | null; alojamento: number; avisos: string[]; bloqueia: boolean } {
  const avisos: string[] = [];
  let bloqueia = false;
  const minimo = Math.max(1, Number(quarto.horas_minimas) || 1);
  const valida = !!d.entrada && !Number.isNaN(d.entrada.getTime());
  if (!valida) {
    avisos.push('Indique a data e hora de entrada.');
    bloqueia = true;
  }
  if (d.modo === 'HORA' && valida && horaBloqueada(d.entrada!, rg)) {
    avisos.push(`Venda à hora bloqueada entre as ${rg.de} e as ${rg.ate}. Use a diária.`);
    bloqueia = true;
  }
  if (d.modo === 'HORA' && d.quantidade < minimo) {
    avisos.push(`Mínimo de ${minimo} hora(s).`);
    bloqueia = true;
  }
  if (!(d.quantidade > 0)) {
    avisos.push('Indique a quantidade.');
    bloqueia = true;
  }
  let saida: Date | null = null;
  if (valida && d.quantidade > 0) {
    saida = saidaPrevista(d.entrada!, d.modo, d.quantidade, rg);
    if (d.modo === 'DIA' && d.entrada!.getHours() * 60 + d.entrada!.getMinutes() < minutos(rg.entrada)) avisos.push(`Entrada antes das ${rg.entrada} (check-in antecipado).`);
  }
  return { saida, alojamento: Math.round(d.quantidade * d.preco * 100), avisos, bloqueia };
}
