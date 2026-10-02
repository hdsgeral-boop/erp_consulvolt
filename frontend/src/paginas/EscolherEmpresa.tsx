import { Avatar, Button, Card, Empty, List, Typography, theme } from 'antd';
import { LogoutOutlined } from '@ant-design/icons';
import { useState } from 'react';
import { useSessao } from '@/sessao/SessaoContexto';
import { iniciais } from '@/layout/marca';
import { notificarErro } from '@/utilitarios/erros';

/** Escolha da empresa a seguir ao login (responsiva: cartão com largura máxima e lista que quebra linha), sobre o fundo da entrada. */
export function EscolherEmpresa() {
  const { empresas, escolherEmpresa, sair, utilizador } = useSessao();
  const { token } = theme.useToken();
  const [aEscolher, setAEscolher] = useState<number | null>(null);
  const escolher = (id: number) => {
    setAEscolher(id);
    escolherEmpresa(id)
      .catch((e) => notificarErro(e))
      .finally(() => setAEscolher(null));
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada">
      <Card
        className="erp-cartao-entrada"
        style={{ width: '100%', maxWidth: 560 }}
        title={<span style={{ whiteSpace: 'normal' }}>{`Bem-vindo, ${utilizador?.nome_completo || utilizador?.nome_utilizador}`}</span>}
        extra={
          <Button icon={<LogoutOutlined />} onClick={() => void sair()}>
            Sair
          </Button>
        }
      >
        <Typography.Paragraph type="secondary">Escolha a empresa com que vai trabalhar.</Typography.Paragraph>
        {empresas.length === 0 ? (
          <Empty description="Não tem acesso a nenhuma empresa. Contacte o administrador." />
        ) : (
          <List
            dataSource={empresas}
            renderItem={(e) => (
              <List.Item
                style={{ flexWrap: 'wrap', gap: 8 }}
                actions={[
                  <Button key="entrar" type="primary" loading={aEscolher === e.id} onClick={() => escolher(e.id)}>
                    Entrar
                  </Button>,
                ]}
              >
                <List.Item.Meta
                  style={{ minWidth: 0 }}
                  avatar={
                    <Avatar shape="square" style={{ background: token.colorPrimary, fontWeight: 600 }} aria-hidden>
                      {iniciais(e.nome)}
                    </Avatar>
                  }
                  title={e.nome}
                  description={e.nif ? `NIF ${e.nif}` : undefined}
                />
              </List.Item>
            )}
          />
        )}
      </Card>
    </main>
  );
}
