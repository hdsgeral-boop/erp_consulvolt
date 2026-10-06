import { Alert, Button, Descriptions, Modal, Radio, Space, Table, Typography, Upload, message } from 'antd';
import { DownloadOutlined, ExperimentOutlined, UploadOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { descarregar, enviar } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import type { RelatorioImportacao } from '../api';

/** POST multipart (ficheiro + campos). Booleanos seguem como 1/0 (regra «boolean» do Laravel). */
export function enviarFicheiroRh<T>(url: string, ficheiro: File, campos: Record<string, string | number | boolean | undefined> = {}) {
  const dados = new FormData();
  dados.append('ficheiro', ficheiro);
  for (const [k, v] of Object.entries(campos)) {
    if (v !== undefined) dados.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
  }
  return enviar<T>('post', url, dados);
}

/** Linhas com erro de um relatório (rejeitadas na leitura + recusadas ao gravar), ordenadas pela linha do Excel. */
export function linhasComErro(r: Pick<RelatorioImportacao, 'rejeitadas' | 'erros'>): { linha: number; motivo: string }[] {
  return [...(r.rejeitadas ?? []), ...(r.erros ?? [])].sort((a, b) => a.linha - b.linha);
}

interface Props {
  aberto: boolean;
  titulo: string;
  /** Endpoint multipart de importação. */
  url: string;
  /** Entidade do modelo (GET /rh/importacoes/modelos/{modelo}). */
  modelo: 'calculo' | 'colaboradores' | 'contratos' | 'produtividade';
  ajuda?: ReactNode;
  /** Mostra a escolha IGNORAR/ACTUALIZAR para os registos já existentes. */
  comDecisao?: 'IGNORAR' | 'ACTUALIZAR';
  aoFechar: () => void;
}

/**
 * Importação Excel do RH (A-08/A-09/M-13), com o fluxo do legado — modelo, simulação, importação — e a regra do servidor:
 * uma linha com erro e nada é gravado (o relatório indica a linha e o motivo).
 */
export function ImportarExcel({ aberto, titulo, url, modelo, ajuda, comDecisao, aoFechar }: Props) {
  const cliente = useQueryClient();
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [decisao, setDecisao] = useState(comDecisao ?? 'IGNORAR');
  const [aEnviar, setAEnviar] = useState(false);
  const [rel, setRel] = useState<RelatorioImportacao | null>(null);
  const [erros, setErros] = useState<{ linha: number; motivo: string }[]>([]);

  const fechar = () => {
    setFicheiro(null);
    setRel(null);
    setErros([]);
    aoFechar();
  };

  const executar = async (simular: boolean) => {
    if (!ficheiro) return;
    setAEnviar(true);
    setErros([]);
    try {
      const { dados, mensagem } = await enviarFicheiroRh<RelatorioImportacao>(url, ficheiro, { simular, decisao: comDecisao ? decisao : undefined });
      setRel(dados);
      setErros(linhasComErro(dados));
      if (!simular) {
        message.success(mensagem);
        void cliente.invalidateQueries({ queryKey: ['rh'] });
      }
    } catch (e) {
      if (e instanceof ErroApi && e.codigo === 'IMPORTACAO_COM_ERROS' && e.erros) {
        const x = e.erros as { linhas?: { linha: number; motivo: string }[]; rejeitadas?: { linha: number; motivo: string }[] };
        setErros(linhasComErro({ rejeitadas: x.rejeitadas ?? [], erros: x.linhas ?? [] }));
        message.error(e.message);
      } else notificarErro(e);
    } finally {
      setAEnviar(false);
    }
  };

  return (
    <Modal title={titulo} open={aberto} width={larguraModal(760)} onCancel={fechar} destroyOnHidden
      footer={
        <Space wrap>
          <Button onClick={fechar}>Fechar</Button>
          <Button icon={<ExperimentOutlined />} disabled={!ficheiro} loading={aEnviar} onClick={() => void executar(true)}>Simular</Button>
          <Button type="primary" icon={<UploadOutlined />} disabled={!ficheiro} loading={aEnviar} onClick={() => void executar(false)}>Importar</Button>
        </Space>
      }>
      <Space direction="vertical" style={{ width: '100%' }} size="middle">
        <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
          {ajuda ?? 'Preencha o modelo Excel e importe-o. Use primeiro «Simular»: nada é gravado e o relatório indica as linhas com erro.'}
        </Typography.Paragraph>
        <Space wrap>
          <Button icon={<DownloadOutlined />} onClick={() => void descarregar(`/rh/importacoes/modelos/${modelo}`, undefined, `Template_${modelo}.xlsx`).catch(notificarErro)}>Template</Button>
          <Upload accept=".xlsx,.xls,.csv" maxCount={1} beforeUpload={(f) => { setFicheiro(f); setRel(null); setErros([]); return false; }}
            onRemove={() => { setFicheiro(null); setRel(null); }} fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}>
            <Button icon={<UploadOutlined />}>Escolher ficheiro</Button>
          </Upload>
        </Space>
        {comDecisao && (
          <Radio.Group value={decisao} onChange={(e) => setDecisao(e.target.value)}>
            <Radio value="ACTUALIZAR">Actualizar os existentes</Radio>
            <Radio value="IGNORAR">Manter os existentes</Radio>
          </Radio.Group>
        )}
        {rel && (
          <Alert type={rel.simulacao ? 'info' : 'success'} showIcon message={rel.simulacao ? 'Simulação (nada foi gravado)' : 'Importação concluída'}
            description={
              <Descriptions size="small" column={{ xs: 1, sm: 2, md: 3 }}>
                {rel.novos !== undefined && <Descriptions.Item label="Novos">{rel.novos}</Descriptions.Item>}
                {rel.existentes && <Descriptions.Item label="Já existentes">{rel.existentes.length}</Descriptions.Item>}
                <Descriptions.Item label="Repetidos no ficheiro">{rel.repetidos}</Descriptions.Item>
                <Descriptions.Item label={rel.simulacao ? 'A criar' : 'Criados'}>{rel.criados}</Descriptions.Item>
                <Descriptions.Item label={rel.simulacao ? 'A actualizar' : 'Actualizados'}>{rel.actualizados}</Descriptions.Item>
                <Descriptions.Item label="Ignorados">{rel.ignorados}</Descriptions.Item>
              </Descriptions>
            } />
        )}
        {erros.length > 0 && (
          <>
            <Typography.Text type="danger" strong>{erros.length} linha(s) com erro — corrija o ficheiro (nada é gravado enquanto houver erros):</Typography.Text>
            <Table rowKey={(l) => `${l.linha}-${l.motivo}`} size="small" pagination={{ pageSize: 10 }} dataSource={erros} scroll={{ x: 'max-content' }}
              columns={[{ title: 'Linha', dataIndex: 'linha', width: 80 }, { title: 'Motivo', dataIndex: 'motivo' }]} />
          </>
        )}
      </Space>
    </Modal>
  );
}
