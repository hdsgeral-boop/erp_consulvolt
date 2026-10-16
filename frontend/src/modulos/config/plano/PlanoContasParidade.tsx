/**
 * Plano de contas — funções do ecrã do sistema anterior (renderPlanoContas, js/app_v2.js:8320-8402; verificação visual de
 * 2026-10-08, linha 14.3.1): coluna «Uso», «Copiar de outra empresa», «Baixar Template» e «Importar Excel».
 * Endpoints: GET /config/plano-contas/uso · POST /config/plano-contas/copiar[/simular] · POST /config/plano-contas/importar
 * · GET /sistema/migracao/modelos/plano_contas (modelo).
 */
import { Alert, Button, Modal, Select, Space, Table, Tag, Typography, Upload, message, theme } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { descarregar, enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { IconeFa } from '@/componentes/fluxos';
import { larguraModal } from '@/componentes/responsivo';
import { enviarFicheiro } from '@/modulos/contab/comum/ficheiros';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarNumero } from '@/utilitarios/formatacao';
import { validarFicheiroPlano } from '../comum/paridade';

export const CHAVE_USO = ['config', 'plano-contas', 'uso'];
const CHAVE_PLANO = ['contab', 'plano-contas'];

/** Códigos das contas com lançamentos (coluna «Uso»). */
export function useContasEmUso(activo = true) {
  return useQuery({
    queryKey: CHAVE_USO,
    queryFn: async () => new Set(await obter<string[]>('/config/plano-contas/uso')),
    enabled: activo,
    staleTime: 60_000,
    retry: false,
  });
}

/** Célula «Uso» do antigo: «🔒 Em Uso» (âmbar) ou «-». */
export function CelulaUso({ emUso }: { emUso: boolean }) {
  return emUso ? (
    <Tag color="gold" style={{ borderRadius: 20, fontWeight: 600 }} icon={<IconeFa nome="lock" style={{ marginInlineEnd: 4 }} />}>
      Em Uso
    </Tag>
  ) : (
    <span>-</span>
  );
}

export function baixarTemplatePlano() {
  return descarregar('/sistema/migracao/modelos/plano_contas', undefined, 'Template_Plano_Contas.xlsx').catch((e) => notificarErro(e, 'Não foi possível obter o modelo'));
}

interface SimulacaoCopia {
  empresa_origem_id: number;
  empresa_origem: string;
  registos_origem: number;
  novas: number;
  existentes: number;
  amostra: { codigo: string; descricao: string | null; tipo: string }[];
}

/** «Copiar de outra empresa» (openCopySimpleTableModal, app_v2.js:4850): só acrescenta as contas que faltam. */
export function CopiarOutraEmpresa({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const { empresas, empresa } = useSessao();
  const cliente = useQueryClient();
  const outras = empresas.filter((e) => e.id !== empresa?.id);
  const { token } = theme.useToken();
  const [origem, setOrigem] = useState<number | undefined>();
  const previa = useQuery({
    queryKey: ['config', 'plano-contas', 'copiar', empresa?.id, origem],
    queryFn: async () => (await enviar<SimulacaoCopia>('post', '/config/plano-contas/copiar/simular', { empresa_origem_id: origem })).dados,
    enabled: aberto && origem !== undefined,
    retry: false,
    staleTime: 0,
  });
  const copiar = useMutation({
    mutationFn: () => enviar<{ copiadas: number; existentes: number }>('post', '/config/plano-contas/copiar', { empresa_origem_id: origem, confirmar: true }),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: CHAVE_PLANO });
      void cliente.invalidateQueries({ queryKey: ['config', 'plano-contas'] });
      fechar();
    },
    onError: (e) => notificarErro(e, 'A cópia não foi feita'),
  });
  const fechar = () => {
    setOrigem(undefined);
    aoFechar();
  };
  const p = previa.data;
  const confirmar = () =>
    Modal.confirm({
      title: `Copiar ${formatarNumero(p?.novas ?? 0)} conta(s) de «${p?.empresa_origem ?? ''}»?`,
      content: `As contas são acrescentadas a «${empresa?.nome ?? 'empresa activa'}». As que já existem não são alteradas nem apagadas.`,
      okText: 'Copiar Registos',
      cancelText: 'Cancelar',
      onOk: () => copiar.mutateAsync(),
    });

  return (
    <Modal
      title={<Space><IconeFa nome="copy" /> Copiar Plano de Contas</Space>}
      open={aberto}
      onCancel={fechar}
      width={larguraModal(560)}
      destroyOnHidden
      footer={
        <Space>
          <Button onClick={fechar}>Cancelar</Button>
          <Button type="primary" icon={<IconeFa nome="copy" />} disabled={!p || p.novas === 0} loading={copiar.isPending} onClick={confirmar}>
            Copiar Registos
          </Button>
        </Space>
      }
    >
      <Typography.Paragraph type="secondary">
        Os registos serão copiados para <strong>{empresa?.nome ?? 'a empresa activa'}</strong>. Registos com o mesmo Código já existentes serão ignorados.
      </Typography.Paragraph>
      {outras.length === 0 ? (
        <Alert type="info" showIcon message="Não existem outras empresas a que tenha acesso para copiar." />
      ) : (
        <>
          <Typography.Text strong>Empresa de Origem</Typography.Text>
          <Select
            style={{ width: '100%', marginTop: 4 }}
            placeholder="Escolha a empresa"
            value={origem}
            onChange={setOrigem}
            showSearch
            optionFilterProp="label"
            options={outras.map((e) => ({ value: e.id, label: e.nome }))}
            aria-label="Empresa de origem"
          />
        </>
      )}
      {previa.error && <Alert style={{ marginTop: 12 }} type="error" showIcon message={(previa.error as Error).message} />}
      {p && (
        <div style={{ marginTop: 12 }}>
          <div style={{ background: token.colorFillAlter, borderRadius: 4, padding: '0.6rem 1rem' }}>
            <strong>{formatarNumero(p.registos_origem)}</strong> na origem · <strong style={{ color: '#16a34a' }}>{formatarNumero(p.novas)} novos</strong> a copiar ·{' '}
            <strong style={{ color: token.colorTextSecondary }}>{formatarNumero(p.existentes)} já existem</strong>
          </div>
          {p.amostra.length > 0 && (
            <Table
              style={{ marginTop: 8 }}
              size="small"
              rowKey="codigo"
              pagination={false}
              scroll={{ y: 220 }}
              dataSource={p.amostra}
              columns={[
                { title: 'Código', dataIndex: 'codigo', width: 110 },
                { title: 'Descrição', dataIndex: 'descricao' },
                { title: 'Tipo', dataIndex: 'tipo', width: 70 },
              ]}
              footer={p.novas > p.amostra.length ? () => `… e mais ${formatarNumero(p.novas - p.amostra.length)} conta(s).` : undefined}
            />
          )}
        </div>
      )}
    </Modal>
  );
}

interface LinhaRelatorio {
  linha: number;
  codigo?: string;
  motivo: string;
}

interface RelatorioImportacao {
  simulacao: boolean;
  linhas_lidas: number;
  linhas_exemplo_ignoradas: number;
  novas: number;
  existentes: number;
  repetidas: number;
  erros: LinhaRelatorio[];
  avisos: LinhaRelatorio[];
  amostra: { codigo: string; descricao: string; tipo: string }[];
  criadas: number;
}

/** «Importar Excel» (importChartOfAccountsExcel, app_v2.js:8643): validar primeiro; só cria contas novas. */
export function ImportarPlano({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [rel, setRel] = useState<RelatorioImportacao | null>(null);
  const [erros, setErros] = useState<LinhaRelatorio[]>([]);
  const fechar = () => {
    setFicheiro(null);
    setRel(null);
    setErros([]);
    aoFechar();
  };
  const executar = useMutation({
    mutationFn: (simular: boolean) => enviarFicheiro<RelatorioImportacao>('/config/plano-contas/importar', ficheiro as File, { simular }),
    onSuccess: ({ dados, mensagem }, simular) => {
      setRel(dados);
      setErros(dados.erros);
      if (!simular) {
        message.success(mensagem);
        void cliente.invalidateQueries({ queryKey: CHAVE_PLANO });
        setFicheiro(null);
      }
    },
    onError: (e) => {
      if (e instanceof ErroApi && e.codigo === 'IMPORTACAO_COM_ERROS' && e.erros) {
        setErros(((e.erros as { linhas?: LinhaRelatorio[] }).linhas ?? []) as LinhaRelatorio[]);
        message.error(e.message);
      } else notificarErro(e, 'Não foi possível importar');
    },
  });
  const podeImportar = !!ficheiro && !!rel?.simulacao && rel.erros.length === 0 && rel.novas > 0;

  return (
    <Modal
      title={<Space><IconeFa nome="file-import" /> Importar Plano de Contas (Excel)</Space>}
      open={aberto}
      onCancel={fechar}
      width={larguraModal(760)}
      destroyOnHidden
      footer={
        <Space wrap>
          <Button onClick={fechar}>Fechar</Button>
          <Button disabled={!ficheiro} loading={executar.isPending && executar.variables === true} onClick={() => executar.mutate(true)}>Validar (simulação)</Button>
          <Button type="primary" disabled={!podeImportar} loading={executar.isPending && executar.variables === false} onClick={() => executar.mutate(false)}>Importar</Button>
        </Space>
      }
    >
      <Space direction="vertical" style={{ width: '100%' }} size="middle">
        <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
          Colunas: <Typography.Text code>Conta</Typography.Text> (código), <Typography.Text code>Descrição</Typography.Text> e <Typography.Text code>Tipo</Typography.Text> (M = movimento, por
          omissão; T = totalizadora). Valide primeiro: nada é gravado enquanto houver linhas com erro. As contas que já existem <strong>não são alteradas nem apagadas</strong>. Máximo 5 MB (XLSX, XLS ou CSV).
        </Typography.Paragraph>
        <Space wrap>
          <Button icon={<IconeFa nome="file-download" />} onClick={() => void baixarTemplatePlano()}>Baixar Template</Button>
          <Upload
            accept=".xlsx,.xls,.csv"
            maxCount={1}
            beforeUpload={(f) => {
              const erro = validarFicheiroPlano(f);
              if (erro) message.error(erro);
              else {
                setFicheiro(f);
                setRel(null);
                setErros([]);
              }
              return false;
            }}
            onRemove={() => {
              setFicheiro(null);
              setRel(null);
              setErros([]);
            }}
            fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
          >
            <Button icon={<IconeFa nome="upload" />}>Escolher ficheiro</Button>
          </Upload>
        </Space>
        {rel && (
          <Alert
            type={rel.erros.length ? 'warning' : rel.simulacao ? 'info' : 'success'}
            showIcon
            message={
              rel.simulacao
                ? `Simulação (nada foi gravado): ${rel.novas} conta(s) nova(s), ${rel.existentes} já existente(s) mantida(s), ${rel.repetidas} repetida(s), ${rel.erros.length} linha(s) com erro.`
                : `Importação concluída: ${rel.criadas} conta(s) criada(s); ${rel.existentes} já existente(s) mantida(s).`
            }
            description={rel.linhas_exemplo_ignoradas > 0 ? `A linha de exemplo do modelo foi ignorada (${rel.linhas_exemplo_ignoradas}).` : undefined}
          />
        )}
        {erros.length > 0 && (
          <>
            <Typography.Text type="danger" strong>{erros.length} linha(s) com erro — corrija o ficheiro:</Typography.Text>
            <Table rowKey={(l) => `${l.linha}-${l.motivo}`} size="small" pagination={{ pageSize: 10 }} dataSource={erros} scroll={{ x: 'max-content' }}
              columns={[{ title: 'Linha', dataIndex: 'linha', width: 70 }, { title: 'Código', dataIndex: 'codigo', width: 120 }, { title: 'Motivo', dataIndex: 'motivo' }]} />
          </>
        )}
        {rel && rel.erros.length === 0 && rel.simulacao && rel.amostra.length > 0 && (
          <Table rowKey="codigo" size="small" pagination={false} scroll={{ y: 220 }} dataSource={rel.amostra} title={() => 'Contas a criar'}
            columns={[{ title: 'Código', dataIndex: 'codigo', width: 120 }, { title: 'Descrição', dataIndex: 'descricao' }, { title: 'Tipo', dataIndex: 'tipo', width: 70 }]}
            footer={rel.novas > rel.amostra.length ? () => `… e mais ${rel.novas - rel.amostra.length} conta(s).` : undefined} />
        )}
        {rel && rel.avisos.length > 0 && (
          <details>
            <summary>{rel.avisos.length} aviso(s) (contas existentes mantidas e linhas repetidas)</summary>
            <ul style={{ paddingLeft: 18, margin: '6px 0 0', maxHeight: 180, overflow: 'auto' }}>
              {rel.avisos.map((a) => <li key={`${a.linha}-${a.motivo}`}>Linha {a.linha}: {a.motivo}</li>)}
            </ul>
          </details>
        )}
      </Space>
    </Modal>
  );
}
