// Extrai o catálogo de permissões do legado (js/permissoes.js -> window.PERMISSOES) para JSON,
// executando o próprio ficheiro num contexto isolado (sem DOM). Uso:
//   node ferramentas/levantamento/extrair_permissoes.mjs ../payroll_system_web/js/permissoes.js backend/resources/permissoes/catalogo.json
import fs from 'node:fs';
import vm from 'node:vm';
const [, , origem, destino] = process.argv;
const nada = () => undefined;
const elemento = new Proxy({}, { get: () => nada });
const window = { addEventListener: nada, setTimeout: nada };
const contexto = vm.createContext({ window, document: { addEventListener: nada, querySelectorAll: () => [], getElementById: () => null, createElement: () => elemento, readyState: 'loading' },
  console, setTimeout: nada, MutationObserver: class { observe() {} }, localStorage: { getItem: () => null, setItem: nada } });
vm.runInContext(fs.readFileSync(origem, 'utf8'), contexto, { filename: origem });
const P = window.PERMISSOES;
if (!P) throw new Error('window.PERMISSOES não definido');
const catalogo = {
  origem: 'js/permissoes.js', gerado_em: new Date().toISOString(),
  modulos: P.CATALOGO.map((m) => ({ id: m.id, nome: m.nome, ecras: m.ecras.map((e) => ({
    id: e.id, nome: e.nome, vistas: e.vistas, pai: e.pai, nota: e.nota || null,
    tarefas: e.tarefas.map((t) => ({ chave: t.k, rotulo: t.r, sensivel: t.s })) })) })),
  segregacao: P.SEGREGACAO.map(([a, b, motivo]) => ({ a, b, motivo })),
  modelos: P.MODELOS.map((m) => ({ nome: m.nome, permissoes: P.permsDoModelo(m) })),
};
// Tarefas do sistema novo que não existem no legado (ecrã → tarefas). Aplicadas sempre que o catálogo é regenerado.
const TAREFAS_NOVAS = {
  armazem_stock: [{ chave: 'armazem_transferencia', rotulo: 'Transferir stock entre armazéns', sensivel: false }],   // ADR-042 (declarada no legado, nunca implementada)
  vendas_faturacao: [{ chave: 'vendas_alterar_preco', rotulo: 'Alterar o preço de venda da ficha do produto nos documentos', sensivel: true, apos: 'vendas_fat_emitir' }],   // ADR-068
  infotipos: [{ chave: 'rh_tabela_irt_gerir', rotulo: 'Alterar a tabela de IRT (Grupo A), comum a todas as empresas', sensivel: true }],   // ADR-068
  config_moedas: [{ chave: 'cambio_manual_fora_tolerancia', rotulo: 'Indicar câmbio manual fora da tolerância face ao câmbio do dia (vendas, compras e tesouraria)', sensivel: true }],   // ADR-068
};
// Perfis-tipo: tarefas do sistema novo acrescentadas aos modelos do legado (ADR-068)
const MODELOS_EXTRA = {
  'Técnico de RH': ['rh_tabela_irt_gerir'],
  'Comercial / Facturação': ['vendas_alterar_preco'],
  'Comercial CRM': ['vendas_alterar_preco'],
  'Director Comercial': ['vendas_alterar_preco'],
};
// Ecrãs da Versão Actual do legado ainda não construídos no sistema novo (ADR-071): ficam fora do catálogo, do menu e
// dos perfis-tipo até a fase que os constrói os retirar desta lista.
const ECRAS_ADIADOS = new Set([]);   // todos os ecrãs da Versão Actual estão construídos ou em construção (ADR-071)
const chavesAdiadas = new Set(catalogo.modulos.flatMap((m) => m.ecras.filter((e) => ECRAS_ADIADOS.has(e.id))
  .flatMap((e) => [e.id, ...e.vistas, ...e.vistas.map((v) => `${v}_view`), ...e.tarefas.map((t) => t.chave)])));
for (const m of catalogo.modulos) m.ecras = m.ecras.filter((e) => !ECRAS_ADIADOS.has(e.id));
catalogo.modulos = catalogo.modulos.filter((m) => m.ecras.length);
catalogo.segregacao = catalogo.segregacao.filter((s) => !chavesAdiadas.has(s.a) && !chavesAdiadas.has(s.b));
for (const m of catalogo.modelos) for (const k of Object.keys(m.permissoes)) if (chavesAdiadas.has(k)) delete m.permissoes[k];
for (const e of catalogo.modulos.flatMap((m) => m.ecras)) {
  for (const { apos, ...t } of TAREFAS_NOVAS[e.id] || []) {
    if (e.tarefas.some((x) => x.chave === t.chave)) continue;
    const i = apos ? e.tarefas.findIndex((x) => x.chave === apos) : -1;
    if (i >= 0) e.tarefas.splice(i + 1, 0, t); else e.tarefas.push(t);
  }
}
for (const m of catalogo.modelos) for (const k of MODELOS_EXTRA[m.nome] || []) m.permissoes[k] = true;
fs.writeFileSync(destino, JSON.stringify(catalogo, null, 2) + '\n');
const ecras = catalogo.modulos.flatMap((m) => m.ecras);
console.log(JSON.stringify({ modulos: catalogo.modulos.length, ecras: ecras.length, tarefas: ecras.reduce((a, e) => a + e.tarefas.length, 0), segregacao: catalogo.segregacao.length, modelos: catalogo.modelos.length }));
