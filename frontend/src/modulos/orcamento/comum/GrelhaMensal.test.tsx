import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { GrelhaMensal, linhasGrelha, type ValoresRubrica } from './GrelhaMensal';
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

// Colagem do Excel (modo editável): só os 12 meses (e as notas); a «Rubrica» fica de fora — as linhas já
// correspondem às rubricas do orçamento, não se criam linhas novas por colagem.
let capturados: Record<number, ValoresRubrica> = {};
function GrelhaEditavel({ rubricas, inicial = {} }: { rubricas: Rubrica[]; inicial?: Record<number, ValoresRubrica> }) {
  const [valores, setValores] = useState<Record<number, ValoresRubrica>>(inicial);
  return (
    <GrelhaMensal
      tipo="EXPLORACAO"
      ano={2027}
      rubricas={rubricas}
      valores={valores}
      editavel
      aoMudar={(rid, v) => {
        capturados = { ...capturados, [rid]: v };
        setValores((s) => ({ ...s, [rid]: v }));
      }}
    />
  );
}

describe('GrelhaMensal (colagem do Excel)', () => {
  beforeEach(() => {
    capturados = {};
  });

  it('botão «Colar do Excel»: preenche os meses a partir da 1.ª rubrica da grelha', () => {
    render(<GrelhaEditavel rubricas={[rubrica(1, 'P01', 'PROVEITO', 'Vendas'), rubrica(2, 'C01', 'CUSTO', null)]} />);
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(screen.getByLabelText('Linhas a colar'), {
      target: { value: '100\t110\t120\t130\t140\t150\t160\t170\t180\t190\t200\t210\n10\t20\t30\t40\t50\t60\t70\t80\t90\t100\t110\t120' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
    expect(capturados[1].valores).toEqual([100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200, 210]);
    expect(capturados[2].valores).toEqual([10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120]);
  }, 30_000);

  it('colagem directa numa célula: preenche a partir do mês/rubrica clicada, pela ordem das rubricas na grelha', () => {
    render(<GrelhaEditavel rubricas={[rubrica(1, 'P01', 'PROVEITO', 'Vendas'), rubrica(2, 'C01', 'CUSTO', null)]} />);
    const celulaMarco = screen.getByLabelText('P01 Mar') as HTMLElement;
    fireEvent.paste(celulaMarco, { clipboardData: { getData: () => '300\t400\n30\t40' } });
    // mês 2 (Mar, índice 2) e 3 (Abr) da 1.ª rubrica; 2.ª rubrica continua sem colagem nos meses anteriores
    expect(capturados[1].valores[2]).toBe(300);
    expect(capturados[1].valores[3]).toBe(400);
    expect(capturados[1].valores[0]).toBe(0);
  });

  it('valor não reconhecido avisa e não altera o mês', () => {
    render(<GrelhaEditavel rubricas={[rubrica(1, 'P01', 'PROVEITO', 'Vendas')]} />);
    const celulaJan = screen.getByLabelText('P01 Jan') as HTMLElement;
    fireEvent.paste(celulaJan, { clipboardData: { getData: () => 'abc\t200' } });
    expect(screen.getByText(/não reconhecido/)).toBeInTheDocument();
    expect(capturados[1].valores[1]).toBe(200);
  });
});
