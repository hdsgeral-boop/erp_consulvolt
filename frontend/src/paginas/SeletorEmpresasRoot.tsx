import { Button, Empty, Input, List, Pagination, Tag, Typography } from 'antd';
import { SearchOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obterPagina } from '@/api/cliente';

/** Empresa de qualquer cliente, como devolvida por GET /plataforma/root/empresas (só metadados, sem dados pessoais). */
export interface EmpresaDeCliente {
  id: number;
  nome: string;
  estado: string;
  cliente_id: number;
  cliente_nome: string | null;
}

const POR_PAGINA = 10;

/**
 * Selector de empresas de TODOS os clientes para a conta root global (ADR-084), que não pertence a nenhum: pesquisa
 * no servidor (nome da empresa ou do cliente), paginado. Escolher uma empresa passa por `escolher` (escolherEmpresa da
 * sessão: PUT /autenticacao/empresa-activa, validado no servidor — o frontend nunca decide o acesso).
 */
export function SeletorEmpresasRoot({ aEscolher, escolher }: { aEscolher: number | null; escolher: (id: number) => void }) {
  const [pesquisa, setPesquisa] = useState('');
  const [termo, setTermo] = useState('');
  const [pagina, setPagina] = useState(1);

  // pesquisa adiada: não pergunta ao servidor a cada tecla
  useEffect(() => {
    const t = window.setTimeout(() => {
      setTermo(pesquisa.trim());
      setPagina(1);
    }, 300);
    return () => window.clearTimeout(t);
  }, [pesquisa]);

  const q = useQuery({
    queryKey: ['root-empresas', termo, pagina],
    queryFn: () => obterPagina<EmpresaDeCliente>('/plataforma/root/empresas', { pesquisa: termo || undefined, pagina, por_pagina: POR_PAGINA }),
    placeholderData: (anterior) => anterior,
    retry: false,
  });
  const lista = q.data?.itens ?? [];
  const total = q.data?.paginacao.total ?? 0;

  return (
    <section aria-label="Empresas de todos os clientes" data-testid="seletor-root">
      <Typography.Title level={5} style={{ marginTop: 0 }}>
        Empresas de todos os clientes
      </Typography.Title>
      <Input
        allowClear
        prefix={<SearchOutlined />}
        placeholder="Pesquisar por empresa ou cliente"
        aria-label="Pesquisar empresa de qualquer cliente"
        value={pesquisa}
        onChange={(ev) => setPesquisa(ev.target.value)}
        style={{ marginBottom: 6 }}
      />
      <div className="erp-lista-escolha" role="region" aria-label="Empresas de todos os clientes disponíveis" tabIndex={0}>
        <List
          loading={q.isFetching}
          dataSource={lista}
          locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={q.isError ? 'Não foi possível carregar as empresas.' : 'Nenhuma empresa encontrada.'} /> }}
          renderItem={(e) => (
            <List.Item
              style={{ flexWrap: 'wrap', gap: 8 }}
              actions={[
                <Button key="entrar" type="primary" loading={aEscolher === e.id} disabled={e.estado !== 'ATIVO'} onClick={() => escolher(e.id)}>
                  Entrar
                </Button>,
              ]}
            >
              <List.Item.Meta
                style={{ minWidth: 0 }}
                title={
                  <>
                    {e.nome}
                    {e.estado !== 'ATIVO' && (
                      <Tag style={{ marginInlineStart: 8 }}>{e.estado}</Tag>
                    )}
                  </>
                }
                description={e.cliente_nome ? `Cliente: ${e.cliente_nome}` : undefined}
              />
            </List.Item>
          )}
        />
      </div>
      {total > POR_PAGINA && (
        <Pagination size="small" align="center" simple current={pagina} pageSize={POR_PAGINA} total={total} showSizeChanger={false} onChange={setPagina} style={{ marginTop: 8 }} />
      )}
    </section>
  );
}
