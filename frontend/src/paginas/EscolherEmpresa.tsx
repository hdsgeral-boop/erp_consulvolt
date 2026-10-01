import { Button, Card, Empty, Flex, List, Typography } from 'antd';
import { BankOutlined } from '@ant-design/icons';
import { useState } from 'react';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';

export function EscolherEmpresa() {
  const { empresas, escolherEmpresa, sair, utilizador } = useSessao();
  const [aEscolher, setAEscolher] = useState<number | null>(null);
  const escolher = (id: number) => {
    setAEscolher(id);
    escolherEmpresa(id)
      .catch((e) => notificarErro(e))
      .finally(() => setAEscolher(null));
  };

  return (
    <Flex align="center" justify="center" style={{ minHeight: '100vh', background: '#f0f2f5', padding: 16 }}>
      <Card style={{ width: 520 }} title={`Bem-vindo, ${utilizador?.nome_completo || utilizador?.nome_utilizador}`} extra={<Button onClick={() => void sair()}>Sair</Button>}>
        <Typography.Paragraph type="secondary">Escolha a empresa com que vai trabalhar.</Typography.Paragraph>
        {empresas.length === 0 ? (
          <Empty description="Não tem acesso a nenhuma empresa. Contacte o administrador." />
        ) : (
          <List
            dataSource={empresas}
            renderItem={(e) => (
              <List.Item
                actions={[
                  <Button key="entrar" type="primary" loading={aEscolher === e.id} onClick={() => escolher(e.id)}>
                    Entrar
                  </Button>,
                ]}
              >
                <List.Item.Meta avatar={<BankOutlined style={{ fontSize: 22 }} />} title={e.nome} description={e.nif ? `NIF ${e.nif}` : undefined} />
              </List.Item>
            )}
          />
        )}
      </Card>
    </Flex>
  );
}
