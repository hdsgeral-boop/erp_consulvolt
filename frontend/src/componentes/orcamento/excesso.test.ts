import { describe, expect, it } from 'vitest';
import { ErroApi } from '@/api/tipos';
import {
  comOpcoesOrcamento,
  comOpcoesOrcamentoPedido,
  corpoPedido,
  eExcessoOrcamental,
  extrairExcesso,
  linhasDeMovimentos,
  opcoesAprovacaoNoActo,
  partirChave,
  podePrepararPedido,
  validarMotivo,
} from './excesso';

const alerta = (estado: string, extra: Record<string, unknown> = {}) => ({
  orcamento_anual_id: 3, orcamento: 'Orçamento 2026', rubrica_orcamental_id: 7, rubrica: '62 FST', modo: 'APROVACAO',
  orcado: 1000, realizado: 800, compromissos: 100, consumido: 900, documento: 250, percentagem: 115, excesso: 150, estado, origem: 'LANCAMENTO', ...extra,
});

const erroExcesso = (erros: Record<string, unknown>) =>
  new ErroApi('O documento excede o orçamento de 62 FST (115 %): peça a aprovação do excesso.', 422, 'ORCAMENTO_EXIGE_APROVACAO', erros);

describe('excesso orçamental (A-02)', () => {
  it('reconhece só o código ORCAMENTO_EXIGE_APROVACAO', () => {
    expect(eExcessoOrcamental(erroExcesso({}))).toBe(true);
    expect(eExcessoOrcamental(new ErroApi('Orçamento esgotado', 422, 'ORCAMENTO_BLOQUEADO'))).toBe(false);
    expect(eExcessoOrcamental(new Error('x'))).toBe(false);
  });

  it('parte a chave do documento no primeiro «|»', () => {
    expect(partirChave('LANCAMENTO|LAN-2026/0042')).toEqual({ origem: 'LANCAMENTO', documento: 'LAN-2026/0042' });
    expect(partirChave('FATURA_FORNECEDOR|12/FT A|7')).toEqual({ origem: 'FATURA_FORNECEDOR', documento: '12/FT A|7' });
    expect(partirChave(null)).toEqual({ origem: null, documento: null });
  });

  it('extrai alertas (números a partir de texto), pendentes e, se vierem, tipo/data/linhas', () => {
    const d = extrairExcesso(erroExcesso({
      alertas: [alerta('APROVACAO', { orcado: '1000.00' }), alerta('AVISO', { rubrica_orcamental_id: 8 })],
      chave_documento: 'PAGAMENTO|PAG-0005',
      tipo: 'TESOURARIA', data: '2026-10-02 00:00:00', linhas: [{ codigo_conta: '62', valor: '250.5' }, { valor: 3 }],
    }));
    expect(d.alertas).toHaveLength(2);
    expect(d.pendentes).toHaveLength(1);
    expect(d.pendentes[0].orcado).toBe(1000);
    expect([d.origem, d.documento, d.tipo, d.data]).toEqual(['PAGAMENTO', 'PAG-0005', 'TESOURARIA', '2026-10-02']);
    expect(d.linhas).toEqual([{ codigo_conta: '62', valor: 250.5, unidade_negocio_id: null, centro_custo_id: null, projeto_id: null }]);
  });

  it('monta o pedido com os dados do servidor e, na falta, com o contexto do ecrã', () => {
    const semLinhas = extrairExcesso(erroExcesso({ alertas: [alerta('APROVACAO')], chave_documento: 'LANCAMENTO|LAN-1' }));
    expect(corpoPedido(semLinhas, undefined, 'x')).toBeNull();
    expect(podePrepararPedido(semLinhas, undefined)).toBe(false);
    const contexto = { tipo: 'EXPLORACAO' as const, data: '2026-10-02', linhas: [{ codigo_conta: '6211', valor: 250 }] };
    expect(corpoPedido(semLinhas, contexto, '  Obra urgente  ')).toEqual({
      tipo: 'EXPLORACAO', origem: 'LANCAMENTO', documento: 'LAN-1', data: '2026-10-02', linhas: contexto.linhas, motivo: 'Obra urgente',
    });
    const comServidor = extrairExcesso(erroExcesso({ chave_documento: 'ENCOMENDA|EC-9', tipo: 'EXPLORACAO', data: '2026-09-30', linhas: [{ codigo_conta: '2611', valor: 10 }] }));
    expect(corpoPedido(comServidor, contexto, 'Motivo válido')?.linhas).toEqual([{ codigo_conta: '2611', valor: 10, unidade_negocio_id: null, centro_custo_id: null, projeto_id: null }]);
    expect(corpoPedido(comServidor, contexto, 'Motivo válido')?.data).toBe('2026-09-30');
  });

  it('converte linhas D/C em linhas de controlo (débito consome, crédito abate) e ignora as incompletas', () => {
    expect(
      linhasDeMovimentos([
        { codigo_conta: '6211', tipo_dc: 'D', valor: 100.005, centro_custo_id: 4 },
        { codigo_conta: '4311', tipo_dc: 'C', valor: '100' },
        { codigo_conta: '62', tipo_dc: 'D', valor: 0 },
        { tipo_dc: 'D', valor: 5 },
        undefined,
      ]),
    ).toEqual([
      { codigo_conta: '6211', valor: 100.01, unidade_negocio_id: null, centro_custo_id: 4, projeto_id: null },
      { codigo_conta: '4311', valor: -100, unidade_negocio_id: null, centro_custo_id: null, projeto_id: null },
    ]);
  });

  it('valida o motivo (5 a 1000 caracteres, sem contar espaços nas pontas)', () => {
    expect(validarMotivo('  abc  ')).toMatch(/pelo menos 5/);
    expect(validarMotivo(undefined)).toMatch(/pelo menos 5/);
    expect(validarMotivo('x'.repeat(1001))).toMatch(/máximo/);
    expect(validarMotivo('Obra urgente')).toBeNull();
  });

  it('acrescenta as opções de aprovação no acto ao corpo, ao FormData e ao pedido do useAccao', () => {
    const o = opcoesAprovacaoNoActo('  Contrato assinado  ');
    expect(o).toEqual({ aprovar_excesso: true, motivo: 'Contrato assinado' });
    expect(comOpcoesOrcamento({ a: 1 }, o)).toEqual({ a: 1, orcamento: o });
    expect(comOpcoesOrcamento({ a: 1 }, undefined)).toEqual({ a: 1 });
    const fd = comOpcoesOrcamento(new FormData(), o);
    expect([fd.get('orcamento[aprovar_excesso]'), fd.get('orcamento[motivo]')]).toEqual(['1', 'Contrato assinado']);
    expect(comOpcoesOrcamentoPedido({ url: '/compras/propostas/1/adjudicar', dados: { data: '2026-10-02' } }, o)).toEqual({
      url: '/compras/propostas/1/adjudicar', dados: { data: '2026-10-02', orcamento: o },
    });
  });
});
