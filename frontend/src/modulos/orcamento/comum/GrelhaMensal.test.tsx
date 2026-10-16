import { render, screen } from '@testing-library/react';
import { GrelhaMensal, linhasGrelha } from './GrelhaMensal';
import type { Rubrica } from './tipos';

const rubrica = (id: number, codigo: string, natureza: Rubrica['natureza'], grupo: string | null, tipo: Rubrica['tipo'] = 'EXPLORACAO'): Rubrica => ({
  id, tipo, codigo, nome: `Rubrica ${codigo}`, natureza, grupo, contas: [], ordem: id, ativo: true, descricao: null, controlo: null, indutor: null, cambial_pct: null, variavel_pct: null,
} as Rubrica);

describe('linhasGrelha (disposição do legado)', () => {
  it('natureza → grupo (com subtotal) → rubricas → total da natureza → resultado previsto', () => {
    const l = linhasGrelha('EXPLORACAO', [rubrica(1, 'P01', 'PROVEITO', 'Vendas'), rubrica(2, 'P02', 'PROVEITO', 'Vendas'), rubrica(3, 'C01', 'CUSTO', null)], {
      1: { valores: Array(12).fill(100), notas: null }, 2: { valores: Array(12).fill(50), notas: null }, 3: { valores: Array(12).fill(40), notas: null },
    });
    expect(l.map((x) => `${x.tipo}:${x.rotulo}`)).toEqual([
      'natureza:Proveitos', 'grupo:Vendas', 'rubrica:P01 Rubrica P01', 'rubrica:P02 Rubrica P02', 'total:Total proveitos',
      'natureza:Custos', 'grupo:Sem grupo', 'rubrica:C01 Rubrica C01', 'total:Total custos', 'resultado:Resultado previsto',
    ]);
    expect(l[1].valores[0]).toBe(150);
    expect(Number(l[1].total)).toBe(1800);
    expect(Number(l[9].total)).toBe(12 * 110);
  });
  it('tesouraria: fluxo de caixa do mês e saldo final previsto acumulado desde o saldo inicial', () => {
    const l = linhasGrelha('TESOURARIA', [rubrica(1, 'R1', 'RECEBIMENTO', null, 'TESOURARIA'), rubrica(2, 'P1', 'PAGAMENTO', null, 'TESOURARIA')], {
      1: { valores: Array(12).fill(10), notas: null }, 2: { valores: Array(12).fill(30), notas: null },
    }, 100);
    const fluxo = l.find((x) => x.rotulo === 'Fluxo de caixa do mês')!;
    const saldo = l.find((x) => x.rotulo === 'Saldo final previsto')!;
    expect(fluxo.valores[0]).toBe(-20);
    expect(saldo.valores.slice(0, 3)).toEqual([80, 60, 40]);
    expect(saldo.total).toBeNull();
  });
});

describe('GrelhaMensal (leitura)', () => {
  it('mostra naturezas, grupos, rubricas, totais, «Total {ano}» e «Real. {ano-1}»', () => {
    render(
      <GrelhaMensal
        tipo="EXPLORACAO"
        ano={2027}
        realAnterior={{ 1: '1000' }}
        rubricas={[rubrica(1, 'P01', 'PROVEITO', 'Vendas'), rubrica(2, 'C01', 'CUSTO', 'Pessoal')]}
        valores={{ 1: { valores: Array(12).fill(100), notas: null }, 2: { valores: Array(12).fill(40), notas: null } }}
      />,
    );
    expect(screen.getByText('Proveitos')).toBeInTheDocument();
    expect(screen.getByText('Vendas')).toBeInTheDocument();
    expect(screen.getByText('Total proveitos')).toBeInTheDocument();
    expect(screen.getByText('Resultado previsto')).toBeInTheDocument();
    expect(screen.getAllByText('Total 2027').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Real. 2026').length).toBeGreaterThan(0);
    expect(screen.getAllByText(/1\s?000,00/).length).toBeGreaterThan(0);
    // resultado anual = 12 × (100 − 40) = 720
    expect(screen.getAllByText(/720,00/).length).toBeGreaterThan(0);
  });
});
