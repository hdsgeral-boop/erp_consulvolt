import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { INDICE_PESQUISA } from './indicePesquisa.gerado';

// O gerador corre em Node (ferramentas/pesquisa/gerar_indice_pesquisa.mjs, fora da raiz do Vite) e analisa o código com o
// compilador de TypeScript: o teste chama-o como processo e compara o resultado.
vi.setConfig({ testTimeout: 300_000 });

const RAIZ_FRONTEND = path.resolve(__dirname, '../..');
const GERADOR = path.resolve(RAIZ_FRONTEND, '../ferramentas/pesquisa/gerar_indice_pesquisa.mjs');
const DESTINO = 'src/layout/indicePesquisa.gerado.ts';
// assíncrono: um processo síncrono bloquearia o trabalhador do Vitest (timeout do RPC)
const gerarIndice = async (raiz: string) => (await promisify(execFile)(process.execPath, [GERADOR, '--stdout', '--raiz', raiz], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })).stdout;

function escrever(raiz: string, ficheiro: string, texto: string) {
  const f = path.join(raiz, ficheiro);
  fs.mkdirSync(path.dirname(f), { recursive: true });
  fs.writeFileSync(f, texto);
}

describe('índice da pesquisa (botões e separadores dos ecrãs)', () => {
  it('o ficheiro gerado está em dia com o código (se falhar: node ferramentas/pesquisa/gerar_indice_pesquisa.mjs)', async () => {
    const actual = fs.readFileSync(path.join(RAIZ_FRONTEND, DESTINO), 'utf8');
    expect((await gerarIndice(RAIZ_FRONTEND)) === actual, 'indicePesquisa.gerado.ts desactualizado — volte a gerá-lo').toBe(true);
  });

  it('extrai separadores, botões, acções e a permissão que os protege; ignora genéricos e componentes partilhados', async () => {
    const raiz = fs.mkdtempSync(path.join(os.tmpdir(), 'indice-pesquisa-'));
    try {
      escrever(raiz, 'src/modulos/vendas/ecras.ts', `import { lazy } from 'react';
const mapa = (n: string) => lazy(() => import('./Mapas').then((m) => ({ default: m[n] })));
export const ecras = { vendas_faturacao: lazy(() => import('./Faturacao')), vendas_mapa: mapa('A'), vendas_config: lazy(() => import('./Config')) };`);
      // ecrã com o hook partilhado: grava só a chave (a pesquisa abre rota?sep=<chave>); o separador de um detalhe não
      escrever(raiz, 'src/modulos/vendas/Config.tsx', `import { Tabs } from 'antd';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';
export default function Config() {
  const { pode } = useSessao();
  const [sep, setSep] = useSeparadorUrl<'series' | 'modelos'>(pode('vendas_cfg') ? ['series', 'modelos'] : ['series']);
  return <Tabs activeKey={sep} onChange={setSep} items={[{ key: 'series', label: 'Séries' }, ...(pode('vendas_cfg') ? [{ key: 'modelos', label: 'Modelos de impressão' }] : [])]} />;
}`);
      escrever(raiz, 'src/modulos/vendas/Faturacao.tsx', `import { Button, Tabs } from 'antd';
import { useSearchParams } from 'react-router';
import { Detalhe } from './comum/Detalhe';
import { Partilhado } from '@/componentes/Partilhado';
export default function Faturacao() {
  const { pode } = useSessao();
  const [params] = useSearchParams();
  const podeAnular = pode('vendas_fat_del');
  const itens = [{ key: 'lista', label: 'Lista de documentos' }, ...(pode('vendas_recibos') ? [{ key: 'recibos', label: <span>Recibos</span> }] : [])];
  return (<>
    <Tabs activeKey={params.get('separador') ?? 'lista'} items={itens} />
    {pode('vendas_fat_emitir', 'vendas_fat_post') && <Button type="primary">Emitir factura</Button>}
    {podeAnular ? <Button>Anular documento</Button> : null}
    <Button disabled={!pode('vendas_fat_contabilizar')}>Contabilizar seleccionados</Button>
    <Button icon={<X />} title="Imprimir lista" />
    <Button>Cancelar</Button>
    <AccoesLinha accoes={[{ icone: 'project-diagram', titulo: 'Fluxo Documental', onClick: f }]} />
    <Detalhe /><Partilhado />
  </>);
}`);
      escrever(raiz, 'src/modulos/vendas/comum/Detalhe.tsx', `export function Detalhe() { return <Tabs items={[{ key: 'geral', label: 'Dados gerais' }]} />; }`);
      escrever(raiz, 'src/modulos/vendas/Mapas.tsx', `export function A() { return <Button>Exportar mapa</Button>; }`);
      escrever(raiz, 'src/componentes/Partilhado.tsx', `export function Partilhado() { return <Button>Botão partilhado</Button>; }`);
      escrever(raiz, 'src/layout/.gitkeep', '');

      const texto = await gerarIndice(raiz);
      const linhas = texto.split('\n').filter((l) => l.startsWith('  [')).map((l) => JSON.parse(l.trim().replace(/,$/, '')));
      expect(linhas).toEqual([
        ['vendas_config', 'c', 'Modelos de impressão', 'vendas_cfg', 'modelos'],
        ['vendas_config', 'c', 'Séries', '', 'series'],
        ['vendas_faturacao', 'b', 'Anular documento', 'vendas_fat_del'],
        ['vendas_faturacao', 'b', 'Contabilizar seleccionados', 'vendas_fat_contabilizar'],
        ['vendas_faturacao', 'b', 'Emitir factura', 'vendas_fat_emitir|vendas_fat_post'],
        ['vendas_faturacao', 'b', 'Fluxo Documental', ''],
        ['vendas_faturacao', 'r', 'Imprimir lista', ''],
        ['vendas_faturacao', 's', 'Dados gerais', ''],
        ['vendas_faturacao', 's', 'Lista de documentos', '', 'separador=lista'],
        ['vendas_faturacao', 's', 'Recibos', 'vendas_recibos', 'separador=recibos'],
        ['vendas_mapa', 'r', 'Exportar mapa', ''],
      ]);
      expect(texto).toContain('export const INDICE_PESQUISA');
    } finally {
      fs.rmSync(raiz, { recursive: true, force: true });
    }
  });

  it('extrai títulos de janelas, itens de menus Dropdown, vistas Segmented e separadores de constantes (.map); ignora títulos dinâmicos; classifica relatórios', async () => {
    const raiz = fs.mkdtempSync(path.join(os.tmpdir(), 'indice-pesquisa-'));
    try {
      escrever(raiz, 'src/modulos/rh/ecras.ts', `export const ecras = { rh_calc: lazy(() => import('./Calc')) };`);
      escrever(raiz, 'src/modulos/rh/Calc.tsx', `import { Dropdown, Modal, Segmented, Tabs } from 'antd';
const ABAS = [{ key: 'a', label: 'Primeira aba' }, { key: 'b', label: 'Segunda aba' }] as const;
export default function Calc() {
  const { pode } = useSessao();
  const [sep, setSep] = useSeparadorUrl(ABAS.map((x) => x.key));
  return (<>
    <Tabs activeKey={sep} onChange={setSep} items={ABAS.map((x) => ({ key: x.key, label: x.label, children: null }))} />
    <Dropdown menu={{ items: [{ key: 'c', label: 'Importar dos contratos' }, ...(pode('est_multas_gerir') ? [{ key: 'm', label: 'Importar multas' }] : [])] }}><span /></Dropdown>
    <Segmented options={[{ value: 1, label: 'Só activas' }, { value: 2, label: 'Todas as vistas' }]} />
    <Modal title="Abrir período salarial" open />
    <Modal title={editar ? 'Editar rubrica' : 'Nova rubrica'} open />
    <Modal title={\`Editar \${nome}\`} open />
    <Modal title="Registo #12" open />
    <Button>Exportar mapa de IRT</Button>
  </>);
}`);
      const linhas = (await gerarIndice(raiz)).split('\n').filter((l) => l.startsWith('  [')).map((l) => JSON.parse(l.trim().replace(/,$/, '')));
      expect(linhas).toEqual([
        ['rh_calc', 'b', 'Importar dos contratos', ''],
        ['rh_calc', 'b', 'Importar multas', 'est_multas_gerir'],
        ['rh_calc', 'd', 'Abrir período salarial', ''],
        ['rh_calc', 'd', 'Editar rubrica', ''],
        ['rh_calc', 'd', 'Nova rubrica', ''],
        ['rh_calc', 'r', 'Exportar mapa de IRT', ''],
        ['rh_calc', 's', 'Primeira aba', '', 'a'],
        ['rh_calc', 's', 'Segunda aba', '', 'b'],
        ['rh_calc', 's', 'Só activas', ''],
        ['rh_calc', 's', 'Todas as vistas', ''],
      ]);
    } finally {
      fs.rmSync(raiz, { recursive: true, force: true });
    }
  });

  it('um componente partilhado por vários ecrãs do módulo fica só no ecrã onde está mais perto da entrada', async () => {
    const raiz = fs.mkdtempSync(path.join(os.tmpdir(), 'indice-pesquisa-'));
    try {
      escrever(raiz, 'src/modulos/est/ecras.ts', `export const ecras = { est_a: lazy(() => import('./A')), est_b: lazy(() => import('./B')) };`);
      escrever(raiz, 'src/modulos/est/A.tsx', `import { Comum } from './comum/Comum';
export default function A() { return <><Button>Botão do A</Button><Comum /></>; }`);
      escrever(raiz, 'src/modulos/est/B.tsx', `import { Fundo } from './comum/Fundo';
export default function B() { return <><Button>Botão do B</Button><Button>Botão do A</Button><Fundo /></>; }`);
      escrever(raiz, 'src/modulos/est/comum/Comum.tsx', `export function Comum() { return <Button>Anular delegação</Button>; }`);
      escrever(raiz, 'src/modulos/est/comum/Fundo.tsx', `import { Comum } from './Comum';
export function Fundo() { return <Comum />; }`);
      const linhas = (await gerarIndice(raiz)).split('\n').filter((l) => l.startsWith('  [')).map((l) => JSON.parse(l.trim().replace(/,$/, '')));
      // «Anular delegação»: distância 1 em A, 2 em B -> só em A; «Botão do A» está à distância 0 nos dois -> fica nos dois
      expect(linhas).toEqual([
        ['est_a', 'b', 'Anular delegação', ''],
        ['est_a', 'b', 'Botão do A', ''],
        ['est_b', 'b', 'Botão do A', ''],
        ['est_b', 'b', 'Botão do B', ''],
      ]);
    } finally {
      fs.rmSync(raiz, { recursive: true, force: true });
    }
  });

  it('é determinístico e idempotente: duas execuções dão exactamente o mesmo ficheiro, e depressa', async () => {
    const t0 = Date.now();
    const a = await gerarIndice(RAIZ_FRONTEND);
    const dt = Date.now() - t0;
    const b = await gerarIndice(RAIZ_FRONTEND);
    expect(b === a).toBe(true);
    expect(dt).toBeLessThan(60_000);
  });
});

// ── cobertura: o índice contra o registo de ecrãs, o catálogo de permissões e o código ────────────────────────────────
interface CatalogoEcra { id: string; nome: string; tarefas: { chave: string; rotulo: string; sensivel?: boolean }[] }
const catalogo = JSON.parse(fs.readFileSync(path.resolve(RAIZ_FRONTEND, '../backend/resources/permissoes/catalogo.json'), 'utf8')) as { modulos: { id: string; ecras: CatalogoEcra[] }[] };
const config = JSON.parse(fs.readFileSync(path.resolve(RAIZ_FRONTEND, '../ferramentas/pesquisa/indice.config.json'), 'utf8')) as { ecrasSemPesquisa: Record<string, string>; paginas: { rota: string }[]; sinonimos: string[][] };
const ecrasCatalogo = new Map(catalogo.modulos.flatMap((m) => m.ecras.map((e) => [e.id, { ...e, modulo: m.id }] as const)));
const semPesquisa = (id: string) => Object.keys(config.ecrasSemPesquisa).some((p) => (p.endsWith('*') ? id.startsWith(p.slice(0, -1)) : id === p));
const registo = new Set<string>();
for (const m of fs.readdirSync(path.join(RAIZ_FRONTEND, 'src/modulos'))) {
  const f = path.join(RAIZ_FRONTEND, 'src/modulos', m, 'ecras.ts');
  if (fs.existsSync(f)) for (const x of fs.readFileSync(f, 'utf8').matchAll(/^\s*['"]?([a-z0-9_]+)['"]?\s*:\s*(?:lazy|[a-zA-Z]+\()/gm)) registo.add(x[1]);
}

describe('cobertura do índice da pesquisa', () => {
  it('a lista de ecrãs sem pesquisa é explícita e justificada', () => {
    for (const [padrao, motivo] of Object.entries(config.ecrasSemPesquisa)) expect(motivo.length, padrao).toBeGreaterThan(20);
  });

  it('todo o ecrã do registo está no catálogo (senão o menu do servidor nunca o devolve e a ligação dá «sem acesso»)', () => {
    expect([...registo].filter((id) => !ecrasCatalogo.has(id))).toEqual([]);
  });

  it('todo o ecrã do catálogo tem componente registado, excepto os justificados', () => {
    expect([...ecrasCatalogo.keys()].filter((id) => !semPesquisa(id) && !registo.has(id))).toEqual([]);
  });

  it('100 % dos ecrãs do registo e do catálogo têm entrada «ecrã» no índice (e só esses)', () => {
    const doIndice = new Set(INDICE_PESQUISA.filter((e) => e[1] === 'e').map((e) => e[0]));
    const esperados = [...ecrasCatalogo.keys()].filter((id) => !semPesquisa(id));
    expect(esperados.filter((id) => !doIndice.has(id))).toEqual([]);
    expect([...doIndice].filter((id) => !esperados.includes(id))).toEqual([]);
    expect(doIndice.size).toBeGreaterThanOrEqual(126);
  });

  it('nenhuma rota morta: todas as entradas apontam a um ecrã do catálogo ou a uma página conhecida', () => {
    const paginas = new Set(config.paginas.map((p) => p.rota));
    const mortas = INDICE_PESQUISA.filter((e) => (e[1] === 'p' ? !paginas.has(e[0]) : !ecrasCatalogo.has(e[0]) || !registo.has(e[0]))).map((e) => `${e[0]}: ${e[2]}`);
    expect(mortas).toEqual([]);
  });

  it('nenhum ia_chat, plataforma_* ou suporte_tickets_* no ERP do cliente', () => {
    expect(INDICE_PESQUISA.filter((e) => e[0] === 'ia_chat' || e[0].startsWith('plataforma_') || e[0].startsWith('suporte_tickets_'))).toEqual([]);
    expect(INDICE_PESQUISA.filter((e) => /^\/(admin|suporte)/.test(e[0]))).toEqual([]);
  });

  it('as permissões do índice são chaves reais do catálogo (uma chave inventada esconderia a entrada a toda a gente)', () => {
    const tarefas = new Set([...ecrasCatalogo.values()].flatMap((e) => e.tarefas.map((t) => t.chave)));
    const validas = (k: string) => tarefas.has(k) || (k.endsWith('_view') && ecrasCatalogo.has(k.slice(0, -5)));
    const invalidas = INDICE_PESQUISA.flatMap((e) => (e[3] ?? '').split(/[;|]/).filter((k) => k && !validas(k)).map((k) => `${e[0]}: ${e[2]} -> ${k}`));
    expect(invalidas).toEqual([]);
  });

  it('os separadores com ligação directa (?sep=) existem no código do módulo; cada tipo tem entradas', () => {
    const texto = new Map<string, string>();
    const lerModulo = (modulo: string) => {
      if (!texto.has(modulo)) {
        const ler = (d: string): string => fs.readdirSync(d, { withFileTypes: true }).map((x) => (x.isDirectory() ? ler(path.join(d, x.name)) : /\.tsx?$/.test(x.name) ? fs.readFileSync(path.join(d, x.name), 'utf8') : '')).join('\n');
        texto.set(modulo, ler(path.join(RAIZ_FRONTEND, 'src/modulos', modulo)));
      }
      return texto.get(modulo) as string;
    };
    const semChave = INDICE_PESQUISA.filter((e) => ['s', 'c', 'r'].includes(e[1]) && e[4] && !e[4].includes('=') && !lerModulo(ecrasCatalogo.get(e[0])!.modulo).includes(`'${e[4]}'`)).map((e) => `${e[0]}: ${e[2]} -> ${e[4]}`);
    expect(semChave).toEqual([]);
    const porTipo = new Map<string, number>();
    for (const e of INDICE_PESQUISA) porTipo.set(e[1], (porTipo.get(e[1]) ?? 0) + 1);
    for (const t of ['b', 'd', 's', 'c', 'r', 'e', 'a', 'p']) expect(porTipo.get(t) ?? 0, `tipo ${t}`).toBeGreaterThan(0);
  });

  it('as funcionalidades novas desta fase estão indexadas (estrutura orgânica, portal, mapas oficiais, recibos, retenção, delegações)', () => {
    const rotulos = (ecra: string) => INDICE_PESQUISA.filter((e) => e[0] === ecra).map((e) => e[2]);
    expect(rotulos('est_estrutura')).toEqual(expect.arrayContaining(['Delegações', 'Multas de entregáveis', 'Nova delegação', 'Importar Excel']));
    expect(rotulos('rh_portal').length).toBeGreaterThan(5);
    expect(rotulos('rh_rel_irt').length + rotulos('rh_rel_inss').length).toBeGreaterThan(0);
    expect(rotulos('calcular')).toEqual(expect.arrayContaining(['Importar multas (entregáveis aplicadas)']));
    expect(INDICE_PESQUISA.some((e) => e[1] === 'p' && e[2] === 'As minhas sessões')).toBe(true);
  });
});
