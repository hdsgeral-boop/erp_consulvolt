import { Flex, Space, Typography } from 'antd';
import type { ReactNode } from 'react';
import { BotoesExportar, type ObterPedido } from './impressao/BotoesExportar';

interface Props {
  titulo: ReactNode;
  subtitulo?: ReactNode;
  accoes?: ReactNode;
  /** Mostra «Imprimir» e «PDF» (motor comum) a seguir às acções; recebe as opções do documento no momento do clique. */
  impressao?: ObterPedido;
  /** Desactiva os botões de impressão (ex.: dados ainda a carregar). */
  impressaoDesactivada?: boolean;
}

/**
 * Cabeçalho das páginas: título/subtítulo à esquerda e acções à direita. Em ecrãs estreitos o bloco das acções
 * passa para a linha seguinte e os botões quebram linha (Space wrap); o título nunca é empurrado para fora.
 * Aspecto do sistema anterior (h2.view-title): título grande cinzento-ardósia e linha por baixo (src/estilos/identidade.css).
 */
export function CabecalhoPagina({ titulo, subtitulo, accoes, impressao, impressaoDesactivada }: Props) {
  const temAccoes = !!accoes || !!impressao;
  return (
    <Flex justify="space-between" align="center" wrap gap={12} style={{ marginBottom: 16 }} className="cabecalho-pagina">
      <div style={{ minWidth: 0, flex: '1 1 260px' }}>
        <Typography.Title level={3} className="erp-titulo-pagina" style={{ margin: 0, overflowWrap: 'anywhere' }}>
          {titulo}
        </Typography.Title>
        {subtitulo && <Typography.Text type="secondary">{subtitulo}</Typography.Text>}
      </div>
      {temAccoes && (
        <Space wrap size={8} style={{ justifyContent: 'flex-end', maxWidth: '100%' }} className="no-print">
          {accoes}
          {impressao && <BotoesExportar obterPedido={impressao} desactivado={impressaoDesactivada} />}
        </Space>
      )}
    </Flex>
  );
}
