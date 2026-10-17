import { Alert, Button, Card, Flex, Input, Modal, Segmented, Space, Table, Tag, Typography, Upload, message } from 'antd';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { descarregar, enviar } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BotoesExportar } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { enviarFicheiro } from '../comum/ficheiros';
import {
  CAMPOS_ACTUALIZAVEIS,
  documentoActualizacao,
  lerLinhasActualizacao,
  resumoActualizacao,
  type LinhaActualizacao,
  type LinhaPrevia,
  type ResultadoActualizacao,
} from '../comum/rotinas';

const URL = '/contabilidade/rotinas/actualizacao-massa';
const ROTULO_CAMPO = new Map(CAMPOS_ACTUALIZAVEIS.map((c) => [c.valor, c.rotulo]));
const CORES: Record<LinhaPrevia['estado'], string> = { OK: 'green', SEM_ALTERACAO: 'default', ERRO: 'red' };
const ESTADOS: Record<LinhaPrevia['estado'], string> = { OK: 'Alterar', SEM_ALTERACAO: 'Sem alteração', ERRO: 'Erro' };

/**
 * Rotinas › Actualização em Massa (legado: «Actualização em Massa por Referência», js/ui_rotinas.js:147-166 e 377-553):
 * «Baixar modelo» (Excel com as colunas aceites, instruções e listas de referência da empresa) e «Importar» do modelo
 * preenchido — ou colar as linhas — com validação linha a linha no servidor e PRÉ-VISUALIZAÇÃO das alterações (valor actual
 * → novo valor) antes de aplicar; aplica numa transacção (tudo ou nada, ou só as válidas, confirmado), com o relatório de
 * erros (imprimir/PDF/Excel). As alterações aplicadas ficam no Histórico e podem ser anuladas.
 */
export function ActualizacaoMassa({ exec }: { exec: boolean }) {
  const cliente = useQueryClient();
  const [origem, setOrigem] = useState<'ficheiro' | 'colar'>('ficheiro');
  const [texto, setTexto] = useState('');
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [linhas, setLinhas] = useState<LinhaActualizacao[]>([]);
  const [previa, setPrevia] = useState<ResultadoActualizacao | null>(null);
  const [aplicado, setAplicado] = useState<ResultadoActualizacao | null>(null);
  const [aDescarregar, setADescarregar] = useState(false);
  const coladas = useMemo(() => lerLinhasActualizacao(texto), [texto]);
  const limparResultado = () => {
    setPrevia(null);
    setAplicado(null);
  };

  const validar = useMutation({
    mutationFn: async () => {
      if (origem === 'ficheiro') {
        if (!ficheiro) throw new Error('Escolha o ficheiro preenchido.');
        return (await enviarFicheiro<ResultadoActualizacao>(`${URL}/importar`, ficheiro)).dados;
      }
      const r = (await enviar<ResultadoActualizacao>('post', URL, { linhas: coladas, simular: true })).dados;
      return { ...r, linhas: coladas };
    },
    onSuccess: (r) => {
      setPrevia(r);
      setAplicado(null);
      setLinhas(r.linhas ?? []);
    },
    onError: (e) => notificarErro(e, 'Não foi possível validar as linhas'),
  });

  const aplicar = useMutation({
    mutationFn: (soValidas: boolean) => enviar<ResultadoActualizacao>('post', URL, { linhas, so_validas: soValidas }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      setAplicado(dados);
      setPrevia(null);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível aplicar a actualização'),
  });

  const confirmarAplicar = () => {
    if (!previa) return;
    const r = resumoActualizacao(previa);
    if (!r.validas) return void message.warning('Não há linhas válidas para aplicar.');
    Modal.confirm({
      title: 'Aplicar a actualização em massa',
      content: r.erros
        ? `Há ${r.erros} linha(s) com erro, que serão ignoradas. Aplicar só as ${r.validas} linha(s) válida(s)? (Fica no Histórico e pode ser anulada.)`
        : `Aplicar as ${r.validas} alteração(ões) numa só operação? (Fica no Histórico e pode ser anulada.)`,
      okText: r.erros ? `Aplicar ${r.validas} válida(s)` : 'Aplicar',
      cancelText: 'Cancelar',
      onOk: () => aplicar.mutateAsync(r.erros > 0),
    });
  };

  const baixarModelo = async () => {
    setADescarregar(true);
    try {
      await descarregar(`${URL}/modelo`, undefined, 'Template_Ajuste_Contabil.xlsx');
    } catch (e) {
      notificarErro(e, 'Não foi possível descarregar o modelo');
    } finally {
      setADescarregar(false);
    }
  };

  const resultado = aplicado ?? previa;
  const resumo = resultado ? resumoActualizacao(resultado) : null;

  return (
    <Card>
      <Flex justify="space-between" wrap gap={12} align="flex-start" style={{ marginBottom: 12 }}>
        <div>
          <Typography.Title level={5} style={{ margin: 0 }}>
            <IconeFa nome="file-import" /> Actualização em Massa por Referência
          </Typography.Title>
          <Typography.Text type="secondary">Modifique campos de lançamentos usando um ficheiro Excel.</Typography.Text>
        </div>
        <Button icon={<IconeFa nome="file-download" />} loading={aDescarregar} onClick={() => void baixarModelo()}>
          Baixar modelo Excel
        </Button>
      </Flex>
      <Alert
        type="info"
        style={{ marginBottom: 12 }}
        message="Instruções"
        description={
          <ul style={{ margin: 0, paddingLeft: 18 }}>
            <li>
              O Excel deve conter: <b>lancamento_id</b>, <b>campo_a_modificar</b>, <b>novo_valor</b> (folha «Ajustes» do modelo).
            </li>
            <li>Campos suportados: {CAMPOS_ACTUALIZAVEIS.map((c) => c.valor).join(', ')} (também os nomes do sistema anterior).</li>
            <li>O «lancamento_id» é o ID da linha na lista de Lançamentos. Os ids das notas, diários, unidades, centros e terceiros estão nas folhas do modelo.</li>
            <li>Nada é gravado sem a pré-visualização: valide, confira as alterações e só depois aplique.</li>
          </ul>
        }
      />
      <Space direction="vertical" style={{ width: '100%' }} size={12}>
        <Segmented<'ficheiro' | 'colar'>
          value={origem}
          onChange={(v) => {
            setOrigem(v);
            limparResultado();
          }}
          options={[
            { value: 'ficheiro', label: <span><IconeFa nome="file-excel" /> Importar ficheiro</span> },
            { value: 'colar', label: <span><IconeFa nome="paste" /> Colar linhas</span> },
          ]}
        />
        {origem === 'ficheiro' ? (
          <Upload
            accept=".xlsx,.xls,.csv"
            maxCount={1}
            disabled={!exec}
            beforeUpload={(f) => {
              setFicheiro(f);
              limparResultado();
              return false;
            }}
            onRemove={() => {
              setFicheiro(null);
              limparResultado();
            }}
            fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
          >
            <Button icon={<IconeFa nome="upload" />} disabled={!exec}>
              Seleccionar ficheiro Excel
            </Button>
          </Upload>
        ) : (
          <>
            <Input.TextArea
              rows={6}
              value={texto}
              aria-label="Linhas a actualizar: id; campo; novo valor"
              onChange={(e) => {
                setTexto(e.target.value);
                limparResultado();
              }}
              placeholder={'91489;nota_demonstracao_id;272\n91490;descricao;Nova descrição'}
              disabled={!exec}
            />
            <Typography.Text type="secondary">{coladas.length} linha(s) reconhecida(s)</Typography.Text>
          </>
        )}
        {exec && (
          <Space wrap>
            <Button
              type="primary"
              icon={<IconeFa nome="check-double" />}
              loading={validar.isPending}
              disabled={origem === 'ficheiro' ? !ficheiro : !coladas.length}
              onClick={() => validar.mutate()}
            >
              Validar e pré-visualizar
            </Button>
            {previa && (
              <Button type="primary" icon={<IconeFa nome="play" />} loading={aplicar.isPending} disabled={!resumoActualizacao(previa).validas} onClick={confirmarAplicar}>
                {resumoActualizacao(previa).erros ? `Aplicar só as ${resumoActualizacao(previa).validas} válida(s)` : 'Aplicar alterações'}
              </Button>
            )}
          </Space>
        )}
      </Space>

      {resultado && resumo && (
        <div style={{ marginTop: 16 }}>
          <Alert
            type={aplicado ? (resumo.erros ? 'warning' : 'success') : resumo.erros ? 'warning' : 'info'}
            showIcon
            message={
              aplicado
                ? `${aplicado.actualizadas} linha(s) actualizada(s) · ${resumo.erros} com erro${aplicado.codigo ? ` · rotina ${aplicado.codigo} (pode ser anulada no Histórico)` : ''}`
                : `Pré-visualização: ${resumo.alterar} alteração(ões), ${resumo.semAlteracao} sem alteração, ${resumo.erros} com erro. Nada foi gravado.`
            }
            description={resultado.avisos?.length ? resultado.avisos.join(' ') : undefined}
          />
          <Flex justify="end" style={{ margin: '8px 0' }}>
            <BotoesExportar
              tamanho="small"
              obterPedido={() => ({
                titulo: aplicado ? 'Actualização em massa — relatório' : 'Actualização em massa — pré-visualização',
                filtros: [aplicado?.codigo ? `Rotina ${aplicado.codigo}` : 'Pré-visualização (nada gravado)', `${resumo.erros} linha(s) com erro`],
                orientacao: 'paisagem',
                conteudo: documentoActualizacao(resultado),
              })}
            />
          </Flex>
          {aplicado ? (
            aplicado.erros.length > 0 && (
              <Table
                scroll={scrollTabela()}
                rowKey={(r) => `${r.linha}`}
                size="small"
                pagination={aplicado.erros.length > 50 ? { pageSize: 50 } : false}
                dataSource={aplicado.erros}
                columns={[
                  { title: 'Linha', dataIndex: 'linha', width: 80 },
                  { title: 'Lançamento', dataIndex: 'lancamento_id', width: 120 },
                  { title: 'Motivo', dataIndex: 'motivo' },
                ]}
              />
            )
          ) : (
            <Table<LinhaPrevia>
              scroll={scrollTabela()}
              rowKey={(r) => `${r.linha}-${r.lancamento_id}-${r.campo}`}
              size="small"
              pagination={(previa?.previa.length ?? 0) > 50 ? { pageSize: 50, showSizeChanger: true } : false}
              dataSource={previa?.previa ?? []}
              rowClassName={(r) => (r.estado === 'ERRO' ? 'erp-linha-erro' : '')}
              columns={[
                { title: 'Linha', dataIndex: 'linha', width: 70 },
                { title: 'ID', dataIndex: 'lancamento_id', width: 90 },
                { title: 'Nº Lanç.', dataIndex: 'numero_lan', responsive: ['md'] },
                { title: 'Conta', dataIndex: 'codigo_conta', responsive: ['md'] },
                { title: 'Campo', dataIndex: 'campo', render: (v: string) => ROTULO_CAMPO.get(v) ?? v },
                { title: 'Valor actual', dataIndex: 'anterior', ellipsis: true, render: (v: string | null) => v ?? <Typography.Text type="secondary">(vazio)</Typography.Text> },
                { title: 'Novo valor', dataIndex: 'novo', ellipsis: true, render: (v: string | null) => v ?? <Typography.Text type="secondary">(vazio)</Typography.Text> },
                { title: 'Estado', dataIndex: 'estado', render: (v: LinhaPrevia['estado'], r) => <Tag color={CORES[v]} title={r.motivo ?? undefined}>{ESTADOS[v]}</Tag> },
                { title: 'Motivo', dataIndex: 'motivo', ellipsis: true, render: (v: string | null) => (v ? <Typography.Text type="danger">{v}</Typography.Text> : null) },
              ]}
            />
          )}
        </div>
      )}
    </Card>
  );
}
