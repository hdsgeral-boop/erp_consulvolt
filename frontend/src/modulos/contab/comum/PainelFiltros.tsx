import { Button, Card, Col, Flex, Row, Typography, theme } from 'antd';
import { FilterOutlined } from '@ant-design/icons';
import { useState, type ReactNode } from 'react';
import { useEcra } from '@/componentes/responsivo';

/**
 * Painel de filtros agrupado, como o «filtros-painel» do sistema anterior (js/ui_lancamentos.js:467-615; css/styles.css:1420-1660):
 * barra opcional no topo («Secções»), secções compactas lado a lado numa só linha em ecrãs largos (grelha
 * «auto-fit, minmax(…, 1fr)» como «.filtros-grupos» — passam à linha seguinte quando não cabem), cada uma com
 * título em maiúsculas e ícone e os campos dois a dois, e uma barra de acções no fundo (filtros rápidos e alteração em
 * massa à esquerda, «Filtrar» à direita). Em telemóvel as secções começam fechadas e os campos ficam um por linha.
 */
export function PainelFiltros({
  children,
  rodape,
  topo,
  aria = 'Filtros',
  larguraMinima = 250,
}: {
  children: ReactNode;
  rodape?: ReactNode;
  topo?: ReactNode;
  aria?: string;
  larguraMinima?: number;
}) {
  const { token } = theme.useToken();
  const { telemovel } = useEcra();
  // em telemóvel as secções começam fechadas (como «Ocultar todas» do legado), para a lista ficar à vista
  const [aberto, setAberto] = useState(false);
  const mostrar = !telemovel || aberto;
  return (
    <Card size="small" style={{ marginBottom: 12, background: token.colorFillAlter }} styles={{ body: { padding: '8px 12px' } }} role="search" aria-label={aria}>
      {telemovel && (
        <Button block icon={<FilterOutlined />} onClick={() => setAberto((a) => !a)} aria-expanded={aberto} style={{ marginBottom: mostrar ? 8 : 0 }}>
          {aberto ? 'Ocultar secções dos filtros' : 'Mostrar secções dos filtros'}
        </Button>
      )}
      {mostrar && topo && <div style={{ paddingBottom: 6, marginBottom: 8, borderBottom: `1px dashed ${token.colorBorderSecondary}` }}>{topo}</div>}
      {mostrar && (
        <div data-grelha-filtros="" style={{ display: 'grid', gridTemplateColumns: `repeat(auto-fit, minmax(min(${larguraMinima}px, 100%), 1fr))`, gap: 8 }}>
          {children}
        </div>
      )}
      {rodape && (
        <Flex wrap gap={8} align="center" justify="space-between" style={{ marginTop: 8, paddingTop: 8, borderTop: `1px solid ${token.colorBorderSecondary}` }}>
          {rodape}
        </Flex>
      )}
    </Card>
  );
}

/** Secção do painel (uma caixa da grelha): título com ícone e campos dois a dois. */
export function GrupoFiltros({ titulo, icone, children }: { titulo: string; icone?: ReactNode; children: ReactNode }) {
  const { token } = theme.useToken();
  return (
    <section
      aria-label={titulo}
      style={{ background: token.colorBgContainer, border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadius, padding: '6px 10px 8px', minWidth: 0 }}
    >
      <Typography.Text
        strong
        title={titulo}
        style={{ display: 'block', fontSize: 11, letterSpacing: 0.4, textTransform: 'uppercase', color: token.colorTextSecondary, marginBottom: 4, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}
      >
        {icone} {titulo}
      </Typography.Text>
      <Row gutter={[6, 4]}>{children}</Row>
    </section>
  );
}

/** Campo de um grupo: rótulo por cima (ligado ao controlo por `htmlFor`) e o controlo a toda a largura (meia caixa, ou a caixa toda se `largo`). */
export function CampoFiltro({ rotulo, htmlFor, children, largo }: { rotulo: string; htmlFor?: string; children: ReactNode; largo?: boolean }) {
  return (
    <Col xs={24} sm={largo ? 24 : 12} style={{ minWidth: 0 }}>
      <label
        htmlFor={htmlFor}
        title={rotulo}
        style={{ display: 'block', fontSize: 11, fontWeight: 600, marginBottom: 1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}
      >
        {rotulo}
      </label>
      {children}
    </Col>
  );
}
