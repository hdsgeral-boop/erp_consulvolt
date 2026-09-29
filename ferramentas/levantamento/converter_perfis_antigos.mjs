// Converte os perfis de permissões no formato ANTIGO (sem _v2 e sem all:true) para o formato v2,
// usando o próprio código do legado: window.hasView/canEdit/canDelete/can (js/app_v2.js) +
// PERMISSOES.converterAntigo (js/permissoes.js:808). Resultado idêntico ao que o editor de perfis
// do legado produziria. Saída sem dados pessoais (só chaves de permissão), usada pelo ETL.
//
// Uso: node ferramentas/levantamento/converter_perfis_antigos.mjs <backup.json> <dir js do legado> <saida.json>
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const [, , caminhoBackup, dirJs, destino] = process.argv;
const nada = () => undefined;
const elemento = new Proxy({}, { get: () => nada });
const window = { addEventListener: nada, setTimeout: nada };
const contexto = vm.createContext({ window, console, setTimeout: nada, MutationObserver: class { observe() {} },
  document: { addEventListener: nada, querySelectorAll: () => [], getElementById: () => null, createElement: () => elemento, readyState: 'loading' },
  localStorage: { getItem: () => null, setItem: nada } });

// 1. Funções antigas de permissões (js/app_v2.js, de "window.hasView =" até ao fim de "window.can =")
const app = fs.readFileSync(path.join(dirJs, 'app_v2.js'), 'utf8');
const inicio = app.indexOf('window.hasView = (moduleKey) =>');
const fimCan = app.indexOf('window.can = (subpermissionKey) =>');
const fim = app.indexOf('\n};', fimCan) + 3;
if (inicio < 0 || fimCan < 0) throw new Error('Funções antigas de permissões não encontradas em app_v2.js');
vm.runInContext(app.slice(inicio, fim), contexto, { filename: 'app_v2.js (permissões antigas)' });
// 2. Catálogo v2 (guarda as funções antigas em `antigo` e expõe converterAntigo)
vm.runInContext(fs.readFileSync(path.join(dirJs, 'permissoes.js'), 'utf8'), contexto, { filename: 'permissoes.js' });

const backup = JSON.parse(fs.readFileSync(caminhoBackup, 'utf8'));
const perfis = backup.data.data.find((t) => t.tableName === 'user_profiles').rows;
const saida = { origem: 'js/app_v2.js:725-888 + js/permissoes.js:808 (converterAntigo)', gerado_em: new Date().toISOString(), perfis: {} };
for (const p of perfis) {
  const perms = p.permissions || {};
  if (perms._v2 === true || perms.all === true) continue;
  const set = window.PERMISSOES.converterAntigo(perms);
  const convertido = { _v2: true };
  [...set].sort().forEach((k) => { convertido[k] = true; });
  saida.perfis[p.id] = { nome: p.name, original: perms, convertido };
  console.log(`perfil #${p.id} ${p.name}: ${Object.keys(perms).filter((k) => perms[k] === true).length} chaves antigas -> ${set.size} chaves v2`);
}
fs.writeFileSync(destino, JSON.stringify(saida, null, 2));
