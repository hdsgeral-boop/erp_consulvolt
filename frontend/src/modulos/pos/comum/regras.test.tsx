import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { estimarOrdem } from '../lavandaria/Recepcao';
import { precoPecaServico } from '../lavandaria/tipos';
import { precoQuarto, quantidadeMinima } from '../hotelaria/tipos';
import type { Pagamento } from './calculos';
import { EstadoPOS, rotuloEstadoPOS } from './estados';
import { gravarPreferencias, htmlRelatorioSessao, htmlTalaoVenda, lerPreferencias, PREFERENCIAS_PADRAO } from './impressao';
import { meiosPadrao, meiosParaApi, validarMeios } from './meios';
import { PainelPagamentos } from './PainelPagamentos';
import {
  accoesDesvio,
  accoesEstadia,
  accoesFrente,
  accoesIntegracao,
  accoesItemPrestacao,
  accoesOrdem,
  accoesReclamacao,
  accoesTerminal,
  decisoesPermitidas,
  podeAnularLiquidacao,
} from './regras';
import type { MeioPagamento, SessaoPOS } from './tipos';

const tudo = () => true;
const nada = () => false;
const so = (...chaves: string[]) => (...pedidas: string[]) => pedidas.some((p) => chaves.includes(p));

describe('frente de caixa', () => {
  const sessao = { id: 1, terminal_pos_id: 1, codigo_sessao: 'T01-2026-0001', nome_operador: 'op', aberto_em: '2026-10-01T08:00:00Z' };
  it('abrir só sem sessão e com o terminal activo; vender/X/Z só com sessão', () => {
    expect(accoesFrente(tudo, { ativo: true, sessao_aberta: null })).toMatchObject({ abrir: true, vender: false, fecharZ: false, relatorioX: false });
    expect(accoesFrente(tudo, { ativo: false, sessao_aberta: null }).abrir).toBe(false);
    expect(accoesFrente(tudo, { ativo: true, sessao_aberta: sessao })).toMatchObject({ abrir: false, vender: true, fecharZ: true, relatorioX: true });
  });

  it('respeita as tarefas pos_venda, pos_fecho e pos_desconto', () => {
    const a = accoesFrente(so('pos_venda'), { ativo: true, sessao_aberta: sessao });
    expect(a).toMatchObject({ vender: true, fecharZ: false, relatorioX: true, desconto: false });
    const z = accoesFrente(so('pos_fecho'), { ativo: true, sessao_aberta: sessao });
    expect(z).toMatchObject({ vender: false, fecharZ: true, relatorioX: true });
    expect(accoesFrente(so('pos_desconto'), null).desconto).toBe(true);
  });
});

describe('integração e desvios', () => {
  const s = (p: Partial<SessaoPOS>) => ({ estado: 'FECHADA', estado_contabilizacao: 'PENDENTE', estado_liquidacao: 'PENDENTE', estado_desvio: 'PENDENTE', deliberacao: null, operador_id: 7, desvio: '-500.00', ...p }) as SessaoPOS;

  it('integrar só sessões fechadas pendentes; descontabilizar só integradas', () => {
    expect(accoesIntegracao(tudo, s({}))).toEqual({ contabilizar: true, descontabilizar: false });
    expect(accoesIntegracao(tudo, s({ estado: 'ABERTA' })).contabilizar).toBe(false);
    expect(accoesIntegracao(tudo, s({ estado_contabilizacao: 'CONTABILIZADA' }))).toEqual({ contabilizar: false, descontabilizar: true });
    expect(accoesIntegracao(so('pos_integrar'), s({ estado_contabilizacao: 'CONTABILIZADA' })).descontabilizar).toBe(false);
  });

  it('deliberar: pendente, com segregação de funções; anular só deliberações manuais', () => {
    expect(accoesDesvio(tudo, s({}), 3).deliberar).toEqual({ visivel: true, bloqueio: undefined });
    expect(accoesDesvio(tudo, s({}), 7).deliberar.bloqueio).toMatch(/Segregação/);
    expect(accoesDesvio(nada, s({}), 3).deliberar.visivel).toBe(false);
    expect(accoesDesvio(tudo, s({ estado_desvio: 'DELIBERADO', deliberacao: { decisao: 'FALTA_CUSTO', automatica: true } }), 3).anular.visivel).toBe(false);
    expect(accoesDesvio(tudo, s({ estado_desvio: 'DELIBERADO', deliberacao: { decisao: 'FALTA_CUSTO' } }), 3).anular.visivel).toBe(true);
  });

  it('decisões: sobra → proveito/sem efeito; falta → custo/operador/sem efeito; com efeito exige integração', () => {
    expect(decisoesPermitidas(s({ desvio: '100.00', estado_contabilizacao: 'CONTABILIZADA' })).map((d) => d.valor)).toEqual(['SOBRA_PROVEITO', 'SEM_EFEITO']);
    const falta = decisoesPermitidas(s({}));
    expect(falta.map((d) => d.valor)).toEqual(['FALTA_CUSTO', 'FALTA_OPERADOR', 'SEM_EFEITO']);
    expect(falta[0].desactivada).toMatch(/Integre/);
    expect(falta[2].desactivada).toBeUndefined();
  });
});

describe('prestação de contas', () => {
  it('registar só itens por prestar, com permissão e sem ser o operador', () => {
    expect(accoesItemPrestacao(tudo, { estado: 'POR_PRESTAR' }, 7, 3)).toEqual({ visivel: true });
    expect(accoesItemPrestacao(tudo, { estado: 'POR_PRESTAR' }, 7, 7).bloqueio).toMatch(/Segregação/);
    expect(accoesItemPrestacao(tudo, { estado: 'BLOQUEADO' }, 7, 3).visivel).toBe(false);
    expect(accoesItemPrestacao(so('pos_prestacao_anular'), { estado: 'POR_PRESTAR' }, 7, 3).visivel).toBe(false);
  });

  it('anular liquidação registada com pos_prestacao_anular', () => {
    expect(podeAnularLiquidacao(so('pos_prestacao_anular'), { estado: 'REGISTADO' })).toBe(true);
    expect(podeAnularLiquidacao(tudo, { estado: 'ANULADO' })).toBe(false);
    expect(podeAnularLiquidacao(so('pos_prestar'), { estado: 'REGISTADO' })).toBe(false);
  });
});

describe('terminais e meios de pagamento', () => {
  it('desactivar bloqueado com sessão aberta; eliminar escondido', () => {
    const aberto = accoesTerminal(tudo, { ativo: true, sessao_aberta: { id: 1, terminal_pos_id: 1, codigo_sessao: 'x', nome_operador: null, aberto_em: '' } });
    expect(aberto.desactivar.bloqueio).toMatch(/sessão aberta/);
    expect(aberto.eliminar).toBe(false);
    expect(accoesTerminal(tudo, { ativo: false, sessao_aberta: null })).toMatchObject({ activar: true, eliminar: true });
    expect(accoesTerminal(nada, { ativo: true, sessao_aberta: null })).toMatchObject({ editar: false, eliminar: false, activar: false });
  });

  it('valida as contas dos meios como o servidor', () => {
    expect(validarMeios(meiosPadrao()).join(' ')).toMatch(/transitória e a conta de liquidação/);
    const ok: MeioPagamento[] = [
      { id: 'pm_num', tipo: 'NUMERARIO', nome: 'Numerário', ativo: true, conta_transitoria: '489', conta_liquidacao: '4511' },
      { id: 'pm_tpa', tipo: 'TPA', nome: 'TPA', ativo: true, conta_transitoria: '488', conta_liquidacao: '43103004', comissao_pct: 1, conta_comissao: '7671' },
    ];
    expect(validarMeios(ok)).toEqual([]);
    expect(validarMeios([{ ...ok[0], conta_liquidacao: '43103004' }]).join(' ')).toMatch(/classe 45/);
    expect(validarMeios([ok[0], { ...ok[1], conta_transitoria: '489' }]).join(' ')).toMatch(/já é usada/);
    expect(validarMeios([{ ...ok[1], conta_comissao: null }]).join(' ')).toMatch(/conta da comissão/);
    expect(validarMeios([{ ...ok[0], ativo: false }]).join(' ')).toMatch(/pelo menos um meio/);
  });

  it('normaliza os meios para a API (campos de TPA só nos TPA)', () => {
    const [num, tpa] = meiosParaApi([
      { id: 'a', tipo: 'NUMERARIO', nome: ' Caixa ', ativo: true, conta_transitoria: ' 489 ', conta_liquidacao: '4511', codigo_tpa: 'x' },
      { tipo: 'TPA', nome: 'TPA', ativo: true, conta_transitoria: '488', conta_liquidacao: '431', comissao_pct: null, conta_comissao: '' },
    ]);
    expect(num).toEqual({ id: 'a', tipo: 'NUMERARIO', nome: 'Caixa', ativo: true, conta_transitoria: '489', conta_liquidacao: '4511' });
    expect(tpa).toMatchObject({ comissao_pct: 0, conta_comissao: null, comissao_deduzida: true });
    expect(tpa.id).toBeUndefined();
  });
});

describe('lavandaria', () => {
  const ordem = (estado: string, itens: { estado: string; estado_orcamento?: string }[], saldo = '0', porFacturar = '0') => ({
    estado,
    itens: itens.map((i, n) => ({ linha_id: n + 1, ...i })),
    saldo,
    por_facturar: porFacturar,
  });

  it('acções da ordem conforme o estado das peças, o saldo e a sessão', () => {
    const o = ordem('EM_EXECUCAO', [{ estado: 'RECEBIDA' }, { estado: 'PRONTA' }], '1000.00', '500.00');
    expect(accoesOrdem(tudo, o, true)).toMatchObject({ iniciar: true, pronta: false, entregar: true, receber: true, faturar: true, anular: true });
    expect(accoesOrdem(tudo, o, false)).toMatchObject({ entregar: false, receber: false, faturar: false });
    expect(accoesOrdem(so('lav_ordens'), o, true)).toMatchObject({ receber: false, anular: false });
    expect(accoesOrdem(tudo, ordem('ENTREGUE', [{ estado: 'ENTREGUE' }]), true).anular).toBe(false);
    expect(accoesOrdem(tudo, ordem('ORCAMENTO', [{ estado: 'RECEBIDA', estado_orcamento: 'PENDENTE' }]), true).orcamentos).toBe(true);
  });

  it('reclamações: decidir com lav_dano_decidir; quem decidiu não paga', () => {
    expect(accoesReclamacao(tudo, { estado: 'AGUARDA_COMPROVATIVO' }, 'ana')).toMatchObject({ comprovar: true, decidir: true });
    expect(accoesReclamacao(tudo, { estado: 'APROVADA', decidido_por: 'ana' }, 'ana').pagar.bloqueio).toMatch(/Segregação/);
    expect(accoesReclamacao(so('lav_dano_pagar'), { estado: 'APROVADA', decidido_por: 'ana' }, 'rui').pagar).toEqual({ visivel: true, bloqueio: undefined });
    expect(accoesReclamacao(tudo, { estado: 'PAGA' }, 'rui').pagar.visivel).toBe(false);
  });

  it('preço da peça por serviço e estimativa da ordem com urgência e taxas', () => {
    const peca = { preco: '10000.00', precos_servico: [{ produto_id: 122, preco: 20000 }] };
    expect(precoPecaServico(peca, 122)).toBe(20000);
    expect(precoPecaServico(peca, 999)).toBe(10000);
    expect(precoPecaServico(undefined, 1)).toBe(0);
    expect(estimarOrdem([{ quantidade: 2, preco: 10000 }], true, 50, [1500, 0])).toBe(3150000);
  });
});

describe('hotelaria', () => {
  it('acções da estadia: anular só sem consumos; check-out com sessão aberta', () => {
    expect(accoesEstadia(tudo, { estado: 'ABERTA', itens: [] }, true)).toMatchObject({ alterar: true, consumos: true, checkout: true, anular: { visivel: true, bloqueio: undefined } });
    expect(accoesEstadia(tudo, { estado: 'ABERTA', itens: [{}] }, false).anular.bloqueio).toMatch(/consumos/);
    expect(accoesEstadia(tudo, { estado: 'ABERTA', itens: [] }, false).checkout).toBe(false);
    expect(accoesEstadia(tudo, { estado: 'FECHADA', itens: [] }, true)).toMatchObject({ alterar: false, checkout: false, anular: { visivel: false } });
    expect(accoesEstadia(so('pos_venda'), { estado: 'ABERTA' }, true)).toMatchObject({ consumos: true, alterar: false });
  });

  it('quantidade mínima e preço pelo modo', () => {
    const q = { horas_minimas: '2.000', preco_por_dia: '35000.00', preco_por_hora: '5000.00' };
    expect(quantidadeMinima('HORA', q)).toBe(2);
    expect(quantidadeMinima('DIA', q)).toBe(1);
    expect(precoQuarto('HORA', q)).toBe(5000);
    expect(precoQuarto('DIA', q)).toBe(35000);
  });
});

describe('impressão', () => {
  beforeEach(() => window.localStorage.clear());

  it('preferências por empresa com valores por omissão e saneamento', () => {
    expect(lerPreferencias(18)).toEqual(PREFERENCIAS_PADRAO);
    gravarPreferencias(18, { ...PREFERENCIAS_PADRAO, formato: 'A4', largura: 58, automatico: false });
    expect(lerPreferencias(18)).toMatchObject({ formato: 'A4', largura: 58, automatico: false });
    expect(lerPreferencias(3)).toEqual(PREFERENCIAS_PADRAO);
    window.localStorage.setItem('erp.pos.impressao.5', '{"formato":"X","largura":99}');
    expect(lerPreferencias(5)).toMatchObject({ formato: 'TERMICO', largura: 80 });
  });

  it('talão escapa HTML e mostra total, pagamentos e troco', () => {
    const html = htmlTalaoVenda(
      {
        id: 1,
        numero_documento: 'FR T01/2026/7',
        data_emissao: '2026-10-01',
        total_liquido: '877.19',
        total_imposto: '122.81',
        total_bruto: '1000.00',
        desconto: '0.00',
        pos_troco: '500.00',
        pos_operador: 'op',
        pos_pagamentos: [{ tipo: 'NUMERARIO', nome: 'Numerário', valor: '1000.00' }],
        itens_venda: [{ id: 1, produto_id: 9, descricao: '<b>Água</b>', quantidade: '2', preco_unitario: '500.00', taxa_imposto: '14', total: '1000.00' }],
      },
      { empresa: 'Empresa & Filhos' },
      PREFERENCIAS_PADRAO,
    );
    expect(html).toContain('FR T01/2026/7');
    expect(html).toContain('Empresa &amp; Filhos');
    expect(html).toContain('&lt;b&gt;Água&lt;/b&gt;');
    expect(html).toContain('Troco');
    expect(html).toContain('size: 80mm auto');
  });

  it('relatório Z inclui o desvio; X não', () => {
    const z = { numero_z: 'Z-T01-2026-0001', codigo_sessao: 'T01-2026-0001', codigo_terminal: 'T01', nome_terminal: 'Loja', nome_operador: 'op', aberto_em: '2026-10-01T08:00:00Z', fechado_em: '2026-10-01T18:00:00Z', fundo_maneio_abertura: '0', numero_vendas: 1, total_vendas: '10.00', totais_por_metodo: [], vendas_numerario: '10.00', numerario_esperado: '10.00', numerario_contado: '9.00', desvio: '-1.00', fechos_tpa: [], justificacao: null } as unknown as SessaoPOS;
    expect(htmlRelatorioSessao(z, { empresa: 'E' }, PREFERENCIAS_PADRAO)).toContain('Desvio');
  });
});

describe('componentes', () => {
  it('etiqueta de estado com rótulo em português', () => {
    render(<EstadoPOS estado="CONTABILIZADA" />);
    expect(screen.getByText('Integrada')).toBeInTheDocument();
    expect(rotuloEstadoPOS('ALGO_NOVO')).toBe('Algo novo');
  });

  it('painel de pagamentos: acrescenta o meio com o valor em falta e mostra o troco', () => {
    const meios = [
      { id: 'pm_num', tipo: 'NUMERARIO' as const, nome: 'Numerário', ativo: true, conta_transitoria: '489', conta_liquidacao: '4511' },
      { id: 'pm_trf', tipo: 'TRANSFERENCIA' as const, nome: 'Transferência', ativo: true, conta_transitoria: '487', conta_liquidacao: '4310' },
    ];
    function Teste() {
      const [p, setP] = useState<Pagamento[]>([]);
      return <PainelPagamentos meios={meios} total={150000} pagamentos={p} onChange={setP} />;
    }
    render(<Teste />);
    fireEvent.click(screen.getByRole('button', { name: /Transferência/ }));
    expect(screen.getByText(/comprovativo da transferência/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Comprovativo Transferência'), { target: { value: 'TRF-1' } });
    expect(screen.queryByText(/comprovativo da transferência/)).not.toBeInTheDocument();
    expect(screen.getByText('Troco')).toBeInTheDocument();
  });
});
