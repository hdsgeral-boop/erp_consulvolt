import { Flex, Typography } from 'antd';
import type { ReactNode } from 'react';

/** Título do separador dentro do ecrã «Relatórios e mapas contabilísticos» (legado: <h3> no cartão do mapa). */
export function TituloMapa({ titulo, subtitulo, accoes }: { titulo: ReactNode; subtitulo?: ReactNode; accoes?: ReactNode }) {
  return (
    <Flex justify="space-between" align="center" wrap gap={8} style={{ marginBottom: 12 }}>
      <div style={{ minWidth: 0 }}>
        <Typography.Title level={4} style={{ margin: 0 }}>
          {titulo}
        </Typography.Title>
        {subtitulo && <Typography.Text type="secondary">{subtitulo}</Typography.Text>}
      </div>
      {accoes}
    </Flex>
  );
}
