// Auditoria da cobertura da pesquisa «Procurar»: compara o índice gerado com o que existe no sistema (registo de ecrãs, catálogo
// de permissões, separadores/botões/janelas do código) e imprime os números e as lacunas. Independente do gerador: lê o código
// com expressões regulares simples, para servir de contra-prova.
//
// Uso (a partir de erp_laravel/):
//   node ferramentas/pesquisa/auditar_pesquisa.mjs                     números + lacunas (texto)
//   node ferramentas/pesquisa/auditar_pesquisa.mjs --json              o mesmo em JSON
//   node ferramentas/pesquisa/auditar_pesquisa.mjs --indice <ficheiro> audita outro índice (ex.: o de uma revisão antiga: git show <rev>:frontend/src/layout/indicePesquisa.gerado.ts)
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(AQUI, '../..');
const FRONT = path.join(RAIZ, 'frontend/src');
const norm = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
const lerJson = (f) => JSON.parse(fs.readFileSync(f, 'utf8'));
const config = lerJson(path.join(AQUI, 'indice.config.json'));
const semPesquisa = (id) => Object.keys(config.ecrasSemPesquisa).some((p) => (p.endsWith('*') ? id.startsWith(p.slice(0, -1)) : id === p));

function ficheiros(pasta, acc = []) {
  for (const d of fs.readdirSync(pasta, { withFileTypes: true })) {
    const f = path.join(pasta, d.name);
    if (d.isDirectory()) ficheiros(f, acc);
    else if (/\.tsx?$/.test(d.name) && !/\.test\.tsx?$/.test(d.name)) acc.push(f);
  }
  return acc;
}

export function auditar(ficheiroIndice = path.join(FRONT, 'layout/indicePesquisa.gerado.ts')) {
  const catalogo = lerJson(path.join(RAIZ, 'backend/resources/permissoes/catalogo.json'));
  const ecrasCat = new Map();
  const tarefas = new Map();
  for (const m of catalogo.modulos) for (const e of m.ecras) {
    ecrasCat.set(e.id, { modulo: m.id, nome: e.nome, pai: e.pai });
    for (const t of e.tarefas) tarefas.set(t.chave, { ecra: e.id, rotulo: t.rotulo, sensivel: !!t.sensivel });
  }
  const registo = new Set();
  for (const m of fs.readdirSync(path.join(FRONT, 'modulos'))) {
    const f = path.join(FRONT, 'modulos', m, 'ecras.ts');
    if (!fs.existsSync(f)) continue;
    for (const x of fs.readFileSync(f, 'utf8').matchAll(/^\s*['"]?([a-z0-9_]+)['"]?\s*:\s*(?:lazy|[a-zA-Z]+\()/gm)) registo.add(x[1]);
  }
  const indice = fs.readFileSync(ficheiroIndice, 'utf8').split('\n').filter((l) => l.startsWith('  [')).map((l) => JSON.parse(l.trim().replace(/,$/, '')));
  const porTipo = {};
  for (const i of indice) porTipo[i[1]] = (porTipo[i[1]] ?? 0) + 1;

  // 1. ecrãs
  const pesquisaveis = [...ecrasCat.keys()].filter((id) => !semPesquisa(id));
  const comEntradas = new Set(indice.filter((i) => !i[0].startsWith('/')).map((i) => i[0]));
  const ecrasSemEntradas = pesquisaveis.filter((id) => registo.has(id) && !comEntradas.has(id));
  const registadosForaDoCatalogo = [...registo].filter((id) => !ecrasCat.has(id));
  const catalogoSemRegisto = pesquisaveis.filter((id) => !registo.has(id));
  const mortas = indice.filter((i) => !i[0].startsWith('/') && !ecrasCat.has(i[0]));
  const obsoletas = indice.filter((i) => i[0] === 'ia_chat' || i[0].startsWith('plataforma_'));

  // 2. permissões referenciadas que não existem no catálogo
  const validas = (k) => tarefas.has(k) || (k.endsWith('_view') && ecrasCat.has(k.slice(0, -5)));
  const permsInvalidas = new Map();
  for (const i of indice) for (const k of (i[3] ?? '').split(/[;|]/).filter(Boolean)) if (!validas(k)) permsInvalidas.set(k, (permsInvalidas.get(k) ?? 0) + 1);

  // 3. separadores
  const sep = indice.filter((i) => i[1] === 's' || (i[1] === 'c' && i[4] !== undefined));
  const separadoresTodos = indice.filter((i) => ['s'].includes(i[1]) || (i[1] === 'c' && i[4] !== undefined));
  const comLigacao = indice.filter((i) => (i[1] === 's' || i[1] === 'c' || i[1] === 'r') && i[4]);

  // 4. tarefas do catálogo: executadas por algum botão/janela do índice do seu ecrã?
  const tarefasIndexadas = new Set();
  for (const i of indice) for (const k of (i[3] ?? '').split(/[;|]/).filter(Boolean)) if (tarefas.has(k)) tarefasIndexadas.add(k);
  const tarefasPesquisaveis = [...tarefas.entries()].filter(([, t]) => !semPesquisa(t.ecra));

  // 5. duplicados: o mesmo rótulo em vários ecrãs
  const rotulos = new Map();
  for (const i of indice) if (['b', 'd', 's', 'c', 'r'].includes(i[1])) {
    const k = `${i[1]}|${norm(i[2])}`;
    if (!rotulos.has(k)) rotulos.set(k, new Set());
    rotulos.get(k).add(i[0]);
  }
  const duplicados = [...rotulos.entries()].filter(([, v]) => v.size > 1);

  // 6. acções sensíveis sem permissão no índice
  const sensivel = /\b(eliminar|apagar|anular|aprovar|descontabilizar|regularizar|fechar periodo|reabrir|estornar|revogar|pagar|transferir)\b/;
  const semPermissao = indice.filter((i) => ['b', 'd'].includes(i[1]) && !i[3] && sensivel.test(norm(i[2])));

  // 7. código vs índice (contra-prova independente: rótulos estáticos que existem no código e não estão em nenhum ecrã do índice)
  const rotulosIndice = new Set(indice.map((i) => norm(i[2])));
  const candidatos = { separadores: new Set(), botoes: new Set(), janelas: new Set(), vistas: new Set() };
  let nTabs = 0;
  for (const f of ficheiros(path.join(FRONT, 'modulos'))) {
    if (/[\\/](ecras|registo)\.tsx?$/.test(f)) continue;
    const t = fs.readFileSync(f, 'utf8');
    if (/<Tabs\b/.test(t)) {
      nTabs += (t.match(/<Tabs\b/g) ?? []).length;
      for (const m of t.matchAll(/\bkey:\s*'[^']+'\s*,\s*label:\s*'([^']{2,60})'/g)) candidatos.separadores.add(norm(m[1]));
    }
    for (const m of t.matchAll(/<Button\b[^>]*>\s*([A-Za-zÀ-ú][^<>{}]{2,60}?)\s*<\/Button>/g)) candidatos.botoes.add(norm(m[1]));
    for (const m of t.matchAll(/<(?:Modal|Drawer)\b[^>]*?\btitle="([^"{}#]{3,70})"/g)) candidatos.janelas.add(norm(m[1]));
    for (const m of t.matchAll(/<(?:ModalFormulario|DrawerFormulario)\b[^>]*?\btitulo="([^"{}#]{3,70})"/g)) candidatos.janelas.add(norm(m[1]));
    for (const m of t.matchAll(/<Segmented\b[\s\S]{0,400}?options=\{\[([^\]]{0,400})\]/g)) for (const l of m[1].matchAll(/label:\s*'([^']{2,40})'/g)) candidatos.vistas.add(norm(l[1]));
  }
  const generico = /^(ok|sim|nao|cancelar|fechar|voltar|limpar|guardar|gravar|confirmar|aplicar|filtrar|pesquisar|procurar|anterior|seguinte|continuar|editar|eliminar|apagar|remover|adicionar|novo|nova|ver|abrir|actualizar|atualizar|recarregar|repor|mais|menos|todos|todas|nenhum|detalhe|detalhes)$/;
  const cobertura = {};
  for (const [k, conj] of Object.entries(candidatos)) {
    const uteis = [...conj].filter((x) => !generico.test(x));
    const falta = uteis.filter((x) => !rotulosIndice.has(x));
    cobertura[k] = { distintos: uteis.length, indexados: uteis.length - falta.length, falta: falta.sort() };
  }

  return {
    totais: { entradas: indice.length, porTipo },
    ecras: {
      catalogo: ecrasCat.size, pesquisaveis: pesquisaveis.length, registados: registo.size,
      semEntradasNoIndice: ecrasSemEntradas, registadosForaDoCatalogo, catalogoSemRegisto,
    },
    entradasMortas: mortas.map((i) => `${i[0]}: ${i[2]}`),
    entradasObsoletas: obsoletas.length,
    permissoesInvalidas: Object.fromEntries(permsInvalidas),
    separadores: { total: separadoresTodos.length + 0, comLigacaoDirecta: comLigacao.length, tabsNoCodigo: nTabs },
    tarefas: { catalogo: tarefas.size, pesquisaveis: tarefasPesquisaveis.length, executadasPorBotaoOuJanela: tarefasPesquisaveis.filter(([k]) => tarefasIndexadas.has(k)).length, sensiveis: tarefasPesquisaveis.filter(([, t]) => t.sensivel).length },
    duplicados: { rotulosEmVariosEcras: duplicados.length, entradasEnvolvidas: duplicados.reduce((a, [, v]) => a + v.size, 0), maiores: duplicados.sort((a, b) => b[1].size - a[1].size).slice(0, 8).map(([k, v]) => `${k} (${v.size} ecrãs)`) },
    semPermissaoSensivel: semPermissao.length,
    semPermissaoSensivelExemplos: semPermissao.slice(0, 8).map((i) => `${i[0]}: ${i[2]}`),
    cobertura,
  };
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const i = process.argv.indexOf('--indice');
  const r = auditar(i > 0 ? path.resolve(process.argv[i + 1]) : undefined);
  if (process.argv.includes('--json')) console.log(JSON.stringify(r, null, 2));
  else {
    const mostrar = JSON.parse(JSON.stringify(r, (k, v) => (Array.isArray(v) && v.length > 12 ? [...v.slice(0, 12), `… (+${v.length - 12})`] : v)));
    console.log(JSON.stringify(mostrar, null, 2));
  }
}
