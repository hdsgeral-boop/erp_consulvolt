import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { formatarKz } from '@/utilitarios/formatacao';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn(),
}));

import {
  AccoesLinha,
  CartaoKpi,
  CHAVE_LOCAL_COLUNAS,
  EstadoVazio,
  GrelhaKpi,
  TabelaComModos,
  aplicarFiltros,
  calcularTotal,
  nomeIconeFa,
  normalizarFiltroPersonalizado,
  passaCondicao,
  reporPreferenciasColunasLocal,
  reporPreferenciasVistaLocal,
  type ColunaLegado,
} from './index';

interface Artigo {
  id: number;
  nome: string;
  armazem: string;
  quantidade: number;
  valor: string;
}

const dados: Artigo[] = [
  { id: 1, nome: 'Cimento 50 kg', armazem: 'Central', quantidade: 10, valor: '1500.50' },
  { id: 2, nome: 'Areia fina', armazem: 'Luanda Sul', quantidade: 3, valor: '200.00' },
  { id: 3, nome: 'Ção (teste.*)', armazem: 'Central', quantidade: 7, valor: '99.50' },
];

const colunas: ColunaLegado<Artigo>[] = [
  { title: 'Nome', dataIndex: 'nome' },
  { title: 'Armazém', dataIndex: 'armazem' },
  { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', total: true, formatoTotal: (v) => v.toLocaleString('pt-PT') },
  { title: 'Valor', dataIndex: 'valor', align: 'right', total: true, render: (v: string) => Number(v).toLocaleString('pt-PT', { minimumFractionDigits: 2 }) },
];

/** O texto do Testing Library é normalizado (espaços não separáveis → espaço); o valor esperado também. */
const kz = (n: number) => formatarKz(n).replace(/\s/g, ' ');

function comCliente(no: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}>{no}</QueryClientProvider>);
}

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  obter.mockReset();
  enviar.mockReset();
  obter.mockResolvedValue([]);
  enviar.mockResolvedValue({ dados: null, mensagem: 'ok' });
  window.history.replaceState(null, '', '/m/stock/armazem_stock');
});

describe('filtros (lógica do antigo: tabelas_padrao.js)', () => {
  it('operadores: contém sem acentos/maiúsculas, começa, termina, igual, diferente, maior e menor', () => {
    expect(passaCondicao('Ção Lda', { operador: 'contains', valor: 'cao' })).toBe(true);
    expect(passaCondicao('Alfa', { operador: 'not_contains', valor: 'lf' })).toBe(false);
    expect(passaCondicao('Alfa', { operador: 'starts_with', valor: 'AL' })).toBe(true);
    expect(passaCondicao('Alfa', { operador: 'ends_with', valor: 'fa' })).toBe(true);
    expect(passaCondicao('Alfa', { operador: 'equals', valor: 'alfa' })).toBe(true);
    expect(passaCondicao('Alfa', { operador: 'not_equals', valor: 'alfa' })).toBe(false);
    expect(passaCondicao('1 500,50 Kz', { operador: 'gt', valor: '1000' })).toBe(true);
    expect(passaCondicao('1 500,50', { operador: 'lt', valor: '1000' })).toBe(false);
    expect(passaCondicao('texto', { operador: 'gt', valor: '1' })).toBe(false);
    expect(passaCondicao('qualquer', { operador: 'equals', valor: '   ' })).toBe(true);
  });

  it('o valor é sempre texto: «.*» não é expressão regular', () => {
    const r = aplicarFiltros(dados, colunas, { colunas: { nome: { operador: 'contains', valor: '.*' } }, personalizado: { juncao: 'todas', condicoes: [] } });
    expect(r.map((l) => l.id)).toEqual([3]);
  });

  it('caixas por coluna + filtro personalizado (todas / qualquer)', () => {
    const base = { colunas: { armazem: { operador: 'contains' as const, valor: 'central' } } };
    expect(aplicarFiltros(dados, colunas, { ...base, personalizado: { juncao: 'todas', condicoes: [] } }).map((l) => l.id)).toEqual([1, 3]);
    const pers = { juncao: 'qualquer' as const, condicoes: [{ coluna: 'quantidade', operador: 'gt' as const, valor: '8' }, { coluna: 'nome', operador: 'starts_with' as const, valor: 'cao' }] };
    expect(aplicarFiltros(dados, colunas, { ...base, personalizado: pers }).map((l) => l.id)).toEqual([1, 3]);
    expect(aplicarFiltros(dados, colunas, { ...base, personalizado: { ...pers, juncao: 'todas' } }).map((l) => l.id)).toEqual([]);
  });

  it('normaliza o filtro personalizado recebido (operadores desconhecidos fora, textos limitados)', () => {
    const f = normalizarFiltroPersonalizado({ juncao: 'x', condicoes: [{ coluna: 'a', operador: 'eval', valor: '1' }, { coluna: 'b', operador: 'equals', valor: 'x'.repeat(500) }] });
    expect(f.juncao).toBe('todas');
    expect(f.condicoes).toHaveLength(1);
    expect(f.condicoes[0].valor).toHaveLength(200);
  });
});

describe('totais', () => {
  it('soma valores numéricos (brutos ou texto pt-PT) e média', () => {
    expect(calcularTotal(colunas[2] as never, dados)).toBe(20);
    expect(calcularTotal(colunas[3] as never, dados)).toBe(1800);
    expect(calcularTotal({ title: 'X', dataIndex: 'quantidade', total: 'media' } as never, dados)).toBe(6.67);
  });
});

describe('ícones do antigo', () => {
  it('aceita «fas fa-…», «fa-…» e nomes da v6', () => {
    expect(nomeIconeFa('fas fa-eye')).toBe('eye');
    expect(nomeIconeFa('fa-print')).toBe('print');
    expect(nomeIconeFa('trash-can')).toBe('trash-alt');
  });
});

describe('TabelaComModos com o padrão das listas do antigo', () => {
  it('contador, filtro por coluna, linha TOTAL: e paginação «Mostrar … registos / Anterior · Próximo»', async () => {
    comCliente(<TabelaComModos<Artigo> rowKey="id" dataSource={dados} columns={colunas} modos={false} />);
    expect(screen.getByText('3 registos encontrados')).toBeInTheDocument();
    expect(screen.getByText('TOTAL:')).toBeInTheDocument();
    expect(screen.getByText(kz(1800))).toBeInTheDocument();
    expect(screen.getByText('Anterior')).toBeInTheDocument();
    expect(screen.getByText('Próximo')).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Registos por página' }).closest('.erp-paginacao-mostrar')).toHaveTextContent(/^Mostrar.*registos$/);

    fireEvent.change(screen.getByRole('textbox', { name: 'Filtrar Armazém' }), { target: { value: 'central' } });
    await waitFor(() => expect(screen.getByText('2 registos encontrados')).toBeInTheDocument());
    expect(screen.getByText('(filtrado de 3 registos totais)')).toBeInTheDocument();
    expect(screen.queryByText('Areia fina')).toBeNull();
    // os totais seguem o filtro (como as DataTables com search: 'applied')
    expect(screen.getByText(kz(1600))).toBeInTheDocument();
  }, 60_000);

  it('menu «Colunas ▾» esconde a coluna e lembra a escolha (servidor + navegador)', async () => {
    comCliente(<TabelaComModos<Artigo> rowKey="id" dataSource={dados} columns={colunas} modos={false} />);
    expect(screen.getByRole('columnheader', { name: /Armazém/ })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Colunas visíveis' }));
    const grupo = await screen.findByRole('group', { name: 'Colunas visíveis' });
    fireEvent.click(within(grupo).getByRole('checkbox', { name: 'Armazém' }));
    await waitFor(() => expect(screen.queryByRole('columnheader', { name: /Armazém/ })).toBeNull());
    const guardado = JSON.parse(window.localStorage.getItem(CHAVE_LOCAL_COLUNAS) ?? '{}') as Record<string, { ocultas: string[] }>;
    expect(guardado['/m/stock/armazem_stock'].ocultas).toEqual(['armazem']);
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('put', '/sistema/preferencias/interface/colunas_listas', expect.anything()));
  }, 60_000);

  it('«Filtro personalizado»: condição coluna/operador/valor aplicada no cliente', async () => {
    comCliente(<TabelaComModos<Artigo> rowKey="id" dataSource={dados} columns={colunas} modos={false} />);
    fireEvent.click(screen.getByRole('button', { name: 'Filtro personalizado' }));
    const dialogo = await screen.findByRole('dialog');
    const condicao = within(dialogo).getByRole('group', { name: 'Condição 1' });
    fireEvent.change(within(condicao).getByRole('textbox', { name: 'Valor' }), { target: { value: 'areia' } });
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Aplicar' }));
    await waitFor(() => expect(screen.getByText('1 registos encontrados')).toBeInTheDocument());
    expect(screen.getByText('Areia fina')).toBeInTheDocument();
  }, 60_000);

  it('acções por linha: coluna «Ações», desactivada com dica e confirmação para acções destrutivas', async () => {
    const ver = vi.fn();
    const apagar = vi.fn();
    comCliente(
      <TabelaComModos<Artigo>
        rowKey="id"
        dataSource={dados}
        columns={colunas}
        modos={false}
        accoes={(l) => [
          { icone: 'fa-eye', titulo: 'Ver', onClick: () => ver(l.id) },
          { icone: 'fa-trash', titulo: 'Eliminar', variante: 'perigo', confirmar: 'Eliminar o artigo?', desactivado: l.id === 2 ? 'Eliminação bloqueada: documento contabilizado' : false, onClick: () => apagar(l.id) },
        ]}
      />,
    );
    expect(screen.getByRole('columnheader', { name: 'Ações' })).toBeInTheDocument();
    fireEvent.click(screen.getAllByRole('button', { name: 'Ver' })[0]);
    expect(ver).toHaveBeenCalledWith(1);
    const bloqueado = screen.getByRole('button', { name: 'Eliminar — Eliminação bloqueada: documento contabilizado' });
    expect(bloqueado).toBeDisabled();
    fireEvent.click(screen.getAllByRole('button', { name: 'Eliminar' })[0]);
    expect(apagar).not.toHaveBeenCalled();
    fireEvent.click(await screen.findByRole('button', { name: 'Confirmar' }));
    await waitFor(() => expect(apagar).toHaveBeenCalledWith(1));
  }, 60_000);

  it('selecção múltipla: «Seleccionadas: N», «Editar em massa (N)» e acção em massa', async () => {
    const editar = vi.fn();
    const atribuir = vi.fn();
    comCliente(
      <TabelaComModos<Artigo>
        rowKey="id"
        dataSource={dados}
        columns={colunas}
        modos={false}
        seleccao={{ editarEmMassa: { onClick: editar }, accoes: [{ texto: 'Atribuir responsável', icone: 'user-tag', onClick: atribuir }] }}
      />,
    );
    expect(screen.getByText('Seleccionadas:')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Editar em massa \(0\)/ })).toBeDisabled();
    const caixas = screen.getAllByRole('checkbox');
    fireEvent.click(caixas[1]);
    fireEvent.click(caixas[3]);
    const botao = await screen.findByRole('button', { name: /Editar em massa \(2\)/ });
    fireEvent.click(botao);
    expect(editar).toHaveBeenCalledWith([dados[0], dados[2]], [1, 3]);
    fireEvent.click(screen.getByRole('button', { name: /Atribuir responsável/ }));
    expect(atribuir).toHaveBeenCalledWith([dados[0], dados[2]], [1, 3]);
  }, 60_000);

  it('lista vazia mostra o estado vazio do antigo', () => {
    comCliente(<TabelaComModos<Artigo> rowKey="id" dataSource={[]} columns={colunas} modos={false} vazio={{ icone: 'clipboard-list', titulo: 'Nenhuma Folha de Contagem Ativa', texto: 'Não existem inventários em contagem.' }} />);
    expect(screen.getByText('0 registos encontrados')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Nenhuma Folha de Contagem Ativa' })).toBeInTheDocument();
  }, 60_000);

  it('`filtrosColuna={false}` e `menuColunas={false}` desligam a linha de filtros e o menu', () => {
    comCliente(<TabelaComModos<Artigo> rowKey="id" dataSource={dados} columns={colunas} modos={false} filtrosColuna={false} menuColunas={false} />);
    expect(screen.queryByRole('textbox', { name: /Filtrar/ })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Colunas visíveis' })).toBeNull();
  }, 60_000);
});

describe('componentes soltos', () => {
  it('CartaoKpi: rótulo, valor, unidade, linha secundária, barra e ícone; clicável é botão', () => {
    const clique = vi.fn();
    const { container } = render(
      <GrelhaKpi>
        <CartaoKpi rotulo="Vendas do mês" valor="1.234,00" unidade="Kz" secundaria="Faturação activa" icone="shopping-cart" cor="azul" barra="esquerda" />
        <CartaoKpi rotulo="Desvios" valor="3" cor="vermelho" aoClicar={clique} />
      </GrelhaKpi>,
    );
    expect(screen.getByText('Vendas do mês')).toBeInTheDocument();
    expect(screen.getByText('Kz')).toBeInTheDocument();
    expect(screen.getByText('Faturação activa')).toBeInTheDocument();
    expect(container.querySelector('.erp-kpi-barra-esquerda')).not.toBeNull();
    expect(container.querySelector('.erp-kpi-icone svg')).not.toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Desvios/ }));
    expect(clique).toHaveBeenCalled();
  });

  it('EstadoVazio e AccoesLinha isolados', () => {
    render(
      <>
        <EstadoVazio icone="check-circle" cor="#059669" titulo="Nenhuma Revisão Pendente" texto="Não existem contagens a aguardar aprovação." />
        <AccoesLinha accoes={[{ icone: 'print', titulo: 'Imprimir' }, null, { icone: 'copy', titulo: 'Copiar', oculto: true }]} />
      </>,
    );
    expect(screen.getByRole('status')).toHaveTextContent('Nenhuma Revisão Pendente');
    expect(screen.getByRole('button', { name: 'Imprimir' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Copiar' })).toBeNull();
  });
});
