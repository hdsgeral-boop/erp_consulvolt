import { Button, Card, Flex, Select, Tag, Typography, theme } from 'antd';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { formatarData } from '@/utilitarios/formatacao';
import type { GuiaSaidaPendente, MeioPagamentoVenda } from '../api';

const AZUL = 'var(--ant-color-primary, #2563eb)';

/**
 * Paridade 4.2.9 — quadro «GUIAS DE SAÍDA PENDENTES DE FATURAÇÃO» do formulário de factura do legado (ui_sales.js
 * ~l. 985): um cartão por guia (número, data, cliente, «PENDENTE», «FATURAR AGORA →»).
 */
export function GuiasPendentes({ guias, escolhida, aoEscolher }: {
  guias: GuiaSaidaPendente[];
  escolhida?: number;
  aoEscolher: (g: GuiaSaidaPendente) => void;
}) {
  // cores do tema (tela nocturna) e raios a 4 px (regra global do antigo)
  const { token } = theme.useToken();
  return (
    <div style={{ marginBottom: 16, padding: 16, background: token.colorFillAlter, border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 4 }}>
      <h4 style={{ margin: '0 0 12px', fontSize: 13, color: token.colorTextSecondary, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
        <IconeFa nome="truck-loading" style={{ marginRight: 6 }} />Guias de Saída Pendentes de Faturação
      </h4>
      <div style={{ display: 'flex', gap: 16, overflowX: 'auto', paddingBottom: 8 }} role="list">
        {guias.map((g) => {
          const activa = g.id === escolhida;
          return (
            <button
              type="button"
              role="listitem"
              key={g.id}
              onClick={() => aoEscolher(g)}
              aria-pressed={activa}
              aria-label={`Facturar agora a guia ${g.numero_documento ?? g.id}`}
              style={{
                flex: '0 0 240px', padding: 12, background: token.colorBgContainer, color: token.colorText, textAlign: 'left', cursor: 'pointer', borderRadius: 4,
                border: `1px solid ${activa ? token.colorPrimary : token.colorBorder}`, boxShadow: activa ? '0 2px 4px rgba(0,0,0,0.08)' : 'none',
              }}
            >
              <Flex justify="space-between" style={{ marginBottom: 4 }}>
                <strong style={{ fontSize: 13 }}>{g.numero_documento ?? `#${g.id}`}</strong>
                <span style={{ fontSize: 11, color: token.colorTextSecondary }}>{g.data ? formatarData(g.data) : ''}</span>
              </Flex>
              <div style={{ fontSize: 12, color: token.colorTextSecondary, marginBottom: 8, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {g.cliente?.nome ?? 'Consumidor Final'}
              </div>
              <Flex justify="space-between" align="center">
                <Tag color="warning" style={{ fontSize: 10, margin: 0 }}>PENDENTE</Tag>
                <span style={{ fontSize: 11, color: AZUL, fontWeight: 600 }}>FATURAR AGORA <IconeFa nome="arrow-right" /></span>
              </Flex>
            </button>
          );
        })}
      </div>
    </div>
  );
}

/**
 * Paridade 4.2.12 — quadro «Meios de Pagamento» com «Adicionar Conta» (contas da Tesouraria a mostrar no documento) e a
 * opção «Ocultar quadro de Meios de Pagamento na impressão» (o checkbox é um Form.Item do formulário, passado em `ocultar`).
 */
export function MeiosPagamentoDocumento({ meios, contas, aoMudar, carregando, ocultar }: {
  meios: MeioPagamentoVenda[];
  contas: (number | undefined)[];
  aoMudar: (contas: (number | undefined)[]) => void;
  carregando?: boolean;
  ocultar?: React.ReactNode;
}) {
  const { token } = theme.useToken();
  const usados = new Set(contas.filter(Boolean));
  const rotulo = (m: MeioPagamentoVenda) => [m.nome, m.iban ? `IBAN ${m.iban}` : m.codigo_conta].filter(Boolean).join(' — ');
  return (
    <Card size="small" style={{ marginBottom: 16, background: token.colorFillAlter }}>
      <Flex justify="space-between" align="center" style={{ marginBottom: 12 }}>
        <span style={{ fontWeight: 700, fontSize: 13 }}><IconeFa nome="credit-card" style={{ color: AZUL, marginRight: 6 }} />Meios de Pagamento</span>
        <Button size="small" icon={<IconeFa nome="plus" />} onClick={() => aoMudar([...contas, undefined])} disabled={!meios.length || contas.length >= 10}>
          Adicionar Conta
        </Button>
      </Flex>
      <Flex vertical gap={8}>
        {contas.map((id, i) => (
          <Flex key={i} gap={8} align="center">
            <Select
              style={{ flex: 1 }}
              value={id}
              loading={carregando}
              placeholder="Escolha a conta"
              aria-label={`Conta de pagamento ${i + 1}`}
              onChange={(v: number) => aoMudar(contas.map((c, k) => (k === i ? v : c)))}
              options={meios.map((m) => ({ value: m.id, label: rotulo(m), disabled: usados.has(m.id) && m.id !== id }))}
            />
            <Button danger size="small" icon={<IconeFa nome="times" />} aria-label={`Remover conta ${i + 1}`} onClick={() => aoMudar(contas.filter((_, k) => k !== i))} />
          </Flex>
        ))}
      </Flex>
      {!meios.length && !carregando && (
        <Typography.Paragraph type="secondary" style={{ fontSize: 12, margin: '8px 0 0' }}>
          Configure os meios de pagamento em <strong>Tesouraria ▸ Gestão ▸ Meios de Pagamento</strong> para os disponibilizar aqui.
        </Typography.Paragraph>
      )}
      {ocultar && <div style={{ marginTop: 12 }}>{ocultar}</div>}
    </Card>
  );
}
