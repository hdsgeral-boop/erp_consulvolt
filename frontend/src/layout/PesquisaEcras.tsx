import { CloseOutlined, SearchOutlined, StarFilled } from '@ant-design/icons';
import { AutoComplete, Button, Input, type InputRef } from 'antd';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ModuloMenu } from '@/api/tipos';
import { useFavoritos } from '@/componentes/preferencias/favoritos';
import { iconeModulo } from '@/componentes/icones/iconesModulos';
import { useSessao } from '@/sessao/SessaoContexto';
import type { EntradaIndicePesquisa } from './indicePesquisa.gerado';
import { nomeModulo, rotuloEcra } from './menuLegado';

/**
 * Pesquisa da barra superior «Procurar» (Ctrl+K / ⌘K; legado ui_dashboard.js:950-1008, «Ir para»): campo no próprio
 * cabeçalho com lista suspensa que encontra
 *  - os ECRÃS do menu do utilizador (GET /sistema/menu — já filtrado pelas permissões);
 *  - as FUNÇÕES dentro dos ecrãs (tarefas do catálogo de permissões que o servidor envia no menu, já filtradas pelas
 *    permissões do utilizador) — confirmadas também com useSessao().pode — cada uma mostra «Módulo › Ecrã» e abre o ecrã;
 *  - os BOTÕES/acções e SEPARADORES de cada ecrã (índice gerado a partir do código por
 *    ferramentas/pesquisa/gerar_indice_pesquisa.mjs → indicePesquisa.gerado.ts, carregado só quando se usa a pesquisa):
 *    «Botão/Separador › Ecrã › Módulo», só dos ecrãs do menu do utilizador e, quando a permissão que protege o botão é
 *    conhecida, só se pode(); ao escolher abre o ecrã (e o separador, quando o ecrã o lê do endereço).
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
export interface ResultadoAccao {
  tipo: 'botao' | 'separador';
  nome: string;
  modulo: string;
  ecra: string;
  nomeModulo: string;
  nomeEcra: string;
  /** rota do ecrã (com ?separador=/?sep= quando o ecrã abre o separador pelo endereço) */
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

/** Ecrãs do menu que correspondem a todas as palavras do termo (favoritos primeiro; sem termo, só os favoritos e o resto do menu). */
export function procurarEcras(menu: ModuloMenu[], termo: string, eFavorito: (m: string, e: string) => boolean, limite = 30): ResultadoEcra[] {
  const palavras = palavrasDe(termo);
  // rótulos do menu do legado (menuLegado.ts); a pesquisa também encontra pelo nome do catálogo e do módulo no catálogo
  const todos = menu.flatMap((m) =>
    m.ecras.map((e) => ({
      modulo: m.id, ecra: e.id, nome: rotuloEcra(m.id, e.id, e.nome), nomeModulo: nomeModulo(m.id, m.nome), rota: `/m/${m.id}/${e.id}`, favorito: eFavorito(m.id, e.id),
      procura: normalizar(`${rotuloEcra(m.id, e.id, e.nome)} ${e.nome} ${nomeModulo(m.id, m.nome)} ${m.nome} ${e.id}`),
    })),
  );
  const filtrados = palavras.length ? todos.filter((r) => palavras.every((p) => r.procura.includes(p))) : todos;
  return filtrados.sort((a, b) => Number(b.favorito) - Number(a.favorito) || (palavras.length ? Number(normalizar(b.nome).startsWith(palavras[0])) - Number(normalizar(a.nome).startsWith(palavras[0])) : 0)).slice(0, limite).map(({ procura: _p, ...r }) => r);
}

export interface OpcoesPesquisa {
  eFavorito: (modulo: string, ecra: string) => boolean;
  /** permissão do utilizador (useSessao().pode) */
  pode: (chave: string) => boolean;
  /** índice das funções (por omissão, as tarefas que vêm no menu) */
  funcoes?: readonly FuncaoSistema[];
  /** botões e separadores dos ecrãs do menu (prepararIndiceAccoes); sem ele não há este grupo */
  accoes?: readonly AccaoIndexada[];
  limiteEcras?: number;
  limiteFuncoes?: number;
  limiteAccoes?: number;
}

/** Entrada do índice já ligada ao menu do utilizador e normalizada (preparada uma vez por menu). */
export interface AccaoIndexada {
  tipo: 'botao' | 'separador';
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
  /** rótulo, ecrã e módulo sem acentos */
  procura: string;
}

/**
 * Liga o índice gerado ao menu do utilizador: só ficam os ecrãs que estão no menu (GET /sistema/menu, já filtrado pelas
 * permissões); o texto de procura fica normalizado em memória para a pesquisa ser rápida a cada tecla.
 */
export function prepararIndiceAccoes(indice: readonly EntradaIndicePesquisa[], menu: ModuloMenu[]): AccaoIndexada[] {
  const ecras = new Map<string, { modulo: string; nomeModulo: string; nomeEcra: string; procura: string }>();
  for (const m of menu)
    for (const e of m.ecras) {
      const nm = nomeModulo(m.id, m.nome);
      const ne = rotuloEcra(m.id, e.id, e.nome);
      ecras.set(e.id, { modulo: m.id, nomeModulo: nm, nomeEcra: ne, procura: normalizar(`${ne} ${e.nome} ${nm} ${m.nome}`) });
    }
  const r: AccaoIndexada[] = [];
  for (const [ecraId, tipo, rotulo, permissoes, consulta] of indice) {
    const e = ecras.get(ecraId);
    if (!e) continue;
    const nomeNormal = normalizar(rotulo);
    r.push({
      tipo: tipo === 's' ? 'separador' : 'botao',
      nome: rotulo,
      modulo: e.modulo,
      ecra: ecraId,
      nomeModulo: e.nomeModulo,
      nomeEcra: e.nomeEcra,
      rota: `/m/${e.modulo}/${ecraId}${consulta ? `?${consulta}` : ''}`,
      permissoes: permissoes ? permissoes.split(';').map((g) => g.split('|').filter(Boolean)).filter((g) => g.length) : [],
      nomeNormal,
      procura: `${nomeNormal} ${e.procura}`,
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
  const achados: (ResultadoAccao & { peso: number; ordem: number })[] = [];
  o.accoes.forEach((a, ordem) => {
    if (!palavras.every((p) => a.procura.includes(p)) || !palavras.some((p) => a.nomeNormal.includes(p))) return;
    if (!a.permissoes.every((g) => g.some((c) => o.pode(c)))) return;
    const favorito = o.eFavorito(a.modulo, a.ecra);
    const peso = (favorito ? 8 : 0) + (palavras.every((p) => a.nomeNormal.includes(p)) ? 4 : 0) + (a.nomeNormal.startsWith(palavras[0]) ? 2 : 0) + (a.tipo === 'separador' ? 1 : 0);
    achados.push({ tipo: a.tipo, nome: a.nome, modulo: a.modulo, ecra: a.ecra, nomeModulo: a.nomeModulo, nomeEcra: a.nomeEcra, rota: a.rota, favorito, peso, ordem });
  });
  return achados
    .sort((a, b) => b.peso - a.peso || a.ordem - b.ordem)
    .slice(0, o.limiteAccoes ?? 12)
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
    const texto = `${nome} ${normalizar(f.chave)} ${ecra.procura}`;
    if (!palavras.every((p) => texto.includes(p))) return;
    const favorito = o.eFavorito(f.modulo, f.ecra);
    const peso = (favorito ? 4 : 0) + (palavras.every((p) => nome.includes(p)) ? 2 : 0) + (nome.startsWith(palavras[0]) ? 1 : 0);
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
    ? procurarEcras(menu, termo, o.eFavorito, limite)
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
const ROTULO_TIPO = { botao: 'Botão', separador: 'Separador' } as const;

/**
 * Campo «Procurar» da barra superior. `compacto` (ecrãs estreitos): só o ícone, que abre o mesmo campo por cima da barra.
 * O atalho Ctrl+K / ⌘K foca o campo (registado aqui).
 */
export function PesquisaCabecalho({ compacto = false }: { compacto?: boolean }) {
  const { menu, pode } = useSessao();
  const { eFavorito } = useFavoritos();
  const navegar = useNavigate();
  const [termo, setTermo] = useState('');
  const [aberta, setAberta] = useState(false);
  const entrada = useRef<InputRef>(null);
  const visivel = !compacto || aberta;
  const atalho = eMac() ? '⌘K' : 'Ctrl+K';

  // índice dos botões e separadores (≈ 90 kB): só se carrega quando o utilizador usa a pesquisa
  const [indice, setIndice] = useState<readonly EntradaIndicePesquisa[] | null>(null);
  const aCarregarIndice = useRef(false);
  const carregarIndice = useCallback(() => {
    if (aCarregarIndice.current) return;
    aCarregarIndice.current = true;
    import('./indicePesquisa.gerado')
      .then((m) => setIndice(m.INDICE_PESQUISA))
      .catch(() => {
        aCarregarIndice.current = false;   // tenta de novo na próxima vez (sem o índice ficam os ecrãs e as funções)
      });
  }, []);
  useEffect(() => {
    if (termo.trim()) carregarIndice();
  }, [termo, carregarIndice]);
  const accoes = useMemo(() => (indice ? prepararIndiceAccoes(indice, menu) : undefined), [indice, menu]);

  const resultados = useMemo(() => pesquisarSistema(menu, termo, { eFavorito, pode: (c) => !!pode?.(c), accoes }), [menu, termo, eFavorito, pode, accoes]);

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
    if (resultados.accoes.length)
      opcoes.push({
        label: <span className="erp-pesquisa-grupo">Botões e separadores</span>,
        options: resultados.accoes.map((r) => {
          const valor = `accao:${r.tipo}:${r.rota}:${r.nome}`;
          rotas.set(valor, r.rota);
          return { value: valor, label: <Opcao modulo={r.modulo} nome={r.nome} caminho={`${ROTULO_TIPO[r.tipo]} › ${r.nomeEcra} › ${r.nomeModulo}`} favorito={r.favorito} termo={termo} /> };
        }),
      });
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
    <div className={`erp-pesquisa-cabecalho${compacto ? ' flutuante' : ''}`} role="search">
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
          placeholder="Procurar ecrãs, funções e botões…"
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
