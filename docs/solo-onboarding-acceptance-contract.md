# Fase finita: preparação guiada da empresa

Registado antes da implementação em 2026-10-13. Autorização: continuar a nova fase de simplificação. Paragem: implementação e verificação locais; nenhuma fase seguinte implícita.

## Aceitação

1. Empresa: recolher identidade, NIF, CAE, sede e regime fiscal; não pré-escolher um regime para novas empresas; exigir confirmação explícita do regime no servidor. Não adivinhar o enquadramento fiscal.
2. Separar produtor e contribuinte. Homologação, credenciais API e chave de software pertencem ao produtor. A chave do contribuinte é emitida pela AGT. Não pedir ao cliente que homologue novamente o software nem que forneça a palavra-passe do Portal do Contribuinte.
3. Associação guiada: confirmação pelo proprietário/administrador, com MFA e confirmação recente da palavra-passe, de uma configuração previamente provisionada e vinculada no servidor ao workspace, empresa, NIF e ambiente de testes. Não aceitar referências de chaves, credenciais ou ambiente escolhidos pelo navegador. Sem configuração vinculada, indicar apoio técnico necessário. Não criar upload informal de segredos. Provisionamento real permanece fora desta autorização.
4. Mostrar preparação local, verificação AGT e série FT utilizável como estados distintos. Séries têm de pertencer à empresa/ligação/ambiente, estar abertas/em uso, ser normais/FESF, ter saldo e ano/estabelecimento elegíveis. Uma ligação verificada sem série não significa pronta para factura.
5. Mostrar próximos passos, explicação da consulta sem emissão, repetição segura da associação e da verificação, e bloqueio explícito de produção. Disponibilizar acesso ao rascunho inicial quando as condições locais estão reunidas; emissão continua separada.
6. Validar autorização, isolamento entre empresas, confirmação fiscal, segredos ausentes das respostas/erros, idempotência, estados de falha e séries inelegíveis com Pest e fakes. Verificar TypeScript, lint, build e UI com dados sintéticos.
7. Preservar trabalho sujo, guia em curso e alteração NC/ND. Consultar sessões suportadas antes de edições; guardar baseline e verificar conflitos. Sem submissões AGT reais, facturas reais, activação, alteração de contas/credenciais reais, migrações da BD da aplicação, commit, push ou deploy.

## Fontes e limites

Contrato AGT 2: https://quiosqueagt.minfin.gov.ao/doc-agt/doc-agt/2/index.html e https://quiosqueagt.minfin.gov.ao/doc-agt/doc-agt/2/gestao.html, consultados em 2026-10-13. A autenticação API do produtor foi confirmada também em https://quiosqueagt.minfin.gov.ao/doc-agt/doc-agt/2/api.html. As chaves do contribuinte são disponibilizadas pela AGT; a chave privada do produtor permanece no seu ambiente. A recepção de um pedido não é validação fiscal. O resultado local `Certificado` dos sete cenários não é prova independente de produção pronta.

A implementação conserva o ambiente de testes já autorizado; não adiciona selecção de produção. Associação assistida pressupõe custódia e verificação de identidade por um operador autorizado antes do provisionamento. Não se promete OAuth, importação automática do Portal nem validação do regime pela AGT. Qualquer futura entrega de chaves reais exige um procedimento de custódia aprovado separadamente.

## Checkpoint implementado e verificado

- 192 testes Pest, 1 141 asserções; incluem associação, confirmação fiscal, isolamento, repetição, MFA/permissões, séries e regressões NC/ND, guia, navegação e banco. SQLite isolado, PHP Herd 8.4.25, respostas AGT falsas.
- 4 testes frontend; TypeScript, ESLint, build e PHPStan dos ficheiros afectados passaram. Pint aplicado apenas ao âmbito alterado.
- UI compilada com fixtures sintéticas: empresa, associação preparada, preparação em falta e série pronta, a 1440/375 px. Sem erros JavaScript nem transbordo horizontal; mudar o regime desmarca a confirmação. Não é validação da integração real.
- Evidência estruturada: `output/solo-onboarding-verification.json`; capturas `output/playwright/solo-{company,prepared,blocked,ready}-{1440,375}.png`.
- Comparação do diff com baseline: nenhum ficheiro tracked fora desta fase alterado. Ficheiros do guia e NC/ND preservados. Nenhuma migração nova ou executada na BD da aplicação; nenhuma submissão real, alteração de credenciais reais, activação, commit ou deploy.

### Preparação operacional ainda necessária

`AGT_PREPARED_ASSOCIATIONS` é uma lista JSON injectada exclusivamente pelo gestor seguro de segredos da instalação; o valor por defeito é vazio. Cada entrada vincula `workspace_public_id`, `legal_entity_public_id`, `tax_identification_number` e `environment=homologation`; contém `basic_auth_username`, `basic_auth_password`, `product_id`, `product_version`, `software_validation_number`, `establishment_number`, `software_key_reference`, `taxpayer_key_reference` e os dois fingerprints SHA-256 `software_key_fingerprint`/`taxpayer_key_fingerprint`. Nunca guardar entradas reais no repositório, browser ou pedidos de apoio. A preparação requer confirmação humana da identidade, origem da chave e custódia antes da associação; o endpoint não descobre nem transfere chaves do Portal.

O endpoint de manutenção antigo fica fechado por defeito (`AGT_MANUAL_SETUP_ENABLED=false`); activá-lo é uma decisão operacional separada, não um passo para clientes. As referências das chaves são ocultadas no percurso normal. Uma associação existente diferente é recusada; rotação/substituição fica fora deste percurso. A ficha fiscal fica protegida logo após associação, evitando mudar o NIF e conservar a chave anterior; banco continua editável em separado.

Este checkpoint termina a fase. Para produção faltam provisionamento autorizado no ambiente correcto, confirmação de activação/aceitação pela AGT, custódia real e séries de produção. Não repetir a homologação do produtor por cliente. As migrações pendentes anteriores, incluindo NC/ND, continuam a exigir execução autorizada separadamente.
