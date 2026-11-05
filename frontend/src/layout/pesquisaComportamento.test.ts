import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import type { ModuloMenu } from '@/api/tipos';
import { INDICE_PESQUISA, PALAVRAS_PESQUISA, SINONIMOS_PESQUISA } from './indicePesquisa.gerado';
import { pesquisarSistema, prepararIndiceAccoes, procurarAccoes, variantesDe, type OpcoesPesquisa } from './PesquisaEcras';

// Comportamento da pesquisa «Procurar» com o vocabulário REAL (índice gerado, sinónimos, catálogo de permissões): o menu é
// montado a partir do catálogo como o servidor o faria para um utilizador com acesso total (ServicoPermissoes::menu, sem
// os ecrãs da plataforma nem o ia_chat); o que o utilizador pode fazer é o conjunto de chaves de `pode`.
interface CatalogoEcra { id: string; nome: string; vistas: string[]; pai: string | null; tarefas: { chave: string; rotulo: string; sensivel?: boolean }[] }
const catalogo = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../backend/resources/permissoes/catalogo.json'), 'utf8')) as { modulos: { id: string; nome: string; ecras: CatalogoEcra[] }[] };
const FORA = (id: string) => id === 'ia_chat' || id === 'config_logs' || id.startsWith('plataforma_') || id.startsWith('suporte_tickets_');
const menuTotal = (): ModuloMenu[] =>
  catalogo.modulos
    .map((m) => ({ id: m.id, nome: m.nome, ecras: m.ecras.filter((e) => !FORA(e.id)).map((e) => ({ id: e.id, nome: e.nome, vistas: e.vistas, pai: e.pai, tarefas: e.tarefas.map((t) => ({ chave: t.chave, rotulo: t.rotulo })) })) }))
    .filter((m) => m.ecras.length);
const MENU = menuTotal();
const accoes = prepararIndiceAccoes(INDICE_PESQUISA, MENU);
const base: OpcoesPesquisa = { eFavorito: () => false, pode: () => true, accoes, sinonimos: SINONIMOS_PESQUISA, palavras: PALAVRAS_PESQUISA };
const procurar = (termo: string, o: Partial<OpcoesPesquisa> = {}, menu = MENU) => pesquisarSistema(menu, termo, { ...base, ...o, accoes: o.accoes ?? (menu === MENU ? accoes : prepararIndiceAccoes(INDICE_PESQUISA, menu)) });

describe('pesquisa «Procurar»: sinónimos, acentos, prefixos e ordem das palavras', () => {
  it.each([
    ['retenção', 'rh_rel_irt'],
    ['retenção na fonte', 'rh_rel_irt'],
    ['IRT', 'rh_rel_irt'],
    ['INSS', 'rh_rel_inss'],
    ['segurança social', 'rh_rel_inss'],
    ['férias', 'rh_ferias'],
    ['ausências', 'rh_ferias'],
    ['recibo de vencimento', 'rh_rel_recibos'],
    ['recibo', 'rh_rel_recibos'],
    ['utilizadores', 'config_utilizadores'],
    ['licença', 'config_licenca'],
    ['reconciliação', 'teso_gestao_conciliacao'],
    ['extracto', 'teso_gestao_conciliacao'],
    ['SAF-T', 'saft_contabilidade'],
    ['AGT', 'inventario_agt'],
    ['organigrama', 'est_organigrama'],
    ['método de trabalho', 'mt_assuntos'],
  ])('«%s» encontra o ecrã %s', (termo, ecra) => {
    expect(procurar(termo).ecras.map((e) => e.ecra)).toContain(ecra);
  });

  it.each([
    ['delegar', /separador:est_estrutura:Delegações/],
    ['multa', /separador:est_estrutura:Multas de entregáveis/],
    ['sessões', /pagina:\/dados-pessoais:As minhas sessões/],
    ['licença', /pagina|ecra|config/],
    ['multas', /Importar multas/],
    ['elegibilidade', /elegibilidade/i],
    ['conta e subscrição', /config_licenca Licença e Plano/],
  ])('«%s» encontra funções/botões/separadores', (termo, esperado) => {
    const r = procurar(termo);
    const texto = [...r.ecras.map((e) => `${e.ecra} ${e.nome}`), ...r.funcoes.map((f) => `${f.chave} ${f.nome}`), ...r.accoes.map((a) => `${a.tipo}:${a.ecra}:${a.nome}`)].join('\n');
    expect(texto).toMatch(esperado);
  });

  it('é indiferente a acentos, maiúsculas e à ordem das palavras, e aceita prefixos', () => {
    const a = procurar('retencao fonte').ecras.map((e) => e.ecra);
    expect(procurar('FONTE retenção').ecras.map((e) => e.ecra)).toEqual(a);
    expect(a).toContain('rh_rel_irt');
    expect(procurar('retenc').ecras.map((e) => e.ecra)).toContain('rh_rel_irt');
    expect(procurar('reconcil').ecras.map((e) => e.ecra)).toContain('teso_gestao_conciliacao');
    expect(procurar('FÉRIAS').ecras.map((e) => e.ecra)).toContain('rh_ferias');
  });

  it('os sinónimos curtos só valem no início de uma palavra («irt» não encontra «virtual»)', () => {
    expect(variantesDe('retencao', SINONIMOS_PESQUISA)).toEqual(expect.arrayContaining(['retencao', '\u0001irt']));
    const menu: ModuloMenu[] = [{ id: 'x', nome: 'X', ecras: [{ id: 'x_virtual', nome: 'Caixa virtual', vistas: [], pai: null }] }];
    expect(procurar('retencao', {}, menu).ecras).toEqual([]);
    const menu2: ModuloMenu[] = [{ id: 'x', nome: 'X', ecras: [{ id: 'x_irt', nome: 'Mapa IRT mensal', vistas: [], pai: null }] }];
    expect(procurar('retencao', {}, menu2).ecras.map((e) => e.ecra)).toEqual(['x_irt']);
  });

  it('ecrãs primeiro: os resultados vêm separados por grupo e o ecrã com o nome exacto lidera', () => {
    const r = procurar('colaboradores');
    expect(r.ecras[0].ecra).toBe('colaboradores');
    // um ecrã favorito sobe ao topo
    const fav = procurar('relatorios', { eFavorito: (_m, e) => e === 'pos_relatorios' });
    expect(fav.ecras[0].ecra).toBe('pos_relatorios');
  });

  it('respeita os limites por grupo', () => {
    const r = procurar('exportar', { limiteAccoes: 3, limiteOutros: 2, limiteEcras: 2, limiteFuncoes: 2 });
    expect(r.ecras.length).toBeLessThanOrEqual(2);
    expect(r.funcoes.length).toBeLessThanOrEqual(2);
    expect(r.accoes.filter((a) => a.tipo === 'relatorio').length).toBeLessThanOrEqual(2);
    expect(r.accoes.filter((a) => ['botao', 'janela', 'separador'].includes(a.tipo)).length).toBeLessThanOrEqual(3);
    const padrao = procurar('novo');
    expect(padrao.ecras.length).toBeLessThanOrEqual(8);
    expect(padrao.funcoes.length).toBeLessThanOrEqual(10);
    expect(padrao.accoes.filter((a) => a.tipo === 'botao' || a.tipo === 'janela' || a.tipo === 'separador').length).toBeLessThanOrEqual(12);
  });

  it('sem termo só aparecem os favoritos', () => {
    expect(procurar('').accoes).toEqual([]);
    expect(procurar('  ').funcoes).toEqual([]);
    expect(procurar('', { eFavorito: (_m, e) => e === 'dashboard' }).ecras.map((e) => e.ecra)).toEqual(['dashboard']);
  });

  it('a ligação dos separadores usa ?sep= e as janelas/botões abrem o ecrã onde se executam', () => {
    const delegacoes = procurar('delegacoes').accoes.find((a) => a.tipo === 'separador' && a.ecra === 'est_estrutura');
    expect(delegacoes?.rota).toBe('/m/estrutura/est_estrutura?sep=delegacoes');
    const janela = procurar('abrir periodo salarial').accoes.find((a) => a.tipo === 'janela');
    expect(janela?.rota).toMatch(/^\/m\/[a-z]+\/[a-z_]+$/);
  });
});

describe('pesquisa «Procurar»: só mostra o que o utilizador pode usar', () => {
  it('tarefas sensíveis só aparecem a quem as tem (funções)', () => {
    const sensiveis = catalogo.modulos.flatMap((m) => m.ecras.filter((e) => !FORA(e.id)).flatMap((e) => e.tarefas.filter((t) => t.sensivel).map((t) => ({ ...t, ecra: e.id }))));
    expect(sensiveis.length).toBeGreaterThan(50);
    for (const t of sensiveis) {
      const sem = procurar(t.rotulo, { pode: (c) => c !== t.chave });
      expect(sem.funcoes.map((f) => f.chave), `${t.chave} sem permissão`).not.toContain(t.chave);
      const com = procurar(t.rotulo, { pode: () => true, limiteFuncoes: 50 });
      expect(com.funcoes.map((f) => f.chave), `${t.chave} com permissão`).toContain(t.chave);
    }
  });

  it('nenhuma entrada do índice protegida por permissão aparece a quem não tem nenhuma', () => {
    const protegidas = INDICE_PESQUISA.filter((e) => e[3] && !['e', 'a', 'p'].includes(e[1]));
    expect(protegidas.length).toBeGreaterThan(300);
    const nada = (c: string) => c === 'qualquer_outra';
    const aparece = protegidas.filter((e) => procurarAccoes(e[2], { ...base, pode: nada, limiteAccoes: 500, limiteOutros: 500 }).some((a) => a.ecra === e[0] && a.nome === e[2]));
    expect(aparece.map((e) => `${e[0]}: ${e[2]}`)).toEqual([]);
  });

  it('um ecrã fora do menu do utilizador nunca aparece (nem as suas funções, botões ou separadores)', () => {
    const semEstrutura = MENU.filter((m) => m.id !== 'estrutura');
    const r = procurar('delegacoes multas', {}, semEstrutura);
    expect([...r.ecras, ...r.funcoes, ...r.accoes].filter((x) => (x as { ecra: string }).ecra.startsWith('est_'))).toEqual([]);
    expect(procurar('est_estrutura', {}, semEstrutura).ecras).toEqual([]);
  });

  it('os ecrãs da plataforma e o ia_chat não são pesquisáveis no ERP do cliente, mesmo com tudo permitido', () => {
    for (const termo of ['plataforma', 'clientes planos saas', 'cupoes', 'monitorizacao', 'ia_chat', 'chat flutuante']) {
      const r = procurar(termo);
      expect([...r.ecras, ...r.funcoes, ...r.accoes].filter((x) => /plataforma_|ia_chat|suporte_tickets/.test((x as { ecra: string }).ecra))).toEqual([]);
    }
  });

  it('as páginas fora de /m abrem-se sem módulo do menu (Dados pessoais, Início)', () => {
    expect(procurar('palavra-passe').accoes.map((a) => a.rota)).toContain('/dados-pessoais');
    expect(procurar('senha').accoes.map((a) => a.nome)).toContain('Alterar a palavra-passe');
  });
});

describe('pesquisa «Procurar»: desempenho com milhares de entradas', () => {
  it('cada pesquisa demora milissegundos mesmo com 5000+ entradas', () => {
    const muitas = Array.from({ length: 3 }, (_, k) => INDICE_PESQUISA.map((e) => (k === 0 ? e : ([e[0], e[1], `${e[2]} ${k}`, e[3], e[4], e[5]] as typeof e)))).flat();
    expect(muitas.length).toBeGreaterThan(5000);
    const preparado = prepararIndiceAccoes(muitas, MENU);
    const termos = ['retenção', 'férias', 'recibo', 'irt', 'delegar', 'multa', 'utilizadores', 'licença', 'sessões', 'exportar', 'novo', 'a', 'impor'];
    const t0 = performance.now();
    for (const t of termos) pesquisarSistema(MENU, t, { ...base, accoes: preparado });
    const media = (performance.now() - t0) / termos.length;
    expect(media).toBeLessThan(150);
  });
});
