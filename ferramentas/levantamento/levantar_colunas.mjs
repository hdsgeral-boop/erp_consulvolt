// Levantamento exaustivo das colunas do backup Dexie do ERP legado.
// Uso: node ferramentas/levantamento/levantar_colunas.mjs <backup.json> <saida.json>
//
// Para cada tabela e coluna regista: preenchimento (linhas reais vs linhas "dado mestre"),
// tipos JS observados, comprimento máximo, intervalo numérico, padrões (data ISO, booleano 0/1,
// lista separada por vírgulas), cardinalidade e amostras. Colunas que só existem em linhas
// is_master_data=1 são marcadas como "enchimento" (padding do Dexie) e não migram.

import fs from 'node:fs';

const [, , caminhoBackup, caminhoSaida] = process.argv;
if (!caminhoBackup || !caminhoSaida) {
  console.error('Uso: node levantar_colunas.mjs <backup.json> <saida.json>');
  process.exit(1);
}

const backup = JSON.parse(fs.readFileSync(caminhoBackup, 'utf8'));
if (backup.formatName !== 'dexie') throw new Error(`Formato inesperado: ${backup.formatName}`);

const RE_DATA = /^\d{4}-\d{2}-\d{2}$/;
const RE_DATAHORA = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/;
const RE_DATA_PT = /^\d{2}\/\d{2}\/\d{4}$/;
const RE_INTEIRO = /^-?\d+$/;
const RE_DECIMAL = /^-?\d+([.,]\d+)?$/;
const MAX_AMOSTRAS = 5;
const MAX_DISTINTOS = 40;

// Linhas is_master_data=1 são placeholders ("REGISTO MESTRE OBRIGATÓRIO") criados pelo legado,
// um por empresa em cada tabela, e nenhuma linha real as referencia. Excepção (decisão 2026-09-29):
// em `companies` a flag marca empresas reais (10 SUNNA, 18 teste de qualidade), que se mantêm;
// só a 11 "SISTEMA - DADO MESTRE" é fictícia.
const EMPRESAS_REAIS_COM_FLAG_MESTRE = new Set([10, 18]);
const eDummy = (tabela, linha) =>
  linha?.is_master_data == 1 && !(tabela === 'companies' && EMPRESAS_REAIS_COM_FLAG_MESTRE.has(linha.id));

function tipoDe(v) {
  if (v === null) return 'null';
  if (Array.isArray(v)) return 'array';
  if (typeof v === 'string') {
    if (v === '') return 'string_vazia';
    if (RE_DATAHORA.test(v)) return 'string_datahora';
    if (RE_DATA.test(v)) return 'string_data';
    if (RE_DATA_PT.test(v)) return 'string_data_pt';
    if (RE_INTEIRO.test(v)) return 'string_inteiro';
    if (RE_DECIMAL.test(v)) return 'string_decimal';
    return 'string';
  }
  if (typeof v === 'number') return Number.isInteger(v) ? 'inteiro' : 'decimal';
  return typeof v; // boolean, object
}

const resultado = { gerado_em: new Date().toISOString(), origem: caminhoBackup, tabelas: {} };
let totalLinhas = 0, totalDummy = 0, totalMasterMantidas = 0;

for (const t of backup.data.data) {
  const nome = t.tableName;
  const linhas = t.rows ?? [];
  const reais = [], dummies = [];
  for (const l of linhas) (eDummy(nome, l) ? dummies : reais).push(l);
  const masterMantidas = reais.filter((l) => l?.is_master_data == 1).length;
  totalLinhas += linhas.length; totalDummy += dummies.length; totalMasterMantidas += masterMantidas;

  const colunas = {};
  const reg = (linha, real) => {
    for (const [k, v] of Object.entries(linha)) {
      const c = (colunas[k] ??= {
        linhas_reais: 0, preenchidas_reais: 0, linhas_dummy: 0, tipos: {}, max_comprimento: 0,
        min: null, max: null, distintos: new Map(), amostras: [], lista_virgulas: 0, tipos_dexie: {},
      });
      if (!real) { c.linhas_dummy++; continue; }
      c.linhas_reais++;
      const tp = tipoDe(v);
      c.tipos[tp] = (c.tipos[tp] ?? 0) + 1;
      if (v === null || v === undefined || v === '') continue;
      c.preenchidas_reais++;
      const s = typeof v === 'object' ? JSON.stringify(v) : String(v);
      c.max_comprimento = Math.max(c.max_comprimento, s.length);
      const n = typeof v === 'number' ? v : (RE_DECIMAL.test(s) ? Number(s.replace(',', '.')) : null);
      if (n !== null && Number.isFinite(n)) { c.min = c.min === null ? n : Math.min(c.min, n); c.max = c.max === null ? n : Math.max(c.max, n); }
      if (typeof v === 'string' && /^\s*\d+\s*(,\s*\d+\s*)+$/.test(v)) c.lista_virgulas++;
      if (c.distintos.size <= MAX_DISTINTOS) { const key = s.slice(0, 80); c.distintos.set(key, (c.distintos.get(key) ?? 0) + 1); }
      if (c.amostras.length < MAX_AMOSTRAS && !c.amostras.includes(s.slice(0, 120))) c.amostras.push(s.slice(0, 120));
    }
    // Tipos serializados pelo Dexie (ex.: {"third_party_id":"undef"}, datas, blobs)
    if (real && linha.$types) for (const [k, tp] of Object.entries(linha.$types)) {
      const c = colunas[k]; if (c) c.tipos_dexie[tp] = (c.tipos_dexie[tp] ?? 0) + 1;
    }
  };
  reais.forEach((l) => reg(l, true));
  dummies.forEach((l) => reg(l, false));

  const saidaCols = {};
  for (const [k, c] of Object.entries(colunas)) {
    const distintos = c.distintos.size > MAX_DISTINTOS ? null : Object.fromEntries([...c.distintos].sort((a, b) => b[1] - a[1]));
    saidaCols[k] = {
      linhas_reais: c.linhas_reais,
      preenchidas_reais: c.preenchidas_reais,
      taxa_preenchimento: reais.length ? +(c.preenchidas_reais / reais.length).toFixed(4) : 0,
      so_em_dummy: c.linhas_reais === 0,
      tipos: c.tipos,
      tipos_dexie: Object.keys(c.tipos_dexie).length ? c.tipos_dexie : undefined,
      max_comprimento: c.max_comprimento,
      min: c.min, max: c.max,
      lista_virgulas: c.lista_virgulas || undefined,
      cardinalidade: distintos ? Object.keys(distintos).length : `>${MAX_DISTINTOS}`,
      distintos: distintos && Object.keys(distintos).length <= 25 ? distintos : undefined,
      amostras: c.amostras,
    };
  }
  resultado.tabelas[nome] = {
    linhas_total: linhas.length, linhas_reais: reais.length, linhas_dummy: dummies.length,
    linhas_master_mantidas: masterMantidas, colunas: saidaCols,
  };
}

resultado.resumo = {
  tabelas: Object.keys(resultado.tabelas).length,
  linhas_total: totalLinhas,
  linhas_dummy_descartadas: totalDummy,
  linhas_reais_a_migrar: totalLinhas - totalDummy,
  linhas_master_mantidas: totalMasterMantidas,
  colunas_total: Object.values(resultado.tabelas).reduce((a, t) => a + Object.keys(t.colunas).length, 0),
  colunas_reais: Object.values(resultado.tabelas).reduce((a, t) => a + Object.values(t.colunas).filter((c) => !c.so_em_dummy).length, 0),
};

fs.writeFileSync(caminhoSaida, JSON.stringify(resultado, null, 2));
console.log(JSON.stringify(resultado.resumo, null, 2));
