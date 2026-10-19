import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

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
import { useSearchParams } from 'react-router-dom';
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
        ['vendas_config', 's', 'Modelos de impressão', 'vendas_cfg', 'modelos'],
        ['vendas_config', 's', 'Séries', '', 'series'],
        ['vendas_faturacao', 'b', 'Anular documento', 'vendas_fat_del'],
        ['vendas_faturacao', 'b', 'Contabilizar seleccionados', 'vendas_fat_contabilizar'],
        ['vendas_faturacao', 'b', 'Emitir factura', 'vendas_fat_emitir|vendas_fat_post'],
        ['vendas_faturacao', 'b', 'Fluxo Documental', ''],
        ['vendas_faturacao', 'b', 'Imprimir lista', ''],
        ['vendas_faturacao', 's', 'Dados gerais', ''],
        ['vendas_faturacao', 's', 'Lista de documentos', '', 'separador=lista'],
        ['vendas_faturacao', 's', 'Recibos', 'vendas_recibos', 'separador=recibos'],
        ['vendas_mapa', 'b', 'Exportar mapa', ''],
      ]);
      expect(texto).toContain('export const INDICE_PESQUISA');
    } finally {
      fs.rmSync(raiz, { recursive: true, force: true });
    }
  });
});
