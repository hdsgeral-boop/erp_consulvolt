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
fs.writeFileSync(destino, JSON.stringify(catalogo, null, 2));
const ecras = catalogo.modulos.flatMap((m) => m.ecras);
console.log(JSON.stringify({ modulos: catalogo.modulos.length, ecras: ecras.length, tarefas: ecras.reduce((a, e) => a + e.tarefas.length, 0), segregacao: catalogo.segregacao.length, modelos: catalogo.modelos.length }));
