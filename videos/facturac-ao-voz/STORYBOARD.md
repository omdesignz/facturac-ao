---
format: 1080x1920
duration: 30s
message: "Mudar de programa de facturação não custa nada — os seus dados vêm consigo"
arc: "Medo → A promessa → As três objecções respondidas → A prova → Convite"
audience: "Donos de negócios angolanos que já pagam por um programa de facturação"
mode: autonomous
captions: disabled
music: "warm, confident, understated — light modern acoustic-electronic, steady pulse, no vocals"
---

## Video direction

**Com narração — o ritmo é a voz.** Cada revelação entra quando a voz lá
chega, não antes. Os cortes continuam secos: é visto no telemóvel, e muita gente
vai vê-lo sem som mesmo assim — por isso as legendas levam as palavras faladas.

**O ecrã não repete o que é dito.** Esta é a diferença de fundo para a versão
muda. Lá, os quadros eram o texto; aqui, o texto é a voz, e cada quadro guarda
apenas a palavra que deve ficar depois de a frase ter passado — `MEDO`,
`CONSIGO`, `EXCEL · SEM SALTOS · SAI TUDO`. Dizer e escrever a mesma frase ao
mesmo tempo imprime-a duas vezes e não acrescenta nada.

**Uma câmara só.** Deriva contínua muito ligeira (`multi-phase-camera`) por
baixo de tudo, para o filme respirar como uma peça e não como cinco cartões.
Nunca uma deriva grande no fim de um quadro — o conteúdo assenta e fica quieto.

**O selo é o objecto que atravessa o filme.** Aparece no Frame 4 a carimbar a
factura e é o mesmo objecto que assina no Frame 5, sem corte de identidade. É a
única coisa que se move entre quadros.

**Ritmo alternado:** 1 e 2 são tipografia pura e rápidos; 3 é acumulação; 4 é o
único momento de produto e o mais lento; 5 resolve. Os quadros 2 e 5 acabam
parados de propósito — a quietude lê-se contra o movimento anterior.

**Paleta:** papel `#FAF9F6` sempre como chão; tinta `#232322` para todo o texto;
o dourado `#F9B233` só como carimbo, régua e bloom — nunca texto dourado sobre
tinta. O preset não inverte.

---

## Frame 1 — Mete medo

- status: outline
- voiceover: "Já paga por um programa de facturação. E mudar? Mudar mete medo."
- src: compositions/frames/01-mete-medo.html
- duration: 5.328s
- transition_in: cut
- scene: "Diz em voz alta o medo que trava a mudança, na linguagem de quem o sente."
- blueprint: kinetic-type-beats (Reproduce)
- narrativeRole: Hook
- focal: a própria frase
- roles: —
- sfx: tick-soft (por linha)

Quem já paga por um programa não está à procura de funcionalidades — está a
calcular o risco de mexer numa coisa que, mal ou bem, funciona. O gancho não
vende nada: nomeia esse cálculo.

Scene 1 (0.0–1.1s): papel vazio; o micro-rótulo `PARA QUEM JÁ FACTURA` assenta
em cima, alinhado à esquerda, em mono — per-word staggered reveal
(`dynamic-content-sequencing`). Centrado, texto ocupa ~15% do quadro.
Scene 2 (1.1–3.0s): a primeira metade da frase — "Mudar de programa de
facturação" — entra palavra a palavra, tinta, corpo grande, alinhada à esquerda
no terço central (`dynamic-content-sequencing`). ~55% do quadro.
Scene 3 (3.0–4.4s): o veredicto "mete medo." cai em corte seco por baixo, mais
pesado que a linha de cima — kinetic beat-slam (`kinetic-beat-slam`). A
hierarquia faz-se por peso, não por cor.
Scene 4 (4.4–5.5s): tudo parado, lê-se. Só a deriva de câmara continua
(`multi-phase-camera`). Nada entra.

## Frame 2 — Não devia

- status: outline
- voiceover: "Não devia. Os seus dados vêm consigo."
- src: compositions/frames/02-nao-devia.html
- duration: 3.660s
- transition_in: cut
- scene: "Responde ao medo e entrega a promessa central: os dados vêm consigo."
- blueprint: kinetic-type-beats (Reproduce)
- narrativeRole: Product_Intro
- focal: "Os seus dados vêm consigo."
- roles: —
- sfx: tick-soft, swell-short

A promessa aterra aqui, no segundo tempo — antes de qualquer prova.

Scene 1 (0.0–1.0s): "Não devia." sozinho no centro, entrada em corte seco
(`discrete-text-sequence`), corpo grande, tinta. Centrado, ~35% do quadro. O
quadro está deliberadamente vazio à volta.
Scene 2 (1.0–2.4s): "Não devia." encolhe e sobe para o terço superior enquanto a
mensagem — "Os seus dados vêm consigo." — chega por baixo, palavra a palavra
(`scale-swap-transition` para a troca, `dynamic-content-sequencing` para a
chegada). Passa a hierarquia 3:1 a favor da linha nova.
Scene 3 (2.4–3.4s): uma régua dourada desenha-se da esquerda para a direita por
baixo da mensagem (`svg-path-draw`) — o dourado entra pela primeira vez, e entra
como sublinhado, não como texto.
Scene 4 (3.4–5.0s): parado. A régua fica. Lê-se.

## Frame 3 — As três objecções

- status: outline
- voiceover: "Clientes e artigos entram do Excel. A numeração continua certa, sem saltos. E leva tudo consigo quando quiser."
- src: compositions/frames/03-objeccoes.html
- duration: 8.242s
- transition_in: cut
- scene: "As três coisas que realmente travam uma mudança, respondidas uma a uma."
- blueprint: grid-card-assemble (Adapt)
- narrativeRole: Benefits
- focal: a terceira linha (é a que responde ao medo de ficar preso outra vez)
- roles: —
- sfx: tick-soft (por linha)

Adapt: mantém-se a cascata escalonada do blueprint e o facto de as três ficarem
no ecrã ao mesmo tempo no fim; troca-se a grelha de cartões por **linhas de
lista separadas por réguas**, porque o preset não tem cartões nem sombras — a
sua gramática é papel, tinta e régua.

Ninguém fica por gostar do programa antigo. Fica por três receios concretos.

Scene 1 (0.0–1.4s): o micro-rótulo `O QUE TRAVA` em cima; por baixo, a primeira
régua desenha-se de ponta a ponta (`svg-path-draw`). Nada mais no quadro.
Scene 2 (1.4–3.4s): linha 1 — a objecção em tinta clara e pequena ("Perco a
minha lista.") e, por baixo, a resposta em tinta cheia e grande ("Clientes e
artigos importam-se do Excel.") — per-word staggered reveal
(`dynamic-content-sequencing`). Lista de largura total, terço superior.
Scene 3 (3.0–5.0s): régua 2 desenha-se e a linha 2 chega da mesma forma ("Parto
a numeração." → "Cada série continua certa — sem saltos nem repetições.").
Scene 4 (5.0–6.3s): régua 3 e linha 3 ("Fico preso outra vez." → "Sai tudo num
arquivo aberto, quando quiser."). As três estão agora no ecrã.
Scene 5 (6.3–8.0s): as três lêem-se juntas, paradas; um bloom dourado muito
suave assenta atrás da terceira e fica (`stat-bars-and-fills` para o
preenchimento do bloom). É a linha que responde ao medo de fundo.

## Frame 4 — A parte chata

- status: outline
- voiceover: "A parte chata faz-se sozinha: o número, a assinatura, a comunicação à AGT."
- src: compositions/frames/04-parte-chata.html
- duration: 6.565s
- transition_in: cut
- scene: "A prova: a factura recebe número, assinatura e QR, e o selo desce."
- blueprint: device-surface-showcase (Adapt)
- narrativeRole: Key_Feature
- asset_candidates: assets/logo-8574e033.svg — o selo da marca, capturado do site (anel perfurado + visto em currentColor)
- focal: assets/logo-8574e033.svg
- roles: selo = cutout (entra por cima da factura) · factura = supporting
- sfx: stamp-thud (na descida do selo), chime-confirm (no rótulo "Comunicada")
- handoff_out: "selo da marca — centro em x:72.2% y:64.6% (780,1241 no quadro 1080×1920), escala 1.0, opacidade 0.96, rotação 0°, imóvel no corte"

Adapt: mantém-se o "surface held as hero enquanto o seu estado muda" do
blueprint; o hero não é um mockup de dispositivo mas **a própria factura em
papel** — é o objecto que o cliente conhece, e a moldura de telemóvel só
acrescentaria cromo que o preset proíbe.

O único momento de produto do filme. Não se afirma certificação: diz-se o que o
programa faz.

Scene 1 (0.0–1.0s): a linha "E a parte chata faz-se sozinha." em cima, palavra a
palavra (`dynamic-content-sequencing`). Por baixo, a folha da factura sobe e
assenta (`multi-phase-camera` para a subida). Centrado, folha ~62% do quadro,
3 camadas de profundidade (papel → folha → selo).
Scene 2 (1.0–2.6s): as três linhas de artigo entram escalonadas na folha e o
total assenta por baixo da régua (`dynamic-content-sequencing`). Mono para os
valores — na aplicação, mono quer dizer "isto é um facto fiscal".
Scene 3 (2.6–4.0s): o bloco fiscal — nº de validação, assinatura, QR — chega em
bloco sobre um fundo dourado suave. O QR aparece em passos, não em fade
(`stat-bars-and-fills`).
Scene 4 (4.0–5.2s): **o momento de assinatura da marca** — o selo desce sobre o
canto inferior direito da folha, grande e rodado, e endireita-se ao assentar; só
depois o visto se desenha a si próprio (`svg-path-draw`). A ordem importa: um
carimbo bate primeiro e só depois se lê.
Scene 5 (5.2–7.0s): o rótulo "Comunicada à AGT" assenta por baixo da folha e
tudo fica quieto. O selo não se mexe mais — vai atravessar o corte.

## Frame 5 — Em breve

- status: outline
- voiceover: "facturac ponto A O. Em breve."
- src: compositions/frames/05-em-breve.html
- duration: 6.205s
- transition_in: cut
- scene: "Selo, marca, e o convite: experimentar não custa nada."
- blueprint: logo-assemble-lockup (Reproduce)
- narrativeRole: CTA
- asset_candidates: assets/logo-8574e033.svg — o mesmo selo, agora sozinho como assinatura
- focal: assets/logo-8574e033.svg
- roles: selo = cutout
- sfx: chime-resolve
- handoff_in: "selo da marca — entra exactamente onde o Frame 4 o deixou (centro 780,1241, escala 1.0, opacidade 0.96, rotação 0°) e viaja para o centro 540,653 enquanto cresce para 1.35"

O selo que acabou de carimbar a factura é o mesmo que assina o filme.

Scene 1 (0.0–1.2s): a folha já não está; o selo viaja da posição do quadro
anterior para o centro-alto e cresce (`scale-swap-transition`). Um só objecto
atravessa o corte — é isto que impede o fim de parecer outro vídeo.
Scene 2 (1.2–2.2s): o wordmark `facturac.ao` assenta por baixo do selo, palavra
a palavra, com `.ao` em dourado (`dynamic-content-sequencing`). Centrado, ~45%
do quadro.
Scene 3 (2.2–3.2s): `EM BREVE` em mono espaçado por baixo, e a régua dourada
desenha-se de ponta a ponta no rodapé (`svg-path-draw`).
Scene 4 (3.2–4.5s): o convite — "Experimente sem cartão" — assenta em tinta
cheia e o quadro fica completamente parado. Sem preço, sem data: é o que é
verdade hoje.
