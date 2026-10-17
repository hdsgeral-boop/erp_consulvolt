import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { vi } from 'vitest';
import { DescricaoEtapa } from './comum/accoesLista';
import { EstadoContratoTag, rotuloEstadoContrato } from './comum/estados';
import { SeloValidacaoArmazem } from './comum/Rececoes';
import { accoesPedido, aprovadorEtapa } from './comum/regras';
import type { FaturaCompra, PedidoCompra } from './comum/tipos';
import { ConteudoPreContabilizacao, PopoverPreContabilizacao } from './faturacao/PopoverPreContabilizacao';

const obter = vi.fn();
vi.mock('@/api/cliente', async (original) => ({ ...(await original<typeof import('@/api/cliente')>()), obter: (...a: unknown[]) => obter(...a) }));

const tudo = () => true;
const nada = () => false;

const pedido = (extra: Partial<PedidoCompra>): PedidoCompra =>
  ({ id: 1, numero_pedido: 'PC 1', nome_requerente: 'Teste', estado: 'PENDENTE', deliberacao: null, ...extra }) as PedidoCompra;

const fatura = { id: 9, numero_fatura: 'FT-T/1', data: '2026-10-01', montante_total: '1140.00', fornecedor_id: 3, fornecedor: { id: 3, nome: 'Fornecedor Fictício', nif: '5000000011' } } as unknown as FaturaCompra;

const previsualizacao = {
  diario: 'FF', data_documento: '2026-10-01', numero_documento: 'FT-T/1', referencia: null, descricao: null, total_debito: '1140.00', total_credito: '1140.00', equilibrado: true,
  linhas: [
    { codigo_conta: '211', nome_conta: 'Compras', tipo_dc: 'D', valor: '1000.00', terceiro: null, unidade_negocio: null, centro_custo: null, projeto: null, codigo_moeda: null, valor_moeda: null },
    { codigo_conta: '3211', nome_conta: 'Fornecedores', tipo_dc: 'C', valor: '1140.00', terceiro: 'Fornecedor Fictício', unidade_negocio: null, centro_custo: null, projeto: null, codigo_moeda: null, valor_moeda: null },
  ],
} as const;

describe('Deliberação: 1.º nível pelo responsável da unidade', () => {
  it('o servidor decide se pode decidir (etapa RESPONSAVEL sem tarefa)', () => {
    const etapas = [{ ordem: 1, nome: 'Responsável', estado: 'PENDENTE', tipo: 'RESPONSAVEL', employee_id: 5, nome_aprovador: 'Chefe (Direcção)' }];
    expect(accoesPedido(pedido({ deliberacao: { etapas }, pode_decidir: true }), nada).decidir).toBe(true);
    expect(accoesPedido(pedido({ deliberacao: { etapas }, pode_decidir: false }), tudo).decidir).toBe(false);
    // sem a indicação do servidor, vale a tarefa (pedidos antigos)
    expect(accoesPedido(pedido({ deliberacao: { etapas: [{ ordem: 1, nome: 'N1', estado: 'PENDENTE', tarefa: 'compras_ped_aprovar' }] } }), tudo).decidir).toBe(true);
  });

  it('aprovador da etapa: nome, utilizador ou tarefa', () => {
    expect(aprovadorEtapa({ ordem: 1, nome: 'N1', estado: 'PENDENTE', nome_aprovador: 'Chefe (Direcção)' })).toBe('Chefe (Direcção)');
    expect(aprovadorEtapa({ ordem: 1, nome: 'N1', estado: 'PENDENTE', utilizador: 'resp.cc' })).toBe('resp.cc');
    expect(aprovadorEtapa({ ordem: 2, nome: 'N2', estado: 'AGUARDA', tarefa: 'compras_ped_aprovar_n2' })).toBe('compras_ped_aprovar_n2');
  });

  it('mostra «Aguarda …» e a nota do aprovador', () => {
    render(<DescricaoEtapa etapa={{ ordem: 1, nome: 'N1', estado: 'PENDENTE', tipo: 'TAREFA', nome_aprovador: 'Utilizadores com a tarefa «Aprovar e rejeitar pedidos»',
      nota_aprovador: 'A unidade do requisitante não tem responsável definido.' }} />);
    expect(screen.getByText('Utilizadores com a tarefa «Aprovar e rejeitar pedidos»').closest('div')?.textContent).toContain('Aguarda');
    expect(screen.getByText('A unidade do requisitante não tem responsável definido.')).toBeInTheDocument();
  });
});

describe('Facturas: simulação contabilística ao passar sobre «Contabilizar»', () => {
  beforeEach(() => obter.mockReset());

  it('só pede a pré-visualização quando o popover abre', async () => {
    obter.mockResolvedValue(previsualizacao);
    render(
      <QueryClientProvider client={new QueryClient()}>
        <PopoverPreContabilizacao fatura={fatura}><button type="button">Contabilizar</button></PopoverPreContabilizacao>
      </QueryClientProvider>,
    );
    expect(obter).not.toHaveBeenCalled();
    fireEvent.mouseEnter(screen.getByRole('button', { name: 'Contabilizar' }).parentElement!);
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/compras/faturas/9/contabilizacao/pre-visualizacao'));
    expect(await screen.findByText('3211')).toBeInTheDocument();
    expect(obter).toHaveBeenCalledTimes(1);
  });

  it('conteúdo como o legado: factura, fornecedor, linhas D/C e equilíbrio', () => {
    render(<ConteudoPreContabilizacao fatura={fatura} dados={previsualizacao as never} />);
    expect(screen.getByText('Simulação contabilística')).toBeInTheDocument();
    expect(screen.getByText('Fatura: FT-T/1')).toBeInTheDocument();
    expect(screen.getByText('Fornecedor Fictício')).toBeInTheDocument();
    expect(screen.getByText('211')).toBeInTheDocument();
    expect(screen.getByText('Diário: FF')).toBeInTheDocument();
    expect(screen.getByText('Equilíbrio D/C OK')).toBeInTheDocument();
  });
});

describe('Recepções e contratos', () => {
  it('selo de validação no armazém (só por contabilizar)', () => {
    const { rerender, container } = render(<SeloValidacaoArmazem rececao={{ contabilizado: false, validado: false, estado: 'RECEBIDO' }} />);
    expect(screen.getByText('POR VALIDAR NO ARMAZÉM')).toBeInTheDocument();
    rerender(<SeloValidacaoArmazem rececao={{ contabilizado: false, validado: true, estado: 'RECEBIDO' }} />);
    expect(screen.getByText('VALIDADA NO ARMAZÉM')).toBeInTheDocument();
    rerender(<SeloValidacaoArmazem rececao={{ contabilizado: true, validado: true, estado: 'RECEBIDO' }} />);
    expect(container.textContent).toBe('');
  });

  it('estado do contrato com os rótulos e cores do legado', () => {
    expect(rotuloEstadoContrato('ATIVO')).toBe('Ativo');
    const { container } = render(<><EstadoContratoTag estado="ATIVO" /><EstadoContratoTag estado="CANCELADO" /><EstadoContratoTag estado="EXPIRADO" /></>);
    const tags = container.querySelectorAll('.ant-tag');
    expect([...tags].map((t) => t.textContent)).toEqual(['Ativo', 'Cancelado', 'Expirado']);
    expect(tags[0].className).toContain('green');
    expect(tags[1].className).toContain('red');
    expect(tags[2].className).toContain('orange');
  });
});
