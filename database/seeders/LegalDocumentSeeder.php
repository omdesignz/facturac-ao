<?php

namespace Database\Seeders;

use App\LegalDocumentType;
use App\Models\LegalDocument;
use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

/**
 * Publishes version 1 of each legal document.
 *
 * The text is written for Angola specifically: data protection follows Lei
 * n.º 22/11 and the APD rather than the GDPR, invoicing obligations follow the
 * AGT's e-invoicing regime, and consumer complaints name INADEC. It is a
 * starting point drafted to be accurate about what this application does, not
 * a substitute for having a lawyer read it before launch.
 */
class LegalDocumentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (LegalDocumentType::ordered() as $type) {
            if (LegalDocument::query()->where('type', $type)->exists()) {
                continue;
            }

            LegalDocument::query()->create([
                'type' => $type,
                'version' => 1,
                'title' => $type->label(),
                'summary' => $this->summary($type),
                'body' => $this->body($type),
                'effective_at' => now(),
            ])->forceFill(['published_at' => now()])->save();
        }
    }

    private function summary(LegalDocumentType $type): string
    {
        return match ($type) {
            LegalDocumentType::Terms => 'As condições de utilização do serviço, o que garantimos e o que lhe compete a si enquanto contribuinte.',
            LegalDocumentType::Privacy => 'Que dados tratamos, com que fundamento, durante quanto tempo e quais são os seus direitos.',
            LegalDocumentType::Cookies => 'Que cookies usamos, para quê, e como pode recusar os que não são essenciais.',
        };
    }

    private function body(LegalDocumentType $type): string
    {
        $company = PlatformSetting::get('company_legal_name');
        $nif = PlatformSetting::get('company_nif');
        $address = PlatformSetting::get('company_address');
        $supportEmail = PlatformSetting::get('support_email');
        $privacyEmail = PlatformSetting::get('data_protection_email');
        $complaintsEmail = PlatformSetting::get('complaints_email');
        $responseDays = PlatformSetting::get('complaints_response_days');
        $authority = (string) config('platform.consumer_authority.name');
        $authorityUrl = (string) config('platform.consumer_authority.url');
        $app = (string) config('app.name');

        return match ($type) {
            LegalDocumentType::Terms => <<<MARKDOWN
                ## 1. Quem somos

                O {$app} é explorado pela {$company}, contribuinte n.º {$nif}, com sede em {$address}.

                ## 2. O que o serviço faz

                O {$app} emite documentos fiscais electrónicos — facturas, facturas-recibo, recibos, notas de crédito e notas de débito — e comunica-os à Administração Geral Tributária (AGT) nos termos do regime de facturação electrónica em vigor.

                O serviço prepara, assina e submete os documentos. Não substitui o seu contabilista nem a sua obrigação de declarar e pagar imposto.

                ## 3. A sua conta

                Precisa de uma conta para usar o serviço. É responsável por manter a palavra-passe em segredo e por tudo o que for feito a partir da sua conta. Se suspeitar de acesso indevido, altere a palavra-passe e avise-nos de imediato para {$supportEmail}.

                Recomendamos vivamente a autenticação de dois factores. Para perfis com permissões elevadas, é obrigatória.

                ## 4. Os seus dados fiscais são seus

                Os documentos que emite, os seus clientes e o seu catálogo pertencem-lhe. Pode exportá-los a qualquer momento. Se cancelar a assinatura, mantemos os dados acessíveis durante o período indicado na Política de Privacidade antes de os eliminarmos.

                ## 5. Documentos emitidos não se apagam

                Um documento fiscal, depois de emitido e comunicado à AGT, não pode ser alterado nem eliminado — nem por si, nem por nós. Corrige-se emitindo uma nota de crédito ou de débito. Esta é uma exigência legal, não uma limitação do produto.

                ## 6. Apoio e diagnóstico

                Quando pede ajuda, a nossa equipa pode precisar de entrar na sua conta para reproduzir o problema. Fá-lo com registo do motivo, por tempo limitado, e é sempre avisado por email quando acontece. Durante esse acesso ficam bloqueadas as operações irreversíveis: emitir documentos, mexer em pagamentos e alterar as suas credenciais.

                ## 7. Pagamento

                O serviço é pago por assinatura, nas condições e no plano que escolher. A falta de pagamento suspende a emissão de novos documentos; não apaga os já emitidos, que continuam acessíveis para consulta e exportação.

                ## 8. Disponibilidade

                Esforçamo-nos por manter o serviço disponível, mas não garantimos funcionamento ininterrupto. Pode haver interrupções para manutenção, e podem ocorrer falhas nos sistemas da AGT que estão fora do nosso controlo. Quando a AGT está indisponível, o documento é guardado e comunicado assim que o serviço da AGT for restabelecido.

                ## 9. Limites da nossa responsabilidade

                Não respondemos por coimas, juros ou prejuízos resultantes de dados que tenha introduzido incorrectamente, do incumprimento de prazos declarativos, ou de decisões da AGT. Respondemos, nos termos gerais de direito, pelos danos causados por falha nossa.

                ## 10. Reclamações

                Se algo correr mal, apresente reclamação na secção **Ajuda e Reclamações** da aplicação ou para {$complaintsEmail}. Respondemos em {$responseDays} dias úteis.

                Se não ficar satisfeito com a nossa resposta, pode recorrer ao {$authority} — {$authorityUrl}.

                ## 11. Alterações a estes termos

                Podemos alterar estes termos. Publicamos sempre uma nova versão datada, mantemos as anteriores acessíveis, e avisamo-lo antes de a nova entrar em vigor. Continuar a usar o serviço depois dessa data significa que aceita a versão nova.

                ## 12. Lei aplicável

                Aplica-se a lei angolana. Para qualquer litígio é competente o foro da Comarca de Luanda, com renúncia expressa a qualquer outro.
                MARKDOWN,

            LegalDocumentType::Privacy => <<<MARKDOWN
                ## 1. Quem trata os seus dados

                O responsável pelo tratamento é a {$company}, contribuinte n.º {$nif}, com sede em {$address}.

                Para qualquer questão sobre dados pessoais, escreva para {$privacyEmail}.

                ## 2. Enquadramento legal

                Tratamos dados pessoais nos termos da **Lei n.º 22/11, de 17 de Junho — Lei da Protecção de Dados Pessoais**, e sob a supervisão da Agência de Protecção de Dados (APD).

                ## 3. Que dados tratamos

                **Dados de conta:** nome, email, palavra-passe (guardada cifrada, nunca em texto simples), e os métodos de autenticação que activar.

                **Dados da empresa:** denominação, NIF, morada, estabelecimentos e regime fiscal.

                **Dados de facturação:** os documentos que emite e os dados dos seus clientes que neles inclui. Aqui somos **subcontratante**: os dados dos seus clientes são seus, e tratamo-los apenas para lhe prestar o serviço.

                **Dados técnicos:** endereço IP, tipo de dispositivo e registos de acesso, guardados por segurança e para investigar incidentes.

                **Dados de pagamento:** o plano, o histórico de cobrança e as referências de pagamento. Não guardamos números de cartão.

                ## 4. Com que fundamento

                - **Execução do contrato** — para lhe prestar o serviço que contratou.
                - **Obrigação legal** — para conservar documentos fiscais pelo prazo que a lei exige.
                - **Interesse legítimo** — para manter o serviço seguro e prevenir fraude.
                - **Consentimento** — para cookies não essenciais e comunicações que não sejam operacionais. Pode retirá-lo a qualquer momento.

                ## 5. Com quem partilhamos

                - **AGT** — os documentos fiscais que emite, por imposição legal.
                - **Prestadores de pagamento** — o estritamente necessário para processar a sua assinatura.
                - **Infra-estrutura** — fornecedores de alojamento e de email, vinculados por contrato e sem autorização para usar os dados para fins próprios.

                Não vendemos dados pessoais. Nunca.

                ## 6. Onde ficam

                Os dados podem ser alojados fora de Angola. Nesse caso asseguramos garantias contratuais de protecção equivalentes às exigidas pela lei angolana.

                ## 7. Durante quanto tempo

                - **Documentos fiscais e registos contabilísticos:** pelo prazo legal de conservação, que não podemos encurtar mesmo a seu pedido.
                - **Dados de conta:** enquanto a conta existir, e 90 dias depois do cancelamento para lhe permitir exportar o que precisa.
                - **Registos de acesso e auditoria:** 12 meses.

                ## 8. Os seus direitos

                Tem direito a aceder aos seus dados, a corrigi-los, a pedir a sua eliminação, a opor-se a certos tratamentos e a recebê-los em formato portável.

                Escreva para {$privacyEmail}. Respondemos no prazo legal. Se não ficar satisfeito, pode reclamar junto da APD.

                Note que o direito à eliminação não se sobrepõe à obrigação legal de conservar documentos fiscais já emitidos.

                ## 9. Segurança

                Ciframos os dados em trânsito e as credenciais fiscais em repouso. Registamos os acessos privilegiados. Suportamos autenticação de dois factores e chaves de acesso (passkeys), e exigimo-la nos perfis com permissões elevadas.

                Se ocorrer uma violação de dados que lhe possa causar prejuízo, avisamo-lo a si e à APD nos termos da lei.

                ## 10. Acesso da equipa de apoio

                A nossa equipa pode entrar na sua conta para diagnosticar problemas. Cada acesso exige um motivo escrito, é limitado no tempo, fica integralmente registado, e é-lhe comunicado por email no momento em que acontece.

                ## 11. Menores

                O serviço destina-se a empresas e profissionais. Não se dirige a menores nem recolhemos dados de menores conscientemente.

                ## 12. Alterações

                Publicamos cada alteração como uma nova versão datada e mantemos as anteriores acessíveis.
                MARKDOWN,

            LegalDocumentType::Cookies => <<<MARKDOWN
                ## 1. O que são

                Cookies são pequenos ficheiros que o seu navegador guarda quando visita um site. Servem para o reconhecer entre páginas e para memorizar preferências.

                ## 2. O que usamos

                ### Essenciais — sempre activos

                Sem estes o serviço não funciona, por isso não dependem de consentimento.

                | Cookie | Para quê | Duração |
                |---|---|---|
                | Sessão | Manter a sua sessão iniciada | Sessão |
                | Token CSRF | Impedir pedidos forjados a partir de outros sites | Sessão |
                | Consentimento | Recordar esta escolha, para não voltarmos a perguntar | 12 meses |

                ### Preferências — opcionais

                Memorizam como gosta de ver a aplicação: tema claro ou escuro, barra lateral aberta ou recolhida. Se recusar, a aplicação funciona na mesma; volta apenas ao aspecto por omissão em cada visita.

                ### Análise de utilização — opcionais

                Ajudam-nos a perceber que funcionalidades são usadas e onde as pessoas se perdem. Só são activados se aceitar.

                ## 3. O que não fazemos

                Não usamos cookies de publicidade. Não partilhamos os seus dados de navegação com redes de anunciantes. Não o seguimos por outros sites.

                ## 4. Como escolher

                Perguntamos na primeira visita. A opção por omissão, se fechar o aviso sem escolher, é **apenas os essenciais** — o silêncio não vale como consentimento.

                Pode mudar de ideias a qualquer momento nas definições da conta, e pode sempre apagar os cookies pelo seu navegador.

                ## 5. Dúvidas

                Escreva para {$privacyEmail}.
                MARKDOWN,
        };
    }
}
