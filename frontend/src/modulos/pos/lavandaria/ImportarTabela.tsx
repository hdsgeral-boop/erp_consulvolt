import { Alert, Button, Flex, Modal, Space, Table, Tag, Typography, Upload, message } from 'antd';
import { DownloadOutlined, ImportOutlined, UploadOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { descarregarCsv } from '@/utilitarios/csv';
import { notificarErro } from '@/utilitarios/erros';
import { enviarFicheiro } from '@/modulos/contab/comum/ficheiros';

export type TipoImportacaoLav = 'pecas' | 'servicos' | 'precos';

interface LinhaAnalise {
  linha: number;
  accao: 'CRIAR' | 'ACTUALIZAR' | 'IGNORADA' | 'REMOVER' | 'SEM_ALTERACAO';
  codigo: string | null;
  designacao: string;
  erros: string[];
  gravada?: boolean;
}

interface Resultado {
  tipo: TipoImportacaoLav;
  simulacao: boolean;
  lidas: number;
  validas: number;
  criadas: number;
  actualizadas: number;
  ignoradas: number;
  linhas: LinhaAnalise[];
}

/** Modelos (cabeçalho e exemplo) — as mesmas colunas do legado (js/lavandaria.js:2139-2280). */
export const MODELOS_LAV: Record<TipoImportacaoLav, { titulo: string; colunas: string[]; exemplo: string[] }> = {
  pecas: { titulo: 'peças', colunas: ['Código', 'Designação', 'Tecido', 'Cor', 'Unidade', 'Preço', 'Activa'], exemplo: ['', 'Camisa', 'Algodão', 'Branca', 'Peça', '1500', 'Sim'] },
  servicos: {
    titulo: 'serviços',
    colunas: ['Código', 'Designação', 'Grupo', 'Conta', 'IVA', 'Prazo', 'Exige orçamento', 'Activo'],
    exemplo: ['', 'Lavagem a seco', 'Lavandaria', '6211', '14', '2', 'Não', 'Sim'],
  },
  precos: { titulo: 'preços por peça e serviço', colunas: ['Peça', 'Serviço', 'Preço'], exemplo: ['PC0001', 'SV0001', '900'] },
};

const ROTULO_ACCAO: Record<LinhaAnalise['accao'], [string, string]> = {
  CRIAR: ['Criar', 'green'],
  ACTUALIZAR: ['Actualizar', 'blue'],
  REMOVER: ['Remover (usa o preço base)', 'orange'],
  SEM_ALTERACAO: ['Sem alteração', 'default'],
  IGNORADA: ['Ignorada', 'red'],
};

/**
 * M-16 — importar peças, serviços ou preços por peça e serviço (Excel/CSV): simulação com a acção de cada linha
 * (criar, actualizar, ignorada com o motivo) e importação das linhas válidas (POST /pos/lavandaria/importar, lav_tabelas).
 */
export function ImportarTabela({ tipo }: { tipo: TipoImportacaoLav }) {
  const cliente = useQueryClient();
  const [aberto, setAberto] = useState(false);
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [res, setRes] = useState<Resultado | null>(null);
  const [aEnviar, setAEnviar] = useState(false);
  const m = MODELOS_LAV[tipo];

  const executar = async (simular: boolean) => {
    if (!ficheiro) return;
    setAEnviar(true);
    try {
      const r = await enviarFicheiro<Resultado>('/pos/lavandaria/importar', ficheiro, { tipo, simular });
      setRes(r.dados);
      if (!simular) {
        message.success(r.mensagem);
        void cliente.invalidateQueries({ queryKey: ['pos', 'lavandaria'] });
      }
    } catch (e) {
      notificarErro(e, simular ? 'O ficheiro não é válido' : 'Não foi possível importar');
    } finally {
      setAEnviar(false);
    }
  };
  const fechar = () => {
    setAberto(false);
    setFicheiro(null);
    setRes(null);
  };

  return (
    <>
      <Button icon={<ImportOutlined />} onClick={() => setAberto(true)}>
        {tipo === 'precos' ? 'Importar preços por serviço' : 'Importar'}
      </Button>
      <Modal
        title={`Importar ${m.titulo}`}
        open={aberto}
        onCancel={fechar}
        width={larguraModal(900)}
        destroyOnHidden
        footer={
          <Space wrap>
            <Button onClick={fechar}>{res && !res.simulacao ? 'Fechar' : 'Cancelar'}</Button>
            <Button disabled={!ficheiro} loading={aEnviar} onClick={() => void executar(true)}>
              Pré-visualizar
            </Button>
            <Button type="primary" disabled={!ficheiro || !res?.simulacao || !res.validas} loading={aEnviar} onClick={() => void executar(false)}>
              Importar {res?.simulacao ? `${res.validas} linha(s)` : ''}
            </Button>
          </Space>
        }
      >
        <Typography.Paragraph type="secondary">
          Colunas (com cabeçalho na 1.ª linha): {m.colunas.join(' · ')}. Uma linha com código, ou com a mesma designação de um registo existente, actualiza-o;
          as restantes criam registos novos com código automático. As linhas com erros são ignoradas.
          {tipo === 'precos' && ' Preço 0 remove o preço específico (a peça passa a usar o preço base nesse serviço).'}
        </Typography.Paragraph>
        <Flex gap={8} wrap style={{ marginBottom: 12 }}>
          <Upload
            accept=".xlsx,.xls,.csv,.txt"
            maxCount={1}
            beforeUpload={(f) => {
              setFicheiro(f);
              setRes(null);
              return false;
            }}
            onRemove={() => setFicheiro(null)}
            fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
          >
            <Button icon={<UploadOutlined />}>Escolher ficheiro</Button>
          </Upload>
          <Button icon={<DownloadOutlined />} onClick={() => descarregarCsv(`modelo_lavandaria_${tipo}`, [m.colunas, m.exemplo].map((l) => l.join(';')).join('\r\n'))}>
            Baixar modelo
          </Button>
        </Flex>
        {res && (
          <>
            <Alert
              type={res.ignoradas ? 'warning' : 'success'}
              showIcon
              style={{ marginBottom: 8 }}
              message={
                res.simulacao
                  ? `Linhas lidas: ${res.lidas} · válidas: ${res.validas} · com erros (ignoradas): ${res.ignoradas}. Nada foi gravado.`
                  : `Criadas: ${res.criadas} · actualizadas: ${res.actualizadas} · ignoradas: ${res.ignoradas}.`
              }
            />
            <Table<LinhaAnalise>
              size="small"
              rowKey="linha"
              scroll={scrollTabela(360)}
              pagination={false}
              dataSource={res.linhas}
              columns={[
                { title: 'Linha', dataIndex: 'linha', width: 64 },
                { title: 'Acção', dataIndex: 'accao', render: (v: LinhaAnalise['accao'], l) => <Tag color={l.erros.length ? 'red' : ROTULO_ACCAO[v][1]}>{l.erros.length ? 'Ignorada' : ROTULO_ACCAO[v][0]}{l.codigo && v === 'ACTUALIZAR' ? ` ${l.codigo}` : ''}</Tag> },
                { title: 'Designação', dataIndex: 'designacao' },
                { title: 'Erros', dataIndex: 'erros', render: (v: string[]) => <Typography.Text type="danger">{v.join('; ')}</Typography.Text> },
              ]}
            />
          </>
        )}
      </Modal>
    </>
  );
}
