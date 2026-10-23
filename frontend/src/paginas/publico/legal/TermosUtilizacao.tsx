import { PaginaLegal } from './PaginaLegal';

export function TermosUtilizacao() {
  return (
    <PaginaLegal titulo="Termos de Utilização" actualizadoEm="20 de Outubro de 2026">
      <p>
        Estes Termos de Utilização regulam o acesso e a utilização do ERP Consulvolt ("Plataforma", "Sistema" ou "Serviço"), disponibilizado em
        regime de subscrição (<em>Software as a Service</em>) pela Consulvolt, sociedade sediada na Urbanização Lar do Patriota, Rua 6/8, Luanda, Angola.
        Ao criar uma conta ou utilizar a Plataforma, a empresa registada ("Cliente") e os seus utilizadores aceitam estes Termos.
      </p>

      <h2>1. Objecto do serviço</h2>
      <p>
        A Plataforma disponibiliza um sistema integrado de gestão empresarial (ERP) com módulos de facturação certificada pela AGT, ponto de venda,
        stocks, tesouraria, compras, contabilidade geral, recursos humanos e processamento salarial, activos fixos, orçamento e CRM, de acordo com o
        plano de subscrição escolhido.
      </p>

      <h2>2. Conta, registo e período de demonstração</h2>
      <ul>
        <li>O registo exige dados verdadeiros, completos e actualizados do Cliente e do seu administrador.</li>
        <li>Toda a nova empresa beneficia de um período de demonstração gratuito, cuja duração é indicada no momento do registo, com acesso limitado a uma empresa e um utilizador.</li>
        <li>Após o período de demonstração, o acesso contínuo à Plataforma depende da subscrição de um plano pago.</li>
        <li>O Cliente é responsável pela confidencialidade das credenciais dos seus utilizadores e por toda a actividade realizada através delas.</li>
      </ul>

      <h2>3. Planos, preços e facturação</h2>
      <p>
        Os planos disponíveis, os módulos incluídos em cada um e os respectivos preços mensais (em Kwanzas, AOA) são os indicados na Plataforma no
        momento da subscrição ou renovação. A Consulvolt pode rever preços e condições dos planos, aplicando-se as novas condições às renovações
        seguintes à sua publicação, nunca com efeito retroactivo sobre um período já pago.
      </p>

      <h2>4. Dados do Cliente</h2>
      <p>
        Todos os dados introduzidos na Plataforma pelo Cliente (registos comerciais, financeiros, de recursos humanos e documentos fiscais) são
        propriedade do Cliente. A Consulvolt actua como responsável pelo tratamento técnico desses dados nos termos descritos na nossa{' '}
        <a href="/privacidade">Política de Privacidade</a>, nunca os utilizando para fins alheios à prestação do serviço sem autorização expressa.
      </p>

      <h2>5. Obrigações do Cliente</h2>
      <ul>
        <li>Utilizar a Plataforma em conformidade com a lei angolana, incluindo as normas fiscais da Administração Geral Tributária (AGT).</li>
        <li>Não tentar contornar os limites de utilizadores, empresas ou módulos do plano subscrito.</li>
        <li>Não utilizar a Plataforma para fins ilícitos, fraudulentos ou que prejudiquem terceiros.</li>
        <li>Manter os dados de contacto (incluindo o email) actualizados, por ser o principal canal de comunicação sobre a conta e a licença.</li>
      </ul>

      <h2>6. Disponibilidade e suporte</h2>
      <p>
        A Consulvolt envida os melhores esforços para manter a Plataforma disponível de forma contínua, podendo realizar manutenções programadas
        com aviso prévio sempre que razoavelmente possível. O apoio técnico é prestado através do centro de suporte integrado na Plataforma e dos
        canais de contacto indicados no site.
      </p>

      <h2>7. Suspensão e cessação</h2>
      <p>
        A Consulvolt pode suspender ou encerrar o acesso de um Cliente em caso de incumprimento destes Termos, impagamento da subscrição após o
        período de tolerância aplicável, ou utilização que coloque em risco a segurança ou a integridade da Plataforma ou de outros clientes. Os
        dados do Cliente são preservados durante a suspensão e disponibilizados para exportação em caso de encerramento da conta, salvo obrigação
        legal em sentido diferente.
      </p>

      <h2>8. Limitação de responsabilidade</h2>
      <p>
        A Consulvolt não se responsabiliza por danos indirectos decorrentes da utilização da Plataforma, nem pela exactidão dos dados introduzidos
        pelo Cliente. A responsabilidade da Consulvolt, quando aplicável, está limitada ao valor pago pelo Cliente nos três meses anteriores ao
        facto gerador, sem prejuízo das normas imperativas da lei angolana.
      </p>

      <h2>9. Alterações aos Termos</h2>
      <p>
        Estes Termos podem ser actualizados periodicamente para reflectir alterações legais, técnicas ou comerciais. Alterações materiais serão
        comunicadas aos clientes activos através do email de contacto registado.
      </p>

      <h2>10. Lei aplicável e foro</h2>
      <p>
        Estes Termos regem-se pela lei angolana. Para a resolução de qualquer litígio emergente da sua interpretação ou execução, é competente o
        foro da Comarca de Luanda, com renúncia expressa a qualquer outro.
      </p>

      <h2>11. Contactos</h2>
      <p>
        Para questões sobre estes Termos, contacte-nos através de <a href="mailto:global@consulvolt.pt">global@consulvolt.pt</a> ou pelos canais
        indicados na página de <a href="/#contactos">Contactos</a>.
      </p>
    </PaginaLegal>
  );
}
