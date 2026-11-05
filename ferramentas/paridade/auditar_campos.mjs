#!/usr/bin/env node
// Auditoria de cobertura de campos: BD -> modelo -> API -> frontend (determinística, só leitura).
//
// Entradas (todas no repositório):
//   ferramentas/paridade/esquema_bd.json   esquema real da BD (extrair_esquema_bd.php, via information_schema)
//   ferramentas/paridade/rotas.json        php artisan route:list --json --path=api
//   backend/app/**, frontend/src/**, docs/dicionario/mapa_de_para.json
// Saídas:
//   docs/paridade/cobertura_campos.json    resultado completo (por coluna)
//   docs/paridade/COBERTURA_CAMPOS_2026-11.md   relatório agregado
//
// Uso: node ferramentas/paridade/auditar_campos.mjs
//
// Heurística (documentada no relatório): por tabela, o «âmbito» é o conjunto de ficheiros que mencionam o modelo ou a tabela;
// a coluna conta como usada quando aparece como literal/propriedade nesse âmbito. É uma triagem, não uma prova: as
// lacunas listadas são confirmadas à mão antes de implementar.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const R = (...p) => path.join(RAIZ, ...p);
const ler = (f) => fs.readFileSync(f, 'utf8');

function listar(dir, exts, saltar = []) {
  const saida = [];
  const visitar = (d) => {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) {
        if (e.name === 'node_modules' || saltar.some((s) => p.replaceAll('\\', '/').endsWith(s))) continue;
        visitar(p);
      } else if (exts.some((x) => e.name.endsWith(x))) saida.push(p);
    }
  };
  visitar(dir);
  return saida.sort();
}

const semComentarios = (s) => s.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"\\])\/\/.*$/gm, '$1');
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// ---------- entradas ----------
const esquema = JSON.parse(ler(R('ferramentas/paridade/esquema_bd.json')));
const rotas = JSON.parse(ler(R('ferramentas/paridade/rotas.json')));
const mapa = JSON.parse(ler(R('docs/dicionario/mapa_de_para.json')));

const tabelas = new Map(); // nome -> colunas[]
for (const c of esquema.colunas) {
  if (!tabelas.has(c.t)) tabelas.set(c.t, []);
  tabelas.get(c.t).push(c);
}
const mapaTabela = new Map(mapa.tabelas.map((t) => [t.pt, t]));
const fksPorColuna = new Map(esquema.fks.map((f) => [`${f.t}.${f.c}`, f.alvo]));

// ---------- backend ----------
const ficheirosBack = listar(R('backend/app'), ['.php']).map((f) => ({
  f: path.relative(RAIZ, f).replaceAll('\\', '/'),
  texto: semComentarios(ler(f)),
}));
const base = ficheirosBack.filter((x) => x.f.includes('/Models/Base/'));
const codigoBack = ficheirosBack.filter((x) => !x.f.includes('/Models/Base/'));

// tabela -> modelo
const modelos = new Map();
for (const x of ficheirosBack.filter((y) => y.f.includes('/Models/') && !y.f.includes('/Concerns/'))) {
  const m = x.texto.match(/\$table\s*=\s*'(\w+)'/);
  if (!m) continue;
  const classe = path.basename(x.f, '.php');
  const concreta = x.f.includes('/Models/Base/') ? classe.replace(/Base$/, '') : classe;
  const hidden = [...(x.texto.match(/\$hidden\s*=\s*\[([^\]]*)\]/)?.[1] ?? '').matchAll(/'(\w+)'/g)].map((a) => a[1]);
  const fillable = [...(x.texto.match(/\$fillable\s*=\s*\[([^\]]*)\]/)?.[1] ?? '').matchAll(/'(\w+)'/g)].map((a) => a[1]);
  const atual = modelos.get(m[1]) ?? { classe: concreta, hidden: [], fillable: [] };
  modelos.set(m[1], { classe: concreta, hidden: [...atual.hidden, ...hidden], fillable: [...atual.fillable, ...fillable] });
}
const ficheiroModelo = new Set(ficheirosBack.filter((y) => y.f.includes('/Models/')).map((y) => y.f));

// ---------- frontend ----------
const ficheirosFront = listar(R('frontend/src'), ['.ts', '.tsx']).map((f) => ({
  f: path.relative(RAIZ, f).replaceAll('\\', '/'),
  texto: semComentarios(ler(f)),
}));
const ehTeste = (f) => /\.test\.tsx?$/.test(f) || f.includes('configuracao-testes') || f.endsWith('.gerado.ts');
const front = ficheirosFront.filter((x) => !ehTeste(x.f));
const frontPorCaminho = new Map(front.map((x) => [x.f, x]));

function resolverImports(x) {
  const saida = [];
  for (const m of x.texto.matchAll(/from\s+'([^']+)'/g)) {
    let alvo = m[1];
    if (alvo.startsWith('@/')) alvo = 'frontend/src/' + alvo.slice(2);
    else if (alvo.startsWith('.')) alvo = path.posix.normalize(path.posix.join(path.posix.dirname(x.f), alvo));
    else continue;
    if (/\/(componentes|api|utilitarios|layout|sessao)\b/.test(alvo)) continue;
    for (const sufixo of ['.tsx', '.ts', '/index.tsx', '/index.ts']) {
      if (frontPorCaminho.has(alvo + sufixo)) {
        saida.push(alvo + sufixo);
        break;
      }
    }
  }
  return saida;
}

const cacheImports = new Map();
function resolverImportsCache(x) {
  if (!cacheImports.has(x.f)) cacheImports.set(x.f, resolverImports(x));
  return cacheImports.get(x.f);
}

// ---------- rotas -> métodos de controller ----------
const controllers = new Map(); // classe completa -> {texto, metodos: Map(nome -> corpo)}
for (const x of codigoBack.filter((y) => y.f.includes('/Http/Controllers/'))) {
  const ns = x.f.replace('backend/app/', 'App/').replace('.php', '').replaceAll('/', '\\');
  const metodos = new Map();
  const pos = [...x.texto.matchAll(/function\s+(\w+)\s*\(/g)].map((m) => ({ nome: m[1], i: m.index }));
  pos.forEach((p, k) => metodos.set(p.nome, x.texto.slice(p.i, pos[k + 1]?.i ?? x.texto.length)));
  controllers.set(ns, { texto: x.texto, metodos, f: x.f });
}
const prefixoUri = (uri) => {
  const u = '/' + uri.replace(/^api\//, '');
  const i = u.indexOf('{');
  return (i >= 0 ? u.slice(0, i) : u).replace(/\/$/, '');
};
const rotasPorController = new Map();
for (const r of rotas) {
  const [cls, met] = String(r.action).split('@');
  if (!met) continue;
  if (!rotasPorController.has(cls)) rotasPorController.set(cls, []);
  rotasPorController.get(cls).push({ uri: r.uri, met, metodo: r.method });
}

// ---------- classificação ----------
const INFRA_NOMES = new Set(['id', 'empresa_id', 'tenant_id', 'criado_em', 'atualizado_em', 'eliminado_em', 'criado_por', 'atualizado_por', 'eliminado_por', 'versao', 'remember_token', 'chave_idempotencia', 'idempotency_key', 'hash', 'hash_anterior', 'hash_registo', 'sequencia']);
const ehInfra = (c) => INFRA_NOMES.has(c) || /_hash$/.test(c) || /^hash_/.test(c) || /_token$/.test(c) || /idempot/.test(c) || /^(criado|atualizado|eliminado)_(por|em)/.test(c);
// Tabelas de maquinaria da plataforma (filas, migração, auditoria, idempotência, partições): sem UI por coluna por desenho.
const TABELAS_INFRA = /^(logs_auditoria(_\d+|_padrao)?|outbox_eventos|trabalhos_falhados|lotes_trabalhos|migracoes|execucoes_idempotentes|execucoes_migracao|etl_linhas_legado|ocorrencias_migracao|quarentena_migracao|restauros_legado|sequencias_documentos|dispositivos_conhecidos|tokens_acesso|webhooks_pagamento|agt_webhook_eventos|projeto_etiqueta|projeto_sinalizacao|execucoes_cambios_bai|cambios_bai_pendentes)$/;
const ehLegado = (c) => /_original$/.test(c) || /_legado$/.test(c) || /^legado_/.test(c) || /^id_legado/.test(c) || /_legacy$/.test(c) || /^dados_arquivo/.test(c);

function moduloDe(tabela, ctrls) {
  const m = mapaTabela.get(tabela);
  if (m) return m.modulo;
  for (const c of ctrls) {
    const q = c.match(/Controllers\\Api\\(\w+)\\/);
    if (q) return q[1];
  }
  return 'Outros';
}

const padraoBack = (col) => new RegExp(`(['"])${esc(col)}\\1|->${esc(col)}\\b|\\b[a-z_]{1,4}\\.${esc(col)}\\b|\\.${esc(col)}['"]|\\[${esc(col)}\\]`);
const padraoFront = (col) => new RegExp(`(?<![\\w])${esc(col)}(?![\\w])`);

// Colunas distintivas (em ≤ 2 tabelas): a pesquisa é global, porque o nome identifica o campo sem ambiguidade
// (ex.: morada.numero_porta é validada num Request que nunca menciona o modelo EnderecoTerceiro).
const tabelasPorColuna = new Map();
for (const [t, cs] of tabelas) for (const c of cs) tabelasPorColuna.set(c.c, (tabelasPorColuna.get(c.c) ?? 0) + 1);
const distintiva = (col) => tabelasPorColuna.get(col) <= 2 && col.length >= 6;
const backHttpGlobal = codigoBack.filter((x) => x.f.includes('/Http/') && !x.f.includes('/Resources/'));
const backResGlobal = codigoBack.filter((x) => x.f.includes('/Resources/'));
const backServGlobal = codigoBack.filter((x) => !x.f.includes('/Http/'));
const resultado = [];
const camposMortos = [];

for (const [tabela, colunas] of [...tabelas].sort((a, b) => a[0].localeCompare(b[0]))) {
  const modelo = modelos.get(tabela);
  const classe = modelo?.classe;
  const reClasse = classe ? new RegExp(`\\b${classe}\\b`) : null;
  const reTabela = new RegExp(`['"\`]${esc(tabela)}['"\`]|\\b${esc(tabela)}\\.`);
  const ambito = codigoBack.filter((x) => !ficheiroModelo.has(x.f) && ((reClasse && reClasse.test(x.texto)) || reTabela.test(x.texto)));
  const modeloConcreto = codigoBack.filter((x) => ficheiroModelo.has(x.f) && classe && x.f.endsWith(`/${classe}.php`));
  const http = ambito.filter((x) => x.f.includes('/Http/'));
  const resource = http.filter((x) => x.f.includes('/Resources/'));
  const entradaHttp = http.filter((x) => !x.f.includes('/Resources/'));
  const servicos = ambito.filter((x) => !x.f.includes('/Http/'));
  const ctrls = http.filter((x) => x.f.includes('/Controllers/')).map((x) => x.f.replace('backend/app/', 'App/').replace('.php', '').replaceAll('/', '\\'));
  const modulo = moduloDe(tabela, ctrls);

  // rotas da tabela: métodos de controllers cujo corpo menciona o modelo/tabela + endpoint do dicionário
  const uris = new Set();
  const m = mapaTabela.get(tabela);
  if (m?.endpoint) uris.add(prefixoUri(m.endpoint.replace(/^\//, '')));
  for (const cls of ctrls) {
    const c = controllers.get(cls);
    if (!c) continue;
    for (const r of rotasPorController.get(cls) ?? []) {
      const corpo = c.metodos.get(r.met) ?? '';
      if ((reClasse && reClasse.test(corpo)) || reTabela.test(corpo)) uris.add(prefixoUri(r.uri));
    }
  }
  // 2.º salto: sem URI directa (acesso via Resource/Service/Request) → rotas dos controllers que mencionam as classes do âmbito
  if (![...uris].some((u) => u.length > 3)) {
    const nomes = ambito.map((x) => path.basename(x.f, '.php')).filter((n) => !/^(Controller|Auditoriaw*)$/.test(n));
    const reNomes = nomes.length ? new RegExp(`\b(${nomes.map(esc).join('|')})\b`) : null;
    for (const [cls, c] of controllers) if (reNomes && reNomes.test(c.texto)) for (const r of rotasPorController.get(cls) ?? []) uris.add(prefixoUri(r.uri));
  }
  uris.delete('');
  const reUris = [...uris].filter((u) => u.length > 3).map((u) => new RegExp(`['"\`]${esc(u)}(?![\\w-])`));
  // 2.ª passagem: URLs montadas com constantes (`${EST}/delegacoes`) — último segmento + penúltimo palavra no mesmo ficheiro
  const caudas = [...uris].map((u) => u.split('/').filter(Boolean)).filter((s) => s.length >= 1 && s[s.length - 1].length > 4)
    .map((s) => ({ ultimo: new RegExp(`/${esc(s[s.length - 1])}(?![\w-])`), pen: s.length > 1 ? new RegExp(esc(s[s.length - 2])) : null }));
  const F = front.filter((x) => reUris.some((r) => r.test(x.texto)) || caudas.some((c) => c.ultimo.test(x.texto) && (!c.pen || c.pen.test(x.texto))));
  const ambitoFront = new Map(F.map((x) => [x.f, x]));
  for (const x of F) for (const i of resolverImports(x)) ambitoFront.set(i, frontPorCaminho.get(i));
  const fSet = new Set(F.map((x) => x.f));
  const importadores = front.filter((x) => !fSet.has(x.f) && resolverImportsCache(x).some((i) => fSet.has(i)));
  for (const x of importadores) { ambitoFront.set(x.f, x); for (const i of resolverImportsCache(x)) ambitoFront.set(i, frontPorCaminho.get(i)); }
  const ficheirosF = [...ambitoFront.values()];
  const temResource = resource.length > 0;
  const temController = ctrls.length > 0;

  for (const c of colunas) {
    const col = c.c;
    const reB = padraoBack(col);
    const reF = padraoFront(col);
    const glob = distintiva(col);
    const nasHttp = (glob ? backHttpGlobal : entradaHttp).filter((x) => reB.test(x.texto)).map((x) => x.f);
    const nosRes = (glob ? backResGlobal : resource).filter((x) => reB.test(x.texto)).map((x) => x.f);
    const nosServ = (glob ? backServGlobal : servicos).filter((x) => reB.test(x.texto)).map((x) => x.f);
    const noModelo = modeloConcreto.some((x) => reB.test(x.texto));
    const oculta = modelo?.hidden.includes(col) ?? false;
    const fraco = col.length >= 8 && col.includes('_') ? new RegExp(`(?<![\w])${esc(col)}(?![\w])`) : null;
    const nasHttpFraco = fraco && !nasHttp.length ? entradaHttp.filter((x) => fraco.test(x.texto)).map((x) => x.f) : [];
    const nosServFraco = fraco && !nosServ.length ? servicos.filter((x) => fraco.test(x.texto)).map((x) => x.f) : [];
    const backendUsa = nasHttpFraco.length + nosServFraco.length + nasHttp.length + nosRes.length + nosServ.length > 0 || noModelo;
    const apiEscrita = nasHttp.length > 0 || nasHttpFraco.length > 0;
    const apiLeitura = nosRes.length > 0 || (!temResource && temController && !oculta);
    const alvoFront = glob ? front : ficheirosF;
    const frontTsx = alvoFront.filter((x) => x.f.endsWith('.tsx') && reF.test(x.texto)).map((x) => x.f);
    const frontTs = alvoFront.filter((x) => x.f.endsWith('.ts') && reF.test(x.texto)).map((x) => x.f);
    const frontUi = frontTsx.length > 0;
    const m2 = m?.colunas.find((k) => k.pt === col);
    const preenchimento = m2?.preenchimento ?? null;

    let classe_;
    let motivo = '';
    if (ehInfra(col) || TABELAS_INFRA.test(tabela)) classe_ = 'INFRA';
    else if (ehLegado(col)) classe_ = 'LEGADO_TECNICO';
    else if (frontUi && (backendUsa || apiLeitura)) classe_ = 'FUNCIONAL_COBERTO';
    else if (frontUi) {
      classe_ = 'FUNCIONAL_COBERTO';
      motivo = 'só frontend (sem referência explícita no backend)';
    } else if (apiLeitura || apiEscrita) {
      classe_ = 'FUNCIONAL_SO_BACKEND';
      motivo = frontTs.length ? 'só nos tipos TypeScript' : '';
    } else if (backendUsa) {
      classe_ = 'FUNCIONAL_SEM_API';
      motivo = 'usada só internamente (serviço/modelo)';
    } else if (preenchimento !== null && preenchimento > 0.05) {
      classe_ = 'FUNCIONAL_SEM_API';
      motivo = 'sem uso em código; o legado tinha dados';
    } else classe_ = 'ORFA';

    resultado.push({
      tabela, coluna: col, tipo: c.d, nulo: c.n === 'YES', modulo,
      classe: classe_, motivo,
      backend: backendUsa, api_leitura: apiLeitura, api_escrita: apiEscrita, frontend_ui: frontUi, frontend_tipo: frontTs.length > 0,
      fk: fksPorColuna.get(`${tabela}.${col}`) ?? null, preenchimento_legado: preenchimento,
      ficheiros_back: [...new Set([...nasHttp, ...nosRes, ...nosServ])].slice(0, 4),
      ficheiros_front: [...new Set([...frontTsx, ...frontTs])].slice(0, 4),
      onde_ligar: { controllers: [...new Set(ctrls)].map((x) => x.split('\\').pop()), frontend: F.map((x) => x.f).slice(0, 3) },
    });
  }

}

// Inverso: campos de formulário (`{ nome: 'x'`, `<Form.Item name="x">`) que não são coluna de NENHUMA tabela nem aparecem no backend HTTP/serviços
const colunasGlobais = new Set(esquema.colunas.map((c) => c.c));
const textoBackHttp = codigoBack.filter((x) => x.f.includes('/Http/') || x.f.includes('/Services/')).map((x) => x.texto).join(String.fromCharCode(10));
for (const x of front) {
  const vistos = new Set();
  for (const mm of x.texto.matchAll(/\{\s*nome:\s*'([a-z][a-z0-9_]*)'/g)) vistos.add(mm[1]);
  for (const mm of x.texto.matchAll(/<Form\.Item[^>]*name="([a-z][a-z0-9_]*)"/g)) vistos.add(mm[1]);
  for (const nome of vistos) {
    if (colunasGlobais.has(nome) || /^(q|busca|pesquisa|filtro|pagina|confirmar|password|senha)/.test(nome)) continue;
    if (padraoBack(nome).test(textoBackHttp)) continue;
    camposMortos.push({ ficheiro: x.f, campo: nome });
  }
}

// ---------- rotas sem ecrã e chamadas a rotas inexistentes ----------
const norm = (u) => u.replace(/\{[^}]+\}/g, '{}');
const rotasIndice = rotas.map((r) => ({ metodo: r.method.split('|')[0], uri: '/' + r.uri.replace(/^api\//, ''), re: new RegExp('^' + esc(norm('/' + r.uri.replace(/^api\//, ''))).replace(/\\\{\\\}/g, '[^/]+') + '$'), action: r.action }));
const textoFront = front.map((x) => x.texto).join('\n');
const rotasSemEcra = [];
const segs = (u) => u.split('/').filter(Boolean);
for (const r of rotas) {
  if (!r.uri.startsWith('api/')) continue;
  const uri = '/' + r.uri.replace(/^api\//, '');
  const p = prefixoUri(r.uri);
  if (!p || p.length < 3) continue;
  const literais = segs(uri).filter((x) => !x.startsWith('{'));
  const ultimo = literais[literais.length - 1];
  const penultimo = literais.length > 1 ? literais[literais.length - 2] : null;
  const noEcra = front.some((x) => x.texto.includes(p)) && (!uri.includes('{') || new RegExp(`/${esc(ultimo)}(?![\w-])`).test(textoFront))
    || front.some((x) => new RegExp(`/${esc(ultimo)}(?![\w-])`).test(x.texto) && (!penultimo || x.texto.includes(penultimo)));
  if (!noEcra) rotasSemEcra.push({ metodo: r.method.split('|')[0], uri, action: String(r.action).replace('App\\Http\\Controllers\\Api\\', '') });
}
const casa = (rota, url) => { const a = segs(rota); const b = segs(url); return a.length === b.length && a.every((s, i) => b[i] === '{}' || s.startsWith('{') || s === b[i]); };
const chamadas404 = [];
for (const x of front) {
  for (const mm of x.texto.matchAll(/\b(obter|obterPagina|descarregar|http\.(?:get|post|put|delete|patch))\s*(?:<[^()]*?>)?\s*\(\s*(['"`])(\/[^'"`]*?)\2/g)) {
    const url = mm[3].replace(/\$\{[^}]+\}/g, '{}').split('?')[0];
    if (!rotasIndice.some((r) => casa(r.uri, url))) chamadas404.push({ ficheiro: x.f, url: mm[3] });
  }
  for (const mm of x.texto.matchAll(/\benviar\s*(?:<[^()]*?>)?\s*\(\s*'(post|put|delete)'\s*,\s*(['"`])(\/[^'"`]*?)\2/g)) {
    const url = mm[3].replace(/\$\{[^}]+\}/g, '{}').split('?')[0];
    if (!rotasIndice.some((r) => casa(r.uri, url) && r.metodo === mm[1].toUpperCase())) chamadas404.push({ ficheiro: x.f, url: `${mm[1].toUpperCase()} ${mm[3]}` });
  }
}

// ---------- agregação ----------
const CLASSES = ['INFRA', 'LEGADO_TECNICO', 'FUNCIONAL_COBERTO', 'FUNCIONAL_SO_BACKEND', 'FUNCIONAL_SEM_API', 'ORFA'];
const contar = (lista) => Object.fromEntries(CLASSES.map((k) => [k, lista.filter((r) => r.classe === k).length]));
const global = contar(resultado);
const porModulo = {};
for (const r of resultado) (porModulo[r.modulo] ??= []).push(r);
const porTabela = {};
for (const r of resultado) (porTabela[r.tabela] ??= []).push(r);

const PRIORIDADE = ['Vendas', 'Compras', 'Logística', 'Tesouraria', 'Contabilidade', 'RH', 'Activos', 'CRM', 'Projectos', 'Orçamento', 'Estrutura', 'Metodo', 'Terceiros', 'POS', 'Acréscimos', 'Sistema'];
const pesoModulo = (m) => { const i = PRIORIDADE.indexOf(m); return i < 0 ? 99 : i; };
const lacunas = resultado.filter((r) => ['FUNCIONAL_SO_BACKEND', 'FUNCIONAL_SEM_API', 'ORFA'].includes(r.classe))
  .sort((a, b) => pesoModulo(a.modulo) - pesoModulo(b.modulo) || a.tabela.localeCompare(b.tabela) || a.coluna.localeCompare(b.coluna));

fs.writeFileSync(R('docs/paridade/cobertura_campos.json'), JSON.stringify({
  gerado_por: 'ferramentas/paridade/auditar_campos.mjs',
  totais: { tabelas: tabelas.size, colunas: resultado.length, ...global },
  por_modulo: Object.fromEntries(Object.entries(porModulo).map(([m, l]) => [m, { tabelas: new Set(l.map((x) => x.tabela)).size, colunas: l.length, ...contar(l) }])),
  por_tabela: Object.fromEntries(Object.entries(porTabela).map(([t, l]) => [t, { modulo: l[0].modulo, colunas: l.length, ...contar(l) }])),
  campos_frontend_sem_coluna: camposMortos,
  rotas_sem_ecra: rotasSemEcra,
  chamadas_frontend_sem_rota: chamadas404,
  colunas: resultado,
}, null, 1) + '\n');

// ---------- relatório ----------
const L = [];
const tab = (cab, linhas) => { L.push(`| ${cab.join(' | ')} |`, `| ${cab.map(() => ':---').join(' | ')} |`); for (const l of linhas) L.push(`| ${l.join(' | ')} |`); L.push(''); };
L.push('# Cobertura de campos BD → modelo → API → frontend (2026-11)', '');
L.push('> Gerado por `ferramentas/paridade/auditar_campos.mjs` (determinístico; entradas: `esquema_bd.json`, `rotas.json`, código em `backend/app` e `frontend/src`). É uma **triagem heurística** por âmbito de tabela (ficheiros que mencionam o modelo/tabela; no frontend, ficheiros que chamam os endpoints dessa tabela e os seus imports locais). As lacunas listadas são confirmadas à mão antes de qualquer implementação.', '');
L.push('## Classes', '', '- **INFRA**: id, empresa_id, carimbos, autoria, hashes, chaves de idempotência — normal não ter UI.', '- **LEGADO_TECNICO**: `*_original`, `*_legado`, colunas de normalização/paridade.', '- **FUNCIONAL_COBERTO**: backend + API + ecrã.', '- **FUNCIONAL_SO_BACKEND**: a API devolve/aceita, nenhum ecrã a mostra ou edita.', '- **FUNCIONAL_SEM_API**: existe na BD/modelo, nenhum endpoint a lê ou escreve (ou só uso interno).', '- **ORFA**: nenhum código a usa e o legado não tinha dados.', '');
L.push('## Precisão da triagem e verificação manual', '',
  'A triagem é estática: nomes de colunas montados por constantes (`EnderecoTerceiro::CAMPOS`), regras de validação com chaves pontuadas (`habilitacoes.*.curso`), URLs montadas com constantes (`${EST}/delegacoes`) e acesso via Resource/Service sem referência ao modelo geram **falsos positivos**. Por isso as classes `FUNCIONAL_SO_BACKEND`/`FUNCIONAL_SEM_API` são um limite superior das lacunas reais. Amostra verificada à mão (Vendas, Terceiros, RH, Compras, Estrutura, CRM):', '',
  '- **Falsos positivos confirmados** (já cobertos): `enderecos_terceiros.*` (morada estruturada no Resource/Request/Serviço de terceiros), `habilitacoes_colaboradores.curso`, `delegacoes_chefia.*` e restantes tabelas de Estrutura (ecrãs em `modulos/estrutura/faseK`), `itens_venda.centro_custo_id/unidade_negocio_id` (por linha na emissão), `itens_venda.total_linha` (devolvido como `valor` da linha), `oportunidades_venda_crm.etapa_desde/ultima_atividade_em` (usados para os dias na etapa / sem actividade que o ecrã mostra), `pedidos_compra.colaborador_requerente_id` (deliberação de compras).',
  '- **Espelhos internos sem UI por desenho**: `catalogo_fornecedores` (sincronizado automaticamente a partir do produto, `ServicoProdutos::sincronizarCatalogoCompras`), tabelas de maquinaria (`outbox_eventos`, `execucoes_*`, `quarentena_migracao`, `logs_auditoria_*`, …) tratadas como INFRA.',
  '- **Lacunas reais corrigidas neste trabalho**: `vendas.data_entrega`/`vendas.local_entrega` (emissão, detalhe, impressão, herança na conversão; commit «data e local de entrega») e `colaboradores.data_cessacao` (listagem, ficha e impressão da ficha; só leitura, preenchida pelo estado do contrato).',
  '- **Mantidas sem uso (legado), sem UI inventada**: `configuracoes_assiduidade.minimo_dia_horas` (o legado só a normalizava, `assiduidade.js:63`; a semântica de cálculo não está documentada — decisão de negócio pendente antes de a ligar à apuração), `documentos_projeto.*` e `faturacao_projetos.projeto_id` (tabelas sem funcionalidade; o legado tinha 0 linhas), `vendas.total_desconto`/`recibos_venda.montante_total_moeda`/`vendas.fe_estabelecimento`/`itens_venda.codigo_projeto`/`lancamentos_estornados.{reconciliacao_codigo,codigo_projeto,sistema_origem}`/`reconciliacoes_bancarias.importacao_codigo`/`tipos_documento.descricao`/`documentos_anexos.entidade_id` (restos de paridade do legado sem consumidor).',
  '- **Restante**: a maioria dos campos técnicos listados abaixo são carimbos de estado (`*_em`/`*_por`), câmbio (`taxa_cambio_*`, `*_moeda`), campos da facturação electrónica AGT (`fe_*`, `saft_*`) e chaves de ligação — não têm valor como campo editável; os que ficam por rever à mão estão na lista priorizada.', '');
L.push(`## Totais (${tabelas.size} tabelas, ${resultado.length} colunas)`, '');
tab(['Classe', 'Colunas', '%'], CLASSES.map((k) => [k, global[k], ((100 * global[k]) / resultado.length).toFixed(1) + '%']));
L.push('## Por módulo', '');
const mods = Object.keys(porModulo).sort((a, b) => pesoModulo(a) - pesoModulo(b) || a.localeCompare(b));
tab(['Módulo', 'Tabelas', 'Colunas', ...CLASSES.map((k) => k.replace('FUNCIONAL_', 'F_'))], mods.map((m) => { const c = contar(porModulo[m]); return [m, new Set(porModulo[m].map((x) => x.tabela)).size, porModulo[m].length, ...CLASSES.map((k) => c[k])]; }));
L.push('## Tabelas com mais lacunas funcionais (SO_BACKEND + SEM_API + ORFA)', '');
const rank = Object.entries(porTabela).map(([t, l]) => ({ t, m: l[0].modulo, n: l.filter((x) => ['FUNCIONAL_SO_BACKEND', 'FUNCIONAL_SEM_API', 'ORFA'].includes(x.classe)).length, tot: l.length })).filter((x) => x.n > 0).sort((a, b) => b.n - a.n);
tab(['Tabela', 'Módulo', 'Lacunas', 'Colunas'], rank.slice(0, 40).map((x) => [x.t, x.m, x.n, x.tot]));
L.push(`## Lista priorizada de lacunas (${lacunas.length})`, '', 'Ordenada por módulo central → tabela → coluna. «Onde ligar» = controller(s) e ecrã(s) do âmbito da tabela.', '');
let modAtual = '';
let tabAtual = '';
for (const r of lacunas) {
  if (r.modulo !== modAtual) { modAtual = r.modulo; tabAtual = ''; L.push('', `### ${modAtual}`, ''); }
  if (r.tabela !== tabAtual) {
    tabAtual = r.tabela;
    L.push(`**${r.tabela}** — controllers: ${r.onde_ligar.controllers.join(', ') || '—'}; ecrãs: ${r.onde_ligar.frontend.join(', ') || '—'}`);
  }
  L.push(`- \`${r.coluna}\` (${r.tipo}) ${r.classe.replace('FUNCIONAL_', '')}${r.motivo ? ` — ${r.motivo}` : ''}`);
}
L.push('', '## Inverso: frontend → backend', '');
L.push(`### Campos de formulário sem coluna correspondente (${camposMortos.length})`, '', 'Campos `nome:`/`<Form.Item name>` que não são coluna de nenhuma tabela e que nenhum controller/request/serviço referencia (candidatos a código morto ou desalinhamento de nomes; muitos são auxiliares de UI legítimos).', '');
tab(['Campo', 'Ficheiro'], camposMortos.slice(0, 150).map((c) => [`\`${c.campo}\``, c.ficheiro]));
L.push(`### Chamadas do frontend a rotas inexistentes — 404 latente (${chamadas404.length})`, '');
for (const c of chamadas404) L.push(`- \`${c.url}\` — ${c.ficheiro}`);
L.push('', `### Rotas da API sem referência no frontend (${rotasSemEcra.length})`, '', 'Procura o prefixo literal da URL em `frontend/src`; rotas só de integração externa (webhooks, AGT, públicas) são esperadas aqui.', '');
for (const r of rotasSemEcra) L.push(`- ${r.metodo} \`${r.uri}\` — ${r.action}`);
L.push('');
fs.writeFileSync(R('docs/paridade/COBERTURA_CAMPOS_2026-11.md'), L.join('\n'));
console.log(JSON.stringify({ tabelas: tabelas.size, colunas: resultado.length, ...global, lacunas: lacunas.length, camposMortos: camposMortos.length, chamadas404: chamadas404.length, rotasSemEcra: rotasSemEcra.length }));
