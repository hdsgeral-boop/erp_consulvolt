import { Tabs } from 'antd';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeparadorManual } from './SeparadorManual';
import { SeparadorProcessos } from './Processos';

const SEPARADORES = ['processos', 'manual'] as const;

/**
 * Manual de Procedimentos (proc_manual): como se executa cada processo da empresa — etapas, quem as faz, o que se controla e o
 * que fica feito. As narrativas vêm do Fluxo de Processos e podem ser adaptadas, escondidas ou criadas de raiz; editar a
 * narrativa muda o manual, nunca o que o painel de acompanhamento mede. Ver: proc_manual_view · editar: proc_editar ·
 * imprimir: proc_imprimir (os pedidos de escrita são também recusados no servidor).
 */
export default function ManualProcedimentos() {
  const { pode } = useSessao();
  const [separador, mudar] = useSeparadorUrl(SEPARADORES, 'manual');
  const podeEditar = pode('proc_editar');
  const podeImprimir = pode('proc_imprimir');
  return (
    <>
      <CabecalhoModulo
        titulo="Manual de Procedimentos"
        icone="book-open"
        subtitulo="Como se executa cada processo da empresa: etapas, quem as faz, o que se controla e o que fica feito. Os processos vêm do Fluxo de Processos e podem ser adaptados, escondidos ou criados de raiz."
      />
      <Tabs
        activeKey={separador}
        onChange={mudar}
        items={[
          { key: 'processos', label: 'Processos', children: <SeparadorProcessos podeEditar={podeEditar} /> },
          { key: 'manual', label: 'Manual', children: <SeparadorManual podeEditar={podeEditar} podeImprimir={podeImprimir} /> },
        ]}
      />
    </>
  );
}
