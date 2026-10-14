<?php

return [
    'edition' => '2026.10',
    'updated_at' => '13 de Outubro de 2026',
    'title' => 'Guia de utilização',
    'subtitle' => 'Do primeiro acesso à gestão do dia a dia.',
    /*
     * One line per topic for the guide's home, in the order the topics are
     * shelved. A chapter's `category` must be one of these keys.
     */
    'categories' => [
        'Primeiros passos' => 'Preparar a empresa, os clientes e os artigos antes da primeira venda.',
        'Vendas e recebimentos' => 'Emitir, vender ao balcão, orçamentar e receber o que lhe devem.',
        'Operação diária' => 'Guias, avenças e importações: o trabalho que se repete todas as semanas.',
        'Controlo e gestão' => 'A comunicação à AGT, o SAF-T, os relatórios e a segurança da conta.',
    ],
    /** The tasks people come looking for, each answered by one chapter. */
    'popular' => [
        ['label' => 'Emitir uma factura', 'chapter' => 'documentos'],
        ['label' => 'Vender ao balcão', 'chapter' => 'pos'],
        ['label' => 'Registar um recebimento', 'chapter' => 'cobrancas'],
        ['label' => 'Acompanhar a AGT', 'chapter' => 'agt'],
        ['label' => 'Exportar o SAF-T', 'chapter' => 'saft'],
        ['label' => 'Importar clientes e artigos', 'chapter' => 'importar'],
    ],
    'chapters' => [
        [
            'id' => 'comecar',
            'category' => 'Primeiros passos',
            'title' => 'O seu primeiro dia no facturac',
            'summary' => 'Um percurso simples: preparar a empresa, organizar os dados e acompanhar a primeira venda.',
            'route' => 'onboarding',
            'minutes' => 3,
            'steps' => [
                [
                    'title' => 'Prepare a base',
                    'body' => 'Comece em Configuração → Preparar empresa. Confira a identidade fiscal, o estabelecimento e os dados bancários. Depois, peça ao responsável pela empresa para configurar a ligação AGT e as séries adequadas.',
                ],
                [
                    'title' => 'Organize o trabalho',
                    'body' => 'Cadastre um cliente e um artigo ou serviço. Em Documentos fiscais, prepare um rascunho e confira as linhas, os impostos e o total. Guardar um rascunho não equivale a emitir.',
                ],
                [
                    'title' => 'Feche o ciclo',
                    'body' => 'Quando estiver tudo correcto e a emissão estiver disponível, emita o documento e acompanhe-o no Monitor AGT. Confira o estado apresentado antes de o considerar aceite. Use este guia para aprender cada etapa, ao seu ritmo.',
                ],
            ],
            'checklist' => [
                'Sei onde configurar a empresa.',
                'Distingo guardar de emitir.',
                'Sei onde acompanhar a comunicação à AGT.',
            ],
            'tip' => 'Os vistos deste guia registam a sua aprendizagem. Não emitem documentos nem verificam a configuração real da empresa.',
            'question' => 'Não encontro uma opção.',
            'answer' => 'Confirme a empresa seleccionada e as suas permissões. Algumas acções pertencem ao proprietário ou a utilizadores autorizados. Se necessário, contacte o administrador.',
            'related' => [
                'empresa',
                'empreendedor-agt',
                'documentos',
                'agt',
            ],
        ],
        [
            'id' => 'empresa',
            'category' => 'Primeiros passos',
            'title' => 'Preparar a empresa',
            'summary' => 'A identidade que aparece nos documentos começa numa configuração cuidada.',
            'route' => 'onboarding',
            'minutes' => 3,
            'steps' => [
                [
                    'title' => 'Confirme a identidade',
                    'body' => 'Abra Preparar empresa. Preencha a razão social, nome comercial, NIF, regime fiscal e actividade. Reveja os dados com o responsável fiscal antes de guardar.',
                ],
                [
                    'title' => 'Identifique o estabelecimento',
                    'body' => 'Indique o código, nome e morada do estabelecimento. Para outras lojas ou unidades, abra Estabelecimentos. Ao criar um documento, confirme sempre qual é a unidade emissora.',
                ],
                [
                    'title' => 'Personalize os documentos',
                    'body' => 'Carregue o logótipo da empresa e configure banco, titular, número da conta e IBAN. Estes dados alimentam os documentos apropriados. Use a pré-visualização para conferir a apresentação.',
                ],
            ],
            'checklist' => [
                'Conferi NIF, regime e actividade.',
                'Confirmei o estabelecimento e a morada.',
                'Revi o logótipo e os dados bancários.',
            ],
            'tip' => 'A ficha actual da empresa e um documento já emitido têm funções diferentes. Uma alteração à configuração não deve ser usada para tentar corrigir um documento fiscal emitido.',
            'question' => 'Os campos estão bloqueados.',
            'answer' => 'Verifique as permissões do seu utilizador. A página distingue a edição dos dados fiscais da edição dos dados bancários; peça ao responsável uma alteração quando necessário.',
            'related' => [
                'empreendedor-agt',
                'agt',
                'seguranca',
            ],
        ],
        [
            'id' => 'empreendedor-agt',
            'category' => 'Primeiros passos',
            'title' => 'Empreendedor individual e AGT',
            'summary' => 'Trabalha por conta própria? A certificação do software cabe à VAP; a adesão fiscal é da sua empresa. Não precisa de repetir a homologação do produtor. Confirme o seu enquadramento antes de avançar.',
            'route' => 'onboarding',
            'minutes' => 4,
            'steps' => [
                [
                    'title' => 'Confirme os dados essenciais',
                    'body' => 'Em Preparar empresa, indique nome ou razão social, NIF, actividade (CAE), sede e estabelecimento. Escolha e confirme explicitamente o regime fiscal com o responsável fiscal; não é atribuído automaticamente. Active a autenticação de dois factores (MFA) da conta Facturac.',
                ],
                [
                    'title' => 'Autorize a associação preparada pela VAP',
                    'body' => 'A AGT disponibiliza as chaves do contribuinte; a VAP prepara a associação segura e gere as credenciais API e a chave do produtor, distintas do seu acesso fiscal. Como proprietário ou administrador, confira empresa, NIF e ambiente de testes, autorize a associação à chave previamente preparada e confirme a sua palavra-passe Facturac quando solicitada. A palavra-passe do Portal do Contribuinte fica consigo.',
                ],
                [
                    'title' => 'Verifique a ligação e prepare a primeira factura',
                    'body' => 'Use Verificar ligação AGT: a consulta não emite facturas. Confirme uma série FT autorizada e utilizável para a empresa e o ambiente; sem série, a ligação verificada não basta. Prepare o primeiro rascunho e confira os dados. A validação da primeira factura de teste integra a verificação controlada pela VAP, sem repetir a homologação por cliente.',
                ],
            ],
            'checklist' => [
                'Confirmei os dados, o regime fiscal e a protecção MFA.',
                'Autorizei a associação da empresa, NIF e ambiente correctos.',
                'Distingo ligação verificada, série FT utilizável e emissão real.',
            ],
            'tip' => 'Percurso implementado e verificado localmente, em testes. Antes de facturar a sério, obtenha confirmação da disponibilização da versão, da activação AGT em produção, do provisionamento e custódia reais das chaves e das séries de produção. Preparar um rascunho não é emitir.',
            'question' => 'A preparação ou a verificação ficou bloqueada.',
            'answer' => 'Sem preparação, peça à VAP a associação; sem série FT, peça uma série autorizada. Para outros erros, consulte a mensagem e contacte Ajuda e reclamações com a referência e a hora. Nunca envie palavras-passe, chaves ou códigos MFA por email ou chat.',
            'related' => [
                'empresa',
                'agt',
                'documentos',
                'seguranca',
            ],
        ],
        [
            'id' => 'clientes',
            'category' => 'Primeiros passos',
            'title' => 'Clientes e condições de pagamento',
            'summary' => 'Mantenha os dados do comprador organizados para facturar e cobrar com menos erros.',
            'route' => 'customers.index',
            'minutes' => 3,
            'steps' => [
                [
                    'title' => 'Crie ou encontre o cliente',
                    'body' => 'Abra Clientes e pesquise antes de criar um novo registo. Confira nome, NIF, país e morada. Evite duplicados: podem dividir o histórico e dificultar a cobrança.',
                ],
                [
                    'title' => 'Defina as condições',
                    'body' => 'Preencha os contactos e as condições de pagamento disponíveis na ficha. Consulte os preços específicos do cliente quando existirem. Confirme os dados seleccionados no momento de preparar o documento.',
                ],
                [
                    'title' => 'Acompanhe a relação',
                    'body' => 'Consulte os documentos e valores em aberto. Em Dívidas, reveja os vencimentos antes de contactar o cliente. Um orçamento aceite ainda não é uma factura paga.',
                ],
            ],
            'checklist' => [
                'Pesquisei antes de criar o cliente.',
                'Conferi a identidade do comprador.',
                'Revi as condições de pagamento.',
            ],
            'tip' => 'Exemplo: para um cliente com pagamento a prazo, confira a data de vencimento na factura. O prazo do orçamento refere-se à aceitação da proposta, não ao vencimento da venda.',
            'question' => 'Preciso de corrigir um NIF num documento emitido.',
            'answer' => 'Editar a ficha do cliente não corrige automaticamente documentos históricos. Abra o documento e siga o processo de correcção disponível, com apoio do responsável fiscal.',
            'related' => [
                'documentos',
                'cobrancas',
            ],
        ],
        [
            'id' => 'artigos',
            'category' => 'Primeiros passos',
            'title' => 'Artigos, preços e inventário',
            'summary' => 'Uma boa ficha de artigo evita repetir trabalho em cada venda.',
            'route' => 'catalogue.index',
            'minutes' => 3,
            'steps' => [
                [
                    'title' => 'Cadastre o que vende',
                    'body' => 'Em Artigos e serviços, defina código, descrição, unidade e preço. Distinga bens de serviços e reveja o tratamento fiscal. Não escolha uma isenção apenas para reduzir o total.',
                ],
                [
                    'title' => 'Organize os preços',
                    'body' => 'Use Tabelas de preços e os preços específicos do cliente quando aplicável. Ao adicionar o artigo a uma venda, confira o preço e o desconto efectivamente apresentados.',
                ],
                [
                    'title' => 'Controle o inventário',
                    'body' => 'Para artigos com controlo de stock, consulte Inventário e registe os movimentos disponíveis com uma descrição clara. Confira quantidades e unidades antes de confirmar uma entrada, saída ou ajuste.',
                ],
            ],
            'checklist' => [
                'Os códigos identificam os artigos sem duplicados.',
                'Revi preços, unidades e impostos.',
                'Identifiquei os artigos com controlo de stock.',
            ],
            'tip' => 'Use descrições que façam sentido para o cliente. Um código interno ajuda a pesquisa; uma descrição clara ajuda a compreender a factura.',
            'question' => 'O preço da venda difere da ficha.',
            'answer' => 'Confira a tabela de preços, o cliente seleccionado e os descontos da linha. Reveja o valor aplicado antes de emitir.',
            'related' => [
                'importar',
                'pos',
                'documentos',
            ],
        ],
        [
            'id' => 'documentos',
            'category' => 'Vendas e recebimentos',
            'title' => 'Da preparação à emissão',
            'summary' => 'Escolha o documento adequado, confira os valores e acompanhe o resultado.',
            'route' => 'documents.index',
            'minutes' => 13,
            'scenarios' => [
                [
                    'code' => 'FT',
                    'title' => 'Factura',
                    'when' => 'Venda com pagamento posterior',
                    'example' => 'Entrega equipamento hoje e o cliente paga em 30 dias.',
                    'action' => 'Prepare FT com cliente, linhas e vencimento. Quando receber, liquide através de RG.',
                    'attention' => 'Se o pagamento total acompanha a venda, use FR; não emita outra venda ao receber.',
                ],
                [
                    'code' => 'FR',
                    'title' => 'Factura-recibo',
                    'when' => 'Venda totalmente paga no acto',
                    'example' => 'Um cliente compra material e paga a totalidade por Multicaixa.',
                    'action' => 'Escolha FR, confira o meio e o montante pago e reveja a pré-visualização.',
                    'attention' => 'Uma FR já comprova o pagamento; não emita RG para liquidar a mesma venda.',
                ],
                [
                    'code' => 'GF',
                    'title' => 'Factura genérica',
                    'when' => 'Serviços mensais de uma instituição financeira',
                    'example' => 'Uma instituição financeira reúne os serviços cobrados e pagos por um cliente durante o mês.',
                    'action' => 'Confirme o enquadramento e registe a data de operação de cada linha.',
                    'attention' => 'GF não é uma factura genérica para qualquer negócio nem substitui FT ou factura global.',
                ],
                [
                    'code' => 'NC',
                    'title' => 'Nota de crédito',
                    'when' => 'Devolução que reduz uma venda anterior',
                    'example' => 'O cliente devolve uma unidade de uma factura de três unidades.',
                    'action' => 'Identifique a factura e a linha original, a quantidade devolvida e o motivo. Registe a tomada de conhecimento do cliente.',
                    'attention' => 'Reveja o efeito no stock. A comunicação exige a referência à linha original; se a validação falhar, peça apoio.',
                ],
                [
                    'code' => 'ND',
                    'title' => 'Nota de débito',
                    'when' => 'Débito sem obrigação de facturar',
                    'example' => 'Pagou por conta do cliente uma despesa cujo comprovativo está emitido em nome dele.',
                    'action' => 'Confirme com o responsável fiscal o repasse pelo valor exacto, sem IVA, antes de preparar a ND.',
                    'attention' => 'Não use ND para aumentar uma venda tributável. O fluxo actual exige documento de origem; se não se adequar ao caso, peça apoio.',
                ],
            ],
            'source' => ['label' => 'DP 71/25 · artigos 3.º a 8.º', 'url' => 'https://lex.ao/docs/presidente-da-republica/2025/decreto-presidencial-n-o-71-25-de-20-de-marco/'],
            'steps' => [
                [
                    'title' => 'Escolha e prepare',
                    'body' => 'Abra Documentos fiscais e crie o documento adequado à operação. A factura regista a venda; a factura-recibo combina venda e recebimento. Se já existe uma factura, registe o recebimento pelo processo de liquidação, evitando facturar a mesma venda outra vez.',
                ],
                [
                    'title' => 'Reveja o rascunho',
                    'body' => 'Confira cliente, estabelecimento, datas, artigos, quantidades, descontos, impostos e total. Indique a disponibilização dos bens ou serviços quando o formulário a pedir. Se a linha deriva de uma guia, seleccione a origem correspondente.',
                ],
                [
                    'title' => 'Emita e acompanhe',
                    'body' => 'Guarde para continuar depois ou emita quando estiver pronto. A emissão atribui numeração e congela o conteúdo fiscal. Consulte o estado no Monitor AGT; depois use as opções de impressão, PDF ou envio disponíveis no documento.',
                ],
            ],
            'checklist' => [
                'O tipo de documento corresponde à operação.',
                'Conferi comprador, datas, linhas e total.',
                'Sei distinguir emissão de aceitação pela AGT.',
            ],
            'tip' => 'Exemplo: uma venda a prazo começa por uma factura. O pagamento posterior deve liquidar essa factura, em vez de criar uma segunda venda.',
            'question' => 'Encontrei um erro depois de emitir.',
            'answer' => 'Não tente reescrever o documento. Abra as opções de correcção disponíveis e mantenha a referência ao original. Confirme com o responsável fiscal se é necessária uma nota de crédito e uma nova factura.',
            'related' => [
                'cobrancas',
                'agt',
                'guias',
            ],
        ],
        [
            'id' => 'pos',
            'category' => 'Vendas e recebimentos',
            'title' => 'Vender no ponto de venda',
            'summary' => 'Do início do turno à conferência de caixa, sem perder o controlo da venda.',
            'route' => 'pos.show',
            'minutes' => 5,
            'screenshots' => [[
                'src' => '/brand/guide-pos-tv.jpg',
                'title' => 'Antes de emitir um TV',
                'alt' => 'Janela Cobrar com Talão de venda seleccionado, fundamento de serviço com bilhete e confirmação de uso pessoal por preencher.',
                'caption' => 'Recorte do POS com dados de demonstração. A escolha TV apresenta o fundamento legal e a confirmação das condições.',
                'instruction' => '1. Escolha o documento adequado. 2. Seleccione a operação real. 3. Confirme as condições apenas quando forem verdadeiras. Depois confira o pagamento. Nesta imagem não foi emitido qualquer documento.',
            ]],
            'scenarios' => [
                [
                    'code' => 'TV',
                    'title' => 'Talão de venda',
                    'when' => 'Pagamento de uma operação abrangida pelo artigo 5.º',
                    'example' => 'Uma pessoa compra um bilhete para uso pessoal, fora de qualquer actividade profissional, e não pede factura-recibo.',
                    'action' => 'No POS escolha TV, seleccione o fundamento real e confirme as condições. Confira o pagamento antes de concluir.',
                    'attention' => 'Uma venda normal de loja não se torna elegível só por ser a consumidor final. Se for pedida factura-recibo, use FR.',
                ],
            ],
            'source' => ['label' => 'DP 71/25 · artigos 3.º a 8.º', 'url' => 'https://lex.ao/docs/presidente-da-republica/2025/decreto-presidencial-n-o-71-25-de-20-de-marco/'],
            'steps' => [
                [
                    'title' => 'Abra o turno',
                    'body' => 'Escolha a caixa disponível e registe o fundo inicial. Confirme o estabelecimento e o operador. A abertura do turno permite acompanhar os movimentos de numerário ao longo do dia.',
                ],
                [
                    'title' => 'Prepare e cobre',
                    'body' => 'Pesquise ou leia o código dos artigos, ajuste quantidades e confira o comprador. No pagamento, reveja o tipo de documento e o meio de pagamento. O talão de venda exige o enquadramento e as confirmações apresentados no formulário; não é a opção automática para qualquer consumidor final.',
                ],
                [
                    'title' => 'Confira e feche',
                    'body' => 'Depois da venda, use a opção de impressão disponível. Registe entradas e saídas de caixa com motivo. No fecho, conte o numerário real e compare-o com o esperado; investigue diferenças antes de terminar o turno.',
                ],
            ],
            'checklist' => [
                'Confirmei a caixa e o fundo inicial.',
                'Conferi o documento e o meio de pagamento.',
                'Sei conferir e fechar o turno.',
            ],
            'tip' => 'Se o cliente pedir factura-recibo, escolha essa opção. Nunca confirme um enquadramento de talão de venda que não corresponda à operação.',
            'question' => 'O pagamento demorou ou apareceu um erro.',
            'answer' => 'Verifique se a venda já aparece no histórico antes de repetir a confirmação. Se o resultado continuar incerto, registe a referência e contacte o apoio para evitar duplicações.',
            'related' => [
                'artigos',
                'documentos',
                'agt',
            ],
        ],
        [
            'id' => 'orcamentos',
            'category' => 'Vendas e recebimentos',
            'title' => 'Preparar e converter orçamentos',
            'summary' => 'A proposta comercial tem o seu próprio ciclo, antes da emissão fiscal.',
            'route' => 'quotes.index',
            'minutes' => 7,
            'scenarios' => [
                [
                    'code' => 'OR',
                    'title' => 'Orçamento',
                    'when' => 'Proposta antes da decisão de compra',
                    'example' => 'O cliente pede o preço da instalação de um equipamento, mas ainda não aceitou.',
                    'action' => 'Prepare as linhas, validade e condições. Depois da aceitação, converta e reveja a FT em rascunho.',
                    'attention' => 'Aceitar ou converter não regista pagamento. O orçamento não substitui o documento fiscal.',
                ],
                [
                    'code' => 'PF',
                    'title' => 'Factura proforma',
                    'when' => 'Documento preliminar solicitado pelo cliente',
                    'example' => 'O cliente pede uma proforma para aprovação interna da compra.',
                    'action' => 'A aplicação ainda não dispõe de emissão própria de proformas. Use o orçamento apenas se servir a finalidade comercial acordada.',
                    'attention' => 'Não altere o título de uma FT para a transformar em proforma. A proforma não comprova uma venda fiscalmente emitida nem pagamento.',
                ],
            ],
            'source' => ['label' => 'DP 71/25 · artigos 3.º a 8.º', 'url' => 'https://lex.ao/docs/presidente-da-republica/2025/decreto-presidencial-n-o-71-25-de-20-de-marco/'],
            'steps' => [
                [
                    'title' => 'Apresente a proposta',
                    'body' => 'Crie o orçamento com cliente, validade, artigos, preços, descontos e condições. Confira a apresentação e o total antes de o marcar como enviado.',
                ],
                [
                    'title' => 'Registe a decisão',
                    'body' => 'Acompanhe o estado e registe a aceitação ou rejeição quando o cliente responder. Depois de enviado, o orçamento fica protegido contra alterações que mudariam a proposta apresentada.',
                ],
                [
                    'title' => 'Converta com revisão',
                    'body' => 'Um orçamento aceite pode ser convertido num rascunho de factura. A conversão não emite nem regista automaticamente um pagamento. Reveja o rascunho, as datas e as condições antes de prosseguir.',
                ],
            ],
            'checklist' => [
                'Defini a validade e as condições.',
                'Registei a decisão do cliente.',
                'Sei que converter cria um rascunho.',
            ],
            'tip' => 'Na versão actual, o fluxo de conversão do orçamento cria uma factura em rascunho. Não trate a proposta ou a aceitação como prova de pagamento.',
            'question' => 'Não consigo converter.',
            'answer' => 'Confira se o orçamento está aceite e se já existe um documento convertido. Abra a ligação ao documento existente quando disponível, em vez de tentar duplicar a conversão.',
            'related' => [
                'clientes',
                'documentos',
                'cobrancas',
            ],
        ],
        [
            'id' => 'cobrancas',
            'category' => 'Vendas e recebimentos',
            'title' => 'Recebimentos e dívidas',
            'summary' => 'Associe cada recebimento ao que o cliente está efectivamente a pagar.',
            'route' => 'debts.index',
            'minutes' => 5,
            'scenarios' => [
                [
                    'code' => 'RG',
                    'title' => 'Recibo',
                    'when' => 'Recebimento posterior, total ou parcial',
                    'example' => 'Uma FT tem 100 000 Kz em aberto. O cliente transfere 40 000 Kz.',
                    'action' => 'Associe o RG à factura e liquide 40 000 Kz. Confira o saldo restante de 60 000 Kz.',
                    'attention' => 'O comprovativo bancário ajuda a conferir o recebimento; não substitui o recibo nem justifica uma nova FR.',
                ],
            ],
            'source' => ['label' => 'DP 71/25 · artigos 3.º a 8.º', 'url' => 'https://lex.ao/docs/presidente-da-republica/2025/decreto-presidencial-n-o-71-25-de-20-de-marco/'],
            'steps' => [
                [
                    'title' => 'Encontre o saldo',
                    'body' => 'Abra Dívidas e localize o cliente e a factura. Confira o valor original, o que já foi liquidado e o saldo em aberto. Use o vencimento para organizar o acompanhamento.',
                ],
                [
                    'title' => 'Prepare a liquidação',
                    'body' => 'No fluxo de recibos, seleccione os documentos a liquidar e os montantes recebidos. Confira a data, o meio de pagamento e as referências antes de emitir o recibo.',
                ],
                [
                    'title' => 'Confirme o resultado',
                    'body' => 'Volte ao documento ou à lista de dívidas e confira o saldo remanescente. Um recebimento parcial deve deixar o restante em aberto. Guarde o comprovativo bancário no processo comercial da empresa.',
                ],
            ],
            'checklist' => [
                'Identifiquei a factura em aberto.',
                'Conferi o valor e o meio de pagamento.',
                'Revi o saldo após o recebimento.',
            ],
            'tip' => 'Exemplo: se a factura tem 100 000 Kz em aberto e recebe 40 000 Kz, liquide apenas o recebido e confira os 60 000 Kz restantes.',
            'question' => 'O saldo não corresponde ao esperado.',
            'answer' => 'Verifique os recibos existentes, liquidações parciais e notas de correcção. Não crie um novo recibo apenas para ajustar visualmente o saldo; peça uma revisão se persistir a diferença.',
            'related' => [
                'documentos',
                'clientes',
                'relatorios',
            ],
        ],
        [
            'id' => 'guias',
            'category' => 'Operação diária',
            'title' => 'Guias e transporte',
            'summary' => 'Documente a circulação dos bens e mantenha a ligação à venda.',
            'route' => 'transport-documents.index',
            'minutes' => 13,
            'screenshots' => [[
                'src' => '/brand/guide-transport.jpg',
                'title' => 'Escolher a guia e o início',
                'alt' => 'Recorte do formulário Nova guia com tipo Guia de remessa, estabelecimento Loja central e início do transporte.',
                'caption' => 'Recorte do formulário de transporte com dados de demonstração. O tipo descreve o movimento; o estabelecimento identifica a unidade emissora.',
                'instruction' => '1. Escolha GR, GT, GA ou GD conforme a operação. 2. Confirme o estabelecimento. 3. Registe o início real do transporte. Nos restantes campos, confira data de emissão, sentido, destinatário, percurso e mercadorias antes de guardar.',
            ]],
            'scenarios' => [
                [
                    'code' => 'GR',
                    'title' => 'Guia de remessa',
                    'when' => 'Entrega de mercadoria identificada',
                    'example' => 'Envia dez cadeiras ao cliente e precisa de documentar a entrega.',
                    'action' => 'Registe destinatário, artigos e quantidades, locais e horário. Ao facturar, seleccione as linhas de origem.',
                    'attention' => 'A remessa não substitui a factura. Confirme o documento adequado e as condições de emissão antes da circulação.',
                ],
                [
                    'code' => 'GT',
                    'title' => 'Guia de transporte',
                    'when' => 'Deslocação de mercadorias',
                    'example' => 'Transfere caixas de mercadorias do armazém para uma loja da mesma empresa.',
                    'action' => 'Identifique origem e destino, transportador, viatura e bens efectivamente transportados.',
                    'attention' => 'Não use uma venda fictícia para documentar uma transferência interna.',
                ],
                [
                    'code' => 'GA',
                    'title' => 'Guia de activos próprios',
                    'when' => 'Movimentação de equipamentos da empresa',
                    'example' => 'Uma máquina da empresa segue para uma obra e continua a pertencer-lhe.',
                    'action' => 'Identifique o activo, a quantidade e os locais da deslocação.',
                    'attention' => 'Distinga equipamento próprio de mercadoria vendida ao cliente.',
                ],
                [
                    'code' => 'GD',
                    'title' => 'Guia ou nota de devolução',
                    'when' => 'Retorno físico de mercadorias',
                    'example' => 'O cliente devolve duas caixas ao fornecedor.',
                    'action' => 'Registe os bens devolvidos e o percurso real. Confira separadamente se é necessária uma NC.',
                    'attention' => 'GD documenta a circulação; NC trata a redução da venda. Um documento não substitui o outro.',
                ],
                [
                    'code' => 'GC',
                    'title' => 'Guia de consignação',
                    'when' => 'Bens entregues para venda em consignação',
                    'example' => 'Deixa produtos num parceiro que só os comprará ou devolverá após a venda.',
                    'action' => 'A emissão GC está bloqueada na aplicação. Mantenha a preparação em rascunho e confirme o procedimento com o responsável fiscal.',
                    'attention' => 'Não escolha outro código apenas para ultrapassar o bloqueio; a alternativa tem de corresponder à operação.',
                ],
            ],
            'steps' => [
                [
                    'title' => 'Prepare a circulação',
                    'body' => 'Crie o rascunho da guia, seleccione o tipo disponível e indique entrada ou saída de mercadorias. Preencha destinatário, origem, destino, transportador e artigos com quantidades correctas.',
                ],
                [
                    'title' => 'Confira datas e emissão',
                    'body' => 'Distinga a data de emissão do início e fim do transporte. Reveja os dados antes de emitir. A emissão depende das verificações de assinatura e configuração; algumas opções podem estar indisponíveis.',
                ],
                [
                    'title' => 'Ligue à facturação',
                    'body' => 'Ao facturar bens de uma guia, seleccione a linha de origem no documento fiscal. A referência é preservada para rastrear a entrega. Uma guia não substitui uma factura e não integra o SAF-T de facturação tipo F.',
                ],
            ],
            'checklist' => [
                'Conferi destinatário, origem e destino.',
                'Distingo a emissão do horário de transporte.',
                'Sei ligar a guia à factura.',
            ],
            'tip' => 'A emissão de guias GC encontra-se bloqueada na versão actual. Se surgir um aviso de assinatura, mantenha o rascunho e peça ao administrador para analisar a configuração.',
            'question' => 'A guia não aparece no SAF-T de facturação.',
            'answer' => 'É esperado: o exportador de facturação tipo F exclui movimentos de mercadorias. As guias continuam no módulo de transportes, com as referências às facturas.',
            'related' => [
                'documentos',
                'saft',
            ],
        ],
        [
            'id' => 'avencas',
            'category' => 'Operação diária',
            'title' => 'Organizar avenças',
            'summary' => 'Prepare facturação recorrente com revisão e autorização explícitas.',
            'route' => 'recurring.index',
            'minutes' => 3,
            'steps' => [
                [
                    'title' => 'Defina a recorrência',
                    'body' => 'Em Avenças, escolha cliente, estabelecimento, frequência, datas e linhas. Confira o tipo de documento, o preço e os impostos antes de activar o plano.',
                ],
                [
                    'title' => 'Escolha como emitir',
                    'body' => 'Por omissão, a recorrência prepara rascunhos para revisão. A emissão automática exige a autorização apresentada na aplicação, com validade limitada. Leia o alcance dessa autorização antes de a activar.',
                ],
                [
                    'title' => 'Acompanhe cada período',
                    'body' => 'Consulte os documentos gerados e o estado de comunicação. Pause uma avença quando o serviço terminar ou as condições mudarem. Parar a recorrência não elimina documentos já emitidos.',
                ],
            ],
            'checklist' => [
                'Conferi frequência, datas e cliente.',
                'Escolhi conscientemente entre rascunho e emissão automática.',
                'Sei onde rever os documentos gerados.',
            ],
            'tip' => 'Uma avença automatiza a preparação periódica. A cobrança e o recebimento devem continuar a ser acompanhados nos documentos e nas dívidas.',
            'question' => 'Não apareceu o documento esperado.',
            'answer' => 'Confira se a avença está activa, a próxima data e a autorização de emissão. Se a data já passou, consulte os avisos e contacte o administrador antes de criar duplicados.',
            'related' => [
                'documentos',
                'cobrancas',
                'agt',
            ],
        ],
        [
            'id' => 'agt',
            'category' => 'Controlo e gestão',
            'title' => 'Ligação e Monitor AGT',
            'summary' => 'Acompanhe configuração, séries e comunicação como etapas distintas.',
            'route' => 'agt.submissions.index',
            'minutes' => 3,
            'steps' => [
                [
                    'title' => 'Prepare a ligação',
                    'body' => 'O responsável autorizado configura a Ligação AGT e executa a verificação disponível. Confirme o ambiente utilizado e as séries do estabelecimento. Uma configuração de homologação não é produção.',
                ],
                [
                    'title' => 'Acompanhe a submissão',
                    'body' => 'Depois de emitir, consulte o Monitor AGT. Leia o estado e a mensagem devolvida: estar em fila ou ter um pedido recebido não equivale a validação final.',
                ],
                [
                    'title' => 'Resolva com contexto',
                    'body' => 'Se houver rejeição, leia o motivo e use o fluxo de correcção adequado. Se o serviço estiver indisponível, acompanhe as tentativas e actualize o estado quando a opção estiver disponível. Evite recriar a venda para contornar um atraso.',
                ],
            ],
            'checklist' => [
                'Confirmei o ambiente e as séries.',
                'Sei encontrar o estado e a mensagem da AGT.',
                'Sei reunir a referência antes de pedir apoio.',
            ],
            'tip' => 'Ao pedir apoio, indique número do documento, referência do pedido, hora e mensagem de erro. Nunca envie chaves privadas, palavras-passe ou códigos de recuperação.',
            'question' => 'A submissão está pendente.',
            'answer' => 'Consulte o estado mais recente e os avisos de ligação. O processamento pode ser assíncrono; uma demora não prova rejeição nem aceitação. Se persistir, contacte o apoio com as referências.',
            'related' => [
                'empresa',
                'documentos',
                'saft',
            ],
        ],
        [
            'id' => 'saft',
            'category' => 'Controlo e gestão',
            'title' => 'Exportar SAF-T de facturação',
            'summary' => 'Reveja o período e o conteúdo antes de descarregar o ficheiro XML.',
            'route' => 'saft.index',
            'minutes' => 3,
            'steps' => [
                [
                    'title' => 'Escolha o âmbito',
                    'body' => 'Abra SAF-T (AO), escolha datas dentro do mesmo exercício e, se necessário, limite ao estabelecimento. Use Rever período para actualizar o resumo.',
                ],
                [
                    'title' => 'Leia as verificações',
                    'body' => 'O ficheiro tipo F reúne facturas e notas, recibos e orçamentos enviados. As guias ficam fora deste exportador. Registos fiscais incompletos podem bloquear a exportação e exigem análise, não omissão.',
                ],
                [
                    'title' => 'Descarregue para o fim correcto',
                    'body' => 'Descarregue o XML e guarde-o com o período correspondente. A aplicação valida a estrutura localmente; isso não substitui uma aceitação da AGT nem transforma o ficheiro num SAF-T contabilístico.',
                ],
            ],
            'checklist' => [
                'Escolhi um período do mesmo exercício.',
                'Revi as verificações e o estabelecimento.',
                'Distingo exportação, submissão e aceitação.',
            ],
            'tip' => 'O comunicado AGT de 20 de Março de 2026 dispensa a submissão do SAF-T de facturação para contribuintes dos Regimes Geral e Simplificado do IVA que emitem facturas electronicamente. A aprovação de testes de homologação, por si só, não determina essa dispensa.',
            'question' => 'O descarregamento está bloqueado.',
            'answer' => 'Reveja as verificações do período. Registe os documentos indicados e solicite uma reparação controlada ao apoio. Não apague documentos emitidos para tentar obter um ficheiro válido.',
            'related' => [
                'agt',
                'relatorios',
                'guias',
            ],
        ],
        [
            'id' => 'relatorios',
            'category' => 'Controlo e gestão',
            'title' => 'Análises e exportação Excel',
            'summary' => 'Transforme os dados da operação em relatórios que pode trabalhar e partilhar.',
            'route' => 'transaction-reports.index',
            'minutes' => 3,
            'steps' => [
                [
                    'title' => 'Defina a pergunta',
                    'body' => 'Abra Análises para uma visão da actividade ou Exportar transacções para preparar um ficheiro. Escolha período, estabelecimento e filtros de acordo com o que pretende analisar.',
                ],
                [
                    'title' => 'Prepare o relatório',
                    'body' => 'Reveja a prévia e as opções de conteúdo disponíveis. Seleccione as colunas e a organização apropriadas ao destinatário. Confira se pretende documentos, linhas ou informação de liquidação.',
                ],
                [
                    'title' => 'Descarregue e personalize',
                    'body' => 'Peça a exportação. Quando o volume exigir processamento em segundo plano, acompanhe o histórico até ficar disponível. No Excel, use filtros, tabelas dinâmicas e gráficos, mantendo uma cópia dos dados originais.',
                ],
            ],
            'checklist' => [
                'Defini o período e os filtros.',
                'Conferi a prévia do relatório.',
                'Sei acompanhar uma exportação em processamento.',
            ],
            'tip' => 'O Excel é um instrumento de análise. Alterar uma célula não altera os registos da aplicação nem substitui uma correcção fiscal. Partilhe apenas os dados necessários.',
            'question' => 'O ficheiro ainda não está disponível.',
            'answer' => 'Consulte o histórico da exportação. Se estiver em processamento, aguarde a conclusão; se falhar, reveja o aviso e tente novamente quando a causa estiver resolvida.',
            'related' => [
                'cobrancas',
                'saft',
                'seguranca',
            ],
        ],
        [
            'id' => 'importar',
            'category' => 'Operação diária',
            'title' => 'Importar dados com conferência',
            'summary' => 'Use o modelo e a prévia para reduzir erros antes de confirmar uma importação.',
            'route' => 'imports.index',
            'minutes' => 3,
            'steps' => [
                [
                    'title' => 'Prepare o ficheiro',
                    'body' => 'Em Importar dados, escolha o tipo disponível e descarregue o modelo quando existir. Organize as colunas e confira códigos, NIFs, unidades e valores antes do envio.',
                ],
                [
                    'title' => 'Mapeie e valide',
                    'body' => 'Envie o ficheiro e associe as colunas aos campos da aplicação. Leia os erros por linha e corrija a origem. Faça primeiro uma importação pequena quando estiver a testar um novo formato.',
                ],
                [
                    'title' => 'Confirme o resultado',
                    'body' => 'Só confirme depois de conferir a prévia e compreender o tratamento dos registos existentes. Depois, abra Clientes ou Artigos e compare uma amostra com o ficheiro de origem.',
                ],
            ],
            'checklist' => [
                'Usei o modelo ou conferi as colunas.',
                'Corrigi os erros antes de confirmar.',
                'Conferi uma amostra dos dados importados.',
            ],
            'tip' => 'Mantenha uma cópia do ficheiro original. Importar dados é diferente de criar e comunicar documentos fiscais.',
            'question' => 'A importação rejeitou algumas linhas.',
            'answer' => 'Leia o campo e o motivo apresentados. Corrija o ficheiro e volte a validar; não substitua um NIF ou código por um valor inventado apenas para ultrapassar a validação.',
            'related' => [
                'clientes',
                'artigos',
            ],
        ],
        [
            'id' => 'seguranca',
            'category' => 'Controlo e gestão',
            'title' => 'Conta, segurança e apoio',
            'summary' => 'Proteja o acesso e saiba como pedir ajuda com a informação certa.',
            'route' => 'settings.security',
            'minutes' => 3,
            'steps' => [
                [
                    'title' => 'Proteja o acesso',
                    'body' => 'Em Conta e segurança, reveja a palavra-passe, a autenticação de dois factores e as sessões. Guarde os códigos de recuperação num local seguro e termine sessões que já não reconhece.',
                ],
                [
                    'title' => 'Confirme o contexto',
                    'body' => 'Antes de trabalhar, confirme o espaço de trabalho e a empresa. Em computadores partilhados, termine a sessão quando acabar. O progresso deste guia é guardado neste navegador, por utilizador, e pode não acompanhar outro dispositivo.',
                ],
                [
                    'title' => 'Peça ajuda',
                    'body' => 'Em Ajuda e reclamações encontra os canais de apoio e o formulário de reclamação. Descreva a acção, a referência do documento e a mensagem apresentada. Consulte também Plano e cobrança para questões da subscrição.',
                ],
            ],
            'checklist' => [
                'Activei e compreendo a protecção da conta.',
                'Guardei os códigos de recuperação em segurança.',
                'Sei onde pedir apoio e acompanhar uma reclamação.',
            ],
            'tip' => 'Nunca inclua palavras-passe, chaves privadas ou códigos de recuperação num pedido de apoio. Uma referência e uma descrição clara costumam ser suficientes para começar.',
            'question' => 'Perdi o acesso ao segundo factor.',
            'answer' => 'Use um código de recuperação guardado anteriormente. Se não o tiver, contacte o apoio para o processo de confirmação da titularidade; não partilhe credenciais com terceiros.',
            'related' => [
                'comecar',
                'agt',
            ],
        ],
    ],
];
