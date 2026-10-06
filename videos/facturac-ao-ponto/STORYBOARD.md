---
format: 1080x1920
duration: 17.2s
message: "facturac.ao está a chegar — e o nome já diz o que é: tire o til, tire a cedilha, ponha um ponto."
arc: "Gancho → A palavra → A lição em três gestos → A piada assenta → A assinatura → Brevemente"
audience: "Donos e gestores de negócios angolanos que facturam"
mode: autonomous
music: "upbeat playful afro-house pop, bright marimba and plucks, punchy kick, claps, joyful and confident, no vocals"
---

## Video direction

**Sem narração — a música é o maestro.** Cada gesto cai num tempo forte do
compasso; as frases entram no tempo e saem no tempo. Visto no telemóvel com e
sem som: a frase obrigatória está sempre escrita no ecrã, palavra a palavra.

**Um só objecto atravessa o filme: a palavra.** *facturação* entra no Frame 1 e é
a mesma palavra, no mesmo sítio, durante o Frame 2 inteiro — o til e a cedilha
saem dela, o ponto entra nela. Nunca é substituída por outra cópia: é editada à
frente do espectador. No fim do Frame 2 ela **é** o wordmark.

**O ponto dourado é a personagem.** Entra a saltar como uma bola, com
squash-and-stretch, ressalta nas letras com vontade própria e aterra no sítio
exacto do logótipo. Volta no Frame 3 como o ponto final de cada palavra da
assinatura e no Frame 4 dá o último salto. É o único dourado do filme.

**Paleta e chão:** papel `#fafaf9` com a grelha dourada muito ténue do produto;
tinta `#171716` para tudo o que se lê; dourado `#f9b233` só no ponto e num
"hit" de luz. Tipografia herói em Century Gothic Bold (contornos, igual ao
logótipo); frases de apoio em Hanken Grotesk.

**Câmara:** deriva lenta e contínua (`multi-phase-camera`) + pequenos "punch-in"
de 3–4% nos tempos fortes — energia sem tremer o texto.

---

## Frame 1 — A palavra

- status: animated
- src: compositions/frames/01-a-palavra.html
- duration: 3.053s
- transition_in: cut
- scene: "A palavra que todos os negócios dizem todos os dias entra letra a letra, no ritmo."
- blueprint: kinetic-type-beats (Reproduce)
- narrativeRole: Hook
- focal: a palavra "facturação"
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: whoosh-soft, typewriter-key
- handoff_out: "palavra 'facturação' centrada em x=540, linha de base em y=1010, corpo 168px, escala 1, opacidade 1, parada"

Scene 1 (0.0–1.0s): papel e grelha; o rótulo `TODOS OS DIAS, EM TODO O NEGÓCIO`
assenta acima do centro em Hanken 600 espaçado (`dynamic-content-sequencing`).
Scene 2 (1.0–2.6s): *facturação* entra letra a letra em Century Gothic Bold, cada
letra um "pop" vertical com overshoot, uma letra por colcheia
(`spring-pop-entrance`, `kinetic-beat-slam` na última). ~85% da largura.
Scene 3 (2.6–3.5s): o rótulo sai para cima; a palavra fica sozinha, parada, a
respirar com a deriva. O til e a cedilha ganham um brilho subtil — é ali que
vai acontecer alguma coisa.

## Frame 2 — A lição

- status: animated
- src: compositions/frames/02-a-licao.html
- duration: 7.837s
- transition_in: cut
- scene: "O vídeo executa a frase: o til voa, a cedilha cai, o ponto entra a saltar — facturação torna-se facturac.ao."
- blueprint: kinetic-type-beats (Adapt)
- narrativeRole: Product_Intro
- focal: a palavra a ser editada; a frase obrigatória por cima
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: whoosh-up, drop-thud, bounce-pop, pop-confirm
- handoff_in: "palavra 'facturação' centrada em x=540, linha de base em y=1010, corpo 168px, escala 1, opacidade 1, parada"
- handoff_out: "wordmark facturac.ao centrado em x=540, linha de base y=1010, corpo 168px, escala 1, opacidade 1, a encolher lentamente"

Scene 1 (0.0–2.4s): por cima da palavra, **"Tire o til."** entra palavra a
palavra (`dynamic-content-sequencing`). No tempo forte, o til de *ã* descola,
roda e voa para fora do quadro pelo canto superior direito com rasto
(`motion-blur-streak`). O *a* fica, assenta com um pequeno ressalto.
Scene 2 (2.4–4.8s): a frase troca por corte para **"Tire a cedilha."**
(`kinetic-beat-slam`). A cedilha do *ç* cai na vertical com gravidade, sai por
baixo do quadro; o *c* dá um pequeno salto de alívio.
Scene 3 (4.8–7.6s): **"Ponha um ponto."** O ponto dourado entra pela direita a
saltar sobre as letras — três ressaltos com squash-and-stretch — enquanto
*ao* desliza para a direita a abrir espaço; aterra entre *c* e *ao* com um
"pop" e uma onda de luz dourada (`spring-pop-entrance`, `ambient-glow-bloom`).
A palavra é agora exactamente o wordmark.
Scene 4 (5.7–7.8s): **"É só isso."** troca no lugar das outras; o ponto dá um
salto curto de satisfação. Por baixo, a conta construída gesto a gesto fecha:
`− til − cedilha + ponto = facturac.ao` (cada termo entrou quando o seu gesto aconteceu).

## Frame 3 — Emitida. Validada. Paga.

- status: animated
- src: compositions/frames/03-assinatura.html
- duration: 2.61s
- transition_in: cut
- scene: "A assinatura da marca, três palavras, cada uma fechada pelo ponto dourado."
- blueprint: kinetic-type-beats (Reproduce)
- narrativeRole: Benefits
- focal: as três palavras
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: pop-soft, pop-soft, pop-confirm

Scene 1 (0.0–2.6s): "Emitida●" / "Validada●" / "Paga●" empilham-se ao centro,
uma por tempo, cada palavra a entrar com `spring-pop-entrance` e o seu ponto
dourado a cair no lugar um instante depois (`kinetic-beat-slam`).
Scene 2 (2.6–3.5s): as três ficam; um rótulo pequeno por baixo:
`FACTURAÇÃO ELECTRÓNICA PARA ANGOLA`.

## Frame 4 — Brevemente

- status: animated
- src: compositions/frames/04-brevemente.html
- duration: 3.7s
- transition_in: cut
- scene: "Brevemente. O wordmark facturac.ao assina, o ponto dá o último salto."
- blueprint: logo-assemble-lockup (Adapt)
- narrativeRole: CTA
- focal: wordmark + "Brevemente"
- asset_candidates: capture/assets/facturac-ao.svg
- sfx: riser-short, impact-soft

Scene 1 (0.0–1.4s): **"Brevemente"** em Century Gothic Bold grande entra com
`spring-pop-entrance`; o ponto dourado cai de cima e torna-se o seu ponto final.
Scene 2 (1.4–4.0s): o wordmark facturac.ao monta-se por baixo — letras em
cascata (`waterfall-entry`), o ponto entra por último a saltar. Rótulo final
`FACTURAÇÃO ELECTRÓNICA PARA ANGOLA`. Fica parado, lê-se.
