import { render, screen } from '@testing-library/react';
import { simularLargura } from '@/componentes/responsivo/testes/simularLargura';
import { Gantt } from './Gantt';

describe('Gantt (componente)', () => {
  it('desenha uma linha por item com as barras e o estado vazio', () => {
    render(
      <Gantt
        inicio="2026-09-01"
        fim="2026-09-30"
        linhas={[
          { chave: 'm-1', nome: 'Fase 1', nivel: 0, tipo: 'marco', inicio: '2026-09-30', fim: '2026-09-30' },
          { chave: 't-1', nome: 'Levantamento', nivel: 1, tipo: 'tarefa', inicio: '2026-09-01', fim: '2026-09-15', progresso: 40, estado: 'EM_CURSO' },
          { chave: 't-2', nome: 'Sem fim', nivel: 1, tipo: 'tarefa', inicio: '2026-09-20', fim: null },
        ]}
      />,
    );
    expect(screen.getByText('Fase 1')).toBeInTheDocument();
    expect(screen.getByText('Levantamento')).toBeInTheDocument();
    expect(screen.getByText('Sem fim')).toBeInTheDocument();
  });

  it('sem linhas mostra a mensagem de vazio', () => {
    render(<Gantt linhas={[]} />);
    expect(screen.getByText('Sem tarefas com datas para mostrar')).toBeInTheDocument();
  });
});

describe('Gantt (responsivo e impressão)', () => {
  afterEach(() => simularLargura(null));

  it('em telemóvel estreita a coluna dos nomes, desloca dentro do contentor e marca as barras para impressão a cores', () => {
    simularLargura(375);
    const { container } = render(
      <Gantt inicio="2026-09-01" fim="2026-09-30" linhas={[{ chave: 't-1', nome: 'Tarefa', nivel: 0, tipo: 'tarefa', inicio: '2026-09-01', fim: '2026-09-10', progresso: 50, estado: 'EM_CURSO' }]} />,
    );
    const raiz = container.querySelector('.erp-gantt') as HTMLElement;
    expect(raiz.classList.contains('erp-deslocar-x')).toBe(true);
    expect((raiz.firstElementChild as HTMLElement).style.minWidth).toBe('860px');
    expect(container.querySelectorAll('.imp-cor').length).toBeGreaterThan(0);
  });
});
