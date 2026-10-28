import { Button, Card, Descriptions, Input, Progress, Space, Table, Tabs, Tag, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarNumero } from '@/utilitarios/formatacao';
import { PainelSuporteCliente } from './comum/PainelSuporteCliente';

interface Resumo {
  cliente: { id: number; nome: string; slug: string; estado: string; nome_sistema: string | null };
  uso: { plano: { codigo: string; nome: string } | null; empresas: { usadas: number; limite: number | null }; utilizadores: { usados: number; limite: number | null } };
  empresas: { id: number; nome: string; licenca_origem: string; licenca_fim: string | null; expirada: boolean }[];
}
interface PlanoC { codigo: string; nome: string; descricao: string | null; preco_mensal: number; moeda: string; max_empresas: number | null; max_utilizadores: number | null; actual: boolean }

const CHAVE = ['minha-conta'];
const pct = (u: number, l: number | null) => (l ? Math.min(100, Math.round((u / l) * 100)) : 0);

/** Minha conta (minha_conta): plano e uso, planos disponíveis, nome do sistema e suporte com conversa. Só o administrador do cliente. */
export default function MinhaConta() {
  const qc = useQueryClient();
  const [nomeSistema, setNomeSistema] = useState<string>();
  const resumo = useQuery({ queryKey: [...CHAVE, 'resumo'], queryFn: () => obter<Resumo>('/minha-conta') });
  const planos = useQuery({ queryKey: [...CHAVE, 'planos'], queryFn: () => obter<PlanoC[]>('/minha-conta/planos') });
  const identidade = useMutation({ mutationFn: () => enviar('put', '/minha-conta/identidade', { nome_sistema: nomeSistema ?? resumo.data?.cliente.nome_sistema ?? '' }), onSuccess: () => { message.success('Nome do sistema guardado.'); qc.invalidateQueries({ queryKey: CHAVE }); }, onError: (e) => notificarErro(e) });
  const r = resumo.data;
  return (
    <>
      <CabecalhoPagina titulo="Minha conta" subtitulo="Plano, utilização, suporte e identidade da sua conta" />
      <Tabs items={[
        {
          key: 'resumo', label: 'Resumo', children: (
            <Space direction="vertical" size={16} style={{ width: '100%' }}>
              <Card loading={resumo.isLoading} title={r?.cliente.nome}>
                {r && (
                  <>
                    <Descriptions size="small" column={{ xs: 1, md: 2 }}>
                      <Descriptions.Item label="Endereço">{r.cliente.slug}</Descriptions.Item>
                      <Descriptions.Item label="Estado"><Tag color={r.cliente.estado === 'ATIVO' ? 'green' : 'orange'}>{r.cliente.estado}</Tag></Descriptions.Item>
                      <Descriptions.Item label="Plano">{r.uso.plano?.nome ?? 'Sem plano atribuído'}</Descriptions.Item>
                    </Descriptions>
                    <Space direction="vertical" style={{ width: '100%', marginTop: 8 }}>
                      <div>Empresas: {r.uso.empresas.usadas}{r.uso.empresas.limite ? ` / ${r.uso.empresas.limite}` : ' (sem limite)'}<Progress percent={pct(r.uso.empresas.usadas, r.uso.empresas.limite)} showInfo={false} /></div>
                      <div>Utilizadores: {r.uso.utilizadores.usados}{r.uso.utilizadores.limite ? ` / ${r.uso.utilizadores.limite}` : ' (sem limite)'}<Progress percent={pct(r.uso.utilizadores.usados, r.uso.utilizadores.limite)} showInfo={false} /></div>
                    </Space>
                  </>
                )}
              </Card>
              <Card title="Nome do sistema">
                <Space.Compact style={{ maxWidth: 480, width: '100%' }}>
                  <Input maxLength={80} value={nomeSistema ?? r?.cliente.nome_sistema ?? ''} onChange={(e) => setNomeSistema(e.target.value)} placeholder="Nome mostrado aos seus utilizadores" />
                  <Button type="primary" loading={identidade.isPending} onClick={() => identidade.mutate()}>Guardar</Button>
                </Space.Compact>
              </Card>
              <Card title="Licenças das empresas">
                <Table rowKey="id" size="small" pagination={false} dataSource={r?.empresas ?? []} columns={[
                  { title: 'Empresa', dataIndex: 'nome' },
                  { title: 'Licença', dataIndex: 'licenca_origem' },
                  { title: 'Fim', render: (_, e) => (e.licenca_fim ? formatarData(e.licenca_fim) : 'Sem prazo') },
                  { title: 'Estado', render: (_, e) => (e.expirada ? <Tag color="red">Expirada</Tag> : <Tag color="green">Válida</Tag>) },
                ]} />
              </Card>
            </Space>
          ),
        },
        {
          key: 'planos', label: 'Planos', children: (
            <Table<PlanoC> rowKey="codigo" size="small" loading={planos.isLoading} pagination={false} dataSource={planos.data ?? []} columns={[
              { title: 'Plano', render: (_, p) => <>{p.nome} {p.actual && <Tag color="blue">Actual</Tag>}</> },
              { title: 'Preço/mês', align: 'right', render: (_, p) => `${formatarNumero(p.preco_mensal)} ${p.moeda}` },
              { title: 'Empresas', render: (_, p) => p.max_empresas ?? 'Ilimitadas' },
              { title: 'Utilizadores', render: (_, p) => p.max_utilizadores ?? 'Ilimitados' },
              { title: 'Descrição', dataIndex: 'descricao' },
            ]} />
          ),
        },
        {
          key: 'suporte', label: 'Suporte', children: <PainelSuporteCliente base="/minha-conta/suporte" anexosBase="/minha-conta/suporte/anexos" />,
        },
      ]} />
    </>
  );
}