import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { ResultadoSalarial } from '../api';
import { ReciboSalario } from './ReciboSalario';

const base: ResultadoSalarial = {
  colaborador_id: 12, tipo_organizacao_id: 1, unidade_negocio_id: null, centro_custo_id: null, avencado: false, reformado: false,
  dias_contrato: '22.00', dias_trabalhados: '22.00', bruto: '220000.00', base_inss: '200000.00', inss_trabalhador: '6000.00', inss_patronal: '16000.00',
  isencoes: '20000.00', base_irt: '194000.00', irt: '19540.00', descontos: '5000.00', liquido: '189460.00', avisos: [], modo_calculo: 'ATUAL',
  rubricas: [
    { nome: 'Salário Base', tipo: 'VENCIMENTO', valor: '200000.00', infotipo_id: 1 },
    { nome: 'Alimentação', tipo: 'VENCIMENTO', valor: '20000.00', infotipo_id: 2 },
    { nome: 'Dias de Trabalho', tipo: 'OUTROS', valor: '22.00', infotipo_id: 3, informativa: true },
    { nome: 'Adiantamento', tipo: 'DESCONTO', valor: '5000.00', infotipo_id: 4 },
  ],
};

describe('ReciboSalario', () => {
  it('mostra vencimentos, descontos, INSS, IRT e líquido; o número do recibo como no servidor', () => {
    render(<ReciboSalario resultado={base} mesAno="09/2026" colaborador={{ nome: 'Colaborador Teste', nif: '000' }} empresa={{ nome: 'Empresa Teste' }} />);
    expect(screen.getByText(/N\.º 202609-0012/)).toBeInTheDocument();
    expect(screen.getByText('Salário Base')).toBeInTheDocument();
    expect(screen.getByText('Adiantamento')).toBeInTheDocument();
    expect(screen.getByText('Segurança Social (trabalhador)')).toBeInTheDocument();
    expect(screen.getByText('IRT')).toBeInTheDocument();
    expect(screen.queryByText('Dias de Trabalho')).not.toBeInTheDocument();
    expect(screen.getByText(/Líquido a receber/).textContent).toMatch(/189.460,00 Kz/);
  });

  it('avençado: IRT do Grupo B e sem Segurança Social', () => {
    render(<ReciboSalario resultado={{ ...base, avencado: true, inss_trabalhador: '0.00' }} mesAno="09/2026" colaborador={{ nome: 'Avençado' }} />);
    expect(screen.getByText('IRT (Grupo B — 6,5 %)')).toBeInTheDocument();
    expect(screen.queryByText('Segurança Social (trabalhador)')).not.toBeInTheDocument();
  });
});
