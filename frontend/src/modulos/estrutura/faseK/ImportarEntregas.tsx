import { Alert, Button, Modal, Space, Table, Tag, Typography, Upload, message } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { DownloadOutlined, ExperimentOutlined, UploadOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData } from '@/utilitarios/formatacao';
import { CHAVES_ENTREGAVEIS } from '../tarefas/comum';
import { fq } from '../tarefas/regras';
import { importarEntregas, modeloImportacaoEntregas } from './api';
import { podeImportar, textoResumoImportacao, validarFicheiroImportacao } from './regras';
import type { EstadoLinhaImportacao, LinhaImportacaoEntrega, RelatorioImportacaoEntregas } from './tipos';

const ESTADO: Record<EstadoLinhaImportacao, { rotulo: string; cor: string }> = {
  OK: { rotulo: 'válida', cor: 'green' },
  ERRO: { rotulo: 'erro', cor: 'red' },
  DUPLICADA: { rotulo: 'já importada', cor: 'default' },
};

/**
 * Importação Excel das entregas de um entregável (K2): descarregar o modelo, escolher o ficheiro, «Validar» (simulação, nada
 * se grava) e «Importar» — só activo quando a validação não tem erros; o servidor grava tudo ou nada (origem EXCEL, por validar).
 * Uma linha já importada antes aparece como «já importada» e é ignorada, não é erro.
 */
export function ImportarEntregas({ entregavel, aberto, aoFechar }: { entregavel: { id: number; nome: string; unidade?: string }; aberto: boolean; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [relatorio, setRelatorio] = useState<RelatorioImportacaoEntregas | null>(null);
  const [aEnviar, setAEnviar] = useState(false);
  const [gravado, setGravado] = useState(false);

  const fechar = () => { setFicheiro(null); setRelatorio(null); setGravado(false); aoFechar(); };

  const executar = async (simular: boolean) => {
    if (!ficheiro) return;
    setAEnviar(true);
    try {
      const { dados, mensagem } = await importarEntregas(entregavel.id, ficheiro, simular);
      setRelatorio(dados);
      if (!simular) {
        message.success(mensagem);
        setGravado(true);
        CHAVES_ENTREGAVEIS.forEach((k) => void cliente.invalidateQueries({ queryKey: k }));
      }
    } catch (e) {
      notificarErro(e);
    } finally {
      setAEnviar(false);
    }
  };

  const colunas: ColumnsType<LinhaImportacaoEntrega> = [
    { title: 'Linha', dataIndex: 'n', width: 70 },
    { title: 'Estado', dataIndex: 'estado', width: 120, render: (e: EstadoLinhaImportacao) => <Tag color={ESTADO[e]?.cor}>{ESTADO[e]?.rotulo ?? e}</Tag> },
    { title: 'Colaborador', key: 'c', render: (_, l) => l.colaborador?.nome ?? '—' },
    { title: 'Data', dataIndex: 'data', render: (v: string | null) => (v ? formatarData(v) : '—') },
    { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (v) => (v != null ? fq(v) : '—') },
    { title: 'Erros', dataIndex: 'erros', render: (e: string[]) => (e?.length ? <span style={{ color: '#b91c1c' }}>{e.join('; ')}</span> : '') },
  ];

  return (
    <Modal open={aberto} title={`Importar entregas (Excel) — ${entregavel.nome}`} onCancel={fechar} width={larguraModal(900)} destroyOnHidden
      footer={
        <Space wrap>
          <Button onClick={fechar}>{gravado ? 'Fechar' : 'Cancelar'}</Button>
          <Button icon={<ExperimentOutlined />} disabled={!ficheiro || gravado} loading={aEnviar} onClick={() => void executar(true)}>Validar</Button>
          <Button type="primary" icon={<UploadOutlined />} disabled={!podeImportar(relatorio) || gravado} loading={aEnviar} onClick={() => void executar(false)}>Importar</Button>
        </Space>
      }>
      <Space orientation="vertical" style={{ width: '100%' }} size="middle">
        <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
          Descarregue o modelo, preencha uma linha por entrega (colaborador por n.º, NIF ou nome; data; quantidade; observações; evidência) e valide.
          As entregas importadas ficam por validar pelo responsável. Basta uma linha com erro para nada ser gravado.
        </Typography.Paragraph>
        <Space wrap>
          <Button icon={<DownloadOutlined />} onClick={() => void modeloImportacaoEntregas(entregavel.id).catch(notificarErro)}>Descarregar modelo</Button>
          <Upload accept=".xlsx,.xls,.csv" maxCount={1} fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
            beforeUpload={(f) => {
              const erro = validarFicheiroImportacao(f);
              if (erro) { message.error(erro); return Upload.LIST_IGNORE; }
              setFicheiro(f); setRelatorio(null); setGravado(false);
              return false;
            }}
            onRemove={() => { setFicheiro(null); setRelatorio(null); }}>
            <Button icon={<UploadOutlined />}>Escolher ficheiro</Button>
          </Upload>
        </Space>
        {relatorio && (
          <Alert showIcon type={gravado ? 'success' : relatorio.resumo.erros ? 'error' : 'info'}
            title={gravado ? 'Importação concluída' : relatorio.resumo.erros ? 'Corrija o ficheiro: nada é gravado enquanto houver erros' : 'Validação sem erros (nada foi gravado)'}
            description={textoResumoImportacao(relatorio.resumo)} />
        )}
        {relatorio && relatorio.linhas.length > 0 && (
          <Table<LinhaImportacaoEntrega> rowKey="n" size="small" dataSource={relatorio.linhas} columns={colunas} pagination={relatorio.linhas.length > 20 ? { pageSize: 20 } : false} scroll={scrollTabela('45vh')} />
        )}
      </Space>
    </Modal>
  );
}
