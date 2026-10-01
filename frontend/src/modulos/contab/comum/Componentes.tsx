import { Button, Flex, Statistic, Tag, Typography } from 'antd';
import { DownloadOutlined } from '@ant-design/icons';
import { formatarKz } from '@/utilitarios/formatacao';
import { descarregarCsv, gerarCsv, type ColunaCsv } from '@/utilitarios/csv';
import { eZero, equilibrio, negativo, type LinhaDC, type Valor } from '@/utilitarios/decimal';

/** Valor em Kz alinhado à direita; negativos a vermelho; zeros discretos (opcional). */
export function ValorKz({ valor, forte, discretoSeZero }: { valor: Valor; forte?: boolean; discretoSeZero?: boolean }) {
  const tipo = negativo(valor) ? 'danger' : discretoSeZero && eZero(valor) ? 'secondary' : undefined;
  return (
    <Typography.Text type={tipo} strong={forte} style={{ whiteSpace: 'nowrap' }}>
      {formatarKz(valor === null || valor === undefined ? valor : String(valor))}
    </Typography.Text>
  );
}

/** Totais de débito/crédito e estado do equilíbrio (D = C) de um conjunto de linhas. */
export function IndicadorEquilibrio({ linhas }: { linhas: (LinhaDC | null | undefined)[] }) {
  const e = equilibrio(linhas);
  return (
    <Flex gap={32} wrap align="center" justify="end" data-testid="indicador-equilibrio">
      <Statistic title="Total a débito" value={formatarKz(e.debito)} />
      <Statistic title="Total a crédito" value={formatarKz(e.credito)} />
      <Statistic
        title="Diferença"
        value={formatarKz(e.diferenca)}
        valueStyle={{ color: e.equilibrado ? undefined : '#cf1322' }}
      />
      {e.equilibrado && e.valido ? <Tag color="green">Equilibrado</Tag> : e.equilibrado ? <Tag>Sem valores</Tag> : <Tag color="red">Desequilibrado</Tag>}
    </Flex>
  );
}

/** Botão que exporta para CSV as linhas mostradas (no cliente). */
export function BotaoCsv<T>({ nome, colunas, linhas, disabled }: { nome: string; colunas: ColunaCsv<T>[]; linhas: T[] | undefined; disabled?: boolean }) {
  return (
    <Button icon={<DownloadOutlined />} disabled={disabled || !linhas?.length} onClick={() => descarregarCsv(nome, gerarCsv(colunas, linhas ?? []))}>
      Exportar CSV
    </Button>
  );
}

const CORES: Record<string, string> = {
  PENDENTE: 'orange', INTEGRADO: 'green', ANULADO: 'red', ANULADA: 'red', ABERTA: 'blue', FECHADA: 'gold', CONTABILIZADA: 'green',
  RASCUNHO: 'default', FINALIZADO: 'green', APROVADO: 'green', CONCILIADO: 'green', CONCILIADO_BANCO: 'green', EXECUTADA: 'green',
  CONCLUIDA: 'green', ESTORNADO: 'red', ESTORNO: 'purple', CONTABILIZADO: 'green', ENCERRADO: 'red', ABERTO: 'green',
};

const ROTULOS: Record<string, string> = {
  PENDENTE: 'Pendente', INTEGRADO: 'Integrado', ANULADO: 'Anulado', ANULADA: 'Anulada', ABERTA: 'Aberta', FECHADA: 'Fechada', CONTABILIZADA: 'Contabilizada',
  RASCUNHO: 'Rascunho', FINALIZADO: 'Finalizado', APROVADO: 'Concluído', CONCILIADO: 'Conciliado', CONCILIADO_BANCO: 'Conciliado', EXECUTADA: 'Executada',
  CONCLUIDA: 'Concluída', ESTORNADO: 'Estornado', ESTORNO: 'Estorno', CONTABILIZADO: 'Contabilizado', ENCERRADO: 'Encerrado', ABERTO: 'Aberto',
};

export function EtiquetaEstado({ estado }: { estado: string | null | undefined }) {
  if (!estado) return <>—</>;
  return <Tag color={CORES[estado] ?? 'default'}>{ROTULOS[estado] ?? estado}</Tag>;
}
