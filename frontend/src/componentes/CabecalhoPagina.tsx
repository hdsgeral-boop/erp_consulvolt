import { Flex, Typography } from 'antd';
import type { ReactNode } from 'react';

export function CabecalhoPagina({ titulo, subtitulo, accoes }: { titulo: ReactNode; subtitulo?: ReactNode; accoes?: ReactNode }) {
  return (
    <Flex justify="space-between" align="center" wrap gap={12} style={{ marginBottom: 16 }}>
      <div>
        <Typography.Title level={3} style={{ margin: 0 }}>
          {titulo}
        </Typography.Title>
        {subtitulo && <Typography.Text type="secondary">{subtitulo}</Typography.Text>}
      </div>
      {accoes && <Flex gap={8} wrap>{accoes}</Flex>}
    </Flex>
  );
}
