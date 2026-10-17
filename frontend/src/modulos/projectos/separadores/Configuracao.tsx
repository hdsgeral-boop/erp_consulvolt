import { Alert, Button, Card, Form, Modal, Skeleton, Typography } from 'antd';
import { SaveOutlined, SettingOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { useAccao } from '@/componentes/Accoes';
import { SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { useSessao } from '@/sessao/SessaoContexto';
import type { PropsSeparador } from '../DetalheProjecto';

interface ConfiguracaoProjectos {
  produto_subempreitada_id: number | null;
  produto_faturacao_id: number | null;
}

/** Códigos dos 422 dos autos que se corrigem nesta configuração (ServicoRevisoesProjetos.php:160 e :273). */
export const ERROS_CONFIGURACAO = ['PRODUTO_AUTOS_EM_FALTA', 'PRODUTO_FATURACAO_EM_FALTA'];

/**
 * Trata os 422 de artigo em falta dos autos/facturação: mostra a mensagem com a ligação para o separador
 * «Configuração». Devolve true se tratou (para `useAccao({ aoErro })`).
 */
export function tratarErroConfiguracao(e: unknown, irConfiguracao: () => void): boolean {
  if (!(e instanceof ErroApi) || !e.codigo || !ERROS_CONFIGURACAO.includes(e.codigo)) return false;
  const m = Modal.warning({
    title: 'Falta configurar o artigo',
    content: <>{e.message} <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>Pode escolher o artigo neste pedido ou defini-lo uma vez na configuração dos projectos.</Typography.Paragraph></>,
    okText: 'Fechar',
    footer: (_, { OkBtn }) => (
      <>
        <Button icon={<SettingOutlined />} onClick={() => { m.destroy(); irConfiguracao(); }}>Abrir a configuração</Button>
        <OkBtn />
      </>
    ),
  });
  return true;
}

/**
 * Configuração dos projectos (GET/PUT /projetos/configuracao — comum a todos os projectos da empresa): o artigo
 * (serviço) das facturas de subempreitada geradas pela revisão mensal e o artigo das facturas dos autos. Ler: quem vê
 * projectos; gravar: proj_gerir (a mesma regra da rota). O legado guardava estas escolhas por pedido; o calendário e as
 * colunas do Kanban são de cada projecto e editam-se na ficha e no Kanban.
 */
export function SeparadorConfiguracao(_: PropsSeparador) {
  const { pode } = useSessao();
  const podeGravar = pode('proj_gerir');
  const [form] = Form.useForm<ConfiguracaoProjectos>();
  const q = useQuery({ queryKey: ['projectos', 'configuracao'], queryFn: () => obter<ConfiguracaoProjectos>('/projetos/configuracao') });
  const accao = useAccao({ invalidar: [['projectos', 'configuracao']] });
  useEffect(() => { if (q.data) form.setFieldsValue(q.data); }, [q.data, form]);
  if (q.isLoading) return <Skeleton active />;
  return (
    <Card title="Configuração dos projectos">
      <Alert type="info" showIcon style={{ marginBottom: 16 }}
        message="Configuração comum a todos os projectos da empresa."
        description="Os artigos são usados quando o pedido não indica outro: na revisão mensal (facturas de subempreitada aos terceiros) e na facturação dos autos ao cliente." />
      <Form form={form} layout="vertical" disabled={!podeGravar} style={{ maxWidth: 560 }}
        onFinish={(v) => accao.mutate({ metodo: 'put', url: '/projetos/configuracao', dados: { produto_subempreitada_id: v.produto_subempreitada_id ?? null, produto_faturacao_id: v.produto_faturacao_id ?? null } })}>
        <Form.Item name="produto_subempreitada_id" label="Artigo das facturas de subempreitada (revisão mensal)" extra="Serviço usado nas facturas de fornecedor geradas para os terceiros com execução no mês.">
          <SeletorProduto allowClear placeholder="Escolha o artigo (serviço)" />
        </Form.Item>
        <Form.Item name="produto_faturacao_id" label="Artigo da facturação dos autos" extra="Serviço usado na factura / factura-recibo / pró-forma emitida a partir de um auto.">
          <SeletorProduto allowClear placeholder="Escolha o artigo (serviço)" />
        </Form.Item>
        {podeGravar
          ? <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={accao.isPending}>Gravar configuração</Button>
          : <Typography.Text type="secondary">Só consulta: alterar a configuração exige a permissão de gerir projectos.</Typography.Text>}
      </Form>
    </Card>
  );
}
