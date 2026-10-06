import { Alert, Button, List, Modal, Space, Tag, Typography, Upload } from 'antd';
import { DownloadOutlined, UploadOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { descarregar } from '@/api/cliente';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { enviarFicheiro } from '../../contab/comum/ficheiros';

export interface ResultadoImportacaoTesouraria {
  simulacao: boolean;
  linhas_lidas: number;
  documentos: number;
  gravados: { id?: number; numero_documento?: string; linhas: string; referencia: string; tipo: string; data: string; valor_total: string }[];
  erros: { linhas: string; referencia: string | null; mensagem: string }[];
}

/** Descarrega o modelo do legado (Template_Importação_Tesouraria). */
export const descarregarModeloTesouraria = () =>
  descarregar('/tesouraria/documentos/modelo-importacao', undefined, 'Template_Importacao_Tesouraria.xlsx').catch((e) => notificarErro(e, 'Não foi possível obter o modelo'));

/**
 * A-12: importação de pagamentos/recebimentos por Excel (processtreasuryImport do legado). Primeiro valida (simulação —
 * nada é gravado, com os erros por linha) e só depois importa; os documentos ficam PENDENTES (por integrar).
 */
export function ModalImportacaoTesouraria({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [aEnviar, setAEnviar] = useState(false);
  const [resultado, setResultado] = useState<ResultadoImportacaoTesouraria | null>(null);

  const fechar = () => { setFicheiro(null); setResultado(null); aoFechar(); };
  const executar = async (simular: boolean) => {
    if (!ficheiro) return;
    setAEnviar(true);
    try {
      const r = await enviarFicheiro<ResultadoImportacaoTesouraria>('/tesouraria/documentos/importar', ficheiro, { simular });
      setResultado(r.dados);
      if (!simular) void cliente.invalidateQueries({ queryKey: ['teso'] });
    } catch (e) {
      notificarErro(e, simular ? 'O ficheiro não é válido' : 'Não foi possível importar');
    } finally {
      setAEnviar(false);
    }
  };
  const validado = resultado?.simulacao && resultado.gravados.length > 0;

  return (
    <Modal
      title="Importar documentos de tesouraria (Excel)"
      open={aberto}
      onCancel={fechar}
      width={larguraModal(760)}
      destroyOnHidden
      footer={
        <Space wrap>
          <Button icon={<DownloadOutlined />} onClick={() => void descarregarModeloTesouraria()}>Baixar modelo</Button>
          <Button onClick={fechar}>{resultado && !resultado.simulacao ? 'Fechar' : 'Cancelar'}</Button>
          <Button disabled={!ficheiro} loading={aEnviar} onClick={() => void executar(true)}>Validar (simulação)</Button>
          <Button type="primary" disabled={!validado} loading={aEnviar} onClick={() => void executar(false)}>Importar</Button>
        </Space>
      }
    >
      <Typography.Paragraph type="secondary">
        Use o modelo do sistema anterior: as linhas com a mesma data, tipo, referência e conta de disponibilidades formam um documento.
        Os documentos ficam <strong>por integrar</strong>; um documento com alguma linha errada não é importado.
      </Typography.Paragraph>
      <Upload
        accept=".xlsx,.xls,.csv"
        maxCount={1}
        beforeUpload={(f) => { setFicheiro(f); setResultado(null); return false; }}
        onRemove={() => { setFicheiro(null); setResultado(null); }}
        fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
      >
        <Button icon={<UploadOutlined />}>Escolher ficheiro</Button>
      </Upload>
      {resultado && (
        <>
          <Alert
            style={{ marginTop: 12 }}
            type={resultado.erros.length ? 'warning' : 'success'}
            showIcon
            message={resultado.simulacao
              ? `Simulação: ${resultado.linhas_lidas} linha(s), ${resultado.documentos} documento(s); ${resultado.gravados.length} válido(s), ${resultado.erros.length} erro(s). Nada foi gravado.`
              : `${resultado.gravados.length} documento(s) importado(s) por integrar; ${resultado.erros.length} erro(s).`}
          />
          {resultado.gravados.length > 0 && (
            <List
              size="small"
              header={<strong>{resultado.simulacao ? 'Documentos válidos' : 'Documentos importados'}</strong>}
              dataSource={resultado.gravados}
              style={{ marginTop: 8, maxHeight: 220, overflow: 'auto' }}
              renderItem={(g) => (
                <List.Item>
                  <Space wrap>
                    <Tag color={g.tipo === 'PAGAMENTO' ? 'volcano' : 'green'}>{g.tipo === 'PAGAMENTO' ? 'Pagamento' : 'Recebimento'}</Tag>
                    <strong>{g.numero_documento ?? g.referencia}</strong>
                    <span>{formatarData(g.data)}</span>
                    <span>{formatarKz(g.valor_total)} Kz</span>
                    <Typography.Text type="secondary">linhas {g.linhas}</Typography.Text>
                  </Space>
                </List.Item>
              )}
            />
          )}
          {resultado.erros.length > 0 && (
            <List
              size="small"
              header={<strong>Erros</strong>}
              dataSource={resultado.erros}
              style={{ marginTop: 8, maxHeight: 220, overflow: 'auto' }}
              renderItem={(e) => <List.Item><Typography.Text type="danger">{e.referencia ? `${e.referencia}: ` : ''}{e.mensagem}</Typography.Text></List.Item>}
            />
          )}
        </>
      )}
    </Modal>
  );
}
