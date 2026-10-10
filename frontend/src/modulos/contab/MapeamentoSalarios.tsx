import { Alert, Button, Card, Form, Modal, Select, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { scrollTabela, useEcra } from '@/componentes/responsivo';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { TabelaComModos, type ColunaLegado } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import {
  alteracoes,
  chaveCelula,
  contarComConta,
  corpoGravacao,
  grelhaRubricas,
  grelhaSistema,
  IMPOSTOS_SISTEMA,
  type Coluna,
  type MapeamentoRubrica,
  type MapeamentoSistema,
} from './comum/mapeamento';
import { SeletorConta } from './comum/Seletores';
import './MapeamentoSalarios.css';

interface DadosMapeamento {
  tipos_organizacao: { id: number; nome: string }[];
  infotipos: { id: number; nome: string; tipo: string | null }[];
  codigos_sistema: string[];
  rubricas: MapeamentoRubrica[];
  sistema: MapeamentoSistema[];
}

/** Resultado de POST /rh/mapeamentos-contabeis/copiar (simulação = pré-visualização do legado). */
interface ResultadoCopia {
  origem: string;
  simulacao: boolean;
  rubricas: number;
  sistema: number;
  ja_mapeados: number;
  sem_correspondencia: number;
  contas_invalidas: string[];
}

interface LinhaRubrica {
  chave: string;
  nome: string;
  tipo: string | null;
}

interface LinhaImposto {
  chave: string;
  nome: string;
  natureza: string;
  avencado: boolean;
  semTipo?: boolean;
}

const DICA_AVENCADO = 'Conta usada na integração salarial para colaboradores avençados (rubricas e salário líquido a pagar). Se vazia, usa a conta do tipo de órgão.';
const DICA_HISTORICO =
  'O sistema anterior reconstruía o mapeamento a partir das descrições dos lançamentos SAL já integrados. Aqui os lançamentos de salários são agregados por conta (sem a rubrica na descrição) e o mapeamento não guarda versões anteriores, por isso não há de onde o recuperar com segurança. Use «Copiar de outra empresa» ou edite o mapeamento.';

/** Etiqueta do tipo da rubrica / natureza do imposto (.map-tipo venc|desc|outro do legado). */
function EtiquetaTipo({ texto }: { texto: string | null }) {
  const t = texto || 'OUTROS';
  const cls = t === 'VENCIMENTO' ? 'venc' : t === 'DESCONTO' || /DÉBITO\)$/.test(t) ? 'desc' : 'outro';
  return <span className={`map-tipo ${cls}`}>{t}</span>;
}

/**
 * Contabilidade › Mapeamento contábil (renderContabilidade, js/app_v2.js:2278-2558) com o layout do ecrã antigo: título e,
 * à direita, «Editar Mapeamento» (leitura → edição; em edição «Gravar Mapeamento» / «Cancelar»), «Copiar de outra
 * empresa» e «Recuperar de Histórico»; texto explicativo; cartão «Mapeamento de Impostos e Encargos» (contas do sistema) e
 * cartão «Mapeamento de Rubricas (Infotipos)» com a pílula «N de M com conta» e o padrão das listas (registos encontrados,
 * Excel/PDF/Imprimir/Colunas, TOTAL e paginação). Os endpoints são do módulo RH (GET/PUT /api/rh/mapeamentos-contabeis e
 * POST …/copiar), protegidos pelas tarefas deste ecrã (contabilidade_view / contab_mapeamento).
 */
export default function MapeamentoSalarios() {
  const { pode, empresas, empresa } = useSessao();
  const cliente = useQueryClient();
  const consulta = useQuery({ queryKey: ['rh', 'mapeamentos-contabeis'], queryFn: () => obter<DadosMapeamento>('/rh/mapeamentos-contabeis') });
  const d = consulta.data;
  const originalR = useMemo(() => grelhaRubricas(d?.rubricas ?? []), [d]);
  const originalS = useMemo(() => grelhaSistema(d?.sistema ?? []), [d]);
  const [editR, setEditR] = useState<Record<string, string>>({});
  const [editS, setEditS] = useState<Record<string, string>>({});
  // legado: window.accountingEditMode (toggleAccountingEdit) — os selectores ficam desactivados em modo leitura
  const [emEdicao, setEmEdicao] = useState(false);
  const [copiar, setCopiar] = useState(false);
  const [formCopia] = Form.useForm<{ empresa_id: number }>();
  const [previa, setPrevia] = useState<ResultadoCopia | null>(null);
  useEffect(() => {
    setEditR(originalR);
    setEditS(originalS);
  }, [originalR, originalS]);

  const mudR = alteracoes(originalR, editR);
  const mudS = alteracoes(originalS, editS);
  const nAlteracoes = mudR.length + mudS.length;
  const editar = pode('contab_mapeamento');
  const outras = empresas.filter((e) => e.id !== empresa?.id).map((e) => ({ value: e.id, label: e.nome }));
  const invalidar = () => void cliente.invalidateQueries({ queryKey: ['rh', 'mapeamentos-contabeis'] });

  const gravar = useMutation({
    mutationFn: () => enviar('put', '/rh/mapeamentos-contabeis', corpoGravacao(mudR, mudS)),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setEmEdicao(false);
      invalidar();
    },
    onError: (e) => notificarErro(e, 'Não foi possível gravar os mapeamentos'),
  });

  const copia = useMutation({
    mutationFn: ({ origem, simular }: { origem: number; simular: boolean }) => enviar<ResultadoCopia>('post', '/rh/mapeamentos-contabeis/copiar', { empresa_origem_id: origem, simular }),
    onSuccess: ({ dados, mensagem }) => {
      if (dados.simulacao) {
        setPrevia(dados);
        return;
      }
      message.success(mensagem);
      setCopiar(false);
      invalidar();
    },
    onError: (e) => notificarErro(e, 'Não foi possível copiar o mapeamento'),
  });

  const cancelar = () => {
    setEditR(originalR);
    setEditS(originalS);
    setEmEdicao(false);
  };

  const { telemovel } = useEcra();
  const colunas: { chave: Coluna; titulo: string }[] = (d?.tipos_organizacao ?? []).map((t) => ({ chave: t.id as Coluna, titulo: `${t.nome} (Conta)` }));

  const seletor = (valores: Record<string, string>, definir: (k: string, v: string) => void, k: string) => (
    <SeletorConta
      value={valores[k] || undefined}
      onChange={(v) => definir(k, v ?? '')}
      allowClear
      disabled={!editar || !emEdicao}
      placeholder="-- Selecione uma conta --"
      style={{ width: '100%', minWidth: 160 }}
    />
  );
  const definirR = (k: string, v: string) => setEditR((e) => ({ ...e, [k]: v }));
  const definirS = (k: string, v: string) => setEditS((e) => ({ ...e, [k]: v }));
  const cabecalhoAvencado = () => ({ title: DICA_AVENCADO });

  // ── Cartão 1: impostos e encargos (sysTrs, js/app_v2.js:2472-2486) ──
  const linhasImpostos: LinhaImposto[] = IMPOSTOS_SISTEMA.filter((i) => d?.codigos_sistema.includes(i.codigo)).map((i) => ({ ...i, chave: i.codigo }));
  const colunasImpostos = [
    {
      title: 'Imposto / Encargo',
      key: 'nome',
      fixed: telemovel ? undefined : ('left' as const),
      render: (_: unknown, l: LinhaImposto) => (
        <>
          <span className="map-nome">{l.nome}</span>
          <EtiquetaTipo texto={l.natureza} />
        </>
      ),
    },
    ...colunas.map((c, i) => ({
      title: c.titulo,
      key: String(c.chave),
      // ROUNDING_DIFF não depende do tipo de organização: uma só célula a toda a largura
      onCell: (l: LinhaImposto) => (l.semTipo ? { colSpan: i === 0 ? colunas.length : 0 } : {}),
      render: (_: unknown, l: LinhaImposto) => seletor(editS, definirS, chaveCelula(l.chave, l.semTipo ? 0 : c.chave)),
    })),
    {
      title: 'Avençado (Conta)',
      key: 'AV',
      className: 'col-avencado',
      onHeaderCell: cabecalhoAvencado,
      render: (_: unknown, l: LinhaImposto) => (l.avencado ? seletor(editS, definirS, chaveCelula(l.chave, 'AV')) : <span className="map-sem-aplicacao">—</span>),
    },
  ];

  // ── Cartão 2: rubricas (infotipos) (trs, js/app_v2.js:2445-2458) ──
  const linhasRubricas: LinhaRubrica[] = (d?.infotipos ?? []).map((i) => ({ chave: String(i.id), nome: i.nome, tipo: i.tipo }));
  const nComConta = contarComConta(
    linhasRubricas.map((l) => l.chave),
    editR,
  );
  const colunaConta = (titulo: string, chave: string, coluna: Coluna, extra: Partial<ColunaLegado<LinhaRubrica>> = {}): ColunaLegado<LinhaRubrica> => {
    const conta = (l: LinhaRubrica) => editR[chaveCelula(l.chave, coluna)] || '';
    const comConta = (linhas: LinhaRubrica[]) => String(linhas.filter((l) => conta(l)).length);
    return {
      title: titulo,
      key: chave,
      render: (_: unknown, l: LinhaRubrica) => seletor(editR, definirR, chaveCelula(l.chave, coluna)),
      valorFiltro: conta,
      valorImpressao: (l) => conta(l) || '—',
      total: comConta,
      totalImpressao: comConta,
      ...extra,
    };
  };
  const colunasRubricas: ColunaLegado<LinhaRubrica>[] = [
    {
      title: 'Infotipo',
      key: 'nome',
      fixed: telemovel ? undefined : 'left',
      ocultavel: false,
      sorter: (a, b) => a.nome.localeCompare(b.nome),
      valorFiltro: (l) => `${l.nome} ${l.tipo ?? ''}`,
      valorImpressao: (l) => `${l.nome}${l.tipo ? ` (${l.tipo})` : ''}`,
      render: (_: unknown, l) => (
        <>
          <span className="map-nome">{l.nome}</span>
          <EtiquetaTipo texto={l.tipo} />
        </>
      ),
    },
    ...colunas.map((c) => colunaConta(c.titulo, String(c.chave), c.chave)),
    colunaConta('Avençado (Conta)', 'AV', 'AV', { className: 'col-avencado', onHeaderCell: cabecalhoAvencado }),
  ];

  const vazio = !!d && !d.rubricas.length && !d.sistema.length;

  return (
    <>
      <CabecalhoPagina
        titulo="Mapeamento contábil"
        accoes={
          editar ? (
            <Space wrap>
              {emEdicao ? (
                <>
                  <Button color="green" variant="solid" icon={<IconeFa nome="save" />} loading={gravar.isPending} disabled={!nAlteracoes} onClick={() => gravar.mutate()}>
                    Gravar Mapeamento{nAlteracoes > 0 ? ` (${nAlteracoes})` : ''}
                  </Button>
                  <Button onClick={cancelar}>Cancelar</Button>
                </>
              ) : (
                <Button type="primary" icon={<IconeFa nome="edit" />} disabled={!d} onClick={() => setEmEdicao(true)}>
                  Editar Mapeamento
                </Button>
              )}
              <Button
                color="primary"
                variant="outlined"
                icon={<IconeFa nome="copy" />}
                disabled={!outras.length}
                title={outras.length ? undefined : 'Não existem outras empresas para copiar.'}
                onClick={() => {
                  formCopia.resetFields();
                  setPrevia(null);
                  setCopiar(true);
                }}
              >
                Copiar de outra empresa
              </Button>
              <Tooltip title={DICA_HISTORICO}>
                <Button color="green" variant="outlined" icon={<IconeFa nome="history" />} disabled>
                  Recuperar de Histórico
                </Button>
              </Tooltip>
            </Space>
          ) : (
            <Tag>Modo Leitura</Tag>
          )
        }
      />
      {!d ? (
        <Card loading={consulta.isLoading}>{consulta.isError && <Alert type="error" message="Não foi possível obter os mapeamentos." />}</Card>
      ) : (
        <>
          {vazio && (
            <Alert
              type="warning"
              showIcon
              style={{ marginBottom: 24 }}
              message="Diagnóstico: Mapeamento Vazio"
              description={`Nenhum mapeamento encontrado nesta empresa. Os dados ainda não foram configurados${editar && outras.length ? ' — use «Copiar de outra empresa» ou «Editar Mapeamento»' : ''}.`}
            />
          )}
          <Typography.Paragraph type="secondary" style={{ marginBottom: 16 }}>
            Associe as Contas Contábeis aos Infotipos por Tipo de Órgão. Apenas contas do tipo <strong>Movimento</strong> estão disponíveis para seleção. A coluna{' '}
            <strong>Avençado (Conta)</strong> define, para os colaboradores avençados, a conta de cada infotipo e a conta do salário líquido a pagar; se ficar vazia, é usada a conta do
            tipo de órgão.
          </Typography.Paragraph>

          <Card className="contab-map-card" size="small" title="Mapeamento de Impostos e Encargos">
            <Table<LinhaImposto> rowKey="chave" size="small" pagination={false} dataSource={linhasImpostos} scroll={scrollTabela()} columns={colunasImpostos} />
          </Card>

          <Card
            className="contab-map-card"
            size="small"
            title={
              <>
                Mapeamento de Rubricas (Infotipos)
                <span className="map-contagem">
                  {nComConta} de {linhasRubricas.length} com conta
                </span>
              </>
            }
          >
            <TabelaComModos<LinhaRubrica>
              idVista="rubricas"
              modos={false}
              rowKey="chave"
              size="small"
              dataSource={linhasRubricas}
              scroll={scrollTabela()}
              columns={colunasRubricas}
              impressao={{ titulo: 'Mapeamento contábil — Rubricas (Infotipos)', orientacao: 'paisagem' }}
            />
          </Card>
        </>
      )}

      <Modal
        title={
          <>
            <IconeFa nome="copy" /> Copiar Mapeamento contábil
          </>
        }
        open={copiar}
        onCancel={() => setCopiar(false)}
        okText="Copiar Mapeamento"
        okButtonProps={{ disabled: !previa || previa.rubricas + previa.sistema === 0 }}
        confirmLoading={copia.isPending && !!previa}
        onOk={() => formCopia.submit()}
      >
        <Typography.Paragraph type="secondary">
          Selecione a empresa de <strong>origem</strong>. O mapeamento será copiado para <strong>{empresa?.nome ?? 'empresa activa'}</strong>. A resolução é feita por <em>nome</em> de
          Infotipo e Órgão. Mapeamentos já existentes não serão sobrepostos e só são copiadas contas de movimento que existam no plano de contas desta empresa.
        </Typography.Paragraph>
        <Form form={formCopia} layout="vertical" onFinish={(v) => copia.mutate({ origem: v.empresa_id, simular: false })}>
          <Form.Item name="empresa_id" label="Empresa de Origem" rules={[{ required: true, message: 'Selecione uma empresa de origem.' }]}>
            <Select
              options={outras}
              showSearch
              optionFilterProp="label"
              onChange={(origem: number) => {
                setPrevia(null);
                copia.mutate({ origem, simular: true });
              }}
            />
          </Form.Item>
        </Form>
        {previa && (
          <Alert
            type={previa.rubricas + previa.sistema > 0 ? 'info' : 'warning'}
            message={
              <>
                <strong>{previa.rubricas}</strong> mapeamentos de Infotipos a copiar · <strong>{previa.sistema}</strong> mapeamentos de Impostos a copiar
              </>
            }
            description={
              <>
                {previa.rubricas + previa.sistema === 0 && <div>Nenhum mapeamento novo para copiar. Verifique se os nomes de Infotipos e Órgãos coincidem entre as empresas.</div>}
                {previa.ja_mapeados > 0 && <div>{previa.ja_mapeados} já mapeado(s) nesta empresa (não são sobrepostos).</div>}
                {previa.sem_correspondencia > 0 && <div>{previa.sem_correspondencia} sem Infotipo ou Órgão com o mesmo nome nesta empresa.</div>}
                {previa.contas_invalidas.length > 0 && <div>Contas inexistentes ou não de movimento nesta empresa: {previa.contas_invalidas.join(', ')}.</div>}
              </>
            }
          />
        )}
      </Modal>
    </>
  );
}
