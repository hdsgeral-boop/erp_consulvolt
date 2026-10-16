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

describe('Gantt (Versão Actual: escala e precedências)', () => {
  afterEach(() => simularLargura(null));

  it('desenha as setas de precedência entre barras visíveis e a coluna extra (só em ecrã largo)', () => {
    simularLargura(1400);
    const { getByTestId, container, getByText } = render(
      <Gantt
        inicio="2026-09-01"
        fim="2026-09-30"
        escala="semana"
        ligacoes={[{ de: 't-1', para: 't-2', tipo: 'FS' }, { de: 't-1', para: 't-9', tipo: 'SS' }]}
        colunaExtra={{ titulo: 'Precedência', valor: (l) => (l.chave === 't-2' ? '← T1' : null) }}
        linhas={[
          { chave: 't-1', nome: 'T1', nivel: 0, tipo: 'tarefa', inicio: '2026-09-01', fim: '2026-09-10' },
          { chave: 't-2', nome: 'T2', nivel: 0, tipo: 'tarefa', inicio: '2026-09-11', fim: '2026-09-20' },
        ]}
      />,
    );
    // só a ligação entre duas linhas visíveis (a de t-9 é ignorada): 2 segmentos em cotovelo
    expect(getByTestId('gantt-precedencias').querySelectorAll('g line').length).toBe(2);
    expect(getByText('← T1')).toBeInTheDocument();
    expect(container.querySelectorAll('[data-bar]').length).toBe(2);
  });
});

describe('Gantt (cores: por estado ou por nível)', () => {
  const linhas = [
    { chave: 'p-1', nome: 'Projecto', nivel: 1, tipo: 'projecto' as const, inicio: '2026-09-01', fim: '2026-09-30' },
    { chave: 't-1', nome: 'Tarefa', nivel: 2, tipo: 'tarefa' as const, inicio: '2026-09-01', fim: '2026-09-10', estado: 'CONCLUIDA' },
  ];
  const bordas = (c: HTMLElement) => [...c.querySelectorAll<HTMLElement>('[data-bar]')].map((e) => e.style.border);

  it('por omissão pinta por estado (Planeamento)', () => {
    const { container } = render(<Gantt inicio="2026-09-01" fim="2026-09-30" linhas={linhas} />);
    expect(bordas(container)[1]).toContain('rgb(16, 185, 129)'); // CONCLUIDA #10b981
  });

  it('corPor="nivel" pinta como o Gantt Global do legado: projecto roxo, tarefas azuis', () => {
    const { container } = render(<Gantt inicio="2026-09-01" fim="2026-09-30" linhas={linhas} corPor="nivel" />);
    const b = bordas(container);
    expect(b[0]).toContain('rgb(139, 92, 246)'); // #8b5cf6
    expect(b[1]).toContain('rgb(59, 130, 246)'); // #3b82f6
  });
});
