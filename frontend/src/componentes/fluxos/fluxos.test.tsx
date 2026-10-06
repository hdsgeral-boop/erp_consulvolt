import { fireEvent, render, screen } from '@testing-library/react';
import { DetalheEtapa, DiagramaFluxo, LegendaFluxo, MiniProgresso, PillEstado } from './ComponentesFluxo';
import { estadoDe, estadoProcesso, ligacaoActiva } from './estados';
import { temIconeFa } from './IconeFa';
import { workflowHtml } from './workflowHtml';

const ETAPAS = [
  { id: 'base', nome: 'Dados base', icone: 'users' },
  { id: 'calc', nome: 'Lançar rubricas', icone: 'calculator' },
  { id: 'fecho', nome: 'Fechar cálculo', icone: 'lock' },
];

// Ícones de etapa e de separador do catálogo do backend (CatalogoFluxos::ICONES): todos têm desenho
const ICONES_CATALOGO = (
  'users calculator lock check-double book hand-holding-usd umbrella-beach calendar-alt calendar-plus user-tie balance-scale star-half-alt list-check sync-alt eye ' +
  'seedling file-invoice-dollar file-alt shopping-basket truck cash-register door-open balance-scale-left tshirt inbox cut soap box-open hard-hat folder-plus sitemap ' +
  'truck-loading clipboard-list search-dollar shopping-cart dolly couch file-invoice barcode tags percent university edit file-upload receipt pencil-ruler paper-plane ' +
  'bell handshake lightbulb tasks exchange-alt file-signature play calendar-check'
).split(' ');

describe('Fluxo de Processos — peças visuais do legado', () => {
  it('tem desenho para todos os ícones do catálogo e dos estados', () => {
    for (const n of [...ICONES_CATALOGO, 'check', 'circle-regular', 'exclamation-triangle', 'times-circle', 'check-circle', 'arrow-right']) expect(temIconeFa(n), n).toBe(true);
  });

  it('estados: desconhecido → por fazer; ligação verde quando a etapa começou; estado global', () => {
    expect(estadoDe('xpto')).toBe('fazer');
    expect(estadoDe('na')).toBe('concluida');
    expect([ligacaoActiva('concluida'), ligacaoActiva('curso'), ligacaoActiva('bloqueada'), ligacaoActiva('fazer')]).toEqual([true, true, true, false]);
    expect(estadoProcesso({ bloqueado: true, concluidas: 1, total_etapas: 3 }).rotulo).toBe('Bloqueado');
    expect(estadoProcesso({ bloqueado: false, concluidas: 3, total_etapas: 3 }).rotulo).toBe('Concluído');
    expect(estadoProcesso({ bloqueado: false, concluidas: 1, total_etapas: 3 }).rotulo).toBe('Em curso');
  });

  it('pastilha, mini-progresso e legenda com as classes de cor do legado', () => {
    const { container } = render(
      <>
        <PillEstado estado="bloqueada" />
        <MiniProgresso estados={['concluida', 'curso', 'fazer']} />
        <LegendaFluxo />
      </>,
    );
    expect(container.querySelector('.fluxo-pill.bloq')?.textContent).toBe('Bloqueada');
    expect([...container.querySelectorAll('.fluxo-mini i')].map((i) => i.className)).toEqual(['ok', 'curso', 'fazer']);
    expect(screen.getByText(/Os botões abrem o ecrã/)).toBeInTheDocument();
  });

  it('diagrama: nós por estado, etapa seleccionada e setas do teclado', () => {
    const escolher = vi.fn();
    const { container } = render(
      <DiagramaFluxo
        etapas={ETAPAS}
        estados={{ base: { estado: 'concluida', resumo: '3 colaboradores' }, calc: { estado: 'curso' }, fecho: { estado: 'fazer' } }}
        seleccionada="calc"
        aoSeleccionar={escolher}
        concluidas={1}
      />,
    );
    expect([...container.querySelectorAll('li.fluxo-etapa')].map((l) => l.className)).toEqual(['fluxo-etapa ok', 'fluxo-etapa curso', 'fluxo-etapa fazer']);
    expect(screen.getByRole('button', { name: /Lançar rubricas/ })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '33');
    fireEvent.keyDown(container.querySelector('ol')!, { key: 'ArrowRight' });
    expect(escolher).toHaveBeenCalledWith('fecho');
  });

  it('detalhe: pendências com ícone, «Sem pendências» e acções', () => {
    const ir = vi.fn();
    const { rerender } = render(
      <DetalheEtapa etapa={ETAPAS[0]} estado="bloqueada" factos={[{ rotulo: 'Colaboradores', texto: '3' }]} problemas={[{ nivel: 'erro', texto: 'Conta em falta' }]} accoes={[{ rotulo: 'Corrigir', primaria: true, aoClicar: ir }]} />,
    );
    expect(screen.getByText('Conta em falta').closest('li')).toHaveClass('erro');
    fireEvent.click(screen.getByRole('button', { name: /Corrigir/ }));
    expect(ir).toHaveBeenCalled();
    rerender(<DetalheEtapa etapa={ETAPAS[0]} estado="concluida" factos={[]} problemas={[]} accoes={[]} />);
    expect(screen.getByText(/Sem pendências nesta etapa/)).toBeInTheDocument();
    rerender(<DetalheEtapa etapa={ETAPAS[0]} estado="fazer" factos={[]} problemas={[]} accoes={[]} />);
    expect(screen.getByText(/fica disponível quando a anterior/)).toBeInTheDocument();
  });

  it('workflow impresso: caixas numeradas com setas ▶, estados e narrativa', () => {
    const html = workflowHtml({
      narrativa: { titulo: 'Salários', objectivo: 'Pagar <bem>', intervenientes: ['RH'], etapas: ETAPAS.map((e) => ({ nome: e.nome, quem: 'RH', descricao: 'd', controlos: 'c', resultado: 'r' })) },
      etapas: ETAPAS,
      estados: { base: { estado: 'concluida' }, calc: { estado: 'bloqueada', resumo: 'falta' } },
      etapaSeleccionada: 'calc',
      pendencias: [{ nivel: 'erro', texto: 'x' }],
      factos: [{ rotulo: 'A', texto: '1' }],
    });
    expect(html.match(/&#9654;/g)).toHaveLength(2);
    expect(html).toContain('Pagar &lt;bem&gt;');
    expect(html).toContain('class="no sel"');
    expect(html).toContain('Bloqueada');
    expect(html).toContain('Etapa seleccionada: Lançar rubricas');
  });
});
