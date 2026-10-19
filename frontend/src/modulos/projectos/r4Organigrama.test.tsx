import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App, Modal, message } from 'antd';
import type { ReactNode } from 'react';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { pagina_atual: 1, por_pagina: 500, total: 0, ultima_pagina: 1 } }),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null, menu: [], preferencias: {} }) }));

import { SeparadorOrganigrama } from './separadores/Organigrama';
import { aplicarMovimento, MIME_MEMBRO, opcoesDestino, previsaoDestino } from './separadores/organigrama/moverMembro';
import { ajustarTexto, medidorAproximado, medirCartao, quebrarLinhas } from './separadores/organigrama/texto';
import { disporArvore, MEDIDAS } from './separadores/organigrama/layout';
import { organigramaSvg } from './separadores/organigrama/svg';
import type { PropsSeparador } from './DetalheProjecto';
import type { FichaProjecto, Membro, Organigrama, Posicao } from './comum/tipos';

/**
 * Ronda 4 (r4_rh_proj) — arrastar membros entre posições do organigrama, como no legado (ligarArrasto,
 * projectos_organigrama.js:354-367): pré-visualização do destino, confirmação acima das vagas, «Desfazer» imediato e
 * alternativa por teclado («Mover para…»). Dados fictícios.
 */
vi.setConfig({ testTimeout: 300_000 });
// com a máquina carregada (vários agentes) as esperas por omissão (1 s) não chegam
configure({ asyncUtilTimeout: 10_000 });

const PROJECTO = { id: 1, codigo: 'PRJ-T', nome: 'Obra fictícia', tipo: 'INTERNO', estado: 'ACTIVO' } as unknown as FichaProjecto;
const ACC = { gerir: true, execucao: true, eliminar: true, revisao: true } as unknown as PropsSeparador['acc'];
const VALORES = { orcamento: '0', executado: '0' };
const membro = (id: number, nome: string, no: number | null): Membro => ({ id, nome, no_organigrama_projeto_id: no, papel: null, tipo: 'LIVRE' } as unknown as Membro);
const posicao = (id: number, titulo: string, pai: number | null, vagas: number, membros: Membro[], responsavel: number | null = null): Posicao => ({
  id, no_pai_id: pai, titulo, area: null, descricao: null, vagas, membro_responsavel_id: responsavel, ordem: id, cor: 'azul', apoio: false, membros, valores: VALORES,
} as unknown as Posicao);
const organigrama = (): Organigrama => ({
  posicoes: [posicao(1, 'Direcção', null, 1, [membro(21, 'Ana Fictícia', 1)], 21), posicao(2, 'Frente', 1, 0, [])],
  sem_posicao: [membro(22, 'Rui Fictício', null)],
  totais: { ...VALORES, vagas: 1, membros: 2, alocados: 1, tarefas_sem_posicao: 0, orcamento_sem_responsavel: 0 },
} as unknown as Organigrama);

describe('regras do mover (puras)', () => {
  it('pré-visualiza o destino com as vagas e recusa a mesma posição', () => {
    const o = organigrama();
    expect(previsaoDestino(o, 22, 2)).toMatchObject({ texto: 'Mover «Rui Fictício» para «Frente» · ficará com 1 membro(s)', excede: false, mesmo: false });
    expect(previsaoDestino(o, 22, 1)).toMatchObject({ texto: 'Mover «Rui Fictício» para «Direcção» · ficará com 2/1 vaga(s) — excede', excede: true, ficaria: 2, vagas: 1 });
    expect(previsaoDestino(o, 21, 1)?.mesmo).toBe(true);
    expect(previsaoDestino(o, 21, null)?.texto).toBe('Retirar «Ana Fictícia» da posição');
  });
  it('opções do «Mover para…»: as outras posições e, se tiver posição, «sem posição»', () => {
    const o = organigrama();
    expect(opcoesDestino(o, 1).map((x) => x.valor)).toEqual([2, null]);
    expect(opcoesDestino(o, null).map((x) => x.rotulo)).toEqual(['Direcção (1/1)', 'Frente (0)']);
  });
  it('actualização optimista: sai da origem (perde a responsabilidade), entra no destino; desfazer repõe', () => {
    const o = organigrama();
    const m = aplicarMovimento(o, 21, 2);
    expect(m.posicoes[0].membros).toHaveLength(0);
    expect(m.posicoes[0].membro_responsavel_id).toBeNull();
    expect(m.posicoes[1].membros.map((x) => x.id)).toEqual([21]);
    const d = aplicarMovimento(m, 21, 1, true);
    expect(d.posicoes[0].membro_responsavel_id).toBe(21);
    const livre = aplicarMovimento(o, 22, 2);
    expect([livre.sem_posicao.length, livre.totais.alocados]).toEqual([0, 2]);
    expect(aplicarMovimento(o, 21, null).totais.alocados).toBe(0);
  });
});

function ecra(ui: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}><App>{ui}</App></QueryClientProvider>);
}

/** DataTransfer mínimo do jsdom (não implementa o arrastar nativo). */
function dataTransfer() {
  const dados = new Map<string, string>();
  return {
    dropEffect: 'none', effectAllowed: 'all',
    get types() { return [...dados.keys()]; },
    setData: (t: string, v: string) => { dados.set(t, v); },
    getData: (t: string) => dados.get(t) ?? '',
  };
}

const posts = (url: string) => enviar.mock.calls.filter((c) => c[0] === 'post' && c[1] === url).map((c) => c[2]);
const cartao = (titulo: string) => screen.getAllByRole('treeitem').find((el) => el.getAttribute('aria-label')?.startsWith(titulo))!;

let resposta: Record<string, unknown>;
let segurar: Promise<void> | null = null;
beforeEach(() => {
  message.destroy();
  Modal.destroyAll();
  segurar = null;
  resposta = { membro_id: 22, de: null, para: 2, era_responsavel: false, alterado: true };
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(url === '/projetos/1/organigrama' ? organigrama() : url.startsWith('/sistema/preferencias') ? [] : null));
  enviar.mockReset().mockImplementation(async (_m: string, url: string) => {
    if (url.endsWith('/organigrama/mover') && segurar) await segurar;
    return { dados: url.endsWith('/organigrama/mover') ? resposta : {}, mensagem: 'ok' };
  });
});

describe('Organigrama — arrastar membros entre posições', () => {
  it('arrastar da «Equipa sem posição» para uma posição: pré-visualização, pedido ao servidor e «Desfazer»', async () => {
    ecra(<SeparadorOrganigrama projecto={PROJECTO} acc={ACC} />);
    const livres = await screen.findByTestId('equipa-sem-posicao');
    const rui = within(livres).getByText('Rui Fictício').closest('[data-org-membro]') as HTMLElement;
    const dt = dataTransfer();
    let soltar = () => {};
    segurar = new Promise<void>((r) => { soltar = r; });
    fireEvent.dragStart(rui, { dataTransfer: dt });
    expect(dt.types).toContain(MIME_MEMBRO);
    fireEvent.dragOver(cartao('Frente'), { dataTransfer: dt });
    expect(await screen.findByTestId('previsao-destino')).toHaveTextContent('Mover «Rui Fictício» para «Frente» · ficará com 1 membro(s)');
    fireEvent.drop(cartao('Frente'), { dataTransfer: dt });
    await waitFor(() => expect(posts('/projetos/1/organigrama/mover')).toEqual([{ membro_id: 22, no_destino_id: 2, no_origem_id: null, confirmar_excesso: false }]));
    // actualização imediata no quadro, antes de o servidor responder
    expect(cartao('Frente').getAttribute('aria-label')).toContain('Rui Fictício');
    segurar = null;
    soltar();
    // «Desfazer» no aviso: volta a «sem posição»
    fireEvent.click((await screen.findAllByRole('button', { name: 'Desfazer' }, { timeout: 5000 })).at(-1)!);
    await waitFor(() => expect(posts('/projetos/1/organigrama/mover')[1]).toEqual({ membro_id: 22, no_destino_id: null, no_origem_id: 2, confirmar_excesso: true, repor_responsavel: false }), { timeout: 5000 });
  });

  it('largar numa posição sem vagas pede confirmação e só então envia com confirmar_excesso', async () => {
    resposta = { membro_id: 22, de: null, para: 1, era_responsavel: false, alterado: true };
    ecra(<SeparadorOrganigrama projecto={PROJECTO} acc={ACC} />);
    const livres = await screen.findByTestId('equipa-sem-posicao');
    const rui = within(livres).getByText('Rui Fictício').closest('[data-org-membro]') as HTMLElement;
    const dt = dataTransfer();
    fireEvent.dragStart(rui, { dataTransfer: dt });
    fireEvent.dragOver(cartao('Direcção'), { dataTransfer: dt });
    expect(await screen.findByTestId('previsao-destino')).toHaveTextContent('2/1 vaga(s) — excede');
    expect(cartao('Direcção').getAttribute('data-org-alvo')).toBe('excede');
    fireEvent.drop(cartao('Direcção'), { dataTransfer: dt });
    expect((await screen.findAllByText('Vagas excedidas')).length).toBeGreaterThan(0);
    expect(posts('/projetos/1/organigrama/mover')).toHaveLength(0);
    fireEvent.click(screen.getByRole('button', { name: 'Alocar' }));
    await waitFor(() => expect(posts('/projetos/1/organigrama/mover')).toEqual([{ membro_id: 22, no_destino_id: 1, no_origem_id: null, confirmar_excesso: true }]));
  });

  it('arrastar o responsável para «Equipa sem posição» e desfazer repõe a responsabilidade', async () => {
    resposta = { membro_id: 21, de: 1, para: null, era_responsavel: true, alterado: true };
    ecra(<SeparadorOrganigrama projecto={PROJECTO} acc={ACC} />);
    await screen.findByTestId('equipa-sem-posicao');
    const ana = within(cartao('Direcção')).getByText('Ana Fictícia').closest('[data-org-membro]') as HTMLElement;
    const dt = dataTransfer();
    fireEvent.dragStart(ana, { dataTransfer: dt });
    const livres = screen.getByTestId('equipa-sem-posicao');
    fireEvent.dragOver(livres, { dataTransfer: dt });
    expect(await within(livres).findByText('Retirar «Ana Fictícia» da posição')).toBeInTheDocument();
    fireEvent.drop(livres, { dataTransfer: dt });
    await waitFor(() => expect(posts('/projetos/1/organigrama/mover')).toEqual([{ membro_id: 21, no_destino_id: null, no_origem_id: 1, confirmar_excesso: false }]));
    fireEvent.click((await screen.findAllByRole('button', { name: 'Desfazer' }, { timeout: 5000 })).at(-1)!);
    await waitFor(() => expect(posts('/projetos/1/organigrama/mover')[1]).toEqual({ membro_id: 21, no_destino_id: 1, no_origem_id: null, confirmar_excesso: true, repor_responsavel: true }), { timeout: 5000 });
  });

  it('alternativa por teclado: «Mover para…» na equipa sem posição', async () => {
    ecra(<SeparadorOrganigrama projecto={PROJECTO} acc={ACC} />);
    await screen.findByTestId('equipa-sem-posicao');
    fireEvent.click(screen.getByRole('button', { name: 'Mover Rui Fictício para…' }));
    fireEvent.click(await screen.findByText('Frente (0)'));
    await waitFor(() => expect(posts('/projetos/1/organigrama/mover')).toEqual([{ membro_id: 22, no_destino_id: 2, no_origem_id: null, confirmar_excesso: false }]));
  });

  it('o arrasto de outra coisa (ficheiro/texto) não é largado como membro; sem permissão não há arrasto', async () => {
    const { unmount } = ecra(<SeparadorOrganigrama projecto={PROJECTO} acc={ACC} />);
    await screen.findByTestId('equipa-sem-posicao');
    const dt = dataTransfer();
    dt.setData('text/plain', 'qualquer');
    fireEvent.dragOver(cartao('Frente'), { dataTransfer: dt });
    fireEvent.drop(cartao('Frente'), { dataTransfer: dt });
    expect(screen.queryByTestId('previsao-destino')).not.toBeInTheDocument();
    expect(posts('/projetos/1/organigrama/mover')).toHaveLength(0);
    unmount();
    await act(async () => { ecra(<SeparadorOrganigrama projecto={PROJECTO} acc={{ ...ACC, gerir: false }} />); });
    const livres = await screen.findByTestId('equipa-sem-posicao');
    expect(within(livres).getByText('Rui Fictício').closest('[data-org-membro]')?.getAttribute('draggable')).toBe('false');
    expect(screen.queryByRole('button', { name: /Mover .* para…/ })).not.toBeInTheDocument();
  });
});

describe('texto completo nos cartões (sem reticências)', () => {
  const TITULO = 'Direcção do Projecto de Reabilitação da Estrada Nacional e Pontes Associadas';
  it('quebra em até 3 linhas; só se exceder reduz a letra; nunca «…» nem perde texto', () => {
    const curto = ajustarTexto('Direcção', 190, { tamanho: 13, minimo: 11, negrito: true });
    expect(curto).toEqual({ linhas: ['Direcção'], tamanho: 13 });
    const longo = ajustarTexto(TITULO, 190, { tamanho: 13, minimo: 11, negrito: true });
    expect(longo.linhas.length).toBeGreaterThan(1);
    expect(longo.linhas.length).toBeLessThanOrEqual(3);
    expect(longo.linhas.join(' ')).toBe(TITULO);
    const enorme = ajustarTexto(`${TITULO} ${TITULO}`, 190, { tamanho: 13, minimo: 11, negrito: true });
    expect(enorme.tamanho).toBe(11);
    expect(enorme.linhas.join(' ')).toBe(`${TITULO} ${TITULO}`);
    expect(enorme.linhas.join('')).not.toContain('…');
    // palavra maior do que a linha parte-se sem perder caracteres
    const palavra = quebrarLinhas('Supercalifragilisticoexpialidocioso', 60, 12, false, medidorAproximado);
    expect(palavra.join('')).toBe('Supercalifragilisticoexpialidocioso');
    expect(palavra.length).toBeGreaterThan(1);
  });

  it('o cartão cresce com o texto e a árvore recalcula posições e ligações', () => {
    const curto = medirCartao({ titulo: 'Frente', pessoas: [] });
    const alto = medirCartao({ titulo: TITULO, pessoas: [{ nome: 'Ana Maria da Conceição Fictícia dos Santos Pereira', cargo: 'Engenheira residente responsável pela fiscalização' }] });
    expect(curto.altura).toBe(MEDIDAS.altura);
    expect(alto.altura).toBeGreaterThan(MEDIDAS.altura);
    const nos = [{ id: 1, pai: null }, { id: 2, pai: 1 }, { id: 3, pai: 1 }];
    const d = disporArvore(nos, 'vertical', new Set(), MEDIDAS, new Map([[1, 200], [2, 160]]));
    expect(d.nos.get(1)!.altura).toBe(200);
    // os filhos começam abaixo do pai mais alto e alinham pelo topo
    expect(d.nos.get(2)!.y).toBe(d.nos.get(1)!.y + 200 + MEDIDAS.niveis);
    expect(d.nos.get(3)!.y).toBe(d.nos.get(2)!.y);
    expect(d.ligacoes[0].d).toContain(`M${d.nos.get(1)!.x + MEDIDAS.largura / 2},${d.nos.get(1)!.y + 200}`);
    expect(d.altura).toBe(d.nos.get(2)!.y + 160 + MEDIDAS.margem);
    const h = disporArvore(nos, 'horizontal', new Set(), MEDIDAS, new Map([[2, 200]]));
    expect(h.nos.get(3)!.y).toBeGreaterThanOrEqual(h.nos.get(2)!.y + 200 + MEDIDAS.irmaos);
  });

  it('SVG da impressão/PNG com o título inteiro em várias linhas', () => {
    const d = disporArvore([{ id: 1, pai: null }], 'vertical', new Set(), MEDIDAS, new Map([[1, medirCartao({ titulo: TITULO, pessoas: [] }).altura]]));
    const svg = organigramaSvg(d, new Map([[1, { id: 1, titulo: TITULO, area: null, cor: 'azul', apoio: false, vagas: '0 membro(s)', pessoas: [] }]]));
    expect(svg).not.toContain('…');
    for (const palavra of TITULO.split(' ')) expect(svg).toContain(palavra);
    expect((svg.match(/font-weight="700"/g) ?? []).length).toBeGreaterThan(1);
  });

  it('o ecrã mostra o título completo da posição, sem reticências, com o texto inteiro no title', async () => {
    const o = organigrama();
    o.posicoes[0] = { ...o.posicoes[0], titulo: TITULO };
    obter.mockImplementation((url: string) => Promise.resolve(url === '/projetos/1/organigrama' ? o : url.startsWith('/sistema/preferencias') ? [] : null));
    ecra(<SeparadorOrganigrama projecto={PROJECTO} acc={ACC} />);
    await screen.findByTestId('equipa-sem-posicao');
    const c = cartao(TITULO);
    expect(c.querySelector('.ant-typography-ellipsis')).toBeNull();
    expect(c.textContent).not.toContain('…');
    expect(within(c).getByTitle(TITULO)).toBeInTheDocument();
    // o título quebra em várias linhas (cada uma no seu bloco) em vez de ser cortado
    expect(within(c).getByTitle(TITULO).children.length).toBeGreaterThan(1);
    expect([...within(c).getByTitle(TITULO).children].map((l) => l.textContent).join(' ')).toBe(TITULO);
  });
});
