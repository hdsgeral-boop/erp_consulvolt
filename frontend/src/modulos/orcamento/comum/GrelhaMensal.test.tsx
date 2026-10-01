import { render, screen } from '@testing-library/react';
import { GrelhaMensal } from './GrelhaMensal';
import type { Rubrica } from './tipos';

const rubrica = (id: number, codigo: string, natureza: Rubrica['natureza'], grupo: string): Rubrica => ({
  id, tipo: 'EXPLORACAO', codigo, nome: `Rubrica ${codigo}`, natureza, grupo, contas: [], ordem: id, ativo: true, descricao: null, controlo: null, indutor: null, cambial_pct: null, variavel_pct: null,
});

describe('GrelhaMensal (leitura)', () => {
  it('mostra grupos, rubricas e os totais de proveitos, custos e resultado', () => {
    render(
      <GrelhaMensal
        tipo="EXPLORACAO"
        rubricas={[rubrica(1, 'P01', 'PROVEITO', 'Proveitos'), rubrica(2, 'C01', 'CUSTO', 'Custos')]}
        valores={{ 1: { valores: Array(12).fill(100), notas: null }, 2: { valores: Array(12).fill(40), notas: null } }}
      />,
    );
    expect(screen.getByText('Proveitos')).toBeInTheDocument();
    expect(screen.getByText('P01 Rubrica P01')).toBeInTheDocument();
    expect(screen.getByText('Total proveitos')).toBeInTheDocument();
    expect(screen.getByText('Resultado')).toBeInTheDocument();
    // resultado anual = 12 × (100 − 40) = 720
    expect(screen.getAllByText(/720,00/).length).toBeGreaterThan(0);
  });
});
