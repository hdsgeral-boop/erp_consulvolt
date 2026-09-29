// Gera o dicionário de dados DE/PARA (fonte única para migrations, models e ETL).
// Uso: node ferramentas/levantamento/gerar_dicionario.mjs <backup.json> <levantamento.json> <dir_saida>
//
// Saídas:
//   mapa_de_para.json      -> consumido pelo gerador de migrations e pelo comando erp:migrar-backup-legado
//   DICIONARIO_DADOS.md    -> documentação legível
//   pendencias.json        -> colunas sem tradução, colisões, FKs fracas, enumerações a normalizar
//
// O processo falha (exit 2) se existir alguma coluna real sem tradução ou colisão de nomes.

import fs from 'node:fs';
import path from 'node:path';
import { GLOSSARIO, SOBREPOSICOES, TIPOS_FORCADOS } from './glossario.mjs';
import { TABELAS } from './tabelas.mjs';
import { NORMALIZACOES, dobrar } from './normalizacoes.mjs';
import { REGRAS } from './regras_integridade.mjs';

const [, , caminhoBackup, caminhoLevantamento, dirSaida] = process.argv;
if (!caminhoBackup || !caminhoLevantamento || !dirSaida) {
  console.error('Uso: node gerar_dicionario.mjs <backup.json> <levantamento.json> <dir_saida>');
  process.exit(1);
}

const backup = JSON.parse(fs.readFileSync(caminhoBackup, 'utf8'));
const lev = JSON.parse(fs.readFileSync(caminhoLevantamento, 'utf8'));
const EMPRESAS_REAIS_COM_FLAG_MESTRE = new Set([10, 18]);
const eDummy = (t, l) => l?.is_master_data == 1 && !(t === 'companies' && EMPRESAS_REAIS_COM_FLAG_MESTRE.has(l.id));
const linhasReais = {};
for (const t of backup.data.data) linhasReais[t.tableName] = (t.rows ?? []).filter((l) => !eDummy(t.tableName, l));

const porLegado = Object.fromEntries(TABELAS.map(([leg, pt, model, modulo, endpoint]) => [leg, { pt, model, modulo, endpoint }]));
const faltamNaMatriz = Object.keys(lev.tabelas).filter((t) => !porLegado[t]);
const faltamNoBackup = TABELAS.map((t) => t[0]).filter((t) => !lev.tabelas[t]);
if (faltamNaMatriz.length || faltamNoBackup.length) {
  console.error('Matriz e backup divergem:', { faltamNaMatriz, faltamNoBackup });
  process.exit(2);
}

// ── Inferência de tipos PostgreSQL ─────────────────────────────────────────────
const RE_MONETARIO = /(^|_)(valor|montante|total|preco|custo|saldo|subtotal|desconto|troco|numerario|comissao|pago|pendente|amortizacao|acumulad|residual|quota|fundo_maneio|vendas_numerario|imposto|diferenca|kz|irt|bonificacao|cambial_.*kz|cambial_v\d|cambial_saldo)/;
const RE_QUANTIDADE = /(^|_)(quantidade|qtd|horas|dias_trabalhados|cambial_q\d)/;
const RE_TAXA = /(^|_)(taxa|percentagem|pct|probabilidade|peso|rate|inflacao|crescimento)/;
const RE_BOOLEANO_NOME = /^(e_|tem_|ativo$|ativa$|predefinido|bloqueado|importado|validado|urgente|automatica|contabilizado|reformado|avencado|movimenta_stock|concluida$|detectada|dependente_fiscal|ocultar_|eliminacao_ativa|lavandaria_ativa|lavandaria_requer|documento_em_balanco|principal$|taxa_cambio_manual|pendente_no_fecho)/;
const RE_TEXTO_LONGO = /(logotipo|imagem|conteudo_ficheiro|descricao|observacoes|notas|detalhes|regras_ia|corpo|comentario|feedback|justificacao|deliberacao|resolucao|missao|responsabilidades|textos|rodape|historico|endereco|morada|melhorar|positivos|dificuldades|realizacoes|motivo)/;

function inferirTipo(colPt, info, eFk) {
  if (colPt === 'id') return { tipo: 'bigint', nota: 'PK preservada do backup' };
  if (eFk) return { tipo: 'bigint' };
  const t = info.tipos;
  const n = (k) => t[k] ?? 0;
  const naoVazios = Object.entries(t).filter(([k]) => !['null', 'string_vazia', 'undefined'].includes(k));
  const soVazios = naoVazios.length === 0;
  const tem = (...ks) => ks.some((k) => n(k) > 0);
  const notas = [];
  if (naoVazios.length > 1) notas.push(`tipos mistos: ${naoVazios.map(([k, v]) => `${k}=${v}`).join(', ')}`);

  if (tem('array', 'object')) return { tipo: 'jsonb', notas };
  if (RE_BOOLEANO_NOME.test(colPt) || (tem('boolean') && !tem('string', 'decimal'))) {
    const inteirosSoBinarios = !tem('inteiro') || (info.min >= 0 && info.max <= 1);
    if (inteirosSoBinarios && !tem('string', 'string_data', 'string_datahora', 'decimal')) return { tipo: 'boolean', notas };
  }
  if (tem('string_datahora')) return { tipo: 'timestamptz', notas };
  if (tem('string_data', 'string_data_pt') && !tem('string', 'inteiro', 'decimal')) return { tipo: 'date', notas };
  const numerico = tem('inteiro', 'decimal', 'string_inteiro', 'string_decimal') && !tem('string', 'boolean', 'string_data', 'string_datahora');
  if (numerico || (soVazios && (RE_MONETARIO.test(colPt) || RE_QUANTIDADE.test(colPt) || RE_TAXA.test(colPt)))) {
    if (/^(codigo|numero_|nif|iban|telefone|codigo_conta|conta_|referencia|documento|registo_id|uid|lote_codigo|reconciliacao_codigo|numero_lan$)/.test(colPt)
        && colPt !== 'numero_hospedes' && colPt !== 'numero_vendas' && !/^numero_(lan)$/.test(colPt)) {
      return { tipo: `varchar(${tamanhoVarchar(info.max_comprimento)})`, notas };
    }
    if (colPt === 'taxa_cambio' || /^taxa_(compra|venda)_bai$/.test(colPt)) return { tipo: 'numeric(18,6)', notas };
    if (RE_TAXA.test(colPt)) return { tipo: 'numeric(9,4)', notas };
    if (RE_QUANTIDADE.test(colPt)) return { tipo: 'numeric(12,3)', notas };
    if (RE_MONETARIO.test(colPt) || tem('decimal', 'string_decimal')) {
      const maxAbs = Math.max(Math.abs(info.min ?? 0), Math.abs(info.max ?? 0));
      if (maxAbs >= 1e13) { notas.push(`valor máximo ${maxAbs} excede NUMERIC(15,2)`); return { tipo: 'numeric(20,2)', notas }; }
      return { tipo: 'numeric(15,2)', notas };
    }
    const maxAbs = Math.max(Math.abs(info.min ?? 0), Math.abs(info.max ?? 0));
    return { tipo: maxAbs > 2147483647 ? 'bigint' : 'integer', notas };
  }
  if (soVazios) return { tipo: 'text', notas: [...notas, 'sem valores reais: tipo a confirmar no código legado'] };
  if (RE_TEXTO_LONGO.test(colPt) || info.max_comprimento > 255) return { tipo: 'text', notas };
  return { tipo: `varchar(${tamanhoVarchar(info.max_comprimento)})`, notas };
}

function tamanhoVarchar(max) {
  for (const b of [10, 20, 30, 50, 100, 150, 200, 255]) if (max * 1.5 <= b) return b;
  return 255;
}

// ── Resolução das colunas ─────────────────────────────────────────────────────
const semTraducao = [], colisoes = [], fksFracas = [], enumeracoes = [], esquemaDoCodigo = [];
const saida = { gerado_em: new Date().toISOString(), convencoes: {
  timestamps: { criado_em: 'CREATED_AT', atualizado_em: 'UPDATED_AT', eliminado_em: 'DELETED_AT' },
  tenant: 'empresa_id', dinheiro: 'numeric(15,2)', quantidade: 'numeric(12,3)', taxa: 'numeric(9,4)', cambio: 'numeric(18,6)',
}, tabelas: [] };

const normalizacoesSemCobertura = [];

for (const [leg, meta] of Object.entries(porLegado)) {
  const lt = lev.tabelas[leg];
  const cols = Object.entries(lt.colunas).filter(([, i]) => !i.so_em_dummy);
  if (lt.linhas_reais === 0) esquemaDoCodigo.push(leg);
  const destinos = new Map();
  const colunas = [];

  for (const [cLeg, info] of cols) {
    const sob = SOBREPOSICOES[leg] ?? {};
    const def = cLeg in sob ? sob[cLeg] : GLOSSARIO[cLeg];
    if (def === undefined) { semTraducao.push(`${leg}.${cLeg}`); continue; }
    if (def === null) { colunas.push({ legado: cLeg, pt: null, descartada: true }); continue; }
    const [pt, fkLeg] = Array.isArray(def) ? def : [def, null];
    if (destinos.has(pt)) { colisoes.push(`${leg}: ${destinos.get(pt)} e ${cLeg} -> ${pt}`); continue; }
    destinos.set(pt, cLeg);

    const { tipo, nota, notas = [] } = inferirTipo(pt, info, !!fkLeg);
    const tipoForcado = TIPOS_FORCADOS[`${leg}.${cLeg}`];
    if (tipoForcado) notas.push(`tipo forçado (inferido: ${tipo})`);
    const col = {
      legado: cLeg, pt, tipo_pg: tipoForcado ?? tipo,
      nulo: !(pt === 'id' || (pt === 'empresa_id' && info.taxa_preenchimento === 1)),
      preenchimento: info.taxa_preenchimento, max_comprimento: info.max_comprimento,
      notas: [...(nota ? [nota] : []), ...notas],
    };
    if (info.tipos_dexie) col.notas.push(`tipos Dexie: ${JSON.stringify(info.tipos_dexie)}`);
    if (info.lista_virgulas) col.notas.push(`${info.lista_virgulas} valores são listas separadas por vírgulas -> tabela pivô`);

    if (fkLeg) {
      const alvo = porLegado[fkLeg];
      const idsAlvo = new Set((linhasReais[fkLeg] ?? []).map((l) => String(l.id)));
      const valores = linhasReais[leg].map((l) => l[cLeg]).filter((v) => v !== null && v !== undefined && v !== '' && v !== 0 && v !== '0');
      const ok = valores.filter((v) => idsAlvo.has(String(v).trim())).length;
      const pct = valores.length ? +(ok / valores.length).toFixed(4) : null;
      col.fk = { tabela_legado: fkLeg, tabela: alvo.pt, coluna: 'id', correspondencia: pct, orfaos: valores.length - ok,
        on_delete: pt === 'empresa_id' ? 'restrict' : 'restrict' };
      if (valores.some((v) => v === 0 || v === '0')) col.notas.push('valores 0 tratados como NULL');
      if (pct !== null && pct < 1) fksFracas.push({ tabela: leg, coluna: cLeg, alvo: fkLeg, correspondencia: pct, orfaos: valores.length - ok, amostra_orfaos: [...new Set(valores.filter((v) => !idsAlvo.has(String(v).trim())).map(String))].slice(0, 8) });
    }

    // Enumerações textuais com variantes de grafia/caixa -> normalizar para código + *_original
    if (info.distintos && tipo.startsWith('varchar') && !fkLeg) {
      const grupos = {};
      for (const [v, c] of Object.entries(info.distintos)) (grupos[dobrar(v)] ??= []).push([v, c]);
      const variantes = Object.values(grupos).filter((g) => g.length > 1);
      const nomeEnum = /(^|_)(tipo|estado|natureza|modo|metodo|papel|sexo|estado_civil|categoria|unidade|sentido|origem|tipo_dc|parentesco)($|_)/.test(pt);
      if (variantes.length || (nomeEnum && Object.keys(info.distintos).length <= 25)) {
        enumeracoes.push({ tabela: leg, coluna: cLeg, pt, variantes_grafia: variantes.length, distintos: info.distintos });
      }
    }
    colunas.push(col);

    const norm = NORMALIZACOES[`${leg}.${cLeg}`];
    if (norm) {
      col.normalizacao = { dominio: norm.dominio, mapa: norm.mapa };
      col.tipo_pg = `varchar(${Math.max(20, ...norm.dominio.map((d) => d.length + 5))})`;
      col.notas.push(`código normalizado ∈ {${norm.dominio.join(', ')}}; texto original em ${pt}_original`);
      const naoCobertos = Object.keys(info.distintos ?? {}).filter((v) => !(dobrar(v) in norm.mapa));
      if (naoCobertos.length) normalizacoesSemCobertura.push(`${leg}.${cLeg}: ${naoCobertos.join(' | ')}`);
      colunas.push({ legado: cLeg, pt: `${pt}_original`, tipo_pg: `varchar(${tamanhoVarchar(info.max_comprimento)})`, nulo: true,
        preenchimento: info.taxa_preenchimento, max_comprimento: info.max_comprimento, origem: 'texto_original', notas: ['texto exacto do legado'] });
    }
    if ((leg === 'accounting_mapos' || leg === 'system_accounting_mapos') && cLeg === 'org_type_id') {
      col.notas.push("-1 no legado = coluna 'Avençado' -> NULL + avencado=true");
      colunas.push({ legado: cLeg, pt: 'avencado', tipo_pg: 'boolean', nulo: false, preenchimento: 1, origem: 'derivada', notas: ['derivada: org_type_id = -1'] });
    }
  }
  for (const [chave] of Object.entries(NORMALIZACOES)) {
    const [tNorm, cNorm] = chave.split('.');
    if (tNorm === leg && !cols.some(([c]) => c === cNorm)) normalizacoesSemCobertura.push(`${chave}: coluna não existe no backup`);
  }
  saida.tabelas.push({ legado: leg, ...meta, linhas_reais: lt.linhas_reais, linhas_dummy_descartadas: lt.linhas_dummy,
    esquema_a_derivar_do_codigo: lt.linhas_reais === 0, colunas });
}

fs.mkdirSync(dirSaida, { recursive: true });
fs.writeFileSync(path.join(dirSaida, 'mapa_de_para.json'), JSON.stringify(saida, null, 2));
fs.writeFileSync(path.join(dirSaida, 'pendencias.json'), JSON.stringify({ semTraducao, colisoes, normalizacoesSemCobertura, fksFracas, enumeracoes, esquemaDoCodigo }, null, 2));

// ── Markdown ──────────────────────────────────────────────────────────────────
const md = [];
const esc = (s) => String(s ?? '').replace(/\|/g, '\\|');
const totalCols = saida.tabelas.reduce((a, t) => a + t.colunas.filter((c) => !c.descartada).length, 0);
md.push('# Dicionário de Dados — Migração ERP_CONSULVOLT (legado Dexie → PostgreSQL)', '');
md.push(`> Gerado automaticamente por \`ferramentas/levantamento/gerar_dicionario.mjs\` em ${saida.gerado_em}. **Não editar à mão**: alterar \`glossario.mjs\` / \`tabelas.mjs\` e regenerar.`, '');
md.push('## Resumo', '');
md.push(`| Indicador | Valor |`, `| :--- | ---: |`);
md.push(`| Tabelas | ${saida.tabelas.length} |`);
md.push(`| Linhas no backup | ${lev.resumo.linhas_total.toLocaleString('pt-PT')} |`);
md.push(`| Linhas fictícias descartadas (is_master_data) | ${lev.resumo.linhas_dummy_descartadas.toLocaleString('pt-PT')} |`);
md.push(`| Linhas reais a migrar | ${lev.resumo.linhas_reais_a_migrar.toLocaleString('pt-PT')} |`);
md.push(`| Colunas reais mapeadas | ${totalCols} |`);
md.push(`| Tabelas sem linhas reais (esquema a derivar do código JS) | ${esquemaDoCodigo.length} |`);
md.push(`| Chaves estrangeiras com órfãos | ${fksFracas.length} |`);
md.push(`| Colunas enumeradas a normalizar (código + \`*_original\`) | ${enumeracoes.length} |`, '');
md.push('## Convenções', '');
md.push('- Tenant: todas as variantes (`company_id`, `rh_company_id`, `crm_company_id`, `pos_company_id`, …) → `empresa_id`.');
md.push('- Carimbos: `criado_em` / `atualizado_em` / `eliminado_em` (constantes `CREATED_AT`/`UPDATED_AT` nos models).');
md.push('- Dinheiro `numeric(15,2)` · quantidades `numeric(12,3)` · taxas/percentagens `numeric(9,4)` · câmbio `numeric(18,6)`.');
md.push('- Enumerações textuais: coluna com código normalizado + coluna `<nome>_original` com o texto do legado.');
md.push('- Linhas `is_master_data=1` são placeholders do legado e não migram, excepto as empresas 10 e 18 (reais).', '');

md.push('## Regras de integridade aplicadas pelo ETL', '');
md.push('| Alvo | Quando | Acção | Motivo |', '| :--- | :--- | :--- | :--- |');
for (const r of REGRAS) md.push(`| \`${esc(r.alvo)}\` | ${esc(r.quando)} | **${r.acao}** | ${esc(r.motivo)} |`);
md.push('');
md.push('## Chaves estrangeiras com órfãos no backup', '');
md.push('| Tabela.coluna | Alvo | Correspondência | Órfãos | Exemplos |', '| :--- | :--- | ---: | ---: | :--- |');
for (const f of fksFracas) md.push(`| \`${f.tabela}.${f.coluna}\` | \`${f.alvo}\` | ${(f.correspondencia * 100).toFixed(1)}% | ${f.orfaos} | ${esc(f.amostra_orfaos.join(', '))} |`);
md.push('');
md.push('## Normalizações de enumerações (código + texto original)', '');
md.push('| Tabela.coluna | Domínio de códigos | Valores do legado → código |', '| :--- | :--- | :--- |');
for (const [k, n] of Object.entries(NORMALIZACOES)) {
  const [tl, cl] = k.split('.');
  const dist = lev.tabelas[tl]?.colunas[cl]?.distintos ?? {};
  const pares = Object.keys(dist).map((v) => `${v} → ${n.mapa[dobrar(v)] ?? '⚠ NULL'}`).join('; ');
  md.push(`| \`${k}\` | ${n.dominio.join(', ')} | ${esc(pares)} |`);
}
md.push('');
md.push(`## Tabelas sem dados reais (${esquemaDoCodigo.length}) — esquema a derivar do código legado`, '');
md.push(esquemaDoCodigo.map((t) => `\`${t}\``).join(', '), '');

let modulo = null;
for (const t of saida.tabelas) {
  if (t.modulo !== modulo) { modulo = t.modulo; md.push(`## Módulo: ${modulo}`, ''); }
  md.push(`### \`${t.legado}\` → \`${t.pt}\` (model \`${t.model}\`, \`${t.endpoint}\`)`, '');
  md.push(`Linhas reais: **${t.linhas_reais}** · fictícias descartadas: ${t.linhas_dummy_descartadas}${t.esquema_a_derivar_do_codigo ? ' · ⚠ **sem dados reais — esquema a derivar do código legado**' : ''}`, '');
  const vis = t.colunas.filter((c) => !c.descartada);
  if (!vis.length) { md.push('_Sem colunas reais no backup._', ''); continue; }
  md.push('| Coluna legado | Coluna PT | Tipo PG | Nulo | Preench. | FK | Notas |', '| :--- | :--- | :--- | :---: | ---: | :--- | :--- |');
  for (const c of vis) {
    const fk = c.fk ? `\`${c.fk.tabela}.id\`${c.fk.correspondencia !== null && c.fk.correspondencia < 1 ? ` ⚠ ${(c.fk.correspondencia * 100).toFixed(1)}% (${c.fk.orfaos} órfãos)` : ''}` : '';
    md.push(`| \`${esc(c.legado)}\` | \`${esc(c.pt)}\` | ${c.tipo_pg} | ${c.nulo ? 'sim' : 'não'} | ${(c.preenchimento * 100).toFixed(0)}% | ${fk} | ${esc(c.notas.join('; '))} |`);
  }
  md.push('');
}
fs.writeFileSync(path.join(dirSaida, 'DICIONARIO_DADOS.md'), md.join('\n'));

console.log(JSON.stringify({ tabelas: saida.tabelas.length, colunas: totalCols, semTraducao: semTraducao.length, colisoes: colisoes.length,
  normalizacoes: Object.keys(NORMALIZACOES).length, semCobertura: normalizacoesSemCobertura.length, fksFracas: fksFracas.length, enumeracoes: enumeracoes.length, esquemaDoCodigo: esquemaDoCodigo.length }, null, 2));
if (semTraducao.length) console.log('SEM TRADUÇÃO:', semTraducao.join(', '));
if (colisoes.length) console.log('COLISÕES:\n  ' + colisoes.join('\n  '));
if (semTraducao.length || colisoes.length || normalizacoesSemCobertura.length) process.exit(2);
