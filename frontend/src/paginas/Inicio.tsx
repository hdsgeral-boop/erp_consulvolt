import { Alert, Badge, Card, Col, Empty, List, Row, Skeleton, Typography } from 'antd';
import { BulbOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';

interface Pendente {
  id: string;
  rotulo: string;
  quantidade: number;
  vista?: string | null;
}

interface DadosInicio {
  saudacao?: string;
  empresa?: { nome?: string; nif?: string | null };
  pendentes?: Pendente[];
  total_pendentes?: number;
  dica_do_dia?: { titulo?: string; texto?: string } | string | null;
  comunicado_avaliacao?: { titulo?: string; texto?: string } | null;
}

/** Página inicial (GET /gestao/inicio, ADR-059): saudação, processos pendentes e Dica do Dia. */
export function Inicio() {
  const { menu, empresa } = useSessao();
  const navegar = useNavigate();
  const consulta = useQuery({ queryKey: ['inicio'], queryFn: () => obter<DadosInicio>('/gestao/inicio') });

  // a vista do pendente aponta para o ecrã do catálogo; procura-se o módulo no menu do utilizador
  const rotaDaVista = (vista?: string | null) => {
    if (!vista) return null;
    for (const m of menu) {
      const e = m.ecras.find((x) => x.id === vista || x.vistas.includes(vista));
      if (e) return `/m/${m.id}/${e.id}`;
    }
    return null;
  };

  if (consulta.isLoading) return <Skeleton active />;
  const d = consulta.data ?? {};
  const dica = typeof d.dica_do_dia === 'string' ? { texto: d.dica_do_dia } : d.dica_do_dia;

  return (
    <>
      <Typography.Title level={3} style={{ marginTop: 0 }}>
        {d.saudacao ?? 'Bem-vindo'}
      </Typography.Title>
      <Typography.Paragraph type="secondary">{d.empresa?.nome ?? empresa?.nome}</Typography.Paragraph>
      {d.comunicado_avaliacao && <Alert type="warning" showIcon style={{ marginBottom: 16 }} message={d.comunicado_avaliacao.titulo} description={d.comunicado_avaliacao.texto} />}
      <Row gutter={[16, 16]}>
        <Col xs={24} lg={16}>
          <Card title="Processos pendentes">
            {d.pendentes && d.pendentes.length ? (
              <List
                dataSource={d.pendentes}
                renderItem={(p) => {
                  const rota = rotaDaVista(p.vista);
                  return (
                    <List.Item onClick={rota ? () => navegar(rota) : undefined} style={{ cursor: rota ? 'pointer' : 'default' }}>
                      <Typography.Text>{p.rotulo}</Typography.Text>
                      <Badge count={p.quantidade} overflowCount={9999} showZero color={p.quantidade ? '#fa8c16' : '#d9d9d9'} />
                    </List.Item>
                  );
                }}
              />
            ) : (
              <Empty description="Sem processos pendentes." />
            )}
          </Card>
        </Col>
        <Col xs={24} lg={8}>
          <Card title={<><BulbOutlined /> Dica do dia</>}>
            <Typography.Paragraph strong>{dica?.titulo}</Typography.Paragraph>
            <Typography.Paragraph>{dica?.texto ?? '—'}</Typography.Paragraph>
          </Card>
        </Col>
      </Row>
    </>
  );
}
