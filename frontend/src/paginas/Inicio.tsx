import { Alert, Badge, Card, Col, Empty, List, Row, Skeleton, Typography, theme } from 'antd';
import { AppstoreOutlined, BulbOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { iconeModulo } from '@/componentes/icones/iconesModulos';
import { ecrasDeTopo, rotaEcra } from '@/layout/itensMenu';

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

/** Página inicial (GET /gestao/inicio, ADR-059): saudação, processos pendentes, Dica do Dia e atalhos para os módulos. */
export function Inicio() {
  const { menu, empresa } = useSessao();
  const navegar = useNavigate();
  const { token } = theme.useToken();
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
        {menu.length > 0 && (
          <Col span={24}>
            <Card title={<><AppstoreOutlined /> Módulos</>}>
              <nav aria-label="Atalhos dos módulos" className="erp-grelha-auto">
                {menu.map((m) => {
                  const primeiro = ecrasDeTopo(m)[0];
                  return (
                    <Card
                      key={m.id}
                      size="small"
                      hoverable={!!primeiro}
                      role="link"
                      tabIndex={primeiro ? 0 : -1}
                      aria-label={`Abrir ${m.nome}`}
                      onClick={primeiro ? () => navegar(rotaEcra(m.id, primeiro.id)) : undefined}
                      onKeyDown={(ev) => {
                        if (primeiro && (ev.key === 'Enter' || ev.key === ' ')) {
                          ev.preventDefault();
                          navegar(rotaEcra(m.id, primeiro.id));
                        }
                      }}
                      style={{ cursor: primeiro ? 'pointer' : 'default' }}
                    >
                      <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 0 }}>
                        <span
                          aria-hidden
                          style={{
                            flex: 'none', width: 40, height: 40, borderRadius: 8, display: 'grid', placeItems: 'center',
                            background: token.colorPrimaryBg, color: token.colorPrimary, fontSize: 20,
                          }}
                        >
                          {iconeModulo(m.id)}
                        </span>
                        <div style={{ minWidth: 0 }}>
                          <Typography.Text strong style={{ display: 'block' }}>{m.nome}</Typography.Text>
                          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            {m.ecras.length === 1 ? '1 ecrã' : `${m.ecras.length} ecrãs`}
                          </Typography.Text>
                        </div>
                      </div>
                    </Card>
                  );
                })}
              </nav>
            </Card>
          </Col>
        )}
      </Row>
    </>
  );
}
