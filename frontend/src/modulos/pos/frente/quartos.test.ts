import { describe, expect, it } from 'vitest';
import type { ProdutoPOS } from '../comum/tipos';
import type { Quarto } from '../hotelaria/tipos';
import { carrinhoDosConsumos, consumosDoCarrinho, contagemQuartos, estadoQuarto, horaBloqueada, preverCheckin, regrasHotel, saidaPrevista } from './quartos';

const produtos = [
  { id: 1, codigo: 'B1', nome: 'Água', preco_unitario: '300.00', taxa_imposto: '14' },
  { id: 2, codigo: 'B2', nome: 'Sumo', preco_unitario: '500.00', taxa_imposto: '14' },
] as unknown as ProdutoPOS[];

const quarto = (id: number, estadia: null | { atrasado: boolean }): Quarto => ({
  produto_id: id,
  codigo: `Q${id}`,
  nome: `Quarto ${id}`,
  preco_por_dia: '28000.00',
  preco_por_hora: '4000.00',
  horas_minimas: '2',
  taxa_imposto: '14',
  estado: estadia ? 'OCUPADO' : 'LIVRE',
  estadia: estadia && { id: id * 10, nome_hospede: 'Hóspede', entrada_em: '2026-10-01T14:00:00', saida_prevista_em: '2026-10-02T12:00:00', atrasado: estadia.atrasado, total_em_aberto: '28000.00' },
});

describe('modo hotelaria da frente de caixa', () => {
  it('regras do terminal com as predefinições do legado', () => {
    expect(regrasHotel(null)).toEqual({ entrada: '14:00', saida: '12:00', tolerancia: 60, bloqueio: true, de: '21:00', ate: '08:00' });
    expect(regrasHotel({ hotel_hora_saida: '11:30:00', hotel_tolerancia_atraso_min: 0, hotel_bloco_horas: false })).toMatchObject({ saida: '11:30', tolerancia: 0, bloqueio: false });
  });

  it('bloqueio da venda à hora (intervalo que passa a meia-noite) e saída prevista', () => {
    const rg = regrasHotel(null);
    expect(horaBloqueada(new Date(2026, 9, 1, 22, 0), rg)).toBe(true);
    expect(horaBloqueada(new Date(2026, 9, 1, 7, 59), rg)).toBe(true);
    expect(horaBloqueada(new Date(2026, 9, 1, 8, 0), rg)).toBe(false);
    expect(horaBloqueada(new Date(2026, 9, 1, 22, 0), { ...rg, bloqueio: false })).toBe(false);
    expect(saidaPrevista(new Date(2026, 9, 1, 15, 0), 'DIA', 2, rg)).toEqual(new Date(2026, 9, 3, 12, 0));
    expect(saidaPrevista(new Date(2026, 9, 1, 15, 0), 'HORA', 2.5, rg)).toEqual(new Date(2026, 9, 1, 17, 30));
  });

  it('pré-visualização do check-in: avisos e bloqueio', () => {
    const rg = regrasHotel(null);
    const q = quarto(1, null);
    const dia = preverCheckin({ modo: 'DIA', entrada: new Date(2026, 9, 1, 10, 0), quantidade: 1, preco: 28000 }, q, rg);
    expect(dia.bloqueia).toBe(false);
    expect(dia.alojamento).toBe(2_800_000);
    expect(dia.avisos).toEqual(['Entrada antes das 14:00 (check-in antecipado).']);
    const hora = preverCheckin({ modo: 'HORA', entrada: new Date(2026, 9, 1, 23, 0), quantidade: 1, preco: 4000 }, q, rg);
    expect(hora.bloqueia).toBe(true);
    expect(hora.avisos).toEqual(['Venda à hora bloqueada entre as 21:00 e as 08:00. Use a diária.', 'Mínimo de 2 hora(s).']);
    expect(preverCheckin({ modo: 'DIA', entrada: null, quantidade: 1, preco: 1 }, q, rg)).toMatchObject({ bloqueia: true, saida: null });
  });

  it('contagens da barra e estado dos cartões', () => {
    const lista = [quarto(1, null), quarto(2, { atrasado: false }), quarto(3, { atrasado: true })];
    expect(contagemQuartos(lista)).toEqual({ livres: 1, ocupados: 2, atrasados: 1 });
    expect(contagemQuartos([])).toEqual({ livres: 0, ocupados: 0, atrasados: 0 });
    expect(estadoQuarto(lista[0], 1)).toEqual({ activo: true, situacao: 'LIVRE' });
    expect(estadoQuarto(lista[1], 1)).toEqual({ activo: false, situacao: 'OCUPADO' });
    expect(estadoQuarto(lista[2], null)).toEqual({ activo: false, situacao: 'ATRASADO' });
  });

  it('consumos da estadia ↔ carrinho (preço alterado e produto fora do catálogo)', () => {
    const carrinho = carrinhoDosConsumos(
      [
        { produto_id: 1, descricao: 'Água', quantidade: 2, preco_unitario: 300, taxa_imposto: 14 },
        { produto_id: 2, descricao: 'Sumo', quantidade: '1.000', preco_unitario: '450.00', taxa_imposto: 14 },
        { produto_id: 9, descricao: 'Toalha extra', quantidade: 1, preco_unitario: 1000, taxa_imposto: 14 },
        { produto_id: 1, descricao: 'Água', quantidade: 0, preco_unitario: 300 },
      ],
      produtos,
    );
    expect(carrinho.map((i) => [i.produto_id, i.nome, i.quantidade, i.preco, i.preco_catalogo, i.taxa])).toEqual([
      [1, 'Água', 2, 30000, 30000, 14],
      [2, 'Sumo', 1, 45000, 50000, 14],
      [9, 'Toalha extra', 1, 100000, 100000, 14],
    ]);
    expect(consumosDoCarrinho(carrinho)).toEqual([
      { produto_id: 1, quantidade: 2 },
      { produto_id: 2, quantidade: 1, preco_unitario: 450 },
      { produto_id: 9, quantidade: 1 },
    ]);
    expect(carrinhoDosConsumos(null, produtos)).toEqual([]);
  });
});
