#!/usr/bin/env node
// Extrai os dados de fábrica do módulo Gestão Imobiliária do legado (v_boss/New/v2/js/modules/imobiliario) para
// backend/resources/imobiliario/fabrica.json: tipos e variáveis dos modelos de documentos, os 8 modelos por omissão e os
// valores base das taxas (TAXAS_BASE). O legado é só lido (avaliado numa `vm` isolada, sem rede nem ficheiros).
//
// Uso:  node ferramentas/paridade/extrair_modelos_imobiliario.mjs [pasta/do/legado] [--verificar]
//   --verificar  não escreve; falha (código 1) se o ficheiro actual divergir do que o legado produz.
//
// As taxas são valores de arranque, a validar pelo contabilista/jurista: o ERP não afirma a sua conformidade legal.
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const aqui = path.dirname(fileURLToPath(import.meta.url));
const raiz = path.resolve(aqui, '..', '..');
const argumentos = process.argv.slice(2);
const verificar = argumentos.includes('--verificar');
const pasta = argumentos.find((a) => !a.startsWith('--'))
  ?? path.resolve(raiz, '..', 'v_boss', 'New', 'v2', 'js', 'modules', 'imobiliario');
const destino = path.join(raiz, 'backend', 'resources', 'imobiliario', 'fabrica.json');

const ESPERADO = { tipos: 8, variaveis: 59, modelos: 8, taxas: 21 };

const tolerante = () => new Proxy(function () {}, { get: () => tolerante(), apply: () => tolerante(), construct: () => tolerante() });
function avaliar(ficheiro) {
  const janela = { activeCompanyId: 1 };
  const contexto = vm.createContext({ window: janela, document: tolerante(), console, setTimeout: () => 0, clearTimeout: () => 0, addEventListener: () => {}, URL: {}, Blob: function () {}, Intl });
  vm.runInContext(fs.readFileSync(path.join(pasta, ficheiro), 'utf8'), contexto, { filename: ficheiro, timeout: 5000 });
  return janela;
}

const modelos = avaliar('imobiliario_templates.js').ImobiliarioModelos;
const contratos = avaliar('imobiliario_contratos.js').ImobiliarioContratos;
if (!modelos || !contratos) { console.error('Módulos do legado não encontrados.'); process.exit(2); }

const tipos = { ...modelos.TIPOS };
const variaveis = modelos.VARIAVEIS.map(([chave, rotulo]) => ({ chave, rotulo }));
const lista = modelos.MODELOS_PADRAO.map((m) => ({ tipo: m.tipo, nome: m.nome, assunto: m.assunto, corpo: m.corpo }));
// O legado chama `det_desocupado_meses` ao prazo de desocupação do agravamento da detenção; no ERP novo é `det_agravamento_meses`.
const taxas = {};
for (const [k, v] of Object.entries(contratos.TAXAS_BASE)) taxas[k === 'det_desocupado_meses' ? 'det_agravamento_meses' : k] = v;

const obtido = { tipos: Object.keys(tipos).length, variaveis: variaveis.length, modelos: lista.length, taxas: Object.keys(taxas).length };
for (const k of Object.keys(ESPERADO)) {
  if (obtido[k] !== ESPERADO[k]) { console.error(`Contagem inesperada de ${k}: ${obtido[k]} (esperado ${ESPERADO[k]}).`); process.exit(2); }
}

const saida = JSON.stringify({ tipos, variaveis, modelos: lista, taxas_base: taxas }, null, 2) + '\n';
if (verificar) {
  const actual = fs.existsSync(destino) ? fs.readFileSync(destino, 'utf8') : '';
  if (actual !== saida) { console.error('fabrica.json diverge do legado.'); process.exit(1); }
  console.log('fabrica.json coincide com o legado.', obtido);
} else {
  fs.mkdirSync(path.dirname(destino), { recursive: true });
  fs.writeFileSync(destino, saida);
  console.log('Escrito', destino, obtido);
}
