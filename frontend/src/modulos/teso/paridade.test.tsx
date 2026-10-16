import { describe, expect, it } from 'vitest';
import dayjs from 'dayjs';
import type { ContrapartidaLista, DocumentoTesouraria, IntegracaoOrfa, MeioPagamento } from './api';
import { GRUPOS_TESOURARIA, GRUPO_DO_ECRA, indicadoresTesouraria, separadorActivoTesouraria } from './BarraTesouraria';
import {
  bloqueioAnulacao, bloqueioEdicao, bloqueioExclusao, colunasHistorico, corValorDc, colunasOperacoes, docsOrigem, textoDocOrigem, textoNotas, textosEntidade, textoUnCc,
  totalValor, urlSegura,
} from './colunasLegado';
import { contaBancariaInicial } from './Conciliacao';
import { accoesHistorico, FERRAMENTAS_DISPENSADAS, parametrosHistorico } from './Historico';
import { linhasExtrato, pedidoMapa, TIPOS_MAPA } from './Mapas';
import { resumoMeios, tipoMeio } from './MeiosPagamento';
import { pedidoValido, preSeleccao } from './ModalReparacao';
import { motivoValido } from './motivo';
import { accoesDocumento, botaoNovo, separadorDaQuery } from './pagamentos/ListaDocumentos';

const linha = (p: Partial<ContrapartidaLista> = {}): ContrapartidaLista => ({
  codigo_conta: '62.1', tipo_dc: 'D', valor: '100.00', unidade_negocio: null, centro_custo: null, nota_demonstracao: null, nota_fluxo_caixa: null,
  numero_documento: null, data_documento_original: null, descricao: null, ...p,
});
const doc = (p: Partial<DocumentoTesouraria> = {}) =>
  ({ id: 1, tipo: 'PAGAMENTO', estado: 'PENDENTE', valor_total: '100.00', numero_documento: 'PG 2026/1', referencia: null, contrapartidas: [], entidades: [], ...p }) as unknown as DocumentoTesouraria;

describe('7.0.1 agregadores da Tesouraria', () => {
  it('três agregadores com os separadores e rótulos do legado', () => {
    expect(GRUPOS_TESOURARIA.operacoes.titulo).toBe('Tesouraria - Operações');
    expect(GRUPOS_TESOURARIA.operacoes.separadores.map((s) => s.rotulo)).toEqual(['Pagamentos', 'Recebimentos', 'Todos os Registos', 'Folha de Caixa']);
    expect(GRUPOS_TESOURARIA.contabilidade.subtitulo).toBe('Operações contabilísticas e integração de tesouraria no Diário geral.');
    expect(GRUPOS_TESOURARIA.contabilidade.separadores.map((s) => s.rotulo)).toEqual(['Integração no Diário', 'Histórico / Anulação']);
    expect(GRUPOS_TESOURARIA.gestao.separadores.map((s) => s.rotulo)).toEqual(['Emissão de Extratos', 'Conciliação Bancária', 'Conferência de Caixa']);
    expect(GRUPO_DO_ECRA.teso_folha_caixa).toBe('operacoes');
    expect(GRUPO_DO_ECRA.teso_meios_pagamento).toBeUndefined();
  });
  it('separador activo pelo caminho e pelo ?tipo=', () => {
    expect(separadorActivoTesouraria('operacoes', '/m/teso/teso_gestao_pagamentos', '?tipo=PAGAMENTO')).toBe('pagamentos');
    expect(separadorActivoTesouraria('operacoes', '/m/teso/teso_gestao_pagamentos', '?tipo=RECEBIMENTO')).toBe('recebimentos');
    expect(separadorActivoTesouraria('operacoes', '/m/teso/teso_gestao_pagamentos/12', '')).toBe('todos');
    expect(separadorActivoTesouraria('operacoes', '/m/teso/teso_folha_caixa', '')).toBe('folha_caixa');
    expect(separadorActivoTesouraria('gestao', '/m/teso/teso_gestao_conciliacao', '')).toBe('conciliacao');
    expect(separadorActivoTesouraria('gestao', '/outro', '')).toBeUndefined();
  });
  it('indicadores «Pendentes» e «Integrados» (… enquanto carrega)', () => {
    const [p, i] = indicadoresTesouraria({ pendentes: 4, integrados: 1234, anulados: 0 });
    expect([p.rotulo, p.valor, i.rotulo]).toEqual(['Pendentes', '4', 'Integrados']);
    expect(String(i.valor).replace(/\D/g, '')).toBe('1234');
    expect(indicadoresTesouraria(undefined)[0].valor).toBe('…');
  });
});

describe('7.1.1 Pagamentos / Recebimentos', () => {
  it('separador da query e botão «Novo …»', () => {
    expect(separadorDaQuery('PAGAMENTO')).toBe('PAGAMENTO');
    expect(separadorDaQuery('x')).toBe('TODOS');
    expect(botaoNovo('PAGAMENTO')).toEqual({ tipo: 'PAGAMENTO', rotulo: 'Novo Pagamento' });
    expect(botaoNovo('RECEBIMENTO').rotulo).toBe('Novo Recebimento');
    expect(botaoNovo('TODOS').rotulo).toBe('Novo Recebimento');
  });
  it('colunas do legado pela ordem', () => {
    expect(colunasOperacoes().map((c) => c.title)).toEqual(['Data', 'Tipo', 'Referencia', 'Conta Fin.', 'Contrapartida', 'U. Negocio / C. Custo', 'D/C',
      'Entidade (NIF)', 'Doc. Origem', 'Notas (D/F)', 'Descrição', 'Valor', 'Estado Recon.', 'Estado Contab.']);
  });
  it('valor a verde no débito da contrapartida e a vermelho no crédito (legado)', () => {
    expect(corValorDc('D')).toBe('#059669');
    expect(corValorDc('C')).toBe('#dc2626');
    expect(corValorDc(undefined)).toBe('#dc2626');
  });
  it('textos das colunas compostas', () => {
    expect(textoUnCc(linha({ unidade_negocio: 'UN1' }))).toBe('UN1 / ---');
    expect(textoNotas(linha({ nota_fluxo_caixa: 'F2' }))).toBe('- / F2');
    expect(textoDocOrigem(linha())).toBe('-');
    expect(textoDocOrigem(linha({ numero_documento: 'FT A/1', data_documento_original: '2026-01-31' }))).toBe('FT A/1 | 31/01/2026');
    const d = doc({ entidades: [{ nome: 'Cliente Teste', nif: '000000000' }, { nome: null, nif: '111' }], contrapartidas: [linha({ numero_documento: 'X' }), linha({ numero_documento: 'X' })] });
    expect(textosEntidade(d)).toEqual(['Cliente Teste (000000000)', 'NIF: 111']);
    expect(textosEntidade(d, false)).toEqual(['Cliente Teste', 'NIF: 111']);
    expect(docsOrigem(d)).toEqual(['X']);
  });
  it('total: soma de um tipo ou saldo recebimentos − pagamentos, sem anulados', () => {
    const ls = [doc({ tipo: 'RECEBIMENTO', valor_total: '300.00' }), doc({ valor_total: '100.00' }), doc({ valor_total: '50.00', estado: 'ANULADO' })];
    expect(totalValor(ls).replace(/\s/g, '')).toContain('200,00');
    expect(totalValor([ls[1], ls[2]], 'PAGAMENTO').replace(/\s/g, '')).toContain('100,00');
  });
  it('acções por linha: Detalhes, Copiar, Editar (cadeado se integrado), Excluir', () => {
    const fn = () => undefined;
    const o = { podeEmitir: true, podeEliminar: true, abrir: fn, copiar: fn, editar: fn, excluir: fn };
    expect(accoesDocumento(doc(), o).map((a) => a.titulo)).toEqual(['Detalhes', 'Copiar operação', 'Editar', 'Excluir']);
    const integ = accoesDocumento(doc({ estado: 'INTEGRADO' }), o);
    expect(integ[2]).toMatchObject({ icone: 'lock', titulo: 'Integrado na Contabilidade — apenas leitura' });
    expect(integ[3].desactivado).toBeTruthy();
    expect(accoesDocumento(doc(), { ...o, podeEmitir: false, podeEliminar: false }).filter((a) => !a.oculto).map((a) => a.chave)).toEqual(['detalhes']);
    expect(bloqueioEdicao(doc())).toBe(false);
    expect(bloqueioExclusao(doc({ estado: 'ANULADO' }))).toBeTruthy();
  });
});

describe('7.4.1 Histórico / Anulação', () => {
  it('colunas do legado', () => {
    expect(colunasHistorico(() => undefined).map((c) => c.title)).toEqual(['Data', 'Tipo', 'Referência', 'C. Tesouraria', 'V. Líquido Total', 'Contrapartidas (Linhas)',
      'Entidade', 'Doc. Origem', 'Compensação', 'Reconciliação Bancária', 'Status']);
  });
  it('filtros: só integrados, contas por prefixo validadas', () => {
    expect(parametrosHistorico({ contaTesouraria: '43.1', contaContrapartida: "1' or 1=1", referencia: '  RC 1 ', valor: 15 })).toMatchObject({
      estado: 'INTEGRADO', conta_prefixo: '43.1', contrapartida_prefixo: undefined, pesquisa: 'RC 1', valor: 15,
    });
  });
  it('anular por linha, bloqueado se reconciliado com o banco; documento externo só http(s)', () => {
    const o = { podeAnular: true, anular: () => undefined };
    expect(accoesHistorico(doc({ estado: 'INTEGRADO' }), o)[0]).toMatchObject({ chave: 'anular', titulo: 'Anular este documento' });
    const rec = doc({ estado: 'INTEGRADO', reconciliacao_bancaria: { codigo: 'REC-1', estado: 'CONCILIADO_BANCO', data: null } });
    expect(bloqueioAnulacao(rec)).toBeTruthy();
    expect(accoesHistorico(rec, o)[0]).toMatchObject({ chave: 'bloqueado', texto: 'Bloqueado' });
    expect(accoesHistorico(doc({ url_documento: 'javascript:alert(1)' }), o)[1].oculto).toBe(true);
    expect(urlSegura('https://exemplo.test/doc.pdf')).toBe('https://exemplo.test/doc.pdf');
    expect(urlSegura('data:text/html,x')).toBeNull();
  });
  it('ferramentas do legado dispensadas têm explicação', () => {
    expect(Object.values(FERRAMENTAS_DISPENSADAS).map((f) => f.titulo)).toEqual(['Recuperar Outros Módulos', 'Reparar Datas']);
    for (const f of Object.values(FERRAMENTAS_DISPENSADAS)) expect(f.texto.length).toBeGreaterThan(100);
  });
  it('reparação: pré-selecção sem os do legado, motivo e confirmação obrigatórios', () => {
    const orfa = (id: number, motivo: IntegracaoOrfa['motivo']) => ({ id, motivo }) as IntegracaoOrfa;
    const docs = [orfa(1, 'LANCAMENTO_ESTORNADO'), orfa(2, 'LEGADO_SEM_LIGACAO'), orfa(3, 'LANCAMENTO_INEXISTENTE')];
    expect(preSeleccao(docs)).toEqual([1, 3]);
    expect(motivoValido(' abc ')).toBe(false);
    expect(pedidoValido([docs[0]], 'motivo válido', false)).toBe(true);
    expect(pedidoValido([docs[0]], 'abc', false)).toBe(false);
    expect(pedidoValido(docs, 'motivo válido', false)).toBe(false);
    expect(pedidoValido(docs, 'motivo válido', true)).toBe(true);
    expect(pedidoValido([], 'motivo válido', true)).toBe(false);
  });
});

describe('7.5.1 / 7.6.1 / 7.7.1', () => {
  it('«Gerar Mapa»: tipos do legado, conta obrigatória e datas pela ordem', () => {
    expect(TIPOS_MAPA.map((t) => t.rotulo)).toEqual(['Extrato Bancário (Contas 43)', 'Folha de Caixa (Contas 45)']);
    expect(pedidoMapa(undefined, dayjs('2026-01-01'), dayjs('2026-01-31'))).toBeNull();
    expect(pedidoMapa('4311', dayjs('2026-02-01'), dayjs('2026-01-31'))).toBeNull();
    expect(pedidoMapa('4311', dayjs('2026-01-01'), dayjs('2026-01-31'))?.conta).toBe('4311');
  });
  it('extracto: 1.ª linha «saldo anterior ao período» com o saldo inicial', () => {
    const ls = linhasExtrato({ codigo_conta: '43.1', descricao: null, data_inicio: '2026-10-01', data_fim: '2026-10-31', saldo_inicial: '500.00', debito: '0', credito: '0', saldo_final: '500.00', movimentos: [] });
    expect(ls).toHaveLength(1);
    expect(ls[0]).toMatchObject({ saldoAnterior: true, saldo: '500.00' });
  });
  it('conciliação abre na conta bancária padrão (ou na 1.ª conta 43 activa)', () => {
    const m = (codigo_conta: string, p: Partial<MeioPagamento> = {}) => ({ codigo_conta, ativo: true, predefinido: false, ...p }) as MeioPagamento;
    expect(contaBancariaInicial([m('4512'), m('4312'), m('4311')])).toBe('4311');
    expect(contaBancariaInicial([m('4311'), m('4312', { predefinido: true })])).toBe('4312');
    expect(contaBancariaInicial([m('4311', { ativo: false }), m('4511', { predefinido: true })])).toBeUndefined();
    expect(contaBancariaInicial(undefined)).toBeUndefined();
  });
  it('meios de pagamento: tipo e resumo', () => {
    expect(tipoMeio({ codigo_conta: '4311' })).toBe('BANCO');
    expect(tipoMeio({ codigo_conta: '4511' })).toBe('CAIXA');
    const r = resumoMeios([
      { codigo_conta: '4311', iban: 'AO06000000000000000000000', ativo: true },
      { codigo_conta: '4511', iban: null, ativo: false },
    ] as MeioPagamento[]);
    expect(r).toEqual({ bancos: 1, caixas: 1, comIban: 1, activos: 1 });
  });
});
