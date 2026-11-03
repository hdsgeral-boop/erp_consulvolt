import { descarregar, enviar, obter } from '@/api/cliente';
import { enviarFicheiroRh } from '@/modulos/rh/comum/ImportarExcel';
import type {
  DadosDelegacao, DelegacaoChefia, EstadoDelegacao, FiltroMultas, MinhasDelegacoes, MultaEntregavel, RelatorioImportacaoEntregas, ResultadoMultasFolha, ResultadoProposta,
} from './tipos';

/** Cliente tipado da Fase K (rotas da especificação K2–K5). */

const EST = '/rh/estrutura';
const PORTAL = '/rh/portal';

export const CHAVE_MULTAS = ['rh', 'estrutura', 'multas'];
export const CHAVE_DELEGACOES = ['rh', 'estrutura', 'delegacoes'];
export const CHAVE_PORTAL_DELEGACOES = ['rh', 'portal', 'delegacoes'];

/* ---------------------------------------------------------------- K2 */

export const modeloImportacaoEntregas = (entregavelId: number) =>
  descarregar(`${EST}/entregaveis/${entregavelId}/execucoes/modelo`, undefined, 'Modelo_entregas.xlsx');
/** `simular = true` valida sem gravar; com `false` o servidor grava tudo ou nada. */
export const importarEntregas = (entregavelId: number, ficheiro: File, simular: boolean) =>
  enviarFicheiroRh<RelatorioImportacaoEntregas>(`${EST}/entregaveis/${entregavelId}/execucoes/importar`, ficheiro, { simular });

/* ---------------------------------------------------------------- K3 */

export const listarMultas = (filtro?: FiltroMultas) => obter<MultaEntregavel[]>(`${EST}/multas`, filtro as Record<string, unknown> | undefined);
export const proporMultas = (unidades?: number[]) => enviar<ResultadoProposta>('post', `${EST}/multas/propor`, unidades?.length ? { unidades } : {});
export const aplicarMulta = (id: number) => enviar<MultaEntregavel>('post', `${EST}/multas/${id}/aplicar`);
export const dispensarMulta = (id: number, motivo: string) => enviar<MultaEntregavel>('post', `${EST}/multas/${id}/dispensar`, { motivo });
export const reporMulta = (id: number) => enviar<MultaEntregavel>('post', `${EST}/multas/${id}/repor`);
export const importarMultasNaFolha = (periodoId: number, substituir = false) =>
  enviar<ResultadoMultasFolha>('post', `/rh/salarios/periodos/${periodoId}/importar-multas-entregaveis`, { substituir });
export const anularMultasNaFolha = (periodoId: number) => enviar<unknown>('post', `/rh/salarios/periodos/${periodoId}/anular-multas-entregaveis`);

/* ---------------------------------------------------------------- K5 */

export const listarDelegacoes = (filtro?: { estado?: EstadoDelegacao; colaborador_id?: number }) => obter<DelegacaoChefia[]>(`${EST}/delegacoes`, filtro);
export const criarDelegacao = (d: DadosDelegacao) => enviar<DelegacaoChefia>('post', `${EST}/delegacoes`, d);
export const revogarDelegacao = (id: number) => enviar<DelegacaoChefia>('post', `${EST}/delegacoes/${id}/revogar`);

export const minhasDelegacoes = () => obter<MinhasDelegacoes | DelegacaoChefia[]>(`${PORTAL}/delegacoes`);
export const candidatosDelegacao = () => obter<{ id: number; nome: string }[]>(`${PORTAL}/delegacoes/candidatos`);
export const criarDelegacaoPortal = (d: DadosDelegacao) => enviar<DelegacaoChefia>('post', `${PORTAL}/delegacoes`, d);
export const revogarDelegacaoPortal = (id: number) => enviar<DelegacaoChefia>('post', `${PORTAL}/delegacoes/${id}/revogar`);
