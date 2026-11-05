// Lista compacta das lacunas de um ou mais módulos: node ferramentas/paridade/listar_lacunas.mjs Vendas,Compras
import fs from 'node:fs';
const j = JSON.parse(fs.readFileSync(new URL('../../docs/paridade/cobertura_campos.json', import.meta.url), 'utf8'));
const mods = (process.argv[2] ?? '').split(',');
const por = {};
for (const c of j.colunas.filter((x) => mods.includes(x.modulo) && ['FUNCIONAL_SO_BACKEND', 'FUNCIONAL_SEM_API', 'ORFA'].includes(x.classe))) {
  (por[c.tabela] ??= []).push(`${c.coluna}[${c.classe === 'ORFA' ? 'OR' : c.classe.endsWith('BACKEND') ? 'SB' : 'SA'}${c.preenchimento_legado != null ? Math.round(c.preenchimento_legado * 100) : ''}]`);
}
for (const [t, l] of Object.entries(por)) console.log(`${t}: ${l.join(' ')}`);
