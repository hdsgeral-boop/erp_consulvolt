#!/usr/bin/env node
// Extrai as narrativas de fábrica do Manual de Procedimentos do legado (v_boss/New/v2/js/fluxo_narrativa.js)
// para backend/resources/procedimentos/narrativas.json (fonte única das «narrativas de fábrica» do Manual de Procedimentos).
//
// Uso:  node ferramentas/paridade/extrair_narrativas_procedimentos.mjs [caminho/do/fluxo_narrativa.js] [--verificar]
//   --verificar  não escreve; falha (código 1) se o ficheiro actual divergir do que o legado produz.
//
// O legado é só lido (avaliado numa `vm` isolada, sem rede nem ficheiros). Saída determinística (chaves ordenadas pela
// ordem do legado; indentação de 2 espaços; LF). Valida as contagens: 19 narrativas, 109 etapas, 84 cruzamentos.
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const aqui = path.dirname(fileURLToPath(import.meta.url));
const raiz = path.resolve(aqui, '..', '..');
const argumentos = process.argv.slice(2);
const verificar = argumentos.includes('--verificar');
const origem = argumentos.find((a) => !a.startsWith('--'))
  ?? path.resolve(raiz, '..', 'v_boss', 'New', 'v2', 'js', 'fluxo_narrativa.js');
const destino = path.join(raiz, 'backend', 'resources', 'procedimentos', 'narrativas.json');

const ESPERADO = { narrativas: 19, etapas: 109, cruzamentos: 84 };

// Chaves do legado -> chaves do Fluxo de Processos do ERP novo (CatalogoFluxos::FLUXOS). As restantes mantêm-se.
const CHAVE_NOVA = { lav: 'lavandaria', proj: 'projetos', imob: 'imobilizado' };
// Módulo do ERP que o processo pressupõe, quando não é um dos módulos existentes (o resto fica nulo).
const MODULO = { imobiliario: 'imobiliario' };
// Processos com painel de acompanhamento no Fluxo de Processos do ERP novo (CatalogoFluxos::FLUXOS).
const FLUXO_ID = new Set(['rh', 'ferias', 'avaliacao', 'vendas', 'pos', 'lavandaria', 'projetos', 'compras', 'imobilizado', 'bancos', 'caixa', 'orcamento', 'crm', 'acrescimos']);

const codigo = fs.readFileSync(origem, 'utf8');

// Contexto mínimo: o ficheiro do legado é um IIFE que só regista `window.FluxoNarrativa`.
const tolerante = () => new Proxy(function () {}, { get: () => tolerante(), apply: () => tolerante(), construct: () => tolerante() });
const janela = {};
const contexto = vm.createContext({ window: janela, document: tolerante(), console, setTimeout: () => 0, clearTimeout: () => 0, addEventListener: () => {}, URL: {}, Blob: function () {} });
vm.runInContext(codigo, contexto, { filename: 'fluxo_narrativa.js', timeout: 5000 });
const brutas = janela.FluxoNarrativa && janela.FluxoNarrativa.NARRATIVAS;
if (!brutas) { console.error('NARRATIVAS não encontradas no ficheiro do legado.'); process.exit(2); }

const texto = (v) => String(v == null ? '' : v).trim();
const saida = {};
for (const [chaveLegado, n] of Object.entries(brutas)) {
  const chave = CHAVE_NOVA[chaveLegado] ?? chaveLegado;
  if (saida[chave]) { console.error(`Chave duplicada: ${chave}`); process.exit(2); }
  saida[chave] = {
    titulo: texto(n.titulo),
    objectivo: texto(n.objectivo),
    fluxo_id: FLUXO_ID.has(chave) ? chave : null,
    modulo: MODULO[chave] ?? null,
    intervenientes: (n.intervenientes ?? []).map(texto),
    etapas: (n.etapas ?? []).map((e) => ({ nome: texto(e.nome), quem: texto(e.quem), descricao: texto(e.descricao), controlos: texto(e.controlos), resultado: texto(e.resultado) })),
    cruza: (n.cruza ?? []).map((c) => ({ com: CHAVE_NOVA[texto(c.com)] ?? texto(c.com), onde: texto(c.onde), o_que: texto(c.o_que) })),
  };
}

const contagem = {
  narrativas: Object.keys(saida).length,
  etapas: Object.values(saida).reduce((s, n) => s + n.etapas.length, 0),
  cruzamentos: Object.values(saida).reduce((s, n) => s + n.cruza.length, 0),
};
console.log(`Narrativas: ${contagem.narrativas} · etapas: ${contagem.etapas} · cruzamentos: ${contagem.cruzamentos}`);
for (const k of Object.keys(ESPERADO)) {
  if (contagem[k] !== ESPERADO[k]) { console.error(`Contagem de ${k} inesperada: ${contagem[k]} (esperado ${ESPERADO[k]}).`); process.exit(3); }
}

// Integridade: todo o cruzamento aponta a uma narrativa ou a um documento externo; a etapa «onde» existe na própria narrativa.
const EXTERNOS = new Set(['estrutura_manual', 'estrategia_plano']);
const avisos = [];
for (const [chave, n] of Object.entries(saida)) {
  const nomes = new Set(n.etapas.map((e) => e.nome));
  if (!n.titulo || !n.etapas.length) avisos.push(`${chave}: sem título ou sem etapas`);
  for (const c of n.cruza) {
    if (!saida[c.com] && !EXTERNOS.has(c.com)) avisos.push(`${chave}: cruzamento com «${c.com}» que não existe`);
    if (c.com === chave) avisos.push(`${chave}: cruzamento consigo próprio`);
    if (c.onde && !nomes.has(c.onde)) avisos.push(`${chave}: «onde» = «${c.onde}» não é uma etapa deste processo`);
  }
}
if (avisos.length) { console.error('Incoerências nos dados do legado:\n - ' + avisos.join('\n - ')); }

const json = JSON.stringify(saida, null, 2) + '\n';
if (verificar) {
  const actual = fs.existsSync(destino) ? fs.readFileSync(destino, 'utf8').replace(/\r\n/g, '\n') : '';
  if (actual !== json) { console.error('narrativas.json diverge do legado: volte a correr o script sem --verificar.'); process.exit(1); }
  console.log('narrativas.json está em linha com o legado.');
} else {
  fs.mkdirSync(path.dirname(destino), { recursive: true });
  fs.writeFileSync(destino, json, 'utf8');
  console.log(`Escrito ${path.relative(raiz, destino)} (${json.length} bytes).`);
}
