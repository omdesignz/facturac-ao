---
format: 1080x1920
duration: 24s
message: "O endereço da sua facturação está a chegar: facturac.ao."
arc: "Gancho (o erro de todos) → A correcção em três gestos → O endereço abre → Emitida. Validada. Paga. → Brevemente"
audience: "Donos e gestores de negócios angolanos que facturam"
mode: autonomous
music: "bright upbeat electronic pop, playful plucks and claps, bouncy bass, optimistic tech launch energy, no vocals"
---

## Video direction

**Uma pequena história dentro de um telemóvel.** O telemóvel está sempre no
centro, grande, ligeiramente inclinado em 3D (≈6°), a flutuar sobre o papel. Tudo
o que acontece, acontece na barra de endereço e no ecrã — o espectador
reconhece-se no erro do primeiro gesto.

**As instruções são legendas grandes, por cima do telemóvel**, em Century Gothic
Bold (contornos), uma frase de cada vez, no tempo da música: "Tire o til." /
"Tire a cedilha." / "Ponha um ponto." / "É só isso." Cada uma acontece na barra
logo a seguir, tecla a tecla — causa e efeito.

**O cursor de texto é o actor.** Pisca, anda, apaga, escreve. Sons de tecla
reais. O ponto dourado é o único dourado e entra na barra com um "pop".

**Paleta:** creme `#fafaf9`; tinta `#171716`; cinza `#575756` para texto
secundário; dourado `#f9b233` só no ponto, na barra de progresso e no "Paga";
vermelho de erro discreto (`#dc2626`) só no gesto falhado.

**Câmara:** o telemóvel deriva lentamente (`multi-phase-camera`); dois
"push-in" para a barra durante a correcção (`coordinate-target-zoom`) e um
recuo para revelar o ecrã inteiro quando a página abre.

---

## Frame 1 — O endereço que todos escreveriam

- status: animated
- src: compositions/frames/01-endereco.html
- duration: 4.284s
- transition_in: cut
- scene: "Alguém escreve facturação na barra do telemóvel, como quem procura; o navegador recusa."
- blueprint: typewriter-reveal (Adapt)
- narrativeRole: Hook
- focal: a barra de endereço
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: keyboard-typing, error-buzz
- handoff_out: "telemóvel centrado x=540 y=1080, escala 1, rotação Y 6°, opacidade 1; barra com 'facturação' em vermelho, câmara aproximada (escala 1.6, barra centrada em y=820)"

Scene 1 (0.0–1.3s): papel; o telemóvel sobe do fundo e assenta
(`spring-pop-entrance`); por cima, a pergunta **"Onde fica a sua facturação?"**;
a câmara aproxima-se da barra (`coordinate-target-zoom`).
Scene 2 (1.3–3.7s): o cursor escreve *facturação* letra a letra, com sons de
tecla (`discrete-text-sequence`).
Scene 3 (3.7–4.3s): "Enter" — a barra treme (`physics-press-reaction`), fica com
contorno vermelho, aviso `Endereço não encontrado` desce por baixo; *ç* e *ã*
ficam sublinhados a vermelho, a apontar o problema.

## Frame 2 — A correcção

- status: animated
- src: compositions/frames/02-correccao.html
- duration: 8.731s
- transition_in: cut
- scene: "As instruções aparecem por cima e acontecem na barra, tecla a tecla, até ao endereço certo."
- blueprint: typewriter-reveal (Adapt)
- narrativeRole: Product_Intro
- focal: as legendas grandes e a barra
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: keyboard-key, whoosh-up, drop-thud, bounce-pop, success-chime
- handoff_in: "telemóvel centrado x=540 y=1080, escala 1, rotação Y 6°, opacidade 1; barra com 'facturação' em vermelho, câmara aproximada (escala 1.6, barra centrada em y=820)"
- handoff_out: "telemóvel centrado x=540 y=1080, escala 1, rotação Y 6°, opacidade 1; barra 'facturac.ao' válida, barra de progresso dourada a 100%"

Scene 1 (0.0–2.4s): o aviso sobe e desaparece. Legenda **"Tire o til."**
(`kinetic-beat-slam`). O cursor salta para o *ã*; o til descola da barra,
cresce e voa para fora do telemóvel (`motion-blur-streak`); fica *a*.
Scene 2 (2.4–4.8s): legenda **"Tire a cedilha."** O cursor salta para o *ç*;
a cedilha cai do telemóvel e sai por baixo; fica *c*.
Scene 3 (4.8–7.4s): legenda **"Ponha um ponto."** O cursor pára entre *c* e
*ao*, e o ponto dourado salta para dentro da barra com um "pop"
(`spring-pop-entrance`). A barra lê *facturac.ao*; o contorno vermelho passa
a tinta, aparece o cadeado.
Scene 4 (7.4–10.0s): legenda **"É só isso."** — "Enter". Barra de progresso
dourada varre a barra de endereço (`stat-bars-and-fills`); som de confirmação.

## Frame 3 — A página abre

- status: animated
- src: compositions/frames/03-pagina.html
- duration: 6.549s
- transition_in: cut
- scene: "O ecrã do telemóvel abre a página: wordmark e uma factura que passa por Emitida, Validada e Paga."
- blueprint: device-surface-showcase (Adapt)
- narrativeRole: Benefits
- focal: o cartão da factura e o seu estado
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: whoosh-soft, pop-soft, pop-soft, pop-confirm
- handoff_in: "telemóvel centrado x=540 y=1080, escala 1, rotação Y 6°, opacidade 1; barra 'facturac.ao' válida, barra de progresso dourada a 100%"

Scene 1 (0.0–1.6s): o ecrã enche-se: o wordmark facturac.ao monta-se no topo
(`waterfall-entry`) e um cartão de factura sobe — "Factura · Kwanza Mercantil"
(`spring-pop-entrance`).
Scene 2 (1.6–5.4s): a pastilha de estado do cartão muda, uma por tempo:
**Emitida** → **Validada** → **Paga** (`scale-swap-transition`); por cima do
telemóvel, as três palavras acumulam-se em grande, cada uma fechada pelo ponto
dourado.
Scene 3 (5.4–7.5s): "Paga" ganha o dourado e um pequeno brilho
(`ambient-glow-bloom`). Tudo pára um instante.

## Frame 4 — Brevemente

- status: animated
- src: compositions/frames/04-brevemente.html
- duration: 4.436s
- transition_in: cut
- scene: "O telemóvel afasta-se; Brevemente e o wordmark assinam."
- blueprint: logo-assemble-lockup (Adapt)
- narrativeRole: CTA
- focal: wordmark + "Brevemente"
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: riser-short, impact-soft

Scene 1 (0.0–1.6s): o telemóvel desce e sai de quadro; **"Brevemente"** sobe
para o centro em Century Gothic Bold (`spring-pop-entrance`).
Scene 2 (1.6–5.5s): o wordmark facturac.ao monta-se por baixo, o ponto entra
por último a saltar (`waterfall-entry`, `spring-pop-entrance`); rótulo
`FACTURAÇÃO ELECTRÓNICA PARA ANGOLA`. Fica parado, lê-se.
