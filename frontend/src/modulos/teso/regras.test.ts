import { accoesConferencia, accoesDocumento, accoesSessao, errosDoLote, extratoConfere, lerDenominacoes, sentidoPorOmissao, somaCorrespondencia, totalContado, totalDocumento } from './regras';

const todas = () => true;
const so = (...permitidas: string[]) => (...c: string[]) => c.some((x) => permitidas.includes(x));

describe('total do documento de tesouraria', () => {
  it('pagamento = débitos − créditos', () => {
    expect(totalDocumento('PAGAMENTO', [{ tipo_dc: 'D', valor: 1000 }, { tipo_dc: 'C', valor: '50.25' }])).toEqual({ total: '949.75', valido: true });
    expect(totalDocumento('PAGAMENTO', [{ tipo_dc: 'C', valor: 10 }]).valido).toBe(false);
  });
  it('recebimento = créditos − débitos', () => {
    expect(totalDocumento('RECEBIMENTO', [{ tipo_dc: 'C', valor: '0.10' }, { tipo_dc: 'C', valor: '0.20' }])).toEqual({ total: '0.30', valido: true });
    expect(totalDocumento('RECEBIMENTO', [{ tipo_dc: 'D', valor: 5 }, { tipo_dc: 'C', valor: 5 }]).valido).toBe(false);
  });
  it('sentido por omissão das linhas', () => {
    expect(sentidoPorOmissao('PAGAMENTO')).toBe('D');
    expect(sentidoPorOmissao('RECEBIMENTO')).toBe('C');
  });
});

describe('acções dos documentos', () => {
  it('pendente: editar, anular e integrar conforme as permissões', () => {
    expect(accoesDocumento({ estado: 'PENDENTE', periodo_processamento_salarial_id: null }, todas)).toEqual({ podeEditar: true, podeAnular: true, podeIntegrar: true, podeDesintegrar: false });
    expect(accoesDocumento({ estado: 'PENDENTE', periodo_processamento_salarial_id: null }, so('teso_integrar'))).toEqual({ podeEditar: false, podeAnular: false, podeIntegrar: true, podeDesintegrar: false });
  });
  it('integrado: só desintegrar; anulado: nada', () => {
    expect(accoesDocumento({ estado: 'INTEGRADO', periodo_processamento_salarial_id: null }, todas)).toEqual({ podeEditar: false, podeAnular: false, podeIntegrar: false, podeDesintegrar: true });
    expect(Object.values(accoesDocumento({ estado: 'ANULADO', periodo_processamento_salarial_id: null }, todas)).some(Boolean)).toBe(false);
  });
});

describe('acções da sessão de caixa', () => {
  it('segue o ciclo ABERTA → FECHADA → CONTABILIZADA', () => {
    expect(accoesSessao({ estado: 'ABERTA', movimentos: [] }, todas)).toMatchObject({ podeRegistar: true, podeFechar: true, podeContabilizar: false, podeEliminar: true });
    expect(accoesSessao({ estado: 'ABERTA', movimentos: [{} as never] }, todas).podeEliminar).toBe(false);
    expect(accoesSessao({ estado: 'FECHADA', movimentos: [] }, todas)).toMatchObject({ podeRegistar: false, podeContabilizar: true, podeDescontabilizar: false });
    expect(accoesSessao({ estado: 'CONTABILIZADA', movimentos: [] }, todas)).toMatchObject({ podeContabilizar: false, podeDescontabilizar: true });
  });
});

describe('conferência de caixa', () => {
  it('o gerente que assina não pode ser o operador', () => {
    const c = { estado: 'FINALIZADO' as const, nome_operador: 'ana', nome_gerente: null };
    expect(accoesConferencia(c, todas, 'ana').podeAssinar).toBe(false);
    expect(accoesConferencia(c, todas, 'rui').podeAssinar).toBe(true);
    expect(accoesConferencia({ ...c, nome_gerente: 'rui' }, todas, 'rui').podeAssinar).toBe(false);
    expect(accoesConferencia({ ...c, estado: 'RASCUNHO' }, todas, 'rui')).toMatchObject({ podeEditar: true, podeFinalizar: true, podeAssinar: false, podeReabrir: false });
  });
  it('calcula o total contado e lê as denominações gravadas', () => {
    expect(totalContado({ N5000: 2, M1: 3, M50: -1, N200: 1.7 })).toBe('10203.00');
    expect(lerDenominacoes('{"N1000":4}')).toEqual({ N1000: 4 });
    expect(lerDenominacoes('xx')).toEqual({});
    expect(lerDenominacoes(null)).toEqual({});
  });
});

describe('correspondência extracto × diário', () => {
  it('crédito no banco casa com débito no diário', () => {
    expect(somaCorrespondencia([{ tipo_dc: 'C', valor: '100.00' }], [{ tipo_dc: 'D', valor: 60 }, { tipo_dc: 'D', valor: 40 }]).casa).toBe(true);
    const r = somaCorrespondencia([{ tipo_dc: 'C', valor: 100 }], [{ tipo_dc: 'C', valor: 100 }]);
    expect(r.casa).toBe(false);
    expect(r.diferenca).toBe('200.00');
    expect(somaCorrespondencia([], []).casa).toBe(false);
  });
});

describe('afinação (ADR-064)', () => {
  it('lista os erros da integração em lote pelo número do documento', () => {
    expect(errosDoLote({ integrados: [{ id: 1, numero_documento: 'PAG A2026/1', numero_lan_contabilizacao: 'BD1' }], erros: [{ id: 2, numero_documento: 'PAG A2026/2', codigo: 'X', mensagem: 'O documento está ANULADO.' }, { id: 9, numero_documento: null, codigo: 'DOCUMENTO_INEXISTENTE', mensagem: 'Documento inexistente.' }] })).toEqual([
      'PAG A2026/2: O documento está ANULADO.',
      '#9: Documento inexistente.',
    ]);
    expect(errosDoLote(null)).toEqual([]);
  });

  it('confere o saldo corrido do extracto em cêntimos', () => {
    const m = (tipo_dc: 'D' | 'C', valor: string) => ({ tipo_dc, valor }) as never;
    expect(extratoConfere({ saldo_inicial: '0.10', saldo_final: '-350.30', movimentos: [m('C', '100.20'), m('C', '250.20')] })).toBe(true);
    expect(extratoConfere({ saldo_inicial: '0.00', saldo_final: '1.00', movimentos: [m('D', '0.99')] })).toBe(false);
  });
});
