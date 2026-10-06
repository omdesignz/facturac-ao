---
format: 1080x1920
duration: 29s
message: "Tudo acaba em .ao: a factura electrónica chega a todas as empresas a 1 de janeiro de 2027, e a facturac.ao está a chegar."
arc: "Gancho (facturação sempre acabou em .ao) → atenc.ao (o prazo) → migrac.ao (traga o que tem) → comunicac.ao (acontece sozinho) → tudo acaba em .ao → Brevemente"
audience: "Donos e gestores de negócios angolanos do Regime Geral e do Regime Simplificado"
mode: autonomous
music: "upbeat confident afro-house, bright marimba and plucks, claps and shakers, building energy, joyful and driving, no vocals"
---

## Video direction

**Uma regra, repetida até virar música:** cada palavra em *-ção* perde o til e a
cedilha e ganha o ponto dourado — *atenção → atenc.ao*, *migração → migrac.ao*,
*comunicação → comunicac.ao*. O gesto é sempre o mesmo, sempre no tempo forte;
o que muda é a mensagem que cada palavra traz da página inicial. No fim a regra
acelera numa rajada e aterra em *facturação → facturac.ao*.

**Os textos são os da página inicial**, encurtados para ecrã, sem factos novos:
1 de janeiro de 2027; Regime Geral e Regime Simplificado; papel ou Excel; clientes
e artigos (só estes se importam); nada gravado sem confirmação; número, assinatura,
AGT e comprovativo sozinhos.

**Ritmo de chão:** papel claro nos quadros 1, 3, 4 e 6; o quadro 2 (o prazo) usa o
fundo de tinta, como a secção "atenc.ao" da página — é o momento sério, e a
mudança de chão marca-o. O quadro 5 é papel com a rajada.

**Paleta e tipo:** papel `#fafaf9` com a grelha dourada ténue; tinta `#171716`;
dourado `#f9b233` só no ponto e em barras finas. Palavras-herói em Century Gothic
Bold (contornos, como o logótipo); apoio em Hanken Grotesk.

---

## Frame 1 — Sempre acabou em .ao

- status: animated
- src: compositions/frames/01-sempre.html
- duration: 4.934s
- transition_in: cut
- scene: "A frase da página: Facturação sempre acabou em ão — e o ão vira .ao."
- blueprint: kinetic-type-beats (Reproduce)
- narrativeRole: Hook
- focal: o final "ão"
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: pop-soft, whoosh-up, pop-confirm

Scene 1 (0.0–1.6s): "Facturação" / "sempre acabou em" entram palavra a palavra
(`dynamic-content-sequencing`); por baixo, enorme, **"ão"**.
Scene 2 (1.6–4.0s): o til do *ã* voa (`motion-blur-streak`); o ponto dourado cai
à frente do *ao* com squash (`spring-pop-entrance`): **".ao"**. A frase lê-se
inteira: Facturação sempre acabou em .ao.

## Frame 2 — atenc.ao

- status: animated
- src: compositions/frames/02-atencao.html
- duration: 6.664s
- transition_in: cut
- scene: "Fundo de tinta: atenção vira atenc.ao, e o prazo de 1 de janeiro de 2027 aparece."
- blueprint: kinetic-type-beats (Adapt)
- narrativeRole: Problem
- focal: "1 de janeiro de 2027"
- asset_candidates: capture/assets/facturac-ao-dark.svg
- sfx: whoosh-up, drop-thud, pop-confirm, pop-soft

Scene 1 (0.0–2.2s): chão de tinta; *atenção* em branco, grande; til voa, cedilha
cai, ponto entra — **atenc.ao** (`spring-pop-entrance`).
Scene 2 (2.2–4.6s): **1 de janeiro de 2027** sobe por baixo, em duas linhas
(`kinetic-beat-slam`), com uma barra dourada a crescer.
Scene 3 (4.6–7.0s): três linhas curtas entram uma a uma: "Regime Geral." /
"Regime Simplificado." / "Factura electrónica para todos." Por fim, pequeno:
`QUEM FACTURA EM PAPEL OU EXCEL TEM DE MUDAR.`

## Frame 3 — migrac.ao

- status: animated
- src: compositions/frames/03-migracao.html
- duration: 4.76s
- transition_in: cut
- scene: "migração vira migrac.ao; clientes e artigos passam de uma pasta para a facturac.ao."
- blueprint: kinetic-type-beats (Adapt)
- narrativeRole: Product_Intro
- focal: as fichas Clientes e Artigos
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: whoosh-up, drop-thud, pop-confirm, whoosh-soft

Scene 1 (0.0–2.0s): papel; *migração* → **migrac.ao** (mesmo gesto).
Scene 2 (2.0–4.4s): **"Traga o que já tem."** Duas fichas — `Clientes` e
`Artigos` — saltam de um ficheiro *Excel* à esquerda e aterram num cartão
facturac.ao à direita (`waterfall-entry`).
Scene 3 (4.4–6.0s): por baixo: `NADA É GRAVADO SEM A SUA CONFIRMAÇÃO`, com um
visto dourado.

## Frame 4 — comunicac.ao

- status: animated
- src: compositions/frames/04-comunicacao.html
- duration: 4.76s
- transition_in: cut
- scene: "comunicação vira comunicac.ao; número, assinatura, AGT e comprovativo marcam-se sozinhos."
- blueprint: kinetic-type-beats (Adapt)
- narrativeRole: Benefits
- focal: a lista de quatro vistos
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: whoosh-up, drop-thud, pop-soft, pop-confirm

Scene 1 (0.0–2.0s): *comunicação* → **comunicac.ao**.
Scene 2 (2.0–5.0s): quatro linhas com um visto que se marca sozinho, uma por
tempo: **O número.** **A assinatura.** **A AGT.** **O comprovativo.**
(`dynamic-content-sequencing`).
Scene 3 (5.0–6.0s): por baixo: `ACONTECEM SOZINHOS, ENQUANTO ATENDE O CLIENTE SEGUINTE`.

## Frame 5 — Tudo acaba em .ao

- status: animated
- src: compositions/frames/05-tudo.html
- duration: 4.76s
- transition_in: cut
- scene: "Rajada de palavras em -ção a virarem c.ao, até facturação → facturac.ao."
- blueprint: ticker-takeover (Adapt)
- narrativeRole: Brand_Outro
- focal: facturac.ao
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: pop-soft, pop-soft, pop-soft, pop-confirm

Scene 1 (0.0–2.4s): no mesmo sítio, a palavra troca em cada colcheia —
*obrigação, organização, validação, aprovação* — e cada uma acende o seu ponto
(`vertical-spring-ticker`).
Scene 2 (2.4–4.0s): a última é *facturação* → **facturac.ao**, e por cima:
**"Tudo acaba em .ao."**

## Frame 6 — Brevemente

- status: animated
- src: compositions/frames/06-brevemente.html
- duration: 3.122s
- transition_in: cut
- scene: "Brevemente. facturac.ao. Emitida. Validada. Paga."
- blueprint: logo-assemble-lockup (Adapt)
- narrativeRole: CTA
- focal: wordmark + Brevemente
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: riser-short, pop-confirm, impact-soft

Scene 1 (0.0–1.4s): **Brevemente.** entra; o ponto dourado cai como ponto final.
Scene 2 (1.4–4.0s): wordmark facturac.ao em cascata, o ponto por último; rótulo
`FACTURAÇÃO ELECTRÓNICA PARA ANGOLA` e `Emitida● Validada● Paga●`.
