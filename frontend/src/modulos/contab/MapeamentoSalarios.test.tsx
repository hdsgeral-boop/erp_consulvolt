import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';
import { contarComConta, IMPOSTOS_SISTEMA } from './comum/mapeamento';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn(),
  descarregar: vi.fn(),
}));

let permissoes = ['contabilidade_view', 'contab_mapeamento'];
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({
    pode: (p: string) => permissoes.includes(p),
    empresa: { id: 1, nome: 'Empresa Activa' },
    empresas: [{ id: 1, nome: 'Empresa Activa' }, { id: 2, nome: 'Empresa Origem' }],
    utilizador: null,
  }),
}));

import MapeamentoSalarios from './MapeamentoSalarios';

const DADOS = {
  tipos_organizacao: [{ id: 10, nome: 'Colaboradores' }, { id: 11, nome: 'Sociais' }],
  infotipos: [
    { id: 1, nome: 'Salário Base', tipo: 'VENCIMENTO' },
    { id: 2, nome: 'Adiantamento', tipo: 'DESCONTO' },
  ],
  codigos_sistema: IMPOSTOS_SISTEMA.map((i) => i.codigo),
  rubricas: [{ infotipo_salarial_id: 1, tipo_organizacao_id: 10, avencado: false, numero_conta: '7211' }],
  sistema: [{ codigo: 'NET_PAY_CREDIT', tipo_organizacao_id: 10, avencado: false, numero_conta: '3611' }],
};

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MapeamentoSalarios />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  permissoes = ['contabilidade_view', 'contab_mapeamento'];
  obter.mockReset();
  enviar.mockReset();
  obter.mockImplementation((url: string) =>
    Promise.resolve(
      url === '/rh/mapeamentos-contabeis'
        ? DADOS
        : url === '/contabilidade/plano-contas'
          ? [{ id: 1, codigo: '7211', descricao: 'Remunerações', tipo: 'M' }, { id: 2, codigo: '3611', descricao: 'Remunerações a pagar', tipo: 'M' }]
          : {},
    ),
  );
  window.history.replaceState(null, '', '/m/contab/contabilidade');
});

describe('Mapeamento contábil — layout do legado', { timeout: 60_000 }, () => {
  it('helpers: linhas do cartão de impostos e «N de M com conta»', () => {
    expect(IMPOSTOS_SISTEMA.slice(0, 6).map((i) => i.nome)).toEqual([
      'Salário Líquido a Pagar',
      'IRT Retido na Fonte (Tabela Progressiva)',
      'IRT Avençado (Taxa Fixa 6.5%)',
      'INSS Trabalhador (3%)',
      'INSS Encargo Empresa (8%)',
      'INSS Passivo Empresa (8%)',
    ]);
    expect(IMPOSTOS_SISTEMA.filter((i) => i.avencado).map((i) => i.codigo)).toEqual(['NET_PAY_CREDIT']);
    expect(contarComConta(['1', '2', '3'], { '1|10': '7211', '1|AV': '7212', '2|10': ' ', '30|10': '7211' })).toBe(1);
  });

  it('título, botões, texto explicativo e os dois cartões; modo leitura até «Editar Mapeamento»', async () => {
    montar();
    expect(await screen.findByText('Mapeamento de Impostos e Encargos')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Mapeamento contábil' })).toBeInTheDocument();
    expect(screen.getByText(/Associe as Contas Contábeis aos Infotipos por Tipo de Órgão/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Copiar de outra empresa/ })).toBeEnabled();
    expect(screen.getByRole('button', { name: /Recuperar de Histórico/ })).toBeDisabled();
    expect(screen.getByText('Salário Líquido a Pagar')).toBeInTheDocument();
    expect(screen.getByText('Gasto (DÉBITO)')).toBeInTheDocument();
    expect(screen.getAllByText('Avençado (Conta)').length).toBeGreaterThanOrEqual(2);
    expect(screen.getAllByText('Colaboradores (Conta)').length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText('1 de 2 com conta')).toBeInTheDocument();
    expect(screen.getByText('2 registos encontrados')).toBeInTheDocument();
    // linhas sem aplicação ao avençado: «—» (todas menos o salário líquido)
    expect(screen.getAllByText('—').length).toBe(IMPOSTOS_SISTEMA.length - 1);

    // selectores de conta (sem o «Mostrar [N] registos» da paginação)
    const combos = () => Array.from(document.querySelectorAll<HTMLInputElement>('.contab-map-card .ant-select input'));
    await waitFor(() => expect(combos().length).toBeGreaterThan(0));
    expect(combos().every((c) => c.disabled)).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: /Editar Mapeamento/ }));
    expect(screen.getByRole('button', { name: /Gravar Mapeamento/ })).toBeDisabled();
    expect(combos().every((c) => !c.disabled)).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));
    expect(screen.getByRole('button', { name: /Editar Mapeamento/ })).toBeInTheDocument();
  });

  it('sem permissão de edição: «Modo Leitura» e sem botões de edição', async () => {
    permissoes = ['contabilidade_view'];
    montar();
    expect(await screen.findByText('Modo Leitura')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Editar Mapeamento/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Copiar de outra empresa/ })).toBeNull();
  });

  it('«Copiar de outra empresa»: pré-visualização (simulação) e cópia', async () => {
    enviar.mockImplementation((_m: string, _u: string, corpo: { simular: boolean }) =>
      Promise.resolve({
        dados: { origem: 'Empresa Origem', simulacao: corpo.simular, rubricas: 3, sistema: 2, ja_mapeados: 1, sem_correspondencia: 0, contas_invalidas: ['7299'] },
        mensagem: 'ok',
      }),
    );
    montar();
    fireEvent.click(await screen.findByRole('button', { name: /Copiar de outra empresa/ }));
    const modal = await screen.findByRole('dialog');
    expect(within(modal).getByText('Empresa Activa')).toBeInTheDocument();
    fireEvent.mouseDown(within(modal).getByRole('combobox'));
    fireEvent.click(await screen.findByText('Empresa Origem', { selector: '.ant-select-item-option-content' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/rh/mapeamentos-contabeis/copiar', { empresa_origem_id: 2, simular: true }));
    expect(await within(modal).findByText(/mapeamentos de Infotipos a copiar/)).toBeInTheDocument();
    expect(within(modal).getByText(/7299/)).toBeInTheDocument();
    fireEvent.click(within(modal).getByRole('button', { name: 'Copiar Mapeamento' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/rh/mapeamentos-contabeis/copiar', { empresa_origem_id: 2, simular: false }));
  });
});
