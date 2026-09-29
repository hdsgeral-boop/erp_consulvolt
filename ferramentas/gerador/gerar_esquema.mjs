// Gerador do esquema PostgreSQL (migrations) e dos models Eloquent a partir do dicionário DE/PARA.
//
// Uso: node ferramentas/gerador/gerar_esquema.mjs <backup.json>
//   (a partir da raiz erp_laravel; lê docs/dicionario/mapa_de_para.json e docs/dicionario/campos_codigo_legado.json)
//
// Saídas (em backend/):
//   database/migrations/2026_09_30_1*_criar_tabelas_<modulo>.php     tabelas, únicos, índices, CHECKs
//   database/migrations/2026_09_30_900000_criar_chaves_estrangeiras.php  todas as FKs (DEFERRABLE)
//   app/Models/Base/<Model>Base.php   REGENERADO sempre (não editar)
//   app/Models/<Model>.php            criado só se não existir (código de negócio vive aqui)
//   database/legado/esquema.json      contrato do esquema (ETL + teste de contrato)
//   database/legado/mapa_de_para.json cópia do DE/PARA usada pelo ETL
//
// Falha (exit 2) se: colunas de código sem tradução, referências a tabelas/colunas inexistentes,
// chaves únicas ou CHECKs violados pelos dados reais do backup.

import fs from 'node:fs';
import path from 'node:path';
import { GLOSSARIO, SOBREPOSICOES } from '../levantamento/glossario.mjs';
import { NORMALIZACOES, dobrar } from '../levantamento/normalizacoes.mjs';
import {
  GLOBAIS, FASE1, EMPRESA_DERIVADA, ELIMINACAO_LOGICA, UNICOS, INDICES_COM_DUPLICADOS_CONHECIDOS, INDICES,
  CASCATA, POLIMORFICOS, PIVOS, COLUNAS_NOVAS, CHECKS, TABELAS_NOVAS,
} from './esquema_extra.mjs';

const RAIZ = process.cwd();
const BACKEND = path.join(RAIZ, 'backend');
const erros = [];
const avisos = [];

const mapa = JSON.parse(fs.readFileSync(path.join(RAIZ, 'docs/dicionario/mapa_de_para.json'), 'utf8'));
const caminhoCodigo = path.join(RAIZ, 'docs/dicionario/campos_codigo_legado.json');
const codigo = fs.existsSync(caminhoCodigo) ? JSON.parse(fs.readFileSync(caminhoCodigo, 'utf8')) : { tabelas: {} };
if (!fs.existsSync(caminhoCodigo)) avisos.push('campos_codigo_legado.json inexistente: tabelas sem dados ficam só com id/carimbos');

const backup = process.argv[2] ? JSON.parse(fs.readFileSync(process.argv[2], 'utf8')) : null;
const EMP_REAIS = new Set([10, 18]);
const real = (t, l) => !(l?.is_master_data == 1 && !(t === 'companies' && EMP_REAIS.has(l.id)));
const linhasLegado = backup ? Object.fromEntries(backup.data.data.map((t) => [t.tableName, (t.rows ?? []).filter((l) => real(t.tableName, l))])) : {};

// ── utilitários ────────────────────────────────────────────────────────────────
const camel = (s) => s.replace(/_([a-z0-9])/g, (_, c) => c.toUpperCase());
const studly = (s) => camel(s).replace(/^./, (c) => c.toUpperCase());
const semId = (c) => c.replace(/_id$/, '');
const slug = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_');
const traduzir = (tabelaLeg, colLeg) => {
  const sob = SOBREPOSICOES[tabelaLeg] ?? {};
  return colLeg in sob ? sob[colLeg] : GLOSSARIO[colLeg];
};
const legPorPt = Object.fromEntries(mapa.tabelas.map((t) => [t.pt, t.legado]));

const RE_MONETARIO = /(^|_)(valor|montante|total|preco|custo|saldo|subtotal|desconto|troco|comissao|pago|pendente|amortizacao|residual|quota|imposto|diferenca|kz|bonificacao|salario|remuneracao|orcamentado|realizado|limite)($|_)/;
const RE_QUANTIDADE = /(^|_)(quantidade|qtd|horas|dias_trabalhados)($|_)/;
const RE_TAXA = /(^|_)(taxa|percentagem|pct|probabilidade|peso|inflacao|crescimento)($|_)/;

function inferirDoExemplo(pt, exemplo) {
  if (pt.endsWith('_em')) return 'timestamptz';
  if (typeof exemplo === 'boolean') return 'boolean';
  if (Array.isArray(exemplo) || (exemplo && typeof exemplo === 'object')) return 'jsonb';
  const e = typeof exemplo === 'string' ? exemplo.toLowerCase() : '';
  if (/^(array|lista|objecto|objeto|object|json)|\[|\{/.test(e)) return 'jsonb';
  if (/bool|true|false/.test(e) || /^(e_|tem_)/.test(pt)) return 'boolean';
  if (/iso|data|date|\d{4}-\d{2}-\d{2}/.test(e) || /^data(_|$)/.test(pt)) return /hora|time|t\d\d:/.test(e) ? 'timestamptz' : 'date';
  const numerico = typeof exemplo === 'number' || /n[uú]mer|number|decimal|inteiro|integer|valor numérico/.test(e);
  if (numerico || RE_MONETARIO.test(pt) || RE_QUANTIDADE.test(pt) || RE_TAXA.test(pt)) {
    if (RE_TAXA.test(pt)) return 'numeric(9,4)';
    if (RE_QUANTIDADE.test(pt)) return 'numeric(12,3)';
    if (RE_MONETARIO.test(pt) || (typeof exemplo === 'number' && !Number.isInteger(exemplo))) return 'numeric(15,2)';
    return 'integer';
  }
  if (/(descricao|observacoes|notas|detalhes|corpo|comentario|conteudo|texto|mensagem|justificacao|motivo|resolucao|endereco|morada)/.test(pt)) return 'text';
  return 'varchar(255)';
}

// ── 1. modelo de esquema ───────────────────────────────────────────────────────
const tabelas = new Map();   // pt -> definição
for (const t of mapa.tabelas) {
  tabelas.set(t.pt, {
    pt: t.pt, legado: t.legado, model: t.model, modulo: t.modulo, endpoint: t.endpoint,
    linhasReais: t.linhas_reais, global: GLOBAIS.has(t.pt), fase1: FASE1.has(t.pt),
    colunas: new Map(), unicos: [], indices: [], checks: [], eliminacaoLogica: ELIMINACAO_LOGICA.has(t.pt),
  });
}
for (const [pt, def] of Object.entries(TABELAS_NOVAS)) {
  tabelas.set(pt, { pt, legado: null, model: def.model, modulo: def.modulo, endpoint: null, linhasReais: 0, global: !!def.global,
    fase1: false, colunas: new Map(), unicos: [], indices: (def.indices ?? []).map((c) => c), checks: [], eliminacaoLogica: false, nova: true });
  for (const [c, tipo, nota] of def.colunas) tabelas.get(pt).colunas.set(c, { pt: c, tipo, nulo: true, origem: 'novo', nota });
}

const adicionar = (t, col) => { if (!t.colunas.has(col.pt)) t.colunas.set(col.pt, col); };

for (const t of mapa.tabelas) {
  const def = tabelas.get(t.pt);
  // 1a. colunas do backup
  for (const c of t.colunas) {
    if (c.descartada || !c.pt) continue;
    const col = { pt: c.pt, legado: c.legado, tipo: c.tipo_pg, nulo: c.nulo, origem: c.origem ?? 'backup', nota: (c.notas ?? []).join('; ') };
    if (c.fk) col.fk = { tabela: c.fk.tabela, correspondencia: c.fk.correspondencia };
    if (c.normalizacao) col.dominio = c.normalizacao.dominio;
    if (c.pt === 'empresa_id' && c.fk === undefined) col.semFk = true;   // logs_auditoria
    adicionar(def, col);
  }
  // 1b. colunas que só o código grava (grupo A e B)
  const cod = codigo.tabelas?.[t.legado];
  for (const [campo, info] of Object.entries(cod?.campos ?? {})) {
    const trad = traduzir(t.legado, campo);
    if (trad === null) continue;
    if (trad === undefined) { erros.push(`sem tradução (código): ${t.legado}.${campo}  [${info.ref ?? ''}]`); continue; }
    const [pt, fkLeg] = Array.isArray(trad) ? trad : [trad, null];
    if (def.colunas.has(pt)) continue;
    const col = { pt, legado: campo, tipo: fkLeg ? 'bigint' : inferirDoExemplo(pt, info.exemplo), nulo: true, origem: 'codigo', nota: `do código legado ${info.ref ?? ''}`.trim() };
    if (fkLeg) {
      const alvo = mapa.tabelas.find((x) => x.legado === fkLeg);
      if (alvo) col.fk = { tabela: alvo.pt };
    }
    adicionar(def, col);
  }
}

// 1c. regras de desenho
for (const def of tabelas.values()) {
  if (def.fase1) continue;
  if (!def.colunas.has('id')) def.colunas.set('id', { pt: 'id', tipo: 'bigint', nulo: false, origem: 'novo' });
  if (!def.global && !def.colunas.has('empresa_id')) {
    def.colunas.set('empresa_id', { pt: 'empresa_id', tipo: 'bigint', nulo: false, origem: 'derivado', fk: { tabela: 'empresas' },
      nota: EMPRESA_DERIVADA[def.pt] ? `derivada de ${EMPRESA_DERIVADA[def.pt].join(' -> ')}` : 'tenant (derivado no ETL)' });
  }
  const emp = def.colunas.get('empresa_id');
  if (emp && !def.global) { emp.nulo = false; emp.tipo = 'bigint'; if (!emp.semFk) emp.fk = { tabela: 'empresas' }; }
  if (emp && def.global) { emp.nulo = true; emp.fk = { tabela: 'empresas' }; }
  for (const c of ['criado_em', 'atualizado_em']) {
    const existente = def.colunas.get(c);
    if (existente) existente.tipo = 'timestamptz';
    else def.colunas.set(c, { pt: c, tipo: 'timestamptz', nulo: true, origem: 'novo' });
  }
  if (def.eliminacaoLogica) def.colunas.set('eliminado_em', { pt: 'eliminado_em', tipo: 'timestamptz', nulo: true, origem: 'novo' });
  for (const col of def.colunas.values()) if (col.fk || (col.pt.endsWith('_id') && col.pt !== 'id' && /^bigint|integer/.test(col.tipo))) col.tipo = 'bigint';
}

// 1c-bis. tamanhos mínimos por semântica: o varchar inferido dos dados (máx. observado × 1,5) é demasiado
// justo para registos novos (ex.: produtos.codigo_conta varchar(10) vs plano_contas.codigo varchar(20)).
const MINIMOS = [
  [/^(codigo_conta|conta_.*|numero_conta|codigo_conta_bancaria)$/, 20],   // = plano_contas.codigo
  [/^(nome|nome_.*|descricao|titulo|designacao)$/, 255],
  [/^(codigo|codigo_.*|numero_.*|referencia.*)$/, 50],
  [/^email$/, 150], [/^(telefone|telefone_.*)$/, 50], [/^nif$/, 30], [/^iban$/, 50],
  [/^(endereco|morada|rua|bairro|municipio|provincia|comuna|cidade|pais|localizacao)$/, 150],
  [/(_original)$/, 100], [/^chave$/, 150],
];
for (const def of tabelas.values()) {
  for (const col of def.colunas.values()) {
    const m = /^varchar\((\d+)\)$/.exec(col.tipo);
    if (!m || col.dominio) continue;
    const minimo = MINIMOS.find(([re]) => re.test(col.pt))?.[1];
    if (minimo && Number(m[1]) < minimo) col.tipo = `varchar(${minimo})`;
  }
}

// 1d. colunas novas, polimórficos e pivôs
for (const [tab, cols] of Object.entries(COLUNAS_NOVAS)) {
  const def = tabelas.get(tab);
  if (!def) { erros.push(`COLUNAS_NOVAS: tabela inexistente ${tab}`); continue; }
  for (const [c, tipo, fkTab, nota] of cols) def.colunas.set(c, { pt: c, tipo, nulo: true, origem: 'novo', nota, ...(fkTab ? { fk: { tabela: fkTab } } : {}) });
}
for (const [tab, p] of Object.entries(POLIMORFICOS)) {
  const def = tabelas.get(tab);
  if (!def?.colunas.has(p.legado.id)) { erros.push(`POLIMORFICOS: ${tab}.${p.legado.id} inexistente`); continue; }
  def.colunas.delete(p.legado.id);
  for (const [cod, [col, alvo]] of Object.entries(p.alvos)) {
    if (!tabelas.has(alvo)) erros.push(`POLIMORFICOS: alvo inexistente ${alvo}`);
    const existente = def.colunas.get(col);
    def.colunas.set(col, { ...(existente ?? {}), pt: col, tipo: 'bigint', nulo: true, origem: existente ? existente.origem : 'derivado', fk: { tabela: alvo }, nota: p.legado.tipo ? `${p.legado.tipo} = ${cod}` : `documento-pai do tipo ${cod}` });
  }
  const lista = Object.values(p.alvos).map(([c]) => `(${c} IS NOT NULL)::int`).join(' + ');
  def.checks.push([`ck_${tab}_documento_origem`, `${lista} <= 1`]);
}
const pivos = [];
for (const p of PIVOS) {
  const [tabDe, colDe] = p.de; const [tabA, colA] = p.a;
  const def = tabelas.get(tabDe);
  const col = def?.colunas.get(colDe);
  if (!col || !tabelas.has(tabA)) { erros.push(`PIVOS: ${tabDe}.${colDe} ou ${tabA} inexistente`); continue; }
  def.colunas.delete(colDe);
  def.colunas.set(`${colDe}_legado`, { ...col, pt: `${colDe}_legado`, tipo: 'text', fk: undefined, nota: `lista de ids do legado; normalizada em ${p.tabela}` });
  pivos.push(p);
  tabelas.set(p.tabela, { pt: p.tabela, legado: null, model: null, modulo: def.modulo, pivo: true, global: false, fase1: false,
    colunas: new Map([
      [p.chave, { pt: p.chave, tipo: 'bigint', nulo: false, origem: 'derivado', fk: { tabela: tabDe }, cascata: true }],
      [colA, { pt: colA, tipo: 'bigint', nulo: false, origem: 'derivado', fk: { tabela: tabA } }],
      ['empresa_id', { pt: 'empresa_id', tipo: 'bigint', nulo: false, origem: 'derivado', fk: { tabela: 'empresas' } }],
      ['criado_em', { pt: 'criado_em', tipo: 'timestamptz', nulo: true, origem: 'novo' }],
    ]), unicos: [], indices: [[colA]], checks: [], chavePrimaria: [p.chave, colA] });
}

// 1e. únicos, índices, checks, domínios
const temCols = (tab, cols) => cols.every((c) => tabelas.get(tab)?.colunas.has(c));
for (const [tab, cols, onde] of UNICOS) {
  if (!tabelas.has(tab) || !temCols(tab, cols)) { erros.push(`UNICOS: ${tab}(${cols}) — tabela/coluna inexistente`); continue; }
  const u = [...cols]; if (onde) u.onde = onde;
  tabelas.get(tab).unicos.push(u);
}
for (const [tab, cols] of [...INDICES, ...INDICES_COM_DUPLICADOS_CONHECIDOS.map(([t, c]) => [t, c])]) {
  if (!tabelas.has(tab) || !temCols(tab, cols)) { erros.push(`INDICES: ${tab}(${cols}) — tabela/coluna inexistente`); continue; }
  tabelas.get(tab).indices.push(cols);
}
for (const [tab, nome, sql] of CHECKS) {
  if (!tabelas.has(tab)) { erros.push(`CHECKS: tabela inexistente ${tab}`); continue; }
  tabelas.get(tab).checks.push([nome, sql]);
}
for (const def of tabelas.values()) {
  for (const col of def.colunas.values()) {
    if (col.dominio) def.checks.push([`ck_${def.pt}_${col.pt}`.slice(0, 63), `${col.pt} IS NULL OR ${col.pt} IN (${col.dominio.map((d) => `'${d}'`).join(',')})`]);
    if (col.fk && !tabelas.has(col.fk.tabela)) erros.push(`FK para tabela inexistente: ${def.pt}.${col.pt} -> ${col.fk.tabela}`);
  }
}
for (const chave of CASCATA) {
  const [tab, col] = chave.split('.');
  const c = tabelas.get(tab)?.colunas.get(col);
  if (!c?.fk) { erros.push(`CASCATA: ${chave} não é uma FK existente`); continue; }
  c.cascata = true;
}

// ── 2. verificação contra os dados reais ───────────────────────────────────────
// Mapeia as linhas do backup para os nomes PT (com normalização) e testa únicos e CHECKs.
function linhasMapeadas(def) {
  const leg = def.legado; if (!leg || !linhasLegado[leg]) return [];
  const t = mapa.tabelas.find((x) => x.pt === def.pt);
  const pais = EMPRESA_DERIVADA[def.pt];
  let empresaDoPai = null;
  if (pais) {
    const tPai = mapa.tabelas.find((x) => x.pt === pais[1]);
    const colFkPai = t.colunas.find((c) => c.pt === pais[0]);
    const empresaCol = tPai.colunas.find((c) => c.pt === 'empresa_id')?.legado;
    const idx = new Map((linhasLegado[tPai.legado] ?? []).map((r) => [String(r.id), r[empresaCol]]));
    empresaDoPai = (r) => idx.get(String(r[colFkPai?.legado]));
  }
  return linhasLegado[leg].map((r) => {
    const o = {};
    for (const c of t.colunas) {
      if (c.descartada || !c.pt || c.origem === 'texto_original' || c.origem === 'derivada') continue;
      let v = r[c.legado];
      if (v === '' || v === undefined) v = null;
      if (c.normalizacao && v !== null) v = c.normalizacao.mapa[dobrar(v)] ?? null;
      o[c.pt] = v;
    }
    if (empresaDoPai && o.empresa_id == null) o.empresa_id = empresaDoPai(r) ?? null;
    return o;
  });
}
// Avalia condições simples "col IN ('A','B')" dos índices parciais sobre linhas mapeadas.
function filtroSql(onde) {
  const m = onde.match(/^(\w+) IN \((.+)\)$/);
  if (!m) throw new Error(`condição não suportada na verificação: ${onde}`);
  const valores = new Set(m[2].split(',').map((v) => v.trim().replace(/^'|'$/g, '')));
  return (l) => valores.has(l[m[1]]);
}
const cacheMapeadas = new Map();
const mapeadas = (def) => (cacheMapeadas.has(def.pt) ? cacheMapeadas.get(def.pt) : (cacheMapeadas.set(def.pt, linhasMapeadas(def)), cacheMapeadas.get(def.pt)));
if (backup) {
  for (const def of tabelas.values()) {
    for (const cols of def.unicos) {
      const vistos = new Map(); let dup = 0; const ex = [];
      const filtro = cols.onde ? filtroSql(cols.onde) : () => true;
      for (const l of mapeadas(def)) {
        if (cols.some((c) => l[c] === null || l[c] === undefined) || !filtro(l)) continue;
        const k = cols.map((c) => String(l[c]).trim().toUpperCase()).join('|');
        if (vistos.has(k)) { dup++; if (ex.length < 3) ex.push(`${k} (ids ${vistos.get(k)},${l.id})`); } else vistos.set(k, l.id);
      }
      if (dup) {
        // plano_contas: 1 duplicado conhecido tratado pelo ETL (quarentena da 2.ª ocorrência) — ADR-005
        const tolerado = def.pt === 'plano_contas' && dup === 1;
        (tolerado ? avisos : erros).push(`ÚNICO violado pelo backup: ${def.pt}(${cols}) — ${dup} duplicados: ${ex.join('; ')}${tolerado ? ' [tratado pelo ETL: quarentena]' : ''}`);
      }
    }
    for (const [tab, nome, , pred] of CHECKS) {
      if (tab !== def.pt) continue;
      const falhas = mapeadas(def).filter((l) => !pred(l));
      if (falhas.length) erros.push(`CHECK ${nome} violado por ${falhas.length} linhas (ex.: id ${falhas[0].id})`);
    }
  }
}

// ── 3. relações dos models ─────────────────────────────────────────────────────
const modelDe = (tab) => tabelas.get(tab)?.model;
const relacoes = new Map();   // pt -> { belongsTo: [], hasMany: [] }
for (const def of tabelas.values()) relacoes.set(def.pt, { belongsTo: [], hasMany: [] });
for (const def of tabelas.values()) {
  if (!def.model) continue;
  const fks = [...def.colunas.values()].filter((c) => c.fk && c.pt !== 'empresa_id');
  for (const c of fks) {
    const alvo = tabelas.get(c.fk.tabela);
    if (!alvo?.model) continue;
    let nome = camel(semId(c.pt));
    if (def.colunas.has(semId(c.pt))) nome += 'Relacao';
    relacoes.get(def.pt).belongsTo.push({ nome, model: alvo.model, coluna: c.pt });
    if (alvo.pt === 'empresas' || alvo.pt === 'utilizadores') continue;
    const mesmas = fks.filter((x) => x.fk.tabela === c.fk.tabela).length;
    let nomeInv = camel(def.pt);
    if (mesmas > 1 || def.pt === alvo.pt) nomeInv += 'Por' + studly(semId(c.pt));
    relacoes.get(alvo.pt).hasMany.push({ nome: nomeInv, model: def.model, coluna: c.pt });
  }
}
for (const [pt, r] of relacoes) {   // evitar colisões de nomes dentro do mesmo model
  const usados = new Set(['empresa', ...[...(tabelas.get(pt)?.colunas.keys() ?? [])].map(camel)]);
  for (const rel of [...r.belongsTo, ...r.hasMany]) {
    let n = rel.nome; let i = 2;
    while (usados.has(n)) n = `${rel.nome}${i++}`;
    rel.nome = n; usados.add(n);
  }
}

// ── 4. emissão ─────────────────────────────────────────────────────────────────
if (erros.length) {
  console.error(`\n${erros.length} ERRO(S):\n  ` + erros.join('\n  '));
  if (avisos.length) console.error(`\nAVISOS:\n  ` + avisos.join('\n  '));
  process.exit(2);
}

const phpStr = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
function blueprint(col) {
  const t = col.tipo;
  let m;
  let expr;
  if (col.pt === 'id') return "            $table->id();";
  if (t === 'bigint') expr = `$table->bigInteger(${phpStr(col.pt)})`;
  else if (t === 'integer') expr = `$table->integer(${phpStr(col.pt)})`;
  else if ((m = t.match(/^numeric\((\d+),(\d+)\)$/))) expr = `$table->decimal(${phpStr(col.pt)}, ${m[1]}, ${m[2]})`;
  else if ((m = t.match(/^varchar\((\d+)\)$/))) expr = `$table->string(${phpStr(col.pt)}, ${m[1]})`;
  else if (t === 'text') expr = `$table->text(${phpStr(col.pt)})`;
  else if (t === 'jsonb') expr = `$table->jsonb(${phpStr(col.pt)})`;
  else if (t === 'boolean') expr = `$table->boolean(${phpStr(col.pt)})`;
  else if (t === 'date') expr = `$table->date(${phpStr(col.pt)})`;
  else if (t === 'timestamptz') expr = `$table->timestampTz(${phpStr(col.pt)})`;
  else throw new Error(`tipo não suportado: ${t} (${col.pt})`);
  if (col.nulo !== false) expr += '->nullable()';
  if (['criado_em', 'atualizado_em'].includes(col.pt)) expr += '->useCurrent()';
  if (col.nota || col.legado) expr += `->comment(${phpStr([col.legado ? `legado: ${col.legado}` : null, col.nota].filter(Boolean).join(' · ').slice(0, 250))})`;
  return `            ${expr};`;
}
const nomeIndice = (prefixo, tab, cols) => {
  const n = `${prefixo}_${tab}_${cols.join('_')}`;
  return n.length <= 63 ? n : `${prefixo}_${tab.slice(0, 30)}_${cols.map((c) => c.slice(0, 8)).join('_')}`.slice(0, 63);
};

const ORDEM_COLUNAS = (a, b) => {
  const peso = (c) => (c.pt === 'id' ? 0 : c.pt === 'empresa_id' ? 1 : ['criado_em', 'atualizado_em', 'eliminado_em'].includes(c.pt) ? 9 : 5);
  return peso(a) - peso(b);
};

const modulos = new Map();
for (const def of tabelas.values()) {
  if (def.fase1) continue;
  if (!modulos.has(def.modulo)) modulos.set(def.modulo, []);
  modulos.get(def.modulo).push(def);
}

const dirMig = path.join(BACKEND, 'database/migrations');
for (const f of fs.readdirSync(dirMig)) if (/^2026_09_30_\d{6}_/.test(f)) fs.unlinkSync(path.join(dirMig, f));   // regenerar

let seq = 100000;
for (const [modulo, defs] of modulos) {
  seq += 100;
  const nome = `2026_09_30_${seq}_criar_tabelas_${slug(modulo)}.php`;
  const ups = []; const downs = [];
  for (const def of defs) {
    const cols = [...def.colunas.values()].sort(ORDEM_COLUNAS);
    const linhas = cols.filter((c) => !(def.chavePrimaria && c.pt === 'id')).map(blueprint);
    if (def.chavePrimaria) linhas.push(`            $table->primary([${def.chavePrimaria.map(phpStr).join(', ')}]);`);
    const extra = [];
    for (const u of def.unicos) {
      const n = nomeIndice('uq', def.pt, u);
      const condicoes = [def.eliminacaoLogica && 'eliminado_em IS NULL', u.onde].filter(Boolean);
      extra.push(`        DB::statement(${phpStr(`CREATE UNIQUE INDEX ${n} ON ${def.pt} (${u.join(', ')})${condicoes.length ? ` WHERE ${condicoes.join(' AND ')}` : ''}`)});`);
    }
    const indexados = new Set(def.unicos.map((u) => u[0]));
    for (const c of cols) if ((c.fk || c.pt === 'empresa_id') && !indexados.has(c.pt) && !(def.chavePrimaria && def.chavePrimaria[0] === c.pt)) {
      extra.push(`        DB::statement('CREATE INDEX ${nomeIndice('ix', def.pt, [c.pt])} ON ${def.pt} (${c.pt})');`);
    }
    for (const i of def.indices) extra.push(`        DB::statement('CREATE INDEX ${nomeIndice('ix', def.pt, i)} ON ${def.pt} (${i.join(', ')})');`);
    for (const [n, sql] of def.checks) extra.push(`        DB::statement(${phpStr(`ALTER TABLE ${def.pt} ADD CONSTRAINT ${n.slice(0, 63)} CHECK (${sql})`)});`);
    const titulo = def.legado ? `${def.legado} (legado) -> ${def.pt}` : `${def.pt} (${def.pivo ? 'pivô' : 'tabela nova'})`;
    ups.push(`        // ${titulo}${def.linhasReais !== undefined ? ` · ${def.linhasReais} linhas reais no backup` : ''}${def.eliminacaoLogica ? ' · eliminação lógica' : ''}
        Schema::create('${def.pt}', function (Blueprint $table) {
${linhas.join('\n')}
        });
${[...new Set(extra)].join('\n')}${extra.length ? '\n' : ''}`);
    downs.unshift(`        Schema::dropIfExists('${def.pt}');`);
  }
  fs.writeFileSync(path.join(dirMig, nome), `<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

/**
 * Módulo ${modulo} — GERADO por ferramentas/gerador/gerar_esquema.mjs a partir de docs/dicionario/mapa_de_para.json.
 * Não editar à mão: alterar glossario.mjs / esquema_extra.mjs e regenerar.
 * As chaves estrangeiras são criadas em 2026_09_30_900000_criar_chaves_estrangeiras.php.
 */
return new class extends Migration
{
    public function up(): void
    {
${ups.join('\n')}    }

    public function down(): void
    {
${downs.join('\n')}
    }
};
`);
}

// FKs (DEFERRABLE: o ETL carrega com SET CONSTRAINTS ALL DEFERRED e a integridade é validada no COMMIT)
const fks = [];
for (const def of tabelas.values()) {
  if (def.fase1) continue;
  for (const c of def.colunas.values()) {
    if (!c.fk || c.semFk) continue;
    const n = nomeIndice('fk', def.pt, [c.pt]);
    fks.push([def.pt, n, `ALTER TABLE ${def.pt} ADD CONSTRAINT ${n} FOREIGN KEY (${c.pt}) REFERENCES ${c.fk.tabela} (id) ON DELETE ${c.cascata ? 'CASCADE' : 'RESTRICT'} DEFERRABLE INITIALLY IMMEDIATE`]);
  }
}
// FKs das tabelas da Fase 1 para tabelas criadas agora
fks.push(['empresas', 'fk_empresas_execucao_consolidacao_id', 'ALTER TABLE empresas ADD CONSTRAINT fk_empresas_execucao_consolidacao_id FOREIGN KEY (execucao_consolidacao_id) REFERENCES execucoes_consolidacao (id) ON DELETE SET NULL DEFERRABLE INITIALLY IMMEDIATE']);
fks.push(['utilizador_empresa', 'fk_utilizador_empresa_colaborador_id', 'ALTER TABLE utilizador_empresa ADD CONSTRAINT fk_utilizador_empresa_colaborador_id FOREIGN KEY (colaborador_id) REFERENCES colaboradores (id) ON DELETE SET NULL DEFERRABLE INITIALLY IMMEDIATE']);
fs.writeFileSync(path.join(dirMig, '2026_09_30_900000_criar_chaves_estrangeiras.php'), `<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;

/**
 * Todas as chaves estrangeiras (${fks.length}) — GERADO por ferramentas/gerador/gerar_esquema.mjs.
 * DEFERRABLE INITIALLY IMMEDIATE: comportamento normal na aplicação; o ETL adia a verificação para o COMMIT
 * (SET CONSTRAINTS ALL DEFERRED) e a transacção falha se sobrar algum órfão.
 * RESTRICT para entidades; CASCADE só para linhas/itens do próprio documento.
 */
return new class extends Migration
{
    public function up(): void
    {
${fks.map(([, , sql]) => `        DB::statement(${phpStr(sql)});`).join('\n')}
    }

    public function down(): void
    {
${fks.map(([tab, n]) => `        DB::statement('ALTER TABLE ${tab} DROP CONSTRAINT IF EXISTS ${n}');`).join('\n')}
    }
};
`);

// Models
const dirModels = path.join(BACKEND, 'app/Models');
const dirBase = path.join(dirModels, 'Base');
fs.rmSync(dirBase, { recursive: true, force: true });
fs.mkdirSync(dirBase, { recursive: true });
const cast = (tipo) => {
  if (tipo === 'bigint' || tipo === 'integer') return 'integer';
  const m = tipo.match(/^numeric\(\d+,(\d+)\)$/); if (m) return `decimal:${m[1]}`;
  if (tipo === 'boolean') return 'boolean';
  if (tipo === 'jsonb') return 'array';
  if (tipo === 'date') return 'date';
  if (tipo === 'timestamptz') return 'datetime';
  return null;
};
let modelsCriados = 0;
for (const def of tabelas.values()) {
  if (def.fase1 || !def.model) continue;
  const cols = [...def.colunas.values()].sort(ORDEM_COLUNAS);
  const tenant = !def.global && def.colunas.has('empresa_id');
  const semAuditoria = /^logs_|^ocorrencias_migracao$|^quarentena_migracao$/.test(def.pt);
  const traits = [tenant && 'PertenceEmpresa', !semAuditoria && 'Auditavel', def.eliminacaoLogica && 'SoftDeletes'].filter(Boolean);
  const imports = new Set(['App\\Models\\ModeloBase']);
  if (tenant) imports.add('App\\Models\\Concerns\\PertenceEmpresa');
  if (!semAuditoria) imports.add('App\\Models\\Concerns\\Auditavel');
  if (def.eliminacaoLogica) imports.add('Illuminate\\Database\\Eloquent\\SoftDeletes');
  const rel = relacoes.get(def.pt);
  if (rel.belongsTo.length || (def.global && def.colunas.has('empresa_id'))) imports.add('Illuminate\\Database\\Eloquent\\Relations\\BelongsTo');
  if (rel.hasMany.length) imports.add('Illuminate\\Database\\Eloquent\\Relations\\HasMany');
  const fillable = cols.filter((c) => !['id', 'criado_em', 'atualizado_em', 'eliminado_em'].includes(c.pt)).map((c) => phpStr(c.pt));
  const casts = cols.filter((c) => c.pt !== 'id' && cast(c.tipo)).map((c) => `            ${phpStr(c.pt)} => ${phpStr(cast(c.tipo))},`);
  const metodos = [];
  if (def.global && def.colunas.has('empresa_id')) metodos.push(`    public function empresa(): BelongsTo\n    {\n        return $this->belongsTo(\\App\\Models\\Empresa::class, 'empresa_id');\n    }`);
  for (const r of rel.belongsTo) metodos.push(`    public function ${r.nome}(): BelongsTo\n    {\n        return $this->belongsTo(\\App\\Models\\${r.model}::class, '${r.coluna}');\n    }`);
  for (const r of rel.hasMany) metodos.push(`    public function ${r.nome}(): HasMany\n    {\n        return $this->hasMany(\\App\\Models\\${r.model}::class, '${r.coluna}');\n    }`);
  const desc = def.legado ? `Legado: ${def.legado} · ${def.linhasReais ?? 0} linhas reais no backup.` : 'Tabela nova do desenho.';
  fs.writeFileSync(path.join(dirBase, `${def.model}Base.php`), `<?php

namespace App\\Models\\Base;

${[...imports].sort().map((i) => `use ${i};`).join('\n')}

/**
 * Tabela ${def.pt} (módulo ${def.modulo}). ${desc}
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\\Models\\${def.model}.
 */
abstract class ${def.model}Base extends ModeloBase
{
${traits.length ? `    use ${traits.join(', ')};\n\n` : ''}    protected $table = '${def.pt}';
${semAuditoria ? '' : `\n    protected string $moduloAuditoria = ${phpStr(def.modulo)};\n`}
    protected $fillable = [
        ${fillable.join(', ')},
    ];

    protected function casts(): array
    {
        return [
${casts.join('\n')}
        ];
    }
${metodos.length ? '\n' + metodos.join('\n\n') + '\n' : ''}}
`);
  const concreto = path.join(dirModels, `${def.model}.php`);
  if (!fs.existsSync(concreto)) {
    modelsCriados++;
    fs.writeFileSync(concreto, `<?php

namespace App\\Models;

use App\\Models\\Base\\${def.model}Base;

/**
 * ${def.pt} — ${def.endpoint ?? 'sem endpoint próprio'}.
 * Regras de negócio e relações adicionais vêm aqui; a estrutura está em ${def.model}Base (gerado).
 */
class ${def.model} extends ${def.model}Base
{
}
`);
  }
}

// Contrato do esquema (ETL + teste de contrato)
const dirLegado = path.join(BACKEND, 'database/legado');
fs.mkdirSync(dirLegado, { recursive: true });
const contrato = {
  gerado_em: new Date().toISOString(),
  tabelas: [...tabelas.values()].filter((d) => !d.fase1).map((d) => ({
    tabela: d.pt, legado: d.legado, model: d.model, modulo: d.modulo, global: d.global, pivo: !!d.pivo,
    empresa_derivada_de: EMPRESA_DERIVADA[d.pt] ?? null, eliminacao_logica: d.eliminacaoLogica,
    colunas: [...d.colunas.values()].sort(ORDEM_COLUNAS).map((c) => ({ coluna: c.pt, legado: c.legado ?? null, tipo: c.tipo, nulo: c.nulo !== false, origem: c.origem,
      fk: c.fk ? { tabela: c.fk.tabela, cascata: !!c.cascata } : null, dominio: c.dominio ?? null })),
    unicos: d.unicos.map((u) => ({ colunas: [...u], onde: u.onde ?? null })), checks: d.checks.map(([n]) => n),
  })),
  polimorficos: POLIMORFICOS, pivos: PIVOS,
};
fs.writeFileSync(path.join(dirLegado, 'esquema.json'), JSON.stringify(contrato, null, 2));
fs.copyFileSync(path.join(RAIZ, 'docs/dicionario/mapa_de_para.json'), path.join(dirLegado, 'mapa_de_para.json'));

const nCols = contrato.tabelas.reduce((a, t) => a + t.colunas.length, 0);
console.log(JSON.stringify({ tabelas: contrato.tabelas.length, colunas: nCols, migrations_modulo: modulos.size, fks: fks.length,
  unicos: contrato.tabelas.reduce((a, t) => a + t.unicos.length, 0), checks: contrato.tabelas.reduce((a, t) => a + t.checks.length, 0),
  models_base: contrato.tabelas.filter((t) => t.model).length, models_concretos_criados: modelsCriados }, null, 2));
if (avisos.length) console.log('AVISOS:\n  ' + avisos.join('\n  '));
