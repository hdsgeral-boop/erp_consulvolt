import { Result } from 'antd';

/** Ecrã ainda não construído na Fase 5 (a API correspondente já existe no backend). */
export function EmConstrucao({ nome }: { nome?: string }) {
  return <Result status="info" title={nome ?? 'Ecrã em construção'} subTitle="Este ecrã será disponibilizado numa próxima etapa da Fase 5." />;
}
