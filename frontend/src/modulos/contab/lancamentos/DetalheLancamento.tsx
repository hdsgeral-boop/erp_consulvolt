import { Alert, Button, Card, Descriptions, Form, Input, Modal, Skeleton, Table, Tag, Typography, message } from 'antd';
import { ArrowLeftOutlined, CopyOutlined, RollbackOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { pares, tabelaHtml } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import type { DocumentoLancamento, LinhaLancamento } from '../api';
import { rotuloTerceiro } from '../comum/terceiro';
import { ValorKz } from '../comum/Componentes';
import { porId, useDiarios, useTabelaAux } from '../comum/dados';
import { accoesLancamento } from '../comum/regras';
import type { EstadoCopia } from './NovoLancamento';

/** Detalhe do lançamento (documento) a que pertence a linha :id, com estorno e cópia. */
export function DetalheLancamento() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [estornar, setEstornar] = useState(false);
  const [form] = Form.useForm<{ motivo: string }>();
  const diarios = useDiarios();
  const centros = useTabelaAux('centros-custo');
  const nomesCentro = porId(centros.data, (c) => `${c.codigo} — ${c.descricao ?? ''}`);

  const consulta = useQuery({ queryKey: ['contab', 'lancamento', id], queryFn: () => obter<DocumentoLancamento>(`/contabilidade/lancamentos/${id}`) });
  const estorno = useMutation({
    mutationFn: (motivo: string) => enviar<DocumentoLancamento>('post', `/contabilidade/lancamentos/${id}/estornar`, { motivo }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      setEstornar(false);
      form.resetFields();
      void cliente.invalidateQueries({ queryKey: ['contab'] });
      if (dados.linhas[0]) navegar(`../${dados.linhas[0].id}`);
    },
    onError: (e) => notificarErro(e, 'Não foi possível estornar'),
  });

  if (consulta.isLoading) return <Skeleton active />;
  const d = consulta.data;
  if (!d) return <Alert type="error" message="Lançamento não encontrado." />;

  const { situacao, podeEstornar } = accoesLancamento(d.linhas, pode);
  const diario = diarios.data?.find((x) => x.id === d.diario_id);
  const original = d.linhas.find((l) => l.estorno_de_id)?.estorno_de_id;
  const estornadoPor = d.linhas.find((l) => l.estornado_por_id)?.estornado_por_id;
  const l0 = d.linhas[0];

  const colunas = [
    { title: 'Conta', dataIndex: 'codigo_conta', render: (v: string) => <strong>{v}</strong> },
    { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null) => v ?? '—' },
    { title: 'Terceiro', key: 'terceiro', render: (_: unknown, r: LinhaLancamento) => rotuloTerceiro(r.terceiro, r.terceiro_id, true) },
    { title: 'Centro de custo', dataIndex: 'centro_custo_id', render: (v: number | null) => (v ? nomesCentro.get(v) ?? `#${v}` : '—') },
    { title: 'Débito', align: 'right' as const, render: (_: unknown, r: LinhaLancamento) => (r.tipo_dc === 'D' ? <ValorKz valor={r.valor} /> : null) },
    { title: 'Crédito', align: 'right' as const, render: (_: unknown, r: LinhaLancamento) => (r.tipo_dc === 'C' ? <ValorKz valor={r.valor} /> : null) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={`Lançamento ${d.numero_lan}`}
        subtitulo={diario ? `${diario.codigo} — ${diario.descricao ?? ''}` : undefined}
        impressao={() => ({
          titulo: `Lançamento ${d.numero_lan}`,
          subtitulo: diario ? `Diário ${diario.codigo} — ${diario.descricao ?? ''}` : undefined,
          filtros: [situacao === 'ESTORNADO' ? 'Lançamento estornado' : situacao === 'ESTORNO' ? 'Estorno de outro lançamento' : null, !d.equilibrado ? 'Desequilibrado (débito ≠ crédito)' : null],
          conteudo:
            pares([
              ['Data do documento', formatarData(d.data_documento)],
              ['N.º do documento', d.numero_documento ?? '—'],
              ['Referência', l0?.referencia ?? '—'],
              ['Origem', l0?.tipo_origem ?? 'Manual'],
              ['Registado em', formatarDataHora(l0?.data_lancamento ?? null)],
              ['Utilizador', l0?.nome_utilizador ?? '—'],
            ]) +
            tabelaHtml<LinhaLancamento>({
              linhas: d.linhas,
              totais: 'Totais',
              colunas: [
                { titulo: 'Conta', valor: (r) => r.codigo_conta, total: 'Totais' },
                { titulo: 'Descrição', valor: (r) => r.descricao ?? '—', quebrar: true, total: '' },
                { titulo: 'Terceiro', valor: (r) => rotuloTerceiro(r.terceiro, r.terceiro_id, true), quebrar: true, total: '' },
                { titulo: 'Centro de custo', valor: (r) => (r.centro_custo_id ? nomesCentro.get(r.centro_custo_id) ?? `#${r.centro_custo_id}` : '—'), total: '' },
                { titulo: 'Débito', valor: (r) => (r.tipo_dc === 'D' ? r.valor : null), formato: 'moeda', total: formatarKz(d.debito) },
                { titulo: 'Crédito', valor: (r) => (r.tipo_dc === 'C' ? r.valor : null), formato: 'moeda', total: formatarKz(d.credito) },
              ],
            }),
        })}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {pode('lancamentos_post') && situacao !== 'ESTORNO' && (
              <Button icon={<CopyOutlined />} onClick={() => navegar('../novo', { state: { copia: d } satisfies EstadoCopia })}>
                Copiar
              </Button>
            )}
            {podeEstornar && (
              <Button danger icon={<RollbackOutlined />} onClick={() => setEstornar(true)}>
                Estornar
              </Button>
            )}
          </>
        }
      />
      {situacao === 'ESTORNADO' && (
        <Alert
          type="error"
          showIcon
          style={{ marginBottom: 16 }}
          message="Este lançamento foi estornado."
          action={estornadoPor ? <Button size="small" onClick={() => navegar(`../${estornadoPor}`)}>Ver o estorno</Button> : undefined}
        />
      )}
      {situacao === 'ESTORNO' && (
        <Alert
          type="info"
          showIcon
          style={{ marginBottom: 16 }}
          message="Este lançamento é o estorno de outro."
          action={original ? <Button size="small" onClick={() => navegar(`../${original}`)}>Ver o original</Button> : undefined}
        />
      )}
      {!d.equilibrado && <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="Lançamento desequilibrado (débito diferente do crédito)." />}
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={{ xs: 1, md: 3 }} size="small">
          <Descriptions.Item label="Data do documento">{formatarData(d.data_documento)}</Descriptions.Item>
          <Descriptions.Item label="N.º do documento">{d.numero_documento ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Referência">{l0?.referencia ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Origem">{l0?.tipo_origem ? <Tag>{l0.tipo_origem}</Tag> : 'Manual'}</Descriptions.Item>
          <Descriptions.Item label="Registado em">{formatarDataHora(l0?.data_lancamento ?? null)}</Descriptions.Item>
          <Descriptions.Item label="Utilizador">{l0?.nome_utilizador ?? '—'}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card title="Linhas">
        <Table<LinhaLancamento>
          rowKey="id"
          size="small"
          pagination={false}
          columns={colunas}
          dataSource={d.linhas}
          scroll={scrollTabela()}
          summary={() => (
            <Table.Summary.Row>
              <Table.Summary.Cell index={0} colSpan={4}>
                <Typography.Text strong>Totais</Typography.Text>
              </Table.Summary.Cell>
              <Table.Summary.Cell index={4} align="right">
                <ValorKz valor={d.debito} forte />
              </Table.Summary.Cell>
              <Table.Summary.Cell index={5} align="right">
                <ValorKz valor={d.credito} forte />
              </Table.Summary.Cell>
            </Table.Summary.Row>
          )}
        />
      </Card>

      <Modal
        title={`Estornar ${d.numero_lan}`}
        open={estornar}
        onCancel={() => setEstornar(false)}
        okText="Estornar"
        okButtonProps={{ danger: true }}
        confirmLoading={estorno.isPending}
        onOk={() => form.submit()}
      >
        <Form form={form} layout="vertical" onFinish={(v) => estorno.mutate(v.motivo)}>
          <Typography.Paragraph type="secondary">
            É criado um lançamento simétrico de {formatarKz(d.debito)} Kz; o original fica no Diário marcado como estornado.
          </Typography.Paragraph>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
            <Input.TextArea rows={3} maxLength={500} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
