import { fireEvent, render, screen } from '@testing-library/react';
import type { ResumoPortal } from '../api';
import { IndicadoresPortal } from './IndicadoresPortal';

const base: ResumoPortal = {
  colaborador: { id: 1, nome_completo: 'Ana Silva', nif: '123', estado: 'ACTIVO', cargo_funcao_id: null, unidade_organica_id: null, data_admissao: null },
  chefia_colaborador_id: null,
  ferias: { direito: 22, marcados: 4, saldo: 18 },
  ano: 2026,
  faltas_por_justificar: 0,
  pedidos_pendentes: 2,
  documentos_emitidos: 3,
  autoavaliacao: { ano: 2026, periodo: 'ANUAL', estado: 'RASCUNHO' },
  aprovacoes_para_mim: 0,
};

describe('IndicadoresPortal (portal_ui.js:176-183)', () => {
  it('mostra Documentos emitidos e Autoavaliação; esconde Ausências e Aprovações quando estão a zero', () => {
    render(<IndicadoresPortal resumo={base} />);
    expect(screen.getByText('Documentos emitidos')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument();
    expect(screen.getByText('Autoavaliação 2026')).toBeInTheDocument();
    expect(screen.getByText('Rascunho')).toBeInTheDocument();
    expect(screen.getByText('Férias disponíveis 2026')).toBeInTheDocument();
    expect(screen.queryByText('Ausências por justificar')).not.toBeInTheDocument();
    expect(screen.queryByText('Aprovações à sua espera')).not.toBeInTheDocument();
  });

  it('com valores mostra as caixas a vermelho; a das ausências abre o separador das faltas', () => {
    const abrir = vi.fn();
    render(<IndicadoresPortal resumo={{ ...base, faltas_por_justificar: 2, aprovacoes_para_mim: 5, autoavaliacao: { ano: 2026, periodo: 'ANUAL', estado: 'SUBMETIDA' } }} aoAbrirFaltas={abrir} />);
    expect(screen.getByText('Ausências por justificar')).toBeInTheDocument();
    expect(screen.getByText('Aprovações à sua espera')).toBeInTheDocument();
    expect(screen.getByText('Submetida')).toBeInTheDocument();
    fireEvent.click(screen.getByText('Ausências por justificar'));
    expect(abrir).toHaveBeenCalledTimes(1);
  });

  it('sem autoavaliação nem campos novos (resposta antiga): «Por fazer» e 0 documentos', () => {
    const { autoavaliacao: _a, documentos_emitidos: _d, ...antigo } = base;
    render(<IndicadoresPortal resumo={antigo} />);
    expect(screen.getByText('Por fazer')).toBeInTheDocument();
    expect(screen.getByText('Documentos emitidos').parentElement).toHaveTextContent('0');
  });
});
