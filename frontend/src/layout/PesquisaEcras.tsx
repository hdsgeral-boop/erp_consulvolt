import { CloseOutlined, HolderOutlined, SearchOutlined, StarFilled } from '@ant-design/icons';
import { AutoComplete, Button, Input, Tooltip, type InputRef } from 'antd';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router';
import type { ModuloMenu } from '@/api/tipos';
import { useFavoritos } from '@/componentes/preferencias/favoritos';
import { iconeModulo } from '@/componentes/icones/iconesModulos';
import { useSessao } from '@/sessao/SessaoContexto';
import type { EntradaIndicePesquisa, TipoEntradaPesquisa } from './indicePesquisa.gerado';
import { nomeModulo, rotuloEcra } from './menuLegado';
import { temPosicaoFlutuante, usePosicaoFlutuante } from './posicaoFlutuante';

const PESQUISA_LARGURA = 460;
const PESQUISA_ALTURA = 48;

/**
 * Pesquisa da barra superior «Procurar» (Ctrl+K / ⌘K; legado ui_dashboard.js:950-1008, «Ir para»): campo no próprio
 * cabeçalho com lista suspensa que encontra
 *  - os ECRÃS do menu do utilizador (GET /sistema/menu — já filtrado pelas permissões);
 *  - as FUNÇÕES dentro dos ecrãs (tarefas do catálogo de permissões que o servidor envia no menu, já filtradas pelas
 *    permissões do utilizador) — confirmadas também com useSessao().pode — cada uma mostra «Módulo › Ecrã» e abre o ecrã;
 *  - os BOTÕES/acções e SEPARADORES de cada ecrã (índice gerado a partir do código por
 *    ferramentas/pesquisa/gerar_indice_pesquisa.mjs → indicePesquisa.gerado.ts, carregado só quando se usa a pesquisa):
 *    «Botão/Separador › Ecrã › Módulo», só dos ecrãs do menu do utilizador e, quando a permissão que protege o botão é
 *    conhecida, só se pode(); ao escolher abre o ecrã e, num separador, `rota?sep=<chave>` (o ecrã lê-o com useSeparadorUrl).
 * Sem acentos, por várias palavras (todas têm de aparecer), favoritos primeiro, ↑/↓/Enter/Esc, resultados agrupados
 * (Ecrãs / Funções / Botões e separadores) e limitados. Nunca mostra o que o utilizador não pode abrir.
 */
/** Função (tarefa do catálogo) ligada ao ecrã onde se faz. */
export interface FuncaoSistema {
  modulo: string;
  ecra: string;
  /** chave da permissão (useSessao().pode) */
  chave: string;
  rotulo: string;
}

/** Índice das funções a partir do menu do servidor (cada ecrã traz as tarefas que o utilizador pode fazer). */
export function funcoesDoMenu(menu: ModuloMenu[]): FuncaoSistema[] {
  return menu.flatMap((m) => m.ecras.flatMap((e) => (e.tarefas ?? []).map((t) => ({ modulo: m.id, ecra: e.id, chave: t.chave, rotulo: t.rotulo }))));
}

export interface ResultadoEcra {
  modulo: string;
  ecra: string;
  nome: string;
  nomeModulo: string;
  rota: string;
  favorito: boolean;
}

export interface ResultadoFuncao {
  chave: string;
  /** nome da função (rótulo da tarefa no catálogo) */
  nome: string;
  modulo: string;
  ecra: string;
  nomeModulo: string;
  nomeEcra: string;
  rota: string;
  /** o ecrã da função é favorito */
  favorito: boolean;
}

/** Botão/acção ou separador de um ecrã (índice gerado a partir do código). */
export type TipoAccao = 'botao' | 'janela' | 'separador' | 'config' | 'relatorio' | 'ajuda' | 'pagina';

/** Grupo (secção da lista) onde cada tipo de entrada do índice aparece. */
export type GrupoAccao = 'accoes' | 'config' | 'relatorios' | 'ajuda';
export const GRUPO_DO_TIPO: Readonly<Record<TipoAccao, GrupoAccao>> = { botao: 'accoes', janela: 'accoes', separador: 'accoes', config: 'config', relatorio: 'relatorios', ajuda: 'ajuda', pagina: 'ajuda' };
const TIPO_DA_LETRA: Readonly<Partial<Record<TipoEntradaPesquisa, TipoAccao>>> = { b: 'botao', d: 'janela', s: 'separador', c: 'config', r: 'relatorio', a: 'ajuda', p: 'pagina' };

export interface ResultadoAccao {
  tipo: TipoAccao;
  nome: string;
  modulo: string;
  ecra: string;
  nomeModulo: string;
  nomeEcra: string;
  /** rota do ecrã (com ?sep=<chave> quando o ecrã abre o separador pelo endereço — useSeparadorUrl) */
  rota: string;
  favorito: boolean;
}

export interface ResultadosPesquisa {
  ecras: ResultadoEcra[];
  funcoes: ResultadoFuncao[];
  accoes: ResultadoAccao[];
}

const normalizar = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const palavrasDe = (termo: string) => normalizar(termo).split(/\s+/).filter(Boolean);

/** Grupos de palavras equivalentes (indice.config.json -> SINONIMOS_PESQUISA). */
export type Sinonimos = readonly (readonly string[])[];
/** Palavra escrita -> ela própria e os membros dos grupos que a contêm (por igualdade ou, com 3+ letras, por prefixo). */
export function variantesDe(palavra: string, sinonimos?: Sinonimos): string[] {
  const r = new Set([palavra]);
  if (sinonimos)
    for (const g of sinonimos) {
      const ng = g.map(normalizar);
      // membros curtos (irt, nif, inss…) só valem no início de uma palavra do texto (marca INICIO): evita «irt» em «virtual»
      if (ng.some((m) => m === palavra || (palavra.length >= 3 && m.startsWith(palavra)))) ng.forEach((m) => r.add(m.length <= 4 && m !== palavra ? `${INICIO}${m}` : m));
    }
  return [...r];
}
/** Uma lista de variantes por palavra do termo; o texto tem de conter, de cada lista, pelo menos uma. */
const LIGACOES = new Set(['e', 'a', 'o', 'as', 'os', 'de', 'da', 'do', 'das', 'dos', 'na', 'no', 'nas', 'nos', 'em', 'para', 'por', 'com']);
/** «conta e subscrição»: as palavras de ligação não restringem a procura (a menos que sejam a única palavra). */
const gruposDe = (palavras: string[], sinonimos?: Sinonimos): string[][] => {
  const uteis = palavras.filter((p) => !LIGACOES.has(p));
  return (uteis.length ? uteis : palavras).map((p) => variantesDe(p, sinonimos));
};
const INICIO = '\u0001';
const regexInicio = new Map<string, RegExp>();
const contem = (texto: string, v: string): boolean => {
  if (!v.startsWith(INICIO)) return texto.includes(v);
  let re = regexInicio.get(v);
  if (!re) regexInicio.set(v, (re = new RegExp(`(^|[^a-z0-9])${v.slice(1).replace(/[^a-z0-9 ]/g, '\\$&')}`)));
  return re.test(texto);
};
const casa = (texto: string, grupos: string[][]) => grupos.every((g) => g.some((v) => contem(texto, v)));
const casaAlguma = (texto: string, grupos: string[][]) => grupos.some((g) => g.some((v) => contem(texto, v)));

/** Ecrãs do menu que correspondem a todas as palavras do termo (favoritos primeiro; sem termo, só os favoritos e o resto do menu). */
export function procurarEcras(menu: ModuloMenu[], termo: string, eFavorito: (m: string, e: string) => boolean, limite = 30, extra?: { sinonimos?: Sinonimos; palavras?: Readonly<Record<string, string>> }): ResultadoEcra[] {
  const palavras = palavrasDe(termo);
  const grupos = gruposDe(palavras, extra?.sinonimos);
  // rótulos do menu do legado (menuLegado.ts); a pesquisa também encontra pelo nome do catálogo e do módulo no catálogo
  const todos = menu.flatMap((m) =>
    m.ecras.map((e) => ({
      modulo: m.id, ecra: e.id, nome: rotuloEcra(m.id, e.id, e.nome), nomeModulo: nomeModulo(m.id, m.nome), rota: `/m/${m.id}/${e.id}`, favorito: eFavorito(m.id, e.id),
      procura: normalizar(`${rotuloEcra(m.id, e.id, e.nome)} ${e.nome} ${nomeModulo(m.id, m.nome)} ${m.nome} ${e.id} ${extra?.palavras?.[e.id] ?? ''}`),
    })),
  );
  const filtrados = palavras.length ? todos.filter((r) => casa(r.procura, grupos)) : todos;
  const noNome = (r: { nome: string }) => (palavras.length ? Number(casa(normalizar(r.nome), grupos)) : 0);
  return filtrados.sort((a, b) => Number(b.favorito) - Number(a.favorito) || noNome(b) - noNome(a) || (palavras.length ? Number(normalizar(b.nome).startsWith(palavras[0])) - Number(normalizar(a.nome).startsWith(palavras[0])) : 0)).slice(0, limite).map(({ procura: _p, ...r }) => r);
}

export interface OpcoesPesquisa {
  eFavorito: (modulo: string, ecra: string) => boolean;
  /** permissão do utilizador (useSessao().pode) */
  pode: (chave: string) => boolean;
  /** índice das funções (por omissão, as tarefas que vêm no menu) */
  funcoes?: readonly FuncaoSistema[];
  /** botões e separadores dos ecrãs do menu (prepararIndiceAccoes); sem ele não há este grupo */
  accoes?: readonly AccaoIndexada[];
  /** sinónimos (SINONIMOS_PESQUISA): escrever «irt» encontra também «retenção na fonte» */
  sinonimos?: Sinonimos;
  /** palavras-chave extra por ecrã e por tarefa (PALAVRAS_PESQUISA) */
  palavras?: Readonly<Record<string, string>>;
  limiteEcras?: number;
  limiteFuncoes?: number;
  /** no grupo dos botões e separadores (12 por omissão) */
  limiteAccoes?: number;
  /** em cada um dos outros grupos: configurações, relatórios, ajuda e páginas (6 por omissão) */
  limiteOutros?: number;
}

/** Entrada do índice já ligada ao menu do utilizador e normalizada (preparada uma vez por menu). */
export interface AccaoIndexada {
  tipo: TipoAccao;
  nome: string;
  modulo: string;
  ecra: string;
  nomeModulo: string;
  nomeEcra: string;
  rota: string;
  /** grupos de permissões: todos têm de se verificar; dentro de cada grupo basta uma */
  permissoes: string[][];
  /** rótulo sem acentos */
  nomeNormal: string;
  /** rótulo e palavras-chave extra (resumo da ajuda, sinónimos da página), sem acentos */
  chaves: string;
  /** rótulo, ecrã e módulo sem acentos */
  procura: string;
}

/**
 * Consulta que abre o separador: a chave gravada no índice dá `sep=<chave>` (useSeparadorUrl); entradas antigas que já
 * trazem a consulta completa (`separador=<chave>`) ficam como estão.
 */
export function consultaSeparador(separador: string | undefined): string {
  if (!separador) return '';
  return separador.includes('=') ? separador : `sep=${encodeURIComponent(separador)}`;
}

/**
 * Liga o índice gerado ao menu do utilizador: só ficam os ecrãs que estão no menu (GET /sistema/menu, já filtrado pelas
 * permissões); o texto de procura fica normalizado em memória para a pesquisa ser rápida a cada tecla.
 */
export function prepararIndiceAccoes(indice: readonly EntradaIndicePesquisa[], menu: ModuloMenu[]): AccaoIndexada[] {
  const ecras = new Map<string, { modulo: string; nomeModulo: string; nomeEcra: string; nomeCatalogo: string; procura: string }>();
  for (const m of menu)
    for (const e of m.ecras) {
      const nm = nomeModulo(m.id, m.nome);
      const ne = rotuloEcra(m.id, e.id, e.nome);
      ecras.set(e.id, { modulo: m.id, nomeModulo: nm, nomeEcra: ne, nomeCatalogo: e.nome, procura: normalizar(`${ne} ${e.nome} ${nm} ${m.nome}`) });
    }
  const r: AccaoIndexada[] = [];
  for (const [ecraId, letra, rotulo, permissoes, separador, palavras] of indice) {
    const tipo = TIPO_DA_LETRA[letra];
    if (!tipo) continue;   // 'e' (ecrã) não é um resultado: os ecrãs vêm do menu
    const nomeNormal = normalizar(rotulo);
    const chaves = palavras ? `${nomeNormal} ${normalizar(palavras)}` : nomeNormal;
    const consulta = consultaSeparador(separador);
    const perms = permissoes ? permissoes.split(';').map((g) => g.split('|').filter(Boolean)).filter((g) => g.length) : [];
    if (letra === 'p') {
      // página fora de /m/…: basta ter sessão (a própria página decide o resto)
      r.push({ tipo, nome: rotulo, modulo: 'geral', ecra: ecraId, nomeModulo: 'Sistema', nomeEcra: rotulo, rota: `${ecraId}${consulta ? `?${consulta}` : ''}`, permissoes: perms, nomeNormal, chaves, procura: chaves });
      continue;
    }
    const e = ecras.get(ecraId);
    if (!e) continue;
    // a ajuda com o mesmo título do ecrã seria um duplicado do próprio ecrã
    if (letra === 'a' && (nomeNormal === normalizar(e.nomeEcra) || nomeNormal === normalizar(e.nomeCatalogo)) && !palavras) continue;
    r.push({
      tipo,
      nome: rotulo,
      modulo: e.modulo,
      ecra: ecraId,
      nomeModulo: e.nomeModulo,
      nomeEcra: e.nomeEcra,
      rota: `/m/${e.modulo}/${ecraId}${consulta ? `?${consulta}` : ''}`,
      permissoes: perms,
      nomeNormal,
      chaves,
      procura: `${chaves} ${e.procura}`,
    });
  }
  return r;
}

/**
 * Botões e separadores que correspondem a todas as palavras do termo, com pelo menos uma no próprio rótulo (o resto pode
 * estar no ecrã ou no módulo, ex.: «contabilizar vendas»). Só os que o utilizador pode usar quando a permissão é conhecida.
 * Ordem: ecrã favorito, todas as palavras no rótulo, rótulo a começar pela 1.ª palavra, separadores antes de botões.
 */
export function procurarAccoes(termo: string, o: OpcoesPesquisa): ResultadoAccao[] {
  const palavras = palavrasDe(termo);
  if (!palavras.length || !o.accoes?.length) return [];
  const grupos = gruposDe(palavras, o.sinonimos);
  const achados: (ResultadoAccao & { peso: number; ordem: number })[] = [];
  o.accoes.forEach((a, ordem) => {
    if (!casa(a.procura, grupos) || !casaAlguma(a.chaves, grupos)) return;
    if (!a.permissoes.every((g) => g.some((c) => o.pode(c)))) return;
    const favorito = o.eFavorito(a.modulo, a.ecra);
    const peso = (favorito ? 8 : 0) + (casa(a.nomeNormal, grupos) ? 4 : 0) + (a.nomeNormal.startsWith(palavras[0]) ? 2 : 0) + (a.tipo === 'separador' ? 1 : 0) - (a.tipo === 'ajuda' || a.tipo === 'pagina' ? 1 : 0);
    achados.push({ tipo: a.tipo, nome: a.nome, modulo: a.modulo, ecra: a.ecra, nomeModulo: a.nomeModulo, nomeEcra: a.nomeEcra, rota: a.rota, favorito, peso, ordem });
  });
  // limite por grupo (um grupo cheio não esconde os outros); dentro do grupo, por peso e depois pela ordem do índice
  const usados: Record<GrupoAccao, number> = { accoes: 0, config: 0, relatorios: 0, ajuda: 0 };
  return achados
    .sort((a, b) => b.peso - a.peso || a.ordem - b.ordem)
    .filter((a) => {
      const g = GRUPO_DO_TIPO[a.tipo];
      return ++usados[g] <= (g === 'accoes' ? (o.limiteAccoes ?? 12) : (o.limiteOutros ?? 6));
    })
    .map(({ peso: _p, ordem: _o, ...r }) => r);
}

/**
 * Funções (tarefas do catálogo) que correspondem a todas as palavras do termo. Só entram as que o utilizador pode fazer
 * E cujo ecrã está no menu dele. A palavra pode estar no nome da função, no ecrã ou no módulo; ordem: ecrã favorito,
 * todas as palavras no nome da função, nome a começar pela 1.ª palavra, ordem do catálogo.
 */
export function procurarFuncoes(menu: ModuloMenu[], termo: string, o: OpcoesPesquisa): ResultadoFuncao[] {
  const palavras = palavrasDe(termo);
  if (!palavras.length) return [];
  const grupos = gruposDe(palavras, o.sinonimos);
  const ecras = new Map<string, { nomeModulo: string; nomeEcra: string; procura: string }>();
  for (const m of menu)
    for (const e of m.ecras) {
      const nm = nomeModulo(m.id, m.nome);
      const ne = rotuloEcra(m.id, e.id, e.nome);
      ecras.set(`${m.id}/${e.id}`, { nomeModulo: nm, nomeEcra: ne, procura: normalizar(`${ne} ${e.nome} ${nm} ${m.nome}`) });
    }
  const achados: (ResultadoFuncao & { peso: number; ordem: number })[] = [];
  (o.funcoes ?? funcoesDoMenu(menu)).forEach((f, ordem) => {
    const ecra = ecras.get(`${f.modulo}/${f.ecra}`);
    if (!ecra || !o.pode(f.chave)) return;
    const nome = normalizar(f.rotulo);
    const texto = `${nome} ${normalizar(f.chave)} ${normalizar(o.palavras?.[f.chave] ?? '')} ${ecra.procura}`;
    if (!casa(texto, grupos)) return;
    const favorito = o.eFavorito(f.modulo, f.ecra);
    const peso = (favorito ? 4 : 0) + (casa(nome, grupos) ? 2 : 0) + (nome.startsWith(palavras[0]) ? 1 : 0);
    achados.push({ chave: f.chave, nome: f.rotulo, modulo: f.modulo, ecra: f.ecra, nomeModulo: ecra.nomeModulo, nomeEcra: ecra.nomeEcra, rota: `/m/${f.modulo}/${f.ecra}`, favorito, peso, ordem });
  });
  return achados
    .sort((a, b) => b.peso - a.peso || a.ordem - b.ordem)
    .slice(0, o.limiteFuncoes ?? 10)
    .map(({ peso: _p, ordem: _o, ...r }) => r);
}

/** Pesquisa completa da barra superior: ecrãs e funções. Sem termo, só os ecrãs favoritos (atalhos). */
export function pesquisarSistema(menu: ModuloMenu[], termo: string, o: OpcoesPesquisa): ResultadosPesquisa {
  const limite = o.limiteEcras ?? 8;
  const ecras = palavrasDe(termo).length
    ? procurarEcras(menu, termo, o.eFavorito, limite, { sinonimos: o.sinonimos, palavras: o.palavras })
    : procurarEcras(menu, '', o.eFavorito, Number.MAX_SAFE_INTEGER).filter((r) => r.favorito).slice(0, limite);
  return { ecras, funcoes: procurarFuncoes(menu, termo, o), accoes: procurarAccoes(termo, o) };
}

/** Pedaços do texto que correspondem às palavras do termo (sem acentos), juntos e por ordem — para o realce. */
export function trechosRealce(texto: string, termo: string): { inicio: number; fim: number }[] {
  const palavras = palavrasDe(termo);
  if (!palavras.length) return [];
  let normal = '';
  const origem: number[] = [];
  for (let i = 0; i < texto.length; i++)
    for (const c of normalizar(texto[i])) {
      normal += c;
      origem.push(i);
    }
  const trechos: { inicio: number; fim: number }[] = [];
  for (const p of palavras)
    for (let k = normal.indexOf(p); k >= 0; k = normal.indexOf(p, k + p.length)) trechos.push({ inicio: origem[k], fim: origem[k + p.length - 1] + 1 });
  trechos.sort((a, b) => a.inicio - b.inicio);
  const juntos: { inicio: number; fim: number }[] = [];
  for (const t of trechos) {
    const u = juntos[juntos.length - 1];
    if (u && t.inicio <= u.fim) u.fim = Math.max(u.fim, t.fim);
    else juntos.push({ ...t });
  }
  return juntos;
}

function realcar(texto: string, termo: string): ReactNode {
  const trechos = trechosRealce(texto, termo);
  if (!trechos.length) return texto;
  const partes: ReactNode[] = [];
  let i = 0;
  trechos.forEach((t, n) => {
    if (t.inicio > i) partes.push(texto.slice(i, t.inicio));
    partes.push(<mark key={n}>{texto.slice(t.inicio, t.fim)}</mark>);
    i = t.fim;
  });
  if (i < texto.length) partes.push(texto.slice(i));
  return partes;
}

function Opcao({ modulo, nome, caminho, favorito, termo }: { modulo: string; nome: string; caminho: string; favorito: boolean; termo: string }) {
  return (
    <div className="erp-pesquisa-opcao" title={`${nome} — ${caminho}`}>
      <span className="erp-pesquisa-icone" aria-hidden>
        {iconeModulo(modulo)}
      </span>
      <span className="erp-pesquisa-textos">
        <span className="erp-pesquisa-nome">{realcar(nome, termo)}</span>
        <span className="erp-pesquisa-caminho">{caminho}</span>
      </span>
      {favorito && <StarFilled className="erp-pesquisa-favorito" aria-label="Favorito" />}
    </div>
  );
}

const eMac = () => typeof navigator !== 'undefined' && /mac|iphone|ipad/i.test(navigator.platform || navigator.userAgent || '');
const PREFIXOS = ['ecra:', 'funcao:', 'accao:'];
const ROTULO_TIPO: Readonly<Record<TipoAccao, string>> = { botao: 'Botão', janela: 'Janela', separador: 'Separador', config: 'Configuração', relatorio: 'Relatório', ajuda: 'Ajuda', pagina: 'Página' };
const TITULO_GRUPO: Readonly<Record<GrupoAccao, string>> = { accoes: 'Botões e separadores', config: 'Configurações', relatorios: 'Relatórios e exportações', ajuda: 'Ajuda e outras páginas' };
const GRUPOS_ORDEM: readonly GrupoAccao[] = ['accoes', 'config', 'relatorios', 'ajuda'];
/** «Botão › abre em Ecrã › Módulo»: as acções que só existem numa janela ou botão abrem o ecrã onde se executam. */
function caminhoAccao(r: ResultadoAccao): string {
  if (r.tipo === 'pagina') return `${ROTULO_TIPO.pagina} › ${r.nomeModulo}`;
  const abre = r.tipo === 'separador' || r.tipo === 'ajuda' ? '' : 'abre em ';
  return `${ROTULO_TIPO[r.tipo]} › ${abre}${r.nomeEcra} › ${r.nomeModulo}`;
}

/**
 * Campo «Procurar» da barra superior. `compacto` (ecrãs estreitos): só o ícone, que abre o mesmo campo por cima da barra.
 * O atalho Ctrl+K / ⌘K foca o campo (registado aqui).
 */
export function PesquisaCabecalho({ compacto = false, idUtilizador }: { compacto?: boolean; idUtilizador?: number | string | null }) {
  const { menu, pode } = useSessao();
  const { eFavorito } = useFavoritos();
  const navegar = useNavigate();
  const [termo, setTermo] = useState('');
  const [aberta, setAberta] = useState(false);
  const entrada = useRef<InputRef>(null);
  const visivel = !compacto || aberta;
  const atalho = eMac() ? '⌘K' : 'Ctrl+K';

  // flutuante e arrastável (pega à esquerda do campo): nasce ancorada no cabeçalho; só flutua depois de arrastada
  // uma vez (ou se já estava flutuante numa sessão anterior — posição guardada por utilizador).
  const padraoPosicao = useMemo(() => ({ x: Math.max(16, (typeof window !== 'undefined' ? window.innerWidth : 1200) - PESQUISA_LARGURA - 24), y: 64 }), []);
  const { posicao, repor: reporPosicao, pega } = usePosicaoFlutuante('pesquisa', padraoPosicao, { largura: PESQUISA_LARGURA, altura: PESQUISA_ALTURA }, idUtilizador);
  const [flutuante, setFlutuante] = useState(() => !compacto && temPosicaoFlutuante('pesquisa', idUtilizador));

  // índice dos botões e separadores (≈ 90 kB): só se carrega quando o utilizador usa a pesquisa
  const [indice, setIndice] = useState<readonly EntradaIndicePesquisa[] | null>(null);
  const [vocabulario, setVocabulario] = useState<{ sinonimos: Sinonimos; palavras: Readonly<Record<string, string>> } | undefined>(undefined);
  const aCarregarIndice = useRef(false);
  const carregarIndice = useCallback(() => {
    if (aCarregarIndice.current) return;
    aCarregarIndice.current = true;
    import('./indicePesquisa.gerado')
      .then((m) => {
        setVocabulario({ sinonimos: m.SINONIMOS_PESQUISA, palavras: m.PALAVRAS_PESQUISA });
        setIndice(m.INDICE_PESQUISA);
      })
      .catch(() => {
        aCarregarIndice.current = false;   // tenta de novo na próxima vez (sem o índice ficam os ecrãs e as funções)
      });
  }, []);
  useEffect(() => {
    if (termo.trim()) carregarIndice();
  }, [termo, carregarIndice]);
  const accoes = useMemo(() => (indice ? prepararIndiceAccoes(indice, menu) : undefined), [indice, menu]);

  const resultados = useMemo(() => pesquisarSistema(menu, termo, { eFavorito, pode: (c) => !!pode?.(c), accoes, sinonimos: vocabulario?.sinonimos, palavras: vocabulario?.palavras }), [menu, termo, eFavorito, pode, accoes, vocabulario]);

  const focar = useCallback(() => {
    if (compacto) setAberta(true);
    // depois de o campo aparecer (modo compacto)
    window.setTimeout(() => entrada.current?.focus(), 0);
  }, [compacto]);

  useEffect(() => {
    if (!compacto) setAberta(false);
  }, [compacto]);

  // Ctrl+K / ⌘K
  useEffect(() => {
    const tecla = (ev: KeyboardEvent) => {
      if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && !ev.shiftKey && ev.key.toLowerCase() === 'k') {
        ev.preventDefault();
        focar();
      }
    };
    window.addEventListener('keydown', tecla);
    return () => window.removeEventListener('keydown', tecla);
  }, [focar]);

  const { opcoes, rotas } = useMemo(() => {
    const rotas = new Map<string, string>();
    const opcoes: { label: ReactNode; options: { value: string; label: ReactNode }[] }[] = [];
    if (resultados.ecras.length)
      opcoes.push({
        label: <span className="erp-pesquisa-grupo">{termo.trim() ? 'Ecrãs' : 'Favoritos'}</span>,
        options: resultados.ecras.map((r) => {
          const valor = `ecra:${r.rota}`;
          rotas.set(valor, r.rota);
          return { value: valor, label: <Opcao modulo={r.modulo} nome={r.nome} caminho={r.nomeModulo} favorito={r.favorito} termo={termo} /> };
        }),
      });
    if (resultados.funcoes.length)
      opcoes.push({
        label: <span className="erp-pesquisa-grupo">Funções</span>,
        options: resultados.funcoes.map((r) => {
          const valor = `funcao:${r.modulo}/${r.ecra}/${r.chave}`;
          rotas.set(valor, r.rota);
          return { value: valor, label: <Opcao modulo={r.modulo} nome={r.nome} caminho={`${r.nomeModulo} › ${r.nomeEcra}`} favorito={r.favorito} termo={termo} /> };
        }),
      });
    for (const grupo of GRUPOS_ORDEM) {
      const doGrupo = resultados.accoes.filter((r) => GRUPO_DO_TIPO[r.tipo] === grupo);
      if (!doGrupo.length) continue;
      opcoes.push({
        label: <span className="erp-pesquisa-grupo">{TITULO_GRUPO[grupo]}</span>,
        options: doGrupo.map((r) => {
          const valor = `accao:${r.tipo}:${r.rota}:${r.nome}`;
          rotas.set(valor, r.rota);
          return { value: valor, label: <Opcao modulo={r.modulo} nome={r.nome} caminho={caminhoAccao(r)} favorito={r.favorito} termo={termo} /> };
        }),
      });
    }
    return { opcoes, rotas };
  }, [resultados, termo]);

  const fechar = () => {
    setTermo('');
    entrada.current?.blur();
    setAberta(false);
  };

  if (!visivel)
    return <Button className="erp-botao-pesquisa" icon={<SearchOutlined />} onClick={focar} aria-label="Abrir a pesquisa" title={`Procurar funções e ecrãs (${atalho})`} />;

  return (
    <div
      className={`erp-pesquisa-cabecalho${compacto ? ' flutuante' : ''}${flutuante ? ' erp-pesquisa-flutuante' : ''}`}
      role="search"
      style={flutuante ? { position: 'fixed', left: posicao.x, top: posicao.y, width: PESQUISA_LARGURA, zIndex: 1050 } : undefined}
    >
      {!compacto && (
        <Tooltip title="Arraste para mover (duplo clique repõe)">
          <span
            className={`erp-pesquisa-pega${pega.aArrastar ? ' a-arrastar' : ''}`}
            role="button"
            tabIndex={0}
            aria-label="Mover a pesquisa"
            onPointerDown={(e) => {
              if (!flutuante) setFlutuante(true);
              pega.onPointerDown(e);
            }}
            onDoubleClick={() => {
              reporPosicao();
              setFlutuante(false);
            }}
          >
            <HolderOutlined aria-hidden />
          </span>
        </Tooltip>
      )}
      <span className="sr-only" role="status" aria-live="polite" style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clip: 'rect(0 0 0 0)', whiteSpace: 'nowrap' }}>
        {termo.trim() ? `${resultados.ecras.length + resultados.funcoes.length + resultados.accoes.length} resultados` : ''}
      </span>
      <AutoComplete
        value={termo}
        options={opcoes}
        // ao escolher uma opção o AutoComplete passa o valor dela: o texto escrito mantém-se até navegar
        onChange={(v) => {
          if (typeof v === 'string' && !PREFIXOS.some((p) => v.startsWith(p))) setTermo(v);
        }}
        onSelect={(v: string) => {
          const rota = rotas.get(v);
          fechar();
          if (rota) navegar(rota);
        }}
        defaultActiveFirstOption
        virtual={false}
        popupMatchSelectWidth={compacto ? true : 460}
        classNames={{ popup: { root: 'erp-pesquisa-popup' } }}
        notFoundContent={termo.trim() ? <span className="erp-pesquisa-vazio">Nada encontrado. Experimente outra palavra.</span> : null}
        style={{ width: '100%' }}
      >
        <Input
          ref={entrada}
          prefix={<SearchOutlined aria-hidden className="erp-pesquisa-lupa" />}
          suffix={!termo && !compacto ? <kbd className="erp-pesquisa-atalho">{atalho}</kbd> : <span />}
          placeholder="Procurar ecrãs, funções, relatórios e botões…"
          aria-label="Procurar no sistema"
          title={`Procurar ecrãs, funções, botões e separadores a que tem acesso (${atalho})`}
          maxLength={80}
          allowClear
          onFocus={carregarIndice}
          onKeyDown={(e) => {
            if (e.key === 'Escape') fechar();
          }}
          onBlur={() => {
            if (compacto && !termo) setAberta(false);
          }}
        />
      </AutoComplete>
      {compacto && <Button type="text" className="erp-pesquisa-fechar" icon={<CloseOutlined />} aria-label="Fechar a pesquisa" onClick={fechar} />}
    </div>
  );
}
