import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { contemTexto } from './lista';
import { NomeProduto, NomeTerceiro } from './referencias';
import { pendente, totaisLinhas } from './calculos';
import { EstadoTag, rotuloEstado } from './estados';
import { accoesContrato, accoesEncomenda, accoesFatura, accoesPedido, accoesProposta, accoesRececao, etapaPendente } from './regras';
import { corpoContas } from './ModalContas';
import { linhasComQuantidade } from './ModaisEncomenda';
import { corpoTerceiro } from './GestaoTerceiros';
import type { ContratoCompra, EncomendaCompra, FaturaCompra, PedidoCompra, PropostaCompra, RececaoCompra } from './tipos';
import { validarEscaloes } from '../pedidos/ModalEscaloes';
import { melhorPrecoPorLinha } from '../prospeccao/QuadroComparativo';
import { linhasDoPedido } from '../prospeccao/NovaProposta';
import { linhasFatura } from '../faturacao/DetalheFatura';
import { linhasSelecionaveis } from '../EncomendasClientes';

const tudo = () => true;
const nada = () => false;
const so = (...chaves: string[]) => (...pedidas: string[]) => pedidas.some((p) => chaves.includes(p));

describe('listas de Compras', () => {
  it('mostra os nomes que vêm na resposta sem pedir a ficha', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <span data-testid="t">
          <NomeTerceiro id={7} terceiro={{ id: 7, nome: ' Fornecedor & Cia ', nif: '5000000099' }} />
        </span>
        <span data-testid="p">
          <NomeProduto id={3} produto={{ id: 3, codigo: 'A1', nome: 'Cimento' }} descricao="Cimento" />
        </span>
        <span data-testid="v">
          <NomeTerceiro id={null} />
        </span>
      </QueryClientProvider>,
    );
    expect(screen.getByTestId('t').textContent).toBe('Fornecedor & Cia');
    expect(screen.getByTestId('p').textContent).toBe('A1 — Cimento');
    expect(screen.getByTestId('v').textContent).toBe('—');
  });

  it('filtra texto sem acentos nem maiúsculas', () => {
    expect(contemTexto('constru', 'LAMINE CONSTRUÇÃO')).toBe(true);
    expect(contemTexto('', null)).toBe(true);
    expect(contemTexto('xyz', 'abc', null, 12)).toBe(false);
  });
});

describe('cálculos', () => {
  it('estima líquido, IVA e total com arredondamento a 2 casas', () => {
    expect(totaisLinhas([{ quantidade: 3, preco_unitario: 10.005, taxa_imposto: 14 }, null, { quantidade: '2', preco_unitario: '100', taxa_imposto: '0' }])).toEqual({
      liquido: 230.02,
      imposto: 4.2,
      total: 234.22,
    });
  });

  it('calcula o pendente sem negativos', () => {
    expect(pendente('10.000', '3.500')).toBe(6.5);
    expect(pendente('2', '5')).toBe(0);
    expect(pendente('4', null)).toBe(4);
  });

  it('só envia linhas com quantidade positiva', () => {
    expect(linhasComQuantidade([{ quantidade: 0 }, { quantidade: 2 }, { quantidade: undefined }])).toEqual([{ quantidade: 2 }]);
  });
});

describe('regras de acções', () => {
  const pedido = (estado: string, etapas: PedidoCompra['deliberacao'] = null) => ({ id: 1, estado, deliberacao: etapas }) as PedidoCompra;

  it('pedido: decidir só no estado PENDENTE e com a tarefa da etapa pendente', () => {
    const p = pedido('PENDENTE', { etapas: [{ nome: 'Unidade', ordem: 1, estado: 'APROVADO' }, { nome: 'Direcção', ordem: 2, estado: 'PENDENTE', tarefa: 'compras_ped_aprovar_n2' }] });
    expect(etapaPendente(p)?.nome).toBe('Direcção');
    expect(accoesPedido(p, so('compras_ped_aprovar')).decidir).toBe(false);
    expect(accoesPedido(p, so('compras_ped_aprovar_n2')).decidir).toBe(true);
    expect(accoesPedido(pedido('APROVADO'), tudo).decidir).toBe(false);
    expect(accoesPedido(pedido('APROVADO'), tudo).registarProposta).toBe(true);
    expect(accoesPedido(pedido('ADJUDICADO'), tudo).anular).toBe(false);
    expect(accoesPedido(pedido('REJEITADO'), so('compras_ped_eliminar')).anular).toBe(true);
  });

  it('proposta: propor → adjudicar/cancelar; anular só antes da adjudicação', () => {
    const c = (estado: string) => ({ estado }) as PropostaCompra;
    expect(accoesProposta(c('PROPOSTA'), tudo)).toEqual({ propor: true, cancelarProposta: false, adjudicar: false, anular: true });
    expect(accoesProposta(c('PROPOSTA_ADJUDICACAO'), so('compras_adjudicate'))).toEqual({ propor: false, cancelarProposta: false, adjudicar: true, anular: false });
    expect(accoesProposta(c('ADJUDICADO'), tudo)).toEqual({ propor: false, cancelarProposta: false, adjudicar: false, anular: false });
  });

  it('encomenda: sem recepções quando recebida; nada quando anulada', () => {
    const e = (estado: string) => ({ estado }) as EncomendaCompra;
    expect(accoesEncomenda(e('PARCIAL'), tudo)).toEqual({ registarRececao: true, registarFatura: true, anular: true });
    expect(accoesEncomenda(e('RECEBIDO'), tudo).registarRececao).toBe(false);
    expect(accoesEncomenda(e('ANULADA'), tudo)).toEqual({ registarRececao: false, registarFatura: false, anular: false });
  });

  it('recepção: validar por validar; reverter validadas; anular só não validadas', () => {
    const r = (estado: string, validado: boolean | null) => ({ estado, validado }) as RececaoCompra;
    expect(accoesRececao(r('RECEBIDO', null), tudo)).toEqual({ validar: true, reverter: false, anular: true });
    expect(accoesRececao(r('VALIDADO', true), tudo)).toEqual({ validar: false, reverter: true, anular: false });
    expect(accoesRececao(r('ANULADO', null), tudo)).toEqual({ validar: false, reverter: false, anular: false });
    expect(accoesRececao(r('RECEBIDO', null), nada).validar).toBe(false);
  });

  it('factura: contabilizada não se anula; paga não se descontabiliza', () => {
    const f = (estado: string, contabilizado: boolean) => ({ estado, contabilizado }) as FaturaCompra;
    expect(accoesFatura(f('PENDENTE', false), tudo)).toEqual({ contabilizar: true, descontabilizar: false, anular: true });
    expect(accoesFatura(f('PENDENTE', true), tudo)).toEqual({ contabilizar: false, descontabilizar: true, anular: false });
    expect(accoesFatura(f('PAGO', true), tudo).descontabilizar).toBe(false);
  });

  it('contrato: só activos aceitam encomendas; cancelados ficam só de leitura', () => {
    const c = (estado: string) => ({ estado }) as ContratoCompra;
    expect(accoesContrato(c('EXPIRADO'), tudo).associar).toBe(false);
    expect(accoesContrato(c('EXPIRADO'), tudo).cancelar).toBe(true);
    expect(Object.values(accoesContrato(c('CANCELADO'), tudo)).some(Boolean)).toBe(false);
  });
});

describe('formulários', () => {
  it('valida os escalões como o servidor', () => {
    expect(validarEscaloes([{ nome: 'A', limite: 100 }, { nome: 'B', limite: null }])).toBeNull();
    expect(validarEscaloes([{ nome: 'A', limite: 100 }, { nome: 'B', limite: 50 }, { nome: 'C' }])).toMatch(/crescentes/);
    expect(validarEscaloes([{ nome: ' ' }])).toMatch(/nome/);
    expect(validarEscaloes([])).toMatch(/entre 1 e 4/);
  });

  it('monta o corpo das contas com null nas vazias', () => {
    expect(corpoContas({ a: ' 4511 ', b: '', c: null })).toEqual({ contas: { a: '4511', b: null, c: null } });
  });

  it('monta o corpo do terceiro (maiúsculas, vazios a null, transitória só em fornecedores)', () => {
    const c = corpoTerceiro('CLIENTE', { id: 1, nome: ' Ana ', nif: '', codigo_conta: '31121', codigo_moeda: 'aoa', fe_pais: 'ao', conta_compra_transitoria: '3281' });
    expect(c).toMatchObject({ papel: 'CLIENTE', nome: 'Ana', nif: null, codigo_moeda: 'AOA', fe_pais: 'AO', conta_compra_transitoria: undefined });
    expect(corpoTerceiro('FORNECEDOR', { id: 1, nome: 'X', nif: null, codigo_conta: '32', conta_compra_transitoria: '3281' }).conta_compra_transitoria).toBe('3281');
  });

  it('cria as linhas da proposta a partir do pedido, com o IVA do catálogo', () => {
    const l = linhasDoPedido([{ id: 7, produto_id: 3, descricao: null, quantidade: '2.000' } as never], (id) => (id === 3 ? 14 : undefined));
    expect(l).toEqual([{ item_pedido_id: 7, produto_id: 3, descricao: null, quantidade: '2.000', preco_unitario: undefined, taxa_imposto: 14 }]);
  });

  it('escolhe o menor preço cotado por artigo', () => {
    expect(melhorPrecoPorLinha({ '1': '100.00', '2': '90.50', '3': null })).toBe('2');
    expect(melhorPrecoPorLinha({ '1': null })).toBeNull();
  });

  it('mostra as linhas do JSON do legado quando a factura não tem linhas normalizadas', () => {
    const f = { linhas: [], itens: [{ item_id: 9, product_id: 4, quantity: 2, unit_price: 50, tax_rate: 14, net_kz: 100, tax_kz: 14 }] } as never;
    expect(linhasFatura(f)).toEqual([{ chave: 'legado-9', produto_id: 4, descricao: null, quantidade: 2, preco: 50, iva: 14, liquido: 100, imposto: 14 }]);
  });

  it('só deixa escolher linhas de encomendas de clientes por comprar e com pendente', () => {
    const e = [
      {
        id: 1,
        numero_documento: 'EN 1',
        data_emissao: '2026-09-01',
        cliente: 'X',
        estado: null,
        linhas: [
          { id: 10, por_comprar: true, pendente: '2.000' },
          { id: 11, por_comprar: true, pendente: '0.000' },
          { id: 12, por_comprar: false, pendente: '5.000' },
        ],
      },
    ] as never;
    expect(linhasSelecionaveis(e)).toEqual([10]);
  });
});

describe('EstadoTag', () => {
  it('mostra o rótulo em português', () => {
    render(<EstadoTag estado="PROPOSTA_ADJUDICACAO" />);
    expect(screen.getByText('Proposta para adjudicação')).toBeInTheDocument();
    expect(rotuloEstado('ESTADO_NOVO')).toBe('Estado novo');
    expect(rotuloEstado(null)).toBe('—');
  });
});
