import { Alert, Button, Modal, Space, Table, Tag, Typography, Upload } from 'antd';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { descarregar } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { enviarFicheiro } from '../../contab/comum/ficheiros';

export interface LinhaImportadaCaixa {
  linha: number;
  id: number | null;
  data: string;
  tipo: 'REC' | 'PAG';
  numero_documento: string | null;
  descricao: string;
  conta_contrapartida: string;
  valor: string;
}

export interface ResultadoImportacaoCaixa {
  simulacao: boolean;
  importado: boolean;
  sessao_id: number;
  linhas_lidas: number;
  validas: LinhaImportadaCaixa[];
  erros: { linha: number; mensagem: string }[];
  total_entradas: string;
  total_saidas: string;
}

/** «Baixar modelo» da Folha de Caixa (downloadFolhaCaixaTemplate do legado: Template_Importacao_Caixa.xlsx). */
export const descarregarModeloCaixa = () =>
  descarregar('/tesouraria/caixa/modelo-importacao', undefined, 'Template_Importacao_Caixa.xlsx').catch((e) => notificarErro(e, 'Não foi possível obter o modelo'));

/** Pode importar? Só depois de uma simulação sem erros e com linhas válidas (a importação é tudo ou nada). */
export function podeImportarCaixa(r: ResultadoImportacaoCaixa | null): boolean {
  return !!r && r.simulacao && r.erros.length === 0 && r.validas.length > 0;
}

/**
 * Importar movimentos de caixa (showImportFolhaCaixaModal do legado, js/ui_folha_caixa.js:1625-1671) para a sessão ABERTA:
 * 1. baixar o modelo; 2. escolher o ficheiro e validar (simulação — nada gravado, erros por linha e pré-visualização);
 * 3. importar (tudo ou nada, numa transacção no servidor).
 */
export function ModalImportacaoCaixa({ sessao, aoFechar }: { sessao: { id: number; codigo_conta: string }; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [aEnviar, setAEnviar] = useState(false);
  const [resultado, setResultado] = useState<ResultadoImportacaoCaixa | null>(null);

  const executar = async (simular: boolean) => {
    if (!ficheiro) return;
    setAEnviar(true);
    try {
      const r = await enviarFicheiro<ResultadoImportacaoCaixa>(`/tesouraria/caixa/sessoes/${sessao.id}/importar`, ficheiro, { simular });
      setResultado(r.dados);
      if (r.dados.importado) void cliente.invalidateQueries({ queryKey: ['teso', 'caixa'] });
    } catch (e) {
      notificarErro(e, simular ? 'O ficheiro não é válido' : 'Não foi possível importar');
    } finally {
      setAEnviar(false);
    }
  };
  const concluido = !!resultado?.importado;

  return (
    <Modal
      open
      title={<><IconeFa nome="file-excel" /> Importar movimentos de caixa — sessão #{sessao.id} ({sessao.codigo_conta})</>}
      onCancel={aoFechar}
      width={larguraModal(820)}
      destroyOnHidden
      footer={
        <Space wrap>
          <Button icon={<IconeFa nome="download" />} onClick={() => void descarregarModeloCaixa()}>Baixar modelo</Button>
          <Button onClick={aoFechar}>{concluido ? 'Fechar' : 'Cancelar'}</Button>
          {!concluido && <Button disabled={!ficheiro} loading={aEnviar} onClick={() => void executar(true)}>Validar (pré-visualização)</Button>}
          {!concluido && <Button type="primary" disabled={!podeImportarCaixa(resultado)} loading={aEnviar} onClick={() => void executar(false)}>Importar</Button>}
        </Space>
      }
    >
      <Typography.Paragraph type="secondary">
        Preencha o modelo (Data, Tipo <strong>REC</strong>/<strong>PAG</strong>, Nº Doc, Referência, Descrição, Valor, Conta Contrapartida, NIF Terceiro,
        Nota Demonstração, Nota Fluxo Caixa, URL) e valide-o: cada linha é verificada e nada é gravado. A importação é <strong>tudo ou nada</strong> —
        se alguma linha tiver erro, nenhuma é gravada.
      </Typography.Paragraph>
      <Upload
        accept=".xlsx,.xls,.csv"
        maxCount={1}
        beforeUpload={(f) => { setFicheiro(f); setResultado(null); return false; }}
        onRemove={() => { setFicheiro(null); setResultado(null); }}
        fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
      >
        <Button icon={<IconeFa nome="upload" />} disabled={concluido}>Escolher ficheiro</Button>
      </Upload>
      {resultado && (
        <>
          <Alert
            style={{ marginTop: 12 }}
            showIcon
            type={resultado.importado ? 'success' : resultado.erros.length ? 'warning' : 'info'}
            message={resultado.importado
              ? `${resultado.validas.length} movimento(s) importado(s): entradas ${formatarKz(resultado.total_entradas)} Kz, saídas ${formatarKz(resultado.total_saidas)} Kz.`
              : `${resultado.simulacao ? 'Pré-visualização' : 'Nada foi importado'}: ${resultado.linhas_lidas} linha(s) lida(s); ${resultado.validas.length} válida(s), ${resultado.erros.length} com erro.`}
          />
          {resultado.erros.length > 0 && (
            <ul style={{ marginTop: 8, maxHeight: 160, overflow: 'auto' }} aria-label="Erros por linha">
              {resultado.erros.map((e) => <li key={e.linha}><Typography.Text type="danger">{e.mensagem}</Typography.Text></li>)}
            </ul>
          )}
          {resultado.validas.length > 0 && (
            <Table<LinhaImportadaCaixa>
              style={{ marginTop: 8 }}
              size="small"
              rowKey="linha"
              pagination={false}
              scroll={{ x: 'max-content', y: 240 }}
              dataSource={resultado.validas}
              columns={[
                { title: 'Linha', dataIndex: 'linha', width: 64 },
                { title: 'Data', dataIndex: 'data', render: formatarData },
                { title: 'Tipo', dataIndex: 'tipo', render: (t: string) => (t === 'REC' ? <Tag color="success">Entrada</Tag> : <Tag color="error">Saída</Tag>) },
                { title: 'Nº Doc.', dataIndex: 'numero_documento', render: (v: string | null) => v ?? '—' },
                { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
                { title: 'Contrapartida', dataIndex: 'conta_contrapartida' },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => formatarKz(v) },
              ]}
            />
          )}
        </>
      )}
    </Modal>
  );
}
