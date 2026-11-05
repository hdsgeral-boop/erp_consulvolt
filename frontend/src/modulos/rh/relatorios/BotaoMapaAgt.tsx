import { Alert, Button, Tooltip } from 'antd';
import { FileExcelOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { descarregar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { descarregar as guardarTexto } from '../comum/componentes';
import { gerarCsv } from '../comum/regras';

/** Rótulo do botão (a pesquisa e a ajuda reconhecem também «Imprimir Formato AGT»). */
export const ROTULO_MAPA_AGT = 'Exportar Mapa de Remunerações — Modelo AGT (IRT A2.1)';

export interface VerificacaoMapaAgt {
  colaboradores: number;
  avencados_excluidos: number;
  ficheiro: string;
  avisos: { colaborador_id: number; nome: string; codigo: 'SEM_NIF' | 'SEM_NSS' | 'SEM_LOCALIZACAO'; mensagem: string }[];
}

/** Resumo em português dos avisos de preenchimento (por tipo), para o ecrã. */
export function resumoAvisos(v: Pick<VerificacaoMapaAgt, 'avisos'>): string[] {
  const nomes = (c: string) => v.avisos.filter((a) => a.codigo === c).map((a) => a.nome);
  return ([['SEM_NIF', 'sem NIF'], ['SEM_NSS', 'sem n.º de Segurança Social'], ['SEM_LOCALIZACAO', 'sem província/município']] as const)
    .map(([c, t]) => ({ t, n: nomes(c) })).filter((x) => x.n.length).map((x) => `${x.n.length} colaborador(es) ${x.t}: ${x.n.join(', ')}`);
}

function useVerificacaoMapaAgt(periodoId: number | undefined, validado: boolean) {
  return useQuery({
    queryKey: ['rh', 'salarios', 'mapa-agt', 'verificacao', String(periodoId)],
    queryFn: () => obter<VerificacaoMapaAgt>(`/rh/salarios/periodos/${periodoId}/mapa-agt/verificacao`),
    enabled: periodoId !== undefined && validado,
  }).data;
}

/**
 * Descarrega o Mapa de Remunerações no modelo oficial da AGT (Template IRT A2.1) do período; só períodos validados.
 * Antes mostra os avisos (colaboradores sem NIF, n.º de SS ou localização): o ficheiro gera-se na mesma, com essas células vazias.
 */
export function BotaoMapaAgt({ periodoId, validado }: { periodoId?: number; validado: boolean }) {
  const [aDescarregar, setADescarregar] = useState(false);
  const v = useVerificacaoMapaAgt(periodoId, validado);
  const descarregarMapa = async () => {
    if (periodoId === undefined) return;
    setADescarregar(true);
    try {
      await descarregar(`/rh/salarios/periodos/${periodoId}/mapa-agt`, undefined, v?.ficheiro ?? 'Mapa Remuneração.xlsx');
    } catch (e) {
      notificarErro(e);
    } finally {
      setADescarregar(false);
    }
  };
  return (
      <Tooltip title={validado ? 'Ficheiro .xlsx com a estrutura do modelo oficial da AGT, preenchido com a folha validada (imprimir/submeter no portal da AGT)' : 'Só disponível para processamentos validados'}>
        <span>
          <Button icon={<FileExcelOutlined />} type="primary" loading={aDescarregar} disabled={!validado || periodoId === undefined || !v?.colaboradores} onClick={() => void descarregarMapa()}>
            {ROTULO_MAPA_AGT}
          </Button>
        </span>
      </Tooltip>
  );
}

/** Avisos de preenchimento do mapa no modelo da AGT (colaboradores sem NIF, n.º de SS ou localização). */
export function AvisoMapaAgt({ periodoId, validado }: { periodoId?: number; validado: boolean }) {
  const v = useVerificacaoMapaAgt(periodoId, validado);
  const ficheiroAvisos = () => v && guardarTexto(`avisos-mapa-agt-${periodoId}.csv`, gerarCsv(['Colaborador', 'Código', 'Aviso'], v.avisos.map((a) => [a.nome, a.codigo, a.mensagem])));
  if (!v || v.avisos.length === 0) return null;
  return (
    <Alert className="rh-nao-imprimir" type="warning" showIcon style={{ marginBottom: 16 }} title="Mapa no modelo da AGT: dados em falta"
      description={<>{resumoAvisos(v).map((t) => <div key={t}>{t}</div>)}<Button type="link" size="small" style={{ padding: 0 }} onClick={ficheiroAvisos}>Descarregar lista de avisos (CSV)</Button></>} />
  );
}
