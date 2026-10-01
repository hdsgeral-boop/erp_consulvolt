import { Typography } from 'antd';
import { formatarKz } from '@/utilitarios/formatacao';

/** Valor em Kz e, quando o documento é noutra moeda, o valor na moeda de origem. */
export function ValorMoeda({ kz, moeda, valorMoeda }: { kz: string | null | undefined; moeda?: string | null; valorMoeda?: string | null }) {
  const estrangeira = !!moeda && moeda !== 'AOA' && valorMoeda;
  return (
    <>
      {formatarKz(kz)}
      {estrangeira && (
        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
          {formatarKz(valorMoeda)} {moeda}
        </Typography.Text>
      )}
    </>
  );
}
