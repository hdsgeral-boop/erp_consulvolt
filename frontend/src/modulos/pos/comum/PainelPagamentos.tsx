import { Alert, Button, Flex, Input, InputNumber, Space, Statistic, Tag, Typography } from 'antd';
import { BankOutlined, CreditCardOutlined, DeleteOutlined, DollarOutlined } from '@ant-design/icons';
import type { ReactNode } from 'react';
import { formatarCentimos, resumirPagamentos, sugestoesNumerario, type Pagamento } from './calculos';
import type { MeioPagamento, TipoMeio } from './tipos';

const ICONES: Record<TipoMeio, ReactNode> = {
  NUMERARIO: <DollarOutlined />,
  TPA: <CreditCardOutlined />,
  TRANSFERENCIA: <BankOutlined />,
};

let sequencia = 0;
export function novaChave(): string {
  sequencia += 1;
  return `p${Date.now().toString(36)}${sequencia}`;
}

/** Meios activos do terminal (com id e conta transitória), pela ordem configurada. */
export function meiosActivos(meios: MeioPagamento[] | null | undefined): (MeioPagamento & { id: string })[] {
  return (meios ?? []).filter((m): m is MeioPagamento & { id: string } => !!m.ativo && !!m.id);
}

/** Acrescenta um pagamento pelo meio indicado, com o valor em falta (ou o indicado). */
export function acrescentarPagamento(pagamentos: Pagamento[], meio: MeioPagamento & { id: string }, total: number, valor?: number): Pagamento[] {
  const falta = resumirPagamentos(pagamentos, total).falta;
  return [...pagamentos, { chave: novaChave(), meio_id: meio.id, tipo: meio.tipo, nome: meio.nome, valor: valor ?? falta }];
}

interface Props {
  meios: (MeioPagamento & { id: string })[];
  /** Total a cobrar, em cêntimos. */
  total: number;
  pagamentos: Pagamento[];
  onChange: (p: Pagamento[]) => void;
  /** Botões grandes (ecrã táctil da frente de caixa). */
  grande?: boolean;
  desactivado?: boolean;
}

/**
 * Pagamento misto: vários meios na mesma operação, troco só em numerário, comprovativo obrigatório nas transferências.
 * Usado na frente de caixa, na caixa da lavandaria e no check-out da hotelaria.
 */
export function PainelPagamentos({ meios, total, pagamentos, onChange, grande, desactivado }: Props) {
  const resumo = resumirPagamentos(pagamentos, total);
  const tamanho = grande ? 'large' : 'middle';
  const numerario = meios.find((m) => m.tipo === 'NUMERARIO');

  const alterar = (chave: string, alteracao: Partial<Pagamento>) => onChange(pagamentos.map((p) => (p.chave === chave ? { ...p, ...alteracao } : p)));

  return (
    <Flex vertical gap={12}>
      <Flex gap={8} wrap>
        {meios.map((m) => (
          <Button key={m.id} size={tamanho} icon={ICONES[m.tipo]} disabled={desactivado} onClick={() => onChange(acrescentarPagamento(pagamentos, m, total))}>
            {m.nome}
          </Button>
        ))}
      </Flex>

      {numerario && total > 0 && (
        <Flex gap={6} wrap align="center">
          <Typography.Text type="secondary">Numerário rápido:</Typography.Text>
          {sugestoesNumerario(total).map((v) => (
            <Button
              key={v}
              size={grande ? 'middle' : 'small'}
              disabled={desactivado}
              onClick={() => onChange([...pagamentos.filter((p) => p.tipo !== 'NUMERARIO'), { chave: novaChave(), meio_id: numerario.id, tipo: 'NUMERARIO', nome: numerario.nome, valor: v }])}
            >
              {formatarCentimos(v)}
            </Button>
          ))}
        </Flex>
      )}

      {pagamentos.map((p) => (
        <Flex key={p.chave} gap={8} align="center" wrap>
          <Tag icon={ICONES[p.tipo]} style={{ minWidth: 110, padding: grande ? '4px 8px' : undefined }}>
            {p.nome}
          </Tag>
          <InputNumber<number>
            aria-label={`Valor ${p.nome}`}
            size={tamanho}
            min={0}
            step={100}
            precision={2}
            decimalSeparator=","
            style={{ width: 170, maxWidth: '100%' }}
            value={p.valor / 100}
            disabled={desactivado}
            onChange={(v) => alterar(p.chave, { valor: Math.round((v ?? 0) * 100) })}
            suffix="Kz"
          />
          {p.tipo !== 'NUMERARIO' && (
            <Input
              aria-label={`Comprovativo ${p.nome}`}
              size={tamanho}
              style={{ width: 200, maxWidth: '100%' }}
              maxLength={100}
              disabled={desactivado}
              status={p.tipo === 'TRANSFERENCIA' && !p.referencia?.trim() ? 'error' : undefined}
              placeholder={p.tipo === 'TRANSFERENCIA' ? 'N.º do comprovativo (obrigatório)' : 'Referência (opcional)'}
              value={p.referencia}
              onChange={(e) => alterar(p.chave, { referencia: e.target.value })}
            />
          )}
          <Button size={tamanho} danger type="text" icon={<DeleteOutlined />} aria-label="Retirar pagamento" disabled={desactivado} onClick={() => onChange(pagamentos.filter((x) => x.chave !== p.chave))} />
        </Flex>
      ))}

      <Flex gap={24} wrap>
        <Statistic title="Total" value={formatarCentimos(total)} suffix="Kz" />
        <Statistic title="Recebido" value={formatarCentimos(resumo.recebido)} suffix="Kz" />
        {resumo.falta > 0 ? (
          <Statistic title="Falta" value={formatarCentimos(resumo.falta)} suffix="Kz" valueStyle={{ color: '#cf1322' }} />
        ) : (
          <Statistic title="Troco" value={formatarCentimos(resumo.troco)} suffix="Kz" valueStyle={{ color: '#3f8600', fontWeight: 600 }} />
        )}
      </Flex>

      {pagamentos.length > 0 && resumo.erros.length > 0 && (
        <Alert
          type="warning"
          showIcon
          message={
            <Space direction="vertical" size={0}>
              {resumo.erros.map((e) => (
                <span key={e}>{e}</span>
              ))}
            </Space>
          }
        />
      )}
    </Flex>
  );
}
