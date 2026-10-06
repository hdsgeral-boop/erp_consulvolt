import { Button, Card, Col, Flex, Row, Typography, theme } from 'antd';
import { FilterOutlined } from '@ant-design/icons';
import { useState, type ReactNode } from 'react';
import { useEcra } from '@/componentes/responsivo';

/**
 * Painel de filtros agrupado, como o «filtros-painel» do sistema anterior (js/ui_lancamentos.js:467-615):
 * secções com título em maiúsculas e ícone («PERÍODO FISCAL», «CONTA E DIÁRIO»…), campos com rótulo por cima
 * e uma barra de acções no fundo (filtros rápidos à esquerda, «Filtrar» à direita).
 * Responsivo: as secções passam a uma por linha abaixo de 992 px e os campos a um por linha em telemóvel.
 */
export function PainelFiltros({ children, rodape, aria = 'Filtros' }: { children: ReactNode; rodape?: ReactNode; aria?: string }) {
  const { token } = theme.useToken();
  const { telemovel } = useEcra();
  // em telemóvel as secções começam fechadas (como «Ocultar todas» do legado), para a lista ficar à vista
  const [aberto, setAberto] = useState(false);
  const mostrar = !telemovel || aberto;
  return (
    <Card size="small" style={{ marginBottom: 16, background: token.colorFillAlter }} styles={{ body: { padding: 12 } }} role="search" aria-label={aria}>
      {telemovel && (
        <Button block icon={<FilterOutlined />} onClick={() => setAberto((a) => !a)} aria-expanded={aberto} style={{ marginBottom: mostrar ? 12 : 0 }}>
          {aberto ? 'Ocultar secções dos filtros' : 'Mostrar secções dos filtros'}
        </Button>
      )}
      {mostrar && <Row gutter={[12, 12]}>{children}</Row>}
      {rodape && (
        <Flex wrap gap={12} align="center" justify="space-between" style={{ marginTop: 12, paddingTop: 12, borderTop: `1px solid ${token.colorBorderSecondary}` }}>
          {rodape}
        </Flex>
      )}
    </Card>
  );
}

/** Secção do painel (Col): título com ícone e campos em grelha. `largura` em colunas de 24 a partir de lg. */
export function GrupoFiltros({ titulo, icone, children, largura = 8 }: { titulo: string; icone?: ReactNode; children: ReactNode; largura?: number }) {
  const { token } = theme.useToken();
  return (
    <Col xs={24} lg={largura}>
      <section
        aria-label={titulo}
        style={{ background: token.colorBgContainer, border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadius, padding: '10px 12px', height: '100%' }}
      >
        <Typography.Text strong style={{ display: 'block', fontSize: 11, letterSpacing: 0.4, textTransform: 'uppercase', color: token.colorTextSecondary, marginBottom: 8 }}>
          {icone} {titulo}
        </Typography.Text>
        <Row gutter={[8, 8]}>{children}</Row>
      </section>
    </Col>
  );
}

/** Campo de um grupo: rótulo por cima (ligado ao controlo por `htmlFor`) e o controlo a toda a largura. */
export function CampoFiltro({ rotulo, htmlFor, children, largo }: { rotulo: string; htmlFor?: string; children: ReactNode; largo?: boolean }) {
  return (
    <Col xs={24} sm={largo ? 24 : 12}>
      <label htmlFor={htmlFor} style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 2 }}>
        {rotulo}
      </label>
      {children}
    </Col>
  );
}
