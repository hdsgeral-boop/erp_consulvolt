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
  it('modelo do legado: duas vias, rubricas, INSS, IRT, líquido por extenso e forma de pagamento', () => {
    render(<ReciboSalario resultado={{ ...base, banco: 'Banco Fictício', iban: 'AO06004000000000000000000' }} mesAno="09/2026" colaborador={{ nome: 'Colaborador Teste', nif: '000' }} empresa={{ nome: 'Empresa Teste' }} />);
    expect(screen.getAllByText(/N\.º 202609-0012/)).toHaveLength(2);
    expect(screen.getByText('Original — Colaborador')).toBeInTheDocument();
    expect(screen.getByText('Duplicado — Entidade Patronal')).toBeInTheDocument();
    expect(screen.getAllByText('Salário Base')).toHaveLength(2);
    expect(screen.getAllByText('Adiantamento')).toHaveLength(2);
    expect(screen.getAllByText('Segurança Social — INSS (3%)')).toHaveLength(2);
    expect(screen.getAllByText('Retenção na fonte — IRT Grupo A')).toHaveLength(2);
    expect(screen.queryByText('Dias de Trabalho')).not.toBeInTheDocument();
    expect(screen.getAllByText(/Líquido a receber/)[0].parentElement?.textContent).toMatch(/189.460,00 Kz/);
    expect(screen.getAllByText(/Cento e oitenta e nove mil quatrocentos e sessenta kwanzas/)).toHaveLength(2);
    expect(screen.getAllByText(/Banco Fictício · IBAN AO06/)).toHaveLength(2);
  });

  it('avençado: IRT do Grupo B, sem Segurança Social; uma via no portal', () => {
    render(<ReciboSalario resultado={{ ...base, avencado: true, inss_trabalhador: '0.00' }} mesAno="09/2026" colaborador={{ nome: 'Avençado' }} vias={1} />);
    expect(screen.getByText('Retenção na fonte — IRT Grupo B (6,5%)')).toBeInTheDocument();
    expect(screen.queryByText(/Segurança Social — INSS/)).not.toBeInTheDocument();
    expect(screen.queryByText('Duplicado — Entidade Patronal')).not.toBeInTheDocument();
  });
});
