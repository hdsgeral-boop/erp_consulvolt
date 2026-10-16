import { Alert, Button, Descriptions, Modal, Select, Space, Typography, message } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { descarregar, enviar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { notificarErro } from '@/utilitarios/erros';
import type { RelatorioImportacao } from '../api';
import { ImportarExcel } from './ImportarExcel';

export type EntidadeCadastro = 'funcoes' | 'infotipos' | 'bancos';

const NOMES: Record<EntidadeCadastro, { rotulo: string; ficheiro: string; ajuda: string }> = {
  funcoes: { rotulo: 'Funções', ficheiro: 'Template_Funcoes.xlsx', ajuda: 'Uma linha por função: Nome (obrigatório), Objectivo Geral do Cargo e Principais Responsabilidades (uma por linha na mesma célula; o «Descrição» dos ficheiros antigos vale como responsabilidades). As funções que já existem (mesmo nome) são ignoradas.' },
  infotipos: { rotulo: 'Infotipos', ficheiro: 'Template_Infotipos.xlsx', ajuda: 'Uma linha por rubrica: Nome, Tipo (VENCIMENTO/DESCONTO/OUTROS), INSS, IRT, Base dia/hora e Por horas. As rubricas que já existem (mesmo nome) são ignoradas.' },
  bancos: { rotulo: 'Bancos', ficheiro: 'Template_Bancos.xlsx', ajuda: 'Uma linha por banco: Nome, Código, NIF, Endereço e Conta Contab. (conta de movimento do plano). Os bancos que já existem (mesmo nome) são ignorados.' },
};

interface RelatorioCopia extends RelatorioImportacao {
  origem: string;
}

/**
 * Barra do antigo nas tabelas de suporte (Funções, Infotipos, Bancos): «Template», «Copiar de outra empresa» e
 * «Importar Excel». As regras estão no servidor (/rh/paridade/cadastros/{entidade}); a lista de empresas de origem só
 * tem as empresas a que o utilizador tem acesso.
 */
export function BotoesCadastroLegado({ entidade, semCopiar }: { entidade: EntidadeCadastro; semCopiar?: boolean }) {
  const n = NOMES[entidade];
  const [importar, setImportar] = useState(false);
  const [copiar, setCopiar] = useState(false);
  return (
    <>
      <Button icon={<IconeFa nome="file-download" />} onClick={() => void descarregar(`/rh/paridade/cadastros/${entidade}/modelo`, undefined, n.ficheiro).catch(notificarErro)}>Template</Button>
      {!semCopiar && <Button style={{ color: '#2563eb' }} icon={<IconeFa nome="copy" />} onClick={() => setCopiar(true)}>Copiar de outra empresa</Button>}
      <Button icon={<IconeFa nome="file-import" />} onClick={() => setImportar(true)}>Importar Excel</Button>
      <ImportarExcel aberto={importar} titulo={`Importar ${n.rotulo} (Excel)`} url={`/rh/paridade/cadastros/${entidade}/importar`} modelo={entidade}
        urlModelo={`/rh/paridade/cadastros/${entidade}/modelo`} ajuda={n.ajuda} aoFechar={() => setImportar(false)} />
      {copiar && <ModalCopiar entidade={entidade} aoFechar={() => setCopiar(false)} />}
    </>
  );
}

function ModalCopiar({ entidade, aoFechar }: { entidade: EntidadeCadastro; aoFechar: () => void }) {
  const n = NOMES[entidade];
  const cliente = useQueryClient();
  const empresas = useQuery({ queryKey: ['rh', 'paridade', 'empresas-origem'], queryFn: () => obter<{ id: number; nome: string }[]>('/rh/paridade/empresas-origem'), staleTime: 60_000 });
  const [origem, setOrigem] = useState<number>();
  const [rel, setRel] = useState<RelatorioCopia | null>(null);
  const [aEnviar, setAEnviar] = useState(false);

  const executar = async (simular: boolean) => {
    if (!origem) return;
    setAEnviar(true);
    try {
      const { dados, mensagem } = await enviar<RelatorioCopia>('post', `/rh/paridade/cadastros/${entidade}/copiar`, { empresa_origem_id: origem, simular });
      setRel(dados);
      if (!simular) {
        message.success(mensagem);
        void cliente.invalidateQueries({ queryKey: ['rh'] });
      }
    } catch (e) {
      notificarErro(e);
    } finally {
      setAEnviar(false);
    }
  };

  return (
    <Modal open title={<Space><IconeFa nome="copy" />Copiar {n.rotulo} de outra empresa</Space>} onCancel={aoFechar} destroyOnHidden
      footer={
        <Space wrap>
          <Button onClick={aoFechar}>Fechar</Button>
          <Button disabled={!origem} loading={aEnviar} onClick={() => void executar(true)}>Simular</Button>
          <Button type="primary" disabled={!origem} loading={aEnviar} onClick={() => void executar(false)}>Copiar</Button>
        </Space>
      }>
      <Typography.Paragraph type="secondary">
        Copia para a empresa activa os registos da empresa escolhida. Os que já existem aqui (mesmo nome) ficam como estão.
      </Typography.Paragraph>
      <Select<number> style={{ width: '100%' }} placeholder="Empresa de origem" loading={empresas.isLoading} value={origem} onChange={(v) => { setOrigem(v); setRel(null); }}
        showSearch optionFilterProp="label" options={(empresas.data ?? []).map((e) => ({ value: e.id, label: e.nome }))} aria-label="Empresa de origem"
        notFoundContent="Sem outras empresas acessíveis" />
      {rel && (
        <Alert style={{ marginTop: 12 }} type={rel.simulacao ? 'info' : 'success'} showIcon message={rel.simulacao ? 'Simulação (nada foi gravado)' : 'Cópia concluída'}
          description={
            <Descriptions size="small" column={2}>
              <Descriptions.Item label={rel.simulacao ? 'A copiar' : 'Copiados'}>{rel.criados}</Descriptions.Item>
              <Descriptions.Item label="Já existiam">{rel.ignorados}</Descriptions.Item>
            </Descriptions>
          } />
      )}
    </Modal>
  );
}
