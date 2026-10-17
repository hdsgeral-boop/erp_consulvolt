import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import type { ReactNode } from 'react';
import type { DocumentoTesouraria } from './api';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn().mockResolvedValue({ itens: [], paginacao: { pagina_atual: 1, por_pagina: 1, total: 0, ultima_pagina: 1 } }),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: { nome_utilizador: 'ana', nome_completo: 'Ana Ficticia' } }) }));

import { DetalheDocumento, rotuloPeriodoSalarial } from './pagamentos/DetalheDocumento';
import { URL_DOCUMENTO, camposCabecalhoApi } from './pagamentos/FormularioDocumento';
import { accoesConferencia } from './regras';
import { modeloActivo } from './cartas/Modelos';
import type { ModeloCarta } from './cartas/api';
import Conferencia from './Conferencia';

vi.setConfig({ testTimeout: 120_000 });

function ecra(no: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}><MemoryRouter><Routes><Route path="/*" element={no} /></Routes></MemoryRouter></QueryClientProvider>);
}

const DOC: DocumentoTesouraria = {
  id: 9, tipo: 'PAGAMENTO', data_documento: '2026-10-15', conta_financeira: '43.1', descricao: 'Pagamento ficticio', valor_total: '1500.00', estado: 'PENDENTE',
  referencia: 'REF-1', projeto_id: 3, codigo_moeda: 'AOA', taxa_cambio: null, valor_total_moeda: null, periodo_processamento_salarial_id: 5, reconciliacao_codigo: null,
  numero_documento: 'PAG 2026/9', numero_lan_contabilizacao: null, integrado_em: null, integrado_por: null, anulado_em: null, motivo_anulacao: null, importado: false,
  url_documento: 'https://exemplo.test/doc', projeto: { id: 3, codigo: 'PRJ-01', nome: 'Obra ficticia' },
  periodo_salarial: { id: 5, mes_ano: '05/2026', estado: 'FECHADO', contabilizado: true },
  notas_demonstracao: { 7: '24' }, notas_fluxo_caixa: { 8: 'F3' },
  linhas: [{ id: 1, codigo_conta: '62.1', terceiro_id: null, numero_documento: null, descricao: 'Serviço', valor: '1500.00', tipo_dc: 'D', unidade_negocio_id: null, centro_custo_id: null,
    projeto_id: null, venda_id: null, fatura_compra_id: null, codigo_moeda: null, valor_moeda: null, valor_introduzido: '1500.00', nota_demonstracao_id: 7, nota_fluxo_caixa_id: 8 }],
};

describe('Documento de tesouraria — campos do cabeçalho do legado', () => {
  it('URL só http/https; vazios vão a null; período só nos pagamentos', () => {
    expect(URL_DOCUMENTO.test('https://exemplo.test/a?b=1')).toBe(true);
    expect(URL_DOCUMENTO.test('http://exemplo.test')).toBe(true);
    for (const u of ['javascript:alert(1)', 'data:text/html,x', 'ftp://x', 'exemplo.test']) expect(URL_DOCUMENTO.test(u)).toBe(false);
    expect(camposCabecalhoApi({ tipo: 'PAGAMENTO', url_documento: '  https://x.test  ', projeto_id: 3, periodo_processamento_salarial_id: 5 }))
      .toEqual({ projeto_id: 3, url_documento: 'https://x.test', periodo_processamento_salarial_id: 5 });
    expect(camposCabecalhoApi({ tipo: 'RECEBIMENTO', url_documento: ' ', periodo_processamento_salarial_id: 5 }))
      .toEqual({ projeto_id: null, url_documento: null, periodo_processamento_salarial_id: null });
    expect(rotuloPeriodoSalarial({ id: 1, mes_ano: '05/2026', estado: 'VALIDADO', contabilizado: false })).toBe('05/2026 (validado)');
    expect(rotuloPeriodoSalarial(null)).toBeNull();
  });
});

describe('Detalhe do documento em modal (viewTesoMovementDetail)', () => {
  beforeEach(() => {
    obter.mockReset().mockResolvedValue(DOC);
    enviar.mockReset();
  });

  it('mostra projecto, período, notas e o botão do documento externo; «Fechar» fecha', async () => {
    const fechar = vi.fn();
    ecra(<DetalheDocumento emModal id={9} aoFechar={fechar} />);
    expect(await screen.findByText('PAG 2026/9 — Pagamento')).toBeInTheDocument();
    expect(obter).toHaveBeenCalledWith('/tesouraria/documentos/9');
    expect(screen.getByText('PRJ-01 - Obra ficticia')).toBeInTheDocument();
    expect(screen.getByText('05/2026 (integrado)')).toBeInTheDocument();
    expect(screen.getByText('24 / F3')).toBeInTheDocument();
    expect(screen.getByText('Documento de Tesouraria').closest('a')).toHaveAttribute('href', 'https://exemplo.test/doc');
    fireEvent.click(screen.getByText('Fechar'));
    expect(fechar).toHaveBeenCalled();
  });

  it('URL perigoso não vira ligação', async () => {
    obter.mockResolvedValue({ ...DOC, url_documento: 'javascript:alert(1)' });
    ecra(<DetalheDocumento emModal id={9} aoFechar={() => undefined} />);
    await screen.findByText('PAG 2026/9 — Pagamento');
    expect(screen.queryByText('Documento de Tesouraria')).toBeNull();
  });
});

describe('Conferência de caixa — «Nome do Tesoureiro / Operador»', () => {
  it('assinar: nem quem registou nem o operador escrito (sem maiúsculas/espaços)', () => {
    const c = { estado: 'FINALIZADO' as const, nome_operador: 'Tesoureiro X', nome_gerente: null, registado_por: 'ana' };
    const todas = () => true;
    expect(accoesConferencia(c, todas, 'ana').podeAssinar).toBe(false);
    expect(accoesConferencia({ ...c, registado_por: 'rui' }, todas, 'ana', 'Ana Ficticia').podeAssinar).toBe(true);
    expect(accoesConferencia({ ...c, registado_por: 'rui', nome_operador: ' ana  ficticia ' }, todas, 'ana', 'Ana Ficticia').podeAssinar).toBe(false);
  });

  it('o campo vem com o utilizador da sessão, é editável e vai no pedido', async () => {
    obter.mockReset().mockImplementation((url: string) => Promise.resolve(url === '/tesouraria/conferencias' ? [] : { saldo_final: '0.00' }));
    enviar.mockReset().mockResolvedValue({ dados: {}, mensagem: 'ok' });
    ecra(<Conferencia />);
    const campo = await screen.findByLabelText('Nome do Tesoureiro / Operador');
    expect(campo).toHaveValue('Ana Ficticia');
    fireEvent.change(campo, { target: { value: 'Tesoureiro Ficticio' } });
    expect(campo).toHaveValue('Tesoureiro Ficticio');
  });
});

describe('Cartas — um texto activo por tipo', () => {
  const m = (p: Partial<ModeloCarta>): ModeloCarta => ({ id: 1, tipo: 'TERCEIRO', nome: null, assunto: 'A', corpo: 'C', nome_assinatura: null, cargo_assinatura: null, local: null, predefinido: false, ...p });
  it('o predefinido do tipo; senão o mais antigo; nenhum se o tipo não tem modelos', () => {
    expect(modeloActivo([m({ id: 2 }), m({ id: 3, predefinido: true })], 'TERCEIRO')?.id).toBe(3);
    expect(modeloActivo([m({ id: 5 }), m({ id: 4 })], 'TERCEIRO')?.id).toBe(4);
    expect(modeloActivo([m({ id: 5 })], 'SALARIOS')).toBeUndefined();
  });
});
