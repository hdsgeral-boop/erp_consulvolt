import { Button, Card, DatePicker, Input, Space, Typography, theme } from 'antd';
import type { Dayjs } from 'dayjs';
import { useState, type ReactNode } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BarraFiltros } from '@/componentes/responsivo';

/**
 * Painel de pesquisa das listas de Compras, como o legado (buildComprasFilterPanelHtml, js/ui_compras_v2.js:71-92):
 * «Pesquisa:», «De / Até:», «Buscar» e «Limpar» numa caixa cinzenta arredondada. Os filtros próprios de cada ecrã
 * (estado, fornecedor, …) entram como filhos, à direita da pesquisa.
 */
export function PainelPesquisaCompras({
  placeholder = 'Nº Doc / Entidade / Justificação...',
  aoPesquisar,
  periodo,
  aoMudarPeriodo,
  aoLimpar,
  children,
}: {
  placeholder?: string;
  aoPesquisar: (texto: string) => void;
  periodo: [Dayjs | null, Dayjs | null] | null;
  aoMudarPeriodo: (p: [Dayjs | null, Dayjs | null] | null) => void;
  aoLimpar?: () => void;
  children?: ReactNode;
}) {
  const { token } = theme.useToken();
  const [texto, setTexto] = useState('');
  const limpar = () => {
    setTexto('');
    aoPesquisar('');
    aoMudarPeriodo(null);
    aoLimpar?.();
  };
  return (
    <Card style={{ marginBottom: 16, background: token.colorFillAlter }} styles={{ body: { paddingBottom: 8 } }}>
      <BarraFiltros
        accoes={
          <Space wrap>
            <Button type="primary" icon={<IconeFa nome="search" />} onClick={() => aoPesquisar(texto.trim())}>
              Buscar
            </Button>
            <Button icon={<IconeFa nome="undo" />} onClick={limpar}>
              Limpar
            </Button>
          </Space>
        }
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, flex: 1, minWidth: 260 }}>
          <Typography.Text strong type="secondary" style={{ whiteSpace: 'nowrap' }}>
            <IconeFa nome="search" style={{ color: token.colorPrimary }} /> Pesquisa:
          </Typography.Text>
          <Input
            placeholder={placeholder}
            allowClear
            value={texto}
            aria-label="Pesquisa"
            style={{ width: '100%' }}
            onChange={(e) => {
              setTexto(e.target.value);
              if (!e.target.value) aoPesquisar('');
            }}
            onPressEnter={() => aoPesquisar(texto.trim())}
          />
        </div>
        <Space size={6} wrap>
          <Typography.Text strong style={{ color: '#475569' }}>
            <IconeFa nome="calendar-alt" style={{ color: '#2563eb' }} /> De / Até:
          </Typography.Text>
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => aoMudarPeriodo(v)} placeholder={['De', 'Até']} />
        </Space>
        {children}
      </BarraFiltros>
    </Card>
  );
}
