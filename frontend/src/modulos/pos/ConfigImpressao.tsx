import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Form, Input, Select, message } from 'antd';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { useTerminais } from './comum/dados';
import {
  guardarConfigServidor, htmlTalaoVenda, imprimirHtml, lerPreferencias, obterConfigServidor, PREFERENCIAS_PADRAO, removerSobreposicaoServidor, sincronizarPreferencias,
  temPreferenciasLocais, terminalDoPosto, useCabecalhoTalao, type PreferenciasImpressao,
} from './comum/impressao';
import './comum/pos.css';
import { useCoresPos } from './comum/cores';

/** Rótulo com ícone colorido, como no legado (renderPOSConfigPrintTab, ui_sales.js:9831-9905). */
function Rotulo({ icone, children }: { icone: string; children: ReactNode }) {
  return (
    <span style={{ fontWeight: 700, color: '#334155', fontSize: '0.85rem' }}>
      <IconeFa nome={icone} style={{ color: 'var(--erp-primaria, #2563eb)', marginRight: 4 }} />
      {children}
    </span>
  );
}

const ajuda = (texto: string) => <span style={{ fontSize: '0.75rem', color: '#64748b' }}>{texto}</span>;

/**
 * POS › Config. Impressão (ecrã pos_config_print) como no sistema anterior: só a impressão deste posto de trabalho
 * (formato, comportamento da consulta de mesa, auto-impressão no fim do checkout e mensagem de rodapé). F7: guardada NO
 * SERVIDOR, por empresa, com sobreposição opcional por posto/terminal (cada caixa pode ter a sua impressora); o navegador só
 * guarda uma cópia. Uma configuração antiga só existente neste navegador oferece «Guardar no servidor». As «Definições de
 * caixa» abrem-se nos Terminais POS.
 */
export default function ConfigImpressao() {
  const { pode, empresa } = useSessao();
  const cores = useCoresPos();
  const [form] = Form.useForm<PreferenciasImpressao>();
  const cabecalho = useCabecalhoTalao();
  const cliente = useQueryClient();
  const terminais = useTerminais();
  const podeGravar = pode('pos_print_config');
  const formato = Form.useWatch('formato', form);
  /** null = valores da empresa; um id = sobreposição desse terminal. */
  const [ambito, setAmbito] = useState<number | null>(null);
  const [aGravar, setAGravar] = useState(false);
  const config = useQuery({ queryKey: ['pos', 'impressao', 'config', empresa?.id, ambito], queryFn: () => obterConfigServidor(ambito), enabled: !!empresa?.id, retry: false });
  const c = config.data;
  const soLocal = !!c && !c.guardada && !ambito && temPreferenciasLocais(empresa?.id);
  useEffect(() => {
    if (!c) {
      form.setFieldsValue(lerPreferencias(empresa?.id));
      return;
    }
    form.setFieldsValue(!c.guardada && !ambito && temPreferenciasLocais(empresa?.id) ? lerPreferencias(empresa?.id) : { ...PREFERENCIAS_PADRAO, ...c.efectiva });
  }, [c, empresa?.id, ambito, form]);

  const gravar = async (v: Partial<PreferenciasImpressao>) => {
    setAGravar(true);
    try {
      let pedido: Partial<PreferenciasImpressao> | Record<string, unknown> = v;
      if (ambito && c?.empresa) {
        // só se sobrepõe o que difere da empresa; o resto herda
        const base = { ...PREFERENCIAS_PADRAO, ...c.empresa } as PreferenciasImpressao;
        pedido = Object.fromEntries((Object.keys(PREFERENCIAS_PADRAO) as (keyof PreferenciasImpressao)[]).map((k) => [k, v[k] !== undefined && v[k] !== base[k] ? v[k] : null]));
      }
      await guardarConfigServidor(pedido as Partial<PreferenciasImpressao>, ambito);
      await sincronizarPreferencias(empresa?.id, terminalDoPosto(empresa?.id));
      await cliente.invalidateQueries({ queryKey: ['pos', 'impressao', 'config'] });
      void message.success('Configurações de impressão do POS guardadas com sucesso!');
    } catch (e) {
      notificarErro(e, 'Não foi possível guardar a configuração de impressão.');
    } finally {
      setAGravar(false);
    }
  };

  const removerSobreposicao = async () => {
    if (!ambito) return;
    try {
      await removerSobreposicaoServidor(ambito);
      await sincronizarPreferencias(empresa?.id, terminalDoPosto(empresa?.id));
      await cliente.invalidateQueries({ queryKey: ['pos', 'impressao', 'config'] });
      void message.success('Este terminal volta a usar a configuração da empresa.');
    } catch (e) {
      notificarErro(e, 'Não foi possível remover a sobreposição.');
    }
  };

  const amostra = () => {
    const p = { ...lerPreferencias(empresa?.id), ...form.getFieldsValue() };
    imprimirHtml(
      htmlTalaoVenda(
        {
          id: 0,
          numero_documento: 'FR T01/2026/0 (exemplo)',
          data_emissao: new Date().toISOString(),
          total_liquido: '877.19',
          total_imposto: '122.81',
          total_bruto: '1000.00',
          desconto: '0.00',
          pos_troco: '0.00',
          pos_operador: 'operador',
          pos_pagamentos: [{ tipo: 'NUMERARIO', nome: 'Numerário', valor: '1000.00' }],
          itens_venda: [{ id: 1, produto_id: 0, descricao: 'Artigo de exemplo', quantidade: '1', preco_unitario: '1000.00', taxa_imposto: '14', total: '1000.00' }],
        },
        cabecalho({ terminal: 'T01' }),
        p,
      ),
    );
  };

  return (
    // cartão do legado (renderPOSConfigPrintTab, ui_sales.js:9835-9899): cabeçalho com ícone, grelha de 2 colunas e botão à direita
    <div className="pos-config-impressao" style={{ borderRadius: 4, boxShadow: '0 10px 15px -3px rgba(0,0,0,0.05)', border: `1px solid ${cores.borda}`, background: cores.fundo, padding: '2rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.5rem', borderBottom: '1px solid #f1f5f9', paddingBottom: '1rem' }}>
        <div style={{ width: 44, height: 44, borderRadius: 4, background: 'rgba(30, 41, 59, 0.1)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#1e293b', fontSize: '1.25rem', flexShrink: 0 }}>
          <IconeFa nome="print" />
        </div>
        <div>
          <h3 style={{ margin: 0, fontSize: '1.2rem', fontWeight: 800, color: cores.texto }}>Configurações de Impressão do POS</h3>
          <p style={{ margin: '0.25rem 0 0 0', fontSize: '0.8rem', color: '#64748b' }}>Configure o formato, comportamento de impressão e mensagens do rodapé do talão térmico.</p>
        </div>
      </div>
      <Form
        form={form}
        layout="vertical"
        disabled={!podeGravar}
        className="pos-config-form"
        onFinish={(v) => void gravar({ ...lerPreferencias(empresa?.id), ...v })}
      >
        <div style={{ marginBottom: '1.25rem', display: 'flex', flexDirection: 'column', gap: 12 }}>
          <Form.Item label={<Rotulo icone="desktop">Âmbito da configuração:</Rotulo>} extra={ajuda('«Toda a empresa» vale para todos os postos; um terminal pode sobrepor só o que precisar (por exemplo, o rolo de 58 mm).')} style={{ marginBottom: 0 }}>
            <Select
              aria-label="Âmbito da configuração"
              value={ambito ?? 0}
              onChange={(v) => setAmbito(v || null)}
              disabled={false}
              options={[{ value: 0, label: 'Toda a empresa' }, ...(terminais.data ?? []).map((t) => ({ value: t.id, label: `Terminal ${t.codigo} · ${t.nome}` }))]}
            />
          </Form.Item>
          {ambito && c?.terminal ? <Alert type="info" showIcon message="Este terminal tem uma sobreposição própria; os campos que não alterar herdam da empresa." /> : null}
          {soLocal && podeGravar ? (
            <Alert
              type="warning"
              showIcon
              message="Esta configuração só existe neste navegador."
              description="Guarde-a no servidor para a partilhar com os outros postos e para a recuperar se limpar o navegador."
              action={
                <Button size="small" type="primary" loading={aGravar} onClick={() => void gravar(lerPreferencias(empresa?.id))}>
                  Guardar no servidor
                </Button>
              }
            />
          ) : null}
          {config.isError ? <Alert type="warning" showIcon message="Sem ligação ao servidor: a mostrar a cópia guardada neste navegador." /> : null}
        </div>
        <div className="pos-config-grelha" style={{ display: 'grid', gap: '1.5rem', marginBottom: '2rem' }}>
          <Form.Item name="formato" label={<Rotulo icone="file-invoice">Formato Padrão do Recibo / Factura:</Rotulo>} extra={ajuda('Determina se o checkout final gera o talão de bobina térmica ou a factura normal A4.')}>
            <Select
              aria-label="Formato Padrão do Recibo / Factura"
              options={[
                { value: 'TERMICO', label: 'Talão térmico (78mm/80mm)' },
                { value: 'A4', label: 'Documento A4 Tradicional' },
              ]}
            />
          </Form.Item>
          <Form.Item
            name="consulta"
            label={<Rotulo icone="eye">Comportamento da Consulta de Mesa:</Rotulo>}
            extra={ajuda("Selecione 'Mostrar Pré-visualização' para validar a conta no ecrã antes de accionar a impressora.")}
          >
            <Select
              aria-label="Comportamento da Consulta de Mesa"
              options={[
                { value: 'PREVISUALIZAR', label: 'Mostrar Pré-visualização no Ecrã (Recomendado)' },
                { value: 'DIRECTO', label: 'Imprimir Directamente (Ignorar Pré-visualização)' },
              ]}
            />
          </Form.Item>
          <Form.Item name="automatico" label={<Rotulo icone="magic">Auto-Impressão no Fim do Checkout:</Rotulo>} extra={ajuda('Define se a impressora térmica é accionada de forma imediata assim que a venda é registada.')}>
            <Select
              aria-label="Auto-Impressão no Fim do Checkout"
              options={[
                { value: true, label: 'Sim, abrir diálogo de impressão automaticamente' },
                { value: false, label: 'Não, apenas concluir no ecrã (impressão manual)' },
              ]}
            />
          </Form.Item>
          {/* acréscimo: largura do rolo (ocupa a célula vazia da grelha do legado) */}
          {formato !== 'A4' ? (
            <Form.Item name="largura" label={<Rotulo icone="print">Largura do rolo térmico:</Rotulo>} extra={ajuda('Largura do papel da impressora térmica deste posto.')}>
              <Select
                aria-label="Largura do rolo térmico"
                options={[
                  { value: 80, label: '80 mm' },
                  { value: 58, label: '58 mm' },
                ]}
              />
            </Form.Item>
          ) : (
            <div />
          )}
          <Form.Item
            name="rodape"
            className="pos-config-largo"
            label={<Rotulo icone="comment-dots">Mensagem de Agradecimento (Rodapé do Talão):</Rotulo>}
            extra={ajuda('Esta mensagem será impressa no fim de todos os talões térmicos (Facturas e Consultas).')}
          >
            <Input.TextArea rows={3} maxLength={200} placeholder="Ex: Obrigado pela sua visita! Siga-nos no Instagram @restaurante" aria-label="Mensagem de Agradecimento (Rodapé do Talão)" />
          </Form.Item>
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, flexWrap: 'wrap', borderTop: '1px solid #f1f5f9', paddingTop: '1.25rem' }}>
          <Button icon={<IconeFa nome="print" />} disabled={false} onClick={amostra}>
            Imprimir talão de teste
          </Button>
          {podeGravar && ambito && c?.terminal ? <Button onClick={() => void removerSobreposicao()}>Usar a da empresa</Button> : null}
          {podeGravar && (
            <Button type="primary" htmlType="submit" loading={aGravar} icon={<IconeFa nome="save" />} style={{ fontWeight: 700, borderRadius: 4, padding: '0 1.8rem', height: 40, boxShadow: '0 4px 10px rgba(59,130,246,0.35)' }}>
              Guardar Configurações
            </Button>
          )}
        </div>
      </Form>
    </div>
  );
}
