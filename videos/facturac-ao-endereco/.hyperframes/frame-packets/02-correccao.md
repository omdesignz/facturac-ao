# Frame packet: 02-correccao

## Project inputs

- Project: /Users/Oz/Apps/vap-invoice/videos/facturac-ao-endereco
- Design tokens: /Users/Oz/Apps/vap-invoice/videos/facturac-ao-endereco/frame.md
- RULES_DIR: /Users/Oz/.claude/skills/hyperframes-animation/rules

## Assigned storyboard block

## Frame 2 — A correcção

- status: outline
- src: compositions/frames/02-correccao.html
- duration: 8.731s
- transition_in: cut
- scene: "As instruções aparecem por cima e acontecem na barra, tecla a tecla, até ao endereço certo."
- blueprint: typewriter-reveal (Adapt)
- narrativeRole: Product_Intro
- focal: as legendas grandes e a barra
- asset_candidates: telemóvel e barra desenhados em HTML; type outlines (Century Gothic Bold)
- sfx: keyboard-key, whoosh-up, drop-thud, bounce-pop, success-chime
- handoff_in: "telemóvel centrado x=540 y=1080, escala 1, rotação Y 6°, opacidade 1; barra com 'facturação.ao' em vermelho, a tremer a acabar"
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

## Selected blueprint: typewriter-reveal

# typewriter-reveal — Typewriter Reveal

**intent**: A live text caret types (and edits) a line as a human would, then either collapses it to a point and pops a brand payoff, or holds it under a persistent brand mark while a sub-line types/swaps into the final CTA — making "someone is typing this" the engine of the shot.

**roles served**

- Hook (from hook-typed-line-to-reveal): Type a relatable question/statement live, then COLLAPSE it and spring-pop the brand — a logo lockup OR a product-UI moment ("here's the everyday pain, now here's us").
- Brand_Outro (from brand-outro-persistent-mark-cta-rail): Hold the hero mark dead-center/top the whole shot while a sub-line beneath it swaps or types its way into the final CTA — landing the ask once the logo is already established.

**duration**: 3.6–7s (Brand_Outro 3.6–6.0s · Hook 5.5–7s)

**shot structure** (one consolidated template; `[slots]` are product-agnostic)

- Scene 1 (0.0–~2.0s): On a solid `[bg color]` field, a blinking text-input caret `|` sits at the line start, then `[primary line]` TYPES on character-by-character with the caret trailing.
  - _Variant — Hook_: nothing else is on screen; the typed `[hook line]` owns the frame. (Sub-variant: the line types inside UI chrome — a rounded `[input/pill]` — and the whole assembly continuously TRANSLATES leftward + scales slightly so the active caret stays pinned near frame-center while earlier words scroll off and clip past the left edge — a ticker push.)
  - _Variant — Brand_Outro_: a `[logo mark]` (+ optional `[wordmark]`) is already centered/upper and STAYS fully visible for the entire shot; an entry flourish plays on the mark itself (e.g. `[checkmark/icon]` strokes into the mark, or thin concentric rings ripple outward from it), and the typed `[tagline / product label]` is the SUB-LINE beneath the mark.

- Scene 2 (~2.0–4.5s): The typed line is MODIFIED in place — the active text is edited rather than re-shot.
  - _Variant — Hook_: final word(s) BACKSPACE out and a new word RETYPES (`[word A]` → `[word B]`), or the fill/caret snaps to `[accent color]` on the final word. Holds briefly.
  - _Variant — Brand_Outro_: the sub-line is REMOVED in place — a direct hard CUT/replace (NO backspace) or a moving mask-WIPE erases it — while the mark performs a small idle move (gentle rotate / sparkle reposition); the mark never leaves frame.

- Scene 3 — resolve:
  - _Variant — Hook (collapse, ~0.3–0.7s)_: caret vanishes; the whole text/assembly COLLAPSES to a point at center (horizontal X-collapse or scale-to-0 zoom-out) and disappears, leaving a clean `[bg]`. Then (remainder) a centered `[brand element]` SPRING-POPS in:
    - _logo-lockup sub-variant_: a `[mark/icon]` pops, then slides aside as a `[wordmark]` UNMASKS / slides out from behind it; both settle into a centered lockup.
    - _product-UI sub-variant_: a `[UI control]` (e.g. button) pops; a `[cursor]` sweeps in from a corner and homes onto it; on contact a ~150ms state-FLIP — base cross-fades to `[accent color]`, icon inverts, and a soft radial GLOW blooms outward and persists.
  - _Variant — Brand_Outro (~4.5s–end)_: the final `[CTA]` resolves in the sub-line slot — TYPED in with a caret and/or shown as a `[CTA in accent-color button]` beside plain text; an optional `[accent color]` GLOW ring / halo settles around the persistent mark. Holds to end. Final frame: `[logo mark]` + (glow ring) + `[CTA]`.

**motion vocabulary**: blinking text caret; character-by-character type-on; backspace-and-retype OR in-place hard-cut/mask-wipe text swap; optional leftward ticker push (assembly translates to keep caret centered); persistent centered hero mark (never vanishes) with entry flourish (icon stroke-draw, concentric ripple rings) and small idle move (rotate / sparkle); X-collapse / scale-to-0 zoom-out of the typed line; spring-pop brand reveal; wordmark unmask-slide into lockup; cursor sweep + UI state-flip + radial glow bloom; accent glow/halo ring settle; pill/button CTA reveal; hold.

**rule mapping** (per motion verb → `rules/<id>.md`)

- blinking text caret → `context-sensitive-cursor` (caret color-switch + blink)
- character-by-character type-on → `discrete-text-sequence` (typing/typos/holds/backspace); recipe `gsap-effects` (typewriter)
- backspace-and-retype → `discrete-text-sequence`
- in-place hard-cut / replace text swap → `discrete-text-sequence` (whole-text state swaps)
- mask-wipe erase of sub-line → `techniques.md` clip-path reveal (run in reverse)
- leftward ticker push (assembly translates to keep caret centered) → `camera-cursor-tracking` (viewport follows a moving caret)
- persistent hero mark hold → no motion rule needed (static anchor; intentional — it's the absence of motion)
- entry flourish: icon stroke-draw into mark → `svg-path-draw`
- entry flourish: concentric ripple rings from mark → `cursor-click-ripple` (ripple bloom)
- small idle mark move (rotate / sparkle reposition) → `sine-wave-loop` (idle)
- X-collapse / scale-to-0 zoom-out of typed line → `scale-swap-transition` (closest fit — it morphs/collapses elements at a shared center; approximation, since a standalone collapse-and-vanish without the paired same-center brand pop isn't its exact case)
- spring-pop brand reveal → `spring-pop-entrance` (alt `physics-press-reaction`)
- collapse-text → pop-brand as a same-center morph pair → `scale-swap-transition` (morph two elements at same center)
- wordmark unmask-slide into lockup → `techniques.md` clip-path reveal (unmask); slide via `spring-pop-entrance`
- cursor sweep onto UI control + press → `cursor-click-ripple` (cursor→target press + ripple)
- UI state-flip (base/icon invert on contact) → `hacker-flip-3d`
- radial glow bloom / accent glow-halo ring settle → `asr-keyword-glow` (accent glow); ring expansion via `center-outward-expansion`
- pill/button CTA reveal → `spring-pop-entrance` (alt `scale-swap-transition`)

**camera modifier**: none required — camera is static for both roles. The Hook ticker push is an ELEMENT translate (the typed assembly slides leftward to keep the caret centered), not a camera move → modeled by `camera-cursor-tracking` rather than a true camera rule.

## Selected motion rule: kinetic-beat-slam

---
name: kinetic-beat-slam
description: Percussive kinetic typography — short phrases slam in on a steady beat with distinct per-phrase entrances, optional rhythm chrome (metronome ticks, beat bar), then a locked finale.
metadata:
  tags: text, kinetic, typography, beat, rhythm, slam, percussive, punchy
---

# Kinetic Beat Slam

Short phrases hit one at a time on a **steady beat**, each with a _different_ entrance, then stack into a locked finale — the recipe for "punchy / rhythmic" text-forward pieces (taglines, manifestos, hype intros). The difference between generic and rhythmic is (1) one shared **onset array** driving every element, (2) **distinct** entrances per phrase rather than one reused helper, and (3) optional **rhythm chrome** that visibly keeps the beat.

## How It Works

A single tempo grid — `PULSE` seconds per sub-beat, `BEATS = [t0, t1, t2, …]` on that grid — is the rhythmic spine; every phrase entrance, accent, and chrome tick reads its time from it, so the piece locks to one pulse instead of drifting hand-tuned offsets. Each phrase gets a different transform axis (scale+blur slam / side snap / rise+rotate) with short attacks (0.35–0.6s on the hit), then the stack holds with a finite low-amplitude breath.

## Recipe

```html
<!-- inside a standard scene clip (hyperframes-core) -->
<div class="kbs-stage">
  <div class="kbs-line" id="p1"><span class="verb">Notice</span> more.</div>
  <div class="kbs-line" id="p2"><span class="verb">Decide</span> faster.</div>
  <div class="kbs-line" id="p3"><span class="verb">Act</span> now.</div>
</div>
<!-- optional rhythm chrome -->
<div class="kbs-metronome" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>
```

```css
.kbs-stage {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 120px 160px; /* title-safe margin */
}
.kbs-line {
  font-family: "Archivo Black", "League Gothic", sans-serif; /* embedded display face */
  font-size: 150px;
  line-height: 0.96;
  letter-spacing: -0.03em;
  color: #f5f5f5;
}
.kbs-line .verb {
  color: #ff5b2e; /* exactly one accent hue */
}
.kbs-metronome {
  position: absolute;
  bottom: 64px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  gap: 14px;
}
.kbs-metronome i {
  width: 6px;
  height: 28px;
  background: #ff5b2e;
  opacity: 0.25;
}
```

```js
// ONE tempo grid drives everything — phrases AND the metronome read it.
const PULSE = 0.4; // seconds per sub-beat
const BEATS = [PULSE * 1, PULSE * 5, PULSE * 9]; // phrase onsets, on the grid

// Distinct entrances per phrase (NOT one reused helper).
tl.fromTo(
  "#p1",
  { scale: 1.5, filter: "blur(16px)", opacity: 0 },
  { scale: 1, filter: "blur(0px)", opacity: 1, duration: 0.5, ease: "power4.out" },
  BEATS[0],
);
tl.fromTo(
  "#p2",
  { x: -320, opacity: 0 },
  { x: 0, opacity: 1, duration: 0.45, ease: "expo.out" },
  BEATS[1],
);
tl.fromTo(
  "#p3",
  { y: 90, rotation: 6, opacity: 0 },
  { y: 0, rotation: 0, opacity: 1, duration: 0.55, ease: "circ.out" },
  BEATS[2],
);

// Rhythm chrome: each tick flashes on the SAME grid, not a magic offset.
gsap.utils.toArray(".kbs-metronome i").forEach((tick, i) => {
  tl.to(tick, { opacity: 1, duration: 0.08, yoyo: true, repeat: 1, ease: "none" }, PULSE * (i + 1));
});

// Finale hold: floor (not ceil) so the repeat never overshoots data-duration;
// max(0,…) so a short hold never yields a negative repeat (GSAP reads negative as -1 = infinite).
const holdStart = BEATS[2] + 0.7,
  cycle = 1.6,
  holdDur = SCENE_DURATION - holdStart;
tl.to(
  ".kbs-stage",
  {
    scale: 1.01,
    duration: cycle / 2,
    ease: "sine.inOut",
    yoyo: true,
    repeat: Math.max(0, Math.floor(holdDur / cycle) - 1),
  },
  holdStart,
);
```

## Variations

- **Entrance easing by attack character** — `power4.out` hard slam ⭐ default hit · `expo.out` hardest snap (side-snaps, whip-ins) · `back.out(2)` overshoot pop (accents only, not body words) · `circ.out` heavy rise with momentum. Use **at least 3 distinct easings** across the piece.
- **Rhythm chrome alternatives** — a center beat bar or a `// label` monospace tag pulsing on-beat instead of the 5-tick metronome; mark any decorative that must survive a shader transition per `../transitions/overview.md`.
- **Finale dressing** — stack + accent underline sweep ([css-marker-patterns](css-marker-patterns.md)); don't just leave the last phrase sitting.

## Values

| token             | range                | notes                                                                                        |
| ----------------- | -------------------- | -------------------------------------------------------------------------------------------- |
| BEATS spacing     | 1.2–1.8s             | <0.8s frantic, >2.5s loses the pulse; keep spacing even — it's a beat                        |
| entrance duration | 0.35–0.6s            | the hit must resolve before the next beat; exits ≤0.25s                                      |
| accent hue        | exactly 1            | the verbs; the rest mono white / near-black                                                  |
| display face      | 150px+, heavy weight | Archivo Black / League Gothic / Oswald — see `hyperframes-creative/references/typography.md` |

## Critical Constraints

- **One beat array, not scattered offsets** — every element times off `BEATS[]` / `PULSE`; this is the single biggest lever for "rhythmic".
- **Different entrance per phrase** — a reused `punchIn()` for all lines is the flat-but-competent tell. Vary the motion axis, reuse the ease _family_.
- **Finale repeat math**: `repeat: Math.max(0, Math.floor(dur / cycle) - 1)` — `Math.ceil` overshoots `data-duration` and trips the `gsap_repeat_ceil_overshoot` lint rule; a negative repeat is read by GSAP as `-1` (infinite).
- **No banned exit animations between scenes** — in a montage the _transition_ is the exit (`../transitions/overview.md`); only a final scene may fade out.
- **Display font must be embedded** or it silently falls back at render — Anton / Bebas-as-literal are NOT embedded (`Bebas Neue` aliases to League Gothic; verify in `typography.md`).

## See also

`3d-text-depth-layers` (extruded depth on the slammed words) · `css-marker-patterns` (finale underline/circle) · `sine-wave-loop` (the finale breath) · `../adapters/gsap-easing-and-stagger.md` (easing vocabulary).

## Selected motion rule: motion-blur-streak

---
name: motion-blur-streak
description: Fake directional velocity blur on a fast entrance or camera push-through — blur peaks at max speed and resolves to 0 at the settle, so the element streaks in then snaps sharp. Two paths — SVG feGaussianBlur on the motion axis, or an echo/ghost trail that collapses into the lead. Plus a per-frame-driven form for an element riding a baked track (tracked insert, shake-matched title): velocity-gated text-free silhouette ghosts (or a rotate/blur/counter-rotate filter sandwich when a true blur is the look), streak axis from the track delta.
metadata:
  tags: motion-blur, velocity, streak, entrance, fly-in, ghost, echo, svg-filter, kinetic, camera, snap, driven, tracked, baked-track
---

# Motion-Blur Streak

Real motion blur isn't available to a seeked renderer (it integrates over shutter time), so this rule **fakes** it for a fast fly-in or hard camera push-through. The whole point is the _coupling_: the blur envelope rides the **same ease and window** as the position tween, so peak blur lands exactly on peak speed and the element is razor-sharp the instant it stops. Two paths:

- **(A) Directional SVG blur** — inline `<feGaussianBlur stdDeviation="X 0">` (X on the motion axis, 0 across it), tweened via a proxy. Cleanest; a true directional smear.
- **(B) Echo / ghost trail** — 2–4 duplicates at decreasing opacity, offset backward along the motion vector, collapsing into the lead as it settles. No filter cost; a stylized "speed-line" trail.

Both paths assume a **tween window** — an element driven per frame from a baked track has none; that form is the Per-Frame-Driven Carve-In below.

**Entrances and mid-shot moves only — never a mid-composition exit.** A blurred element fleeing off-frame mid-composition reads as a glitch; a hard exit between scenes is the transition's job (`../transitions/overview.md`). Two sanctioned scope extensions: the envelope may ride the **camera wrapper** during a travel leg (Camera-Travel Carve-Out), or become a per-frame function of a baked track for a **driven element** (Per-Frame-Driven Carve-In).

## How It Works

A fast `out`-eased move front-loads velocity — fastest off the start, bleeding to zero at the settle. Map the blur/echo envelope onto that same curve: position travels from an off-frame / pushed-back start to rest over `MOVE_DUR`; in lockstep on the same window and ease the smear goes `PEAK_BLUR → 0` (A) or the ghosts collapse onto the lead (B). By the settle the element is fully crisp and dwells ≥1 s — the contrast between violent streak and still, sharp settle IS the effect. GSAP can't tween an SVG attribute directly: tween a plain `{ v }` proxy and write `setAttribute("stdDeviation", …)` in `onUpdate`, seeding it once at setup so a seek to t=0 shows the streaked start.

## Recipe

```html
<!-- inside a standard scene clip; overflow: hidden on the scene (the smear extends past rest) -->
<svg width="0" height="0" aria-hidden="true" style="position: absolute">
  <filter id="streak" x="-50%" y="-50%" width="200%" height="200%">
    <feGaussianBlur id="streak-blur" in="SourceGraphic" stdDeviation="0 0" />
  </filter>
</svg>
<div class="streak-el" id="streak-el" style="filter: url(#streak)">{phrase}</div>
<!-- Path B instead: N-1 aria-hidden .streak-ghost duplicates BEHIND the lead, no filter -->
```

```js
// Path A — proxy-tweened directional blur.
const blurNode = document.getElementById("streak-blur");
const blurProxy = { v: PEAK_BLUR };
const writeBlur = () => blurNode.setAttribute("stdDeviation", `${blurProxy.v} 0`); // X axis only
writeBlur(); // seed frame 0 — a seek to t=0 must show the streaked start, not a sharp pre-frame

tl.fromTo(
  "#streak-el",
  { x: ENTER_FROM_X, opacity: 0 },
  { x: 0, opacity: 1, duration: MOVE_DUR, ease: MOVE_EASE },
  MOVE_START,
);
tl.to(blurProxy, { v: 0, duration: MOVE_DUR, ease: MOVE_EASE, onUpdate: writeBlur }, MOVE_START);

// Path B — ghosts on the SAME window/ease; per-ghost variation by index.
gsap.utils.toArray(".streak-ghost").forEach((g) => {
  const i = Number(g.dataset.i); // 1..N-1, set in HTML
  tl.fromTo(
    g,
    { x: ENTER_FROM_X - i * ECHO_STEP_PX, opacity: GHOST_BASE_OPACITY / i },
    { x: 0, opacity: 0, duration: MOVE_DUR, ease: MOVE_EASE },
    MOVE_START,
  );
});
```

## Variations

- **Vertical streak** — swap axes: `y`, `stdDeviation="0 Y"`, vertical echo offsets.
- **Camera push-through** — `scale: SCALE_FROM → 1` with a symmetric `"B B"` envelope (depth-wise smear, not directional): the wordmark punches out of soft focus and snaps crisp at the lock.
- **Staggered grid streak-in** — each card streaks into its slot at `MOVE_START + i * CARD_STAGGER` with its own blur proxy / ghosts; sharp the instant it lands.
- **Hold-the-streak** — blur on a marginally slower curve than position (position `expo.out`, blur `power3.out`) so the last wisp resolves just after arrival. Sparingly; default is locked envelopes.

## Camera-Travel Carve-Out

The envelope is also sanctioned at **wrapper level**: on the `.world` / camera wrapper of a virtual-camera scene ([viewport-change.md](viewport-change.md), [multi-phase-camera.md](multi-phase-camera.md), [3d-camera-flight.md](3d-camera-flight.md)) during a **travel leg** — a dive, a whip sweep, a violent final push. This does **not** violate "never a mid-composition exit": the world never leaves frame — the camera travels _through_ it, and every leg ends with the world at rest, sharp, inside the frame. Each leg is an **arrival** at the next pose, so the entrance doctrine applies leg by leg. Three deltas from the element-level recipe:

- **Envelope follows the leg's ease.** An `out` leg (dive, final push) uses the base recipe unchanged. An `inOut` repositioning leg peaks mid-leg: split the envelope at the velocity peak — `0 → PEAK` on the in-half ease over the first half, `PEAK → 0` on the out-half over the second. Seed the proxy at **0** for these (the streaked state lives mid-leg, not at t=0; seed-at-`PEAK_BLUR` belongs to the entrance shape, where the first frame IS the fastest).
- **Filter placement.** 2D camera: `filter: url(#streak)` on the `.world` wrapper. 3D flight: on the **perspective stage** above the 3D context — a `filter` on a `preserve-3d` element flattens it and collapses every `translateZ`. Never per-element inside the world: one frame-wide envelope, not N desynced ones.
- **Full-frame blur is heavy** — cap `PEAK_BLUR` ~18–20 at wrapper level (vs 30 for one element); a brief whip may touch ~24. Axis rule as usual: `"X 0"` for a lateral whip/pan, `"B B"` for a dive/push.

### Whip sweep (named composition)

The heavily-blurred lateral whip that resolves into the next region — two rules on one window:

1. **Position** — [nudge-curve.md](nudge-curve.md)'s three-phase chain on the camera state, tuned burst-dominant (tail still ≥3× ramp-in in time).
2. **Blur** — `0 → PEAK` across the ramp-in, held at `PEAK` through the linear burst (constant velocity = constant smear), `PEAK → 0` across the tail.

Swap or reveal the next region's content DURING the burst — the smear masks the change; the `power4.out` tail lands it sharp. Reveal during the burst, read after the tail.

```js
tl.to(cam, { x: WHIP_X * 0.1, duration: 0.12, ease: "power3.in", onUpdate: applyCamera }, WHIP_AT);
tl.to(
  cam,
  { x: WHIP_X * 0.75, duration: 0.1, ease: "none", onUpdate: applyCamera },
  WHIP_AT + 0.12,
);
tl.to(
  cam,
  { x: WHIP_X, duration: 0.35, ease: "power4.out", onUpdate: applyCamera },
  WHIP_AT + 0.22,
);

tl.to(blurProxy, { v: PEAK_BLUR, duration: 0.12, ease: "power3.in", onUpdate: writeBlur }, WHIP_AT);
// blur holds at PEAK through the linear burst (no tween needed — value rests at PEAK)
tl.to(blurProxy, { v: 0, duration: 0.35, ease: "power4.out", onUpdate: writeBlur }, WHIP_AT + 0.22);
```

## Per-Frame-Driven Carve-In (baked tracks)

When the element is not tweened but **driven** — a tracked insert or callout riding a baked per-frame track, a title matched to camera shake — there is no move window to share. The envelope becomes a per-frame function of the track's own delta, computed in the same `onUpdate` that applies the position.

One element per transform, so no write clobbers another:

```html
<!-- .track: position + any fade, the only element either touches -->
<div class="track">
  <!-- ×2, the lead's box -->
  <div class="streak-ghost" aria-hidden="true" data-layout-allow-overlap></div>
  <!-- Path A only: rotate onto the travel axis; same box as .lead so both rotate about one center -->
  <div class="blur-wrap" style="filter: url(#streak)">
    <!-- counter-rotate only; no GSAP transform tweens on it -->
    <div class="lead">…</div>
  </div>
</div>
```

```js
// inside the frame-lookup driver (an ease:"none" proxy → frames[i]); TRACK.x / TRACK.y are baked arrays
const j = Math.max(1, i); // frame 0 borrows the frame-1 delta so a seek to t=0 has a defined state
const dx = TRACK.x[j] - TRACK.x[j - 1];
const dy = TRACK.y[j] - TRACK.y[j - 1];
const speed = Math.hypot(dx, dy); // px per frame
const angle = (Math.atan2(dy, dx) * 180) / Math.PI; // the streak axis IS the travel direction
track.style.transform = `translate(${TRACK.x[i]}px, ${TRACK.y[i]}px)`;

// Path B, driven form (the default here): text-free silhouette clones trail the lead along −delta.
const base = speed > TRAIL_GATE ? Math.min(TRAIL_MAX, TRAIL_K * speed) : 0;
ghost1.style.transform = `translate(${-dx * 0.25}px, ${-dy * 0.25}px)`;
ghost2.style.transform = `translate(${-dx * 0.5}px, ${-dy * 0.5}px)`;
ghost1.style.opacity = String(base); // a fade on .track multiplies in; never read another tween's value
ghost2.style.opacity = String(base * 0.5);

// Path A, driven form: rotate a filter wrapper onto the travel axis, blur along X only, counter-rotate the content.
const sigma = speed > TRAIL_GATE ? Math.min(SIGMA_MAX, 0.5 * speed) : 0;
blurWrap.style.transform = `rotate(${angle}deg)`;
lead.style.transform = `rotate(${-angle}deg)`;
blurNode.setAttribute("stdDeviation", `${sigma} 0`);
```

Three deltas from the tweened recipe:

- **Ghosts are text-free silhouettes** — the lead's box, border, radius and fill with **no content** (`aria-hidden="true"`, `data-layout-allow-overlap`). A 25–50%-opacity clone reads as shape; a clone carrying the text reads as a duplicate element, and content-free clones give the layout and contrast checkers nothing to flag.
- **Velocity-gated, not window-shaped** — the trail exists only above `TRAIL_GATE` px/frame and scales with speed (`min(TRAIL_MAX, TRAIL_K × speed)`): a slow drift shows nothing, a whip shows a 180°-shutter smear. Fade `.track`, never `.lead`: group opacity takes the trail with it, and the ghost opacity stays a pure function of the frame index whatever order frames are seeked in.
- **Axis from the baked delta** — `atan2(dy, dx)` per frame; the streak follows the track around corners with nothing to seed at setup.

**Which path for a driven subtree.** Path B is the default: it costs no filter raster (a full filter region re-rasterizes every frame under per-frame drive), its clones carry no text so the layout and contrast checkers see nothing new, and it degrades to nothing when the track is still. Path A in the driven form is legitimate when a true blur is the look. Both forms measured deterministic on the reference host (2026-09-08: single-card 120-frame and three-card 450-frame drives of each path, hardware-GL and software lanes, every double render bit-identical) — but a per-frame-driven filter is the first thing to re-measure on a new host: keep the double-render frame-hash comparison (`--format png-sequence` twice) in the pipeline rather than trusting one clean run. One earlier build (2026-08) observed 1-LSB `feGaussianBlur` jitter under per-frame load and shipped Path B for that reason.

## Values

| token               | range                                              | notes                                                                                           |
| ------------------- | -------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| MOVE_EASE           | `expo.out` / `power4.out` (default) / `power3.out` | `out`-family ONLY — `in`/`inOut` puts peak speed in the wrong place; position and blur share it |
| MOVE_DUR            | 0.25–0.6s                                          | over ~0.7s reads as a focus pull, not velocity                                                  |
| ENTER_FROM_X/Y      | 40–120% of the element's own dimension             | enough runway for the streak to read                                                            |
| PEAK_BLUR           | 8–30 (default 18)                                  | >30 erases the glyph at the start; ~18–20 cap at wrapper level                                  |
| SCALE_FROM          | 1.3–2.5                                            | push-through variation                                                                          |
| N (ghosts)          | 2–4                                                | >4 reads as strobe, not streak                                                                  |
| ECHO_STEP_PX        | 12–40px                                            | `N × step ≲ ENTER_FROM` so the furthest ghost starts inside the runway                          |
| GHOST_BASE_OPACITY  | 0.3–0.6                                            | opaque ghosts read as duplicate elements                                                        |
| CARD_STAGGER        | 0.05–0.12s                                         | one assembling wave, not separate arrivals                                                      |
| TRAIL_GATE          | 3–6 px/frame (default 4)                           | driven form: below it the track counts as still — no trail, no blur                             |
| TRAIL_K · TRAIL_MAX | 0.05 · 0.32                                        | driven form: ghost opacity `min(TRAIL_MAX, TRAIL_K × speed)` — hits the cap at ~6 px/frame      |
| TRAIL_OFFSETS       | 0.25 / 0.5 of the per-frame delta                  | two clones = a discrete 180° shutter; a third at 0.75 only for a long whip                      |
| SIGMA_MAX           | 8–12                                               | driven Path A: `σ = min(SIGMA_MAX, 0.5 × speed)` along the travel axis                          |

## Critical Constraints

- Blur peaks at peak speed and resolves to 0 at the settle — share the ease and window between position and envelope. A blur that lingers after the stop reads as a focus pull.
- Entrances / mid-shot arrivals only — never a mid-composition exit; wrapper-level use only per the Camera-Travel Carve-Out, per-frame-driven use only per the Per-Frame-Driven Carve-In.
- Seed `stdDeviation` at setup: at `PEAK_BLUR` for the entrance shape, at 0 for a whip / `inOut` leg.
- Generous filter region (`x="-50%" y="-50%" width="200%" height="200%"`) or the smear clips at the element's box edge.
- Directional axis: `"X 0"` horizontal, `"0 Y"` vertical, `"B B"` only for a depth/scale move — symmetric blur on a sideways move looks like defocus.
- Dwell ≥1 s sharp after the snap; a streak landing at the last beat reads as "flashed and gone".
- Heavy element on a solid field — thin type (< ~120px / 800 weight) or a busy backdrop swallows the smear.
- `overflow: hidden` on the scene — the smear / furthest ghost extends past the resting position during travel.
- Driven form: clones are text-free silhouettes (`aria-hidden`, `data-layout-allow-overlap`) inside the faded `.track`, axis from the baked delta; nothing is seeded at setup except the structural frame-0 state.

## See also

`kinetic-beat-slam` (streak as one beat's entrance) · `center-outward-expansion` (grid streak-in) · `scale-swap-transition` (same-footprint morph — not an arrival) · `nudge-curve` (the whip sweep's position half) · `3d-camera-flight` / `viewport-change` (the Camera-Travel Carve-Out's wrappers).

## Selected motion rule: spring-pop-entrance

---
name: spring-pop-entrance
description: The canonical entrance pop — an element (or staggered group) arrives by scaling 0 → 1 on a smooth long-tail settle (power3 default); bouncy overshoot is a rare, explicitly-playful exception. fromTo so it's correct at t=0 under seek.
metadata:
  tags: spring, entrance, pop, scale, power3, settle, stagger, reveal, arrival
---

# Spring-Pop Entrance

> **Smooth beats bouncy.** This entrance defaults to a smooth long-tail settle — `power3.out` (or `expo.out` for a faster front) — that decelerates cleanly into the resting size with **no overshoot**. Bouncy `back.out` is the **#1 instant turn-off** in agent-made videos and is almost never executed well; it is a rare, explicitly-playful exception (consumer / fun brand), never the default. When unsure, settle smoothly.

THE entrance primitive: an element (or staggered group) arrives by springing from nothing — `scale: 0 → 1`, optional small `y` rise — and settles without bouncing. This is **arrival**, not reaction: distinct from [press-release-spring.md](press-release-spring.md) (a click/press → release feedback chain on an element that already rests on screen). Many blueprints used to borrow that rule to fake an entrance; reach for this instead.

## How It Works

One `fromTo` carries the whole arrival: from `{ scale: 0, opacity: 0 }` (explicit, so t=0 is correct under seek) to `{ scale: 1, opacity: 1, ease: "power3.out" }`. For a **group**, the same `fromTo` runs per element at `i * STAGGER`, capped so the group reads as one arriving beat. The `scale` grow is load-bearing; the `y` rise is garnish — drop everything else and it must still read as a clean entrance. Let the ease produce the settle: never hand-key a `scale: 1.1` mid-state (it double-bounces against the curve).

## Recipe

```html
<!-- inside a standard scene clip (hyperframes-core) -->
<div class="pop-hero" id="hero">{heroLabel}</div>

<div class="pop-grid">
  <div class="pop-item">{itemA}</div>
  <div class="pop-item">{itemB}</div>
  <div class="pop-item">{itemC}</div>
</div>
```

```css
.pop-hero,
.pop-item {
  transform-origin: 50% 50%; /* in-place pop; move to the source point for the anchored variation */
  will-change: transform;
}
.pop-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: GRID_GAP;
  place-items: center;
}
```

```js
// Single hero pop — smooth long-tail settle, no overshoot.
tl.fromTo(
  "#hero",
  { scale: 0, opacity: 0 },
  { scale: 1, opacity: 1, duration: POP_DUR, ease: "power3.out" },
  ENTRY_AT,
);

// Staggered group pop — one arriving beat.
gsap.utils.toArray(".pop-item").forEach((el, i) => {
  tl.fromTo(
    el,
    { scale: 0, opacity: 0, y: Y_RISE },
    { scale: 1, opacity: 1, y: 0, duration: POP_DUR, ease: "power3.out" },
    GROUP_ENTRY_AT + i * STAGGER,
  );
});
```

## Variations

- **Calm settle** (premium / enterprise): `power3.out`, no rotation, `Y_RISE` 0–12px — a weighted, confident landing for a hero wordmark or product shot.
- **Firm settle** (everyday default): `power3.out` or `expo.out` for a punchier front, `Y_RISE` ~24px — cards, icons, callouts.
- **Exact-physics settle**: when the settle IS the shot, swap the ease for `springEase({ response: 0.4 })` (critically damped) from `../adapters/gsap-easing-and-stagger.md` → Spring Eases; take `duration` from the helper.
- **Origin-anchored pop**: a callout growing out of a specific point (marker, pointer tip) sets `transform-origin` to that point (e.g. `0% 100%`) so `scale: 0 → 1` reads as "emerging from the source", not "inflating in place".
- **Pop into a held slot**: land the pop and hold still — no idle loop baked into the entrance. If the held frame genuinely needs life, hand off to [sine-wave-loop.md](sine-wave-loop.md) for subtle jitter on a separate later tween; prefer revealing the next element on its VO cue.
- **Bouncy pop (RARE — explicitly-playful only)**: swap the ease for `back.out(OVERSHOOT)` and optionally settle a small `rotation: ROT_FROM → 0` so elements look hand-placed. Only for a deliberately playful register — never product / enterprise / serious tone:

```js
tl.fromTo(
  el,
  { scale: 0, opacity: 0, rotation: ROT_FROM },
  { scale: 1, opacity: 1, rotation: 0, duration: POP_DUR, ease: `back.out(${OVERSHOOT})` },
  GROUP_ENTRY_AT + i * STAGGER,
);
```

Even here keep `OVERSHOOT ≤ ~2` — past that it reads as cartoon wobble. Better still: the baked spring at `dampingFraction: 0.6–0.7` (same adapters doc) gives ~5–10% overshoot that reads physical where `back.out` reads cartoon.

## Values

| token      | range                                     | notes                                                            |
| ---------- | ----------------------------------------- | ---------------------------------------------------------------- |
| EASE       | `power3.out` default; `expo.out` punchier | `back.out(OVERSHOOT)` only in the playful variant                |
| POP_DUR    | 0.4–0.7s                                  | shorter = tight snap; hero must be visible by **t ≤ 0.5s**       |
| STAGGER    | 0.04–0.08s                                | `min(0.06, 0.5 / ITEM_COUNT)` — self-caps the window             |
| ITEM_COUNT | 3–9                                       | >9 makes the stagger vanish — switch to a wipe/sweep reveal      |
| Y_RISE     | 0–32px                                    | small; never large enough to read as a slide-up                  |
| ROT_FROM   | −10°–+10°                                 | playful variant only; alternate sign by index (`i % 2 ? 6 : -6`) |
| ENTRY_AT   | 0–0.4s                                    | a beat of quiet, but keep the subject landing by t ≤ 0.5s        |

## Critical Constraints

- Default ease `power3.out` (no overshoot); `back.out` only in the explicitly-playful variant, and there `OVERSHOOT ≤ ~2`.
- `ITEM_COUNT × STAGGER ≤ ~0.5s` — the group must land inside one beat.
- Entrances state the collapsed from-state in `fromTo` — never rely on a CSS-hidden start (it renders visible before the tween claims it under seek).
- `transform-origin: 50% 50%` for an in-place pop; the source point only for the anchored variation.
- This is a finite arrival — idle motion on a held element is a separate, later `sine-wave-loop` tween.

## See also

`center-outward-expansion` (pop while radiating to slots) · `press-release-spring` (the click-feedback counterpart) · `sine-wave-loop` (post-arrival jitter, sparingly).

## Selected motion rule: stat-bars-and-fills

---
name: stat-bars-and-fills
description: Data-viz primitives that pair a number with a graphic — growth bars (CSS scaleY stagger), a progress fill (bar or ring), and a partial star-rating wipe. Seek-safe, deterministic.
metadata:
  tags: data, stats, chart, bars, progress, ring, stars, rating, infographic, number
---

# Stat Bars & Fills

The graphics that give a stat **visual weight** beside its number: a small bar chart, a progress bar/ring filling to a percentage, or a star row filling to a fractional rating. Pair these with [counting-dynamic-scale.md](counting-dynamic-scale.md) (the number) for a complete stat scene.

**Layout blueprint — pick ONE and hold it across all stats:**

- **Single-focus** — one centered frame, the number is the hero, a ring or bar sits under/around it. Cleanest for a sequential reveal (stat 1 → stat 2 → stat 3 in the same frame).
- **Split-frame** — big number on the left, paired graphic on the right. Better when stats are shown together or each needs a distinct visual.

Don't mix blueprints between stats in one piece — that reads as inconsistent.

## Recipe

### 1 — Growth Bars (CSS `scaleY` stagger)

Bars grow from the baseline with a stagger; the last bar is the accent. Heights are authored in CSS (inline height per bar); GSAP only reveals `scaleY: 0 → 1` — never animate `height`.

```css
.bars {
  display: flex;
  align-items: flex-end;
  gap: 14px;
  height: 280px;
}
.bar {
  width: 48px;
  background: #3a4a64;
  transform: scaleY(0);
  transform-origin: bottom center; /* grow UP from the baseline, not from center */
}
.bar:last-child {
  background: #ffc300; /* accent the final/current bar */
}
```

```js
tl.to(".bar", { scaleY: 1, duration: 0.7, ease: "power3.out", stagger: 0.08 }, 0.3);
```

### 2 — Progress Fill

**Bar form** — `scaleX` from a left origin:

```css
.track {
  width: 520px;
  height: 16px;
  background: #1b263b;
  border-radius: 8px;
  overflow: hidden;
}
/* width:100% is REQUIRED — an absolutely-positioned fill with no width is 0px, and scaleX of 0 is
   still 0 → the bar renders invisible (automated gates may miss a zero-width scaled element). */
.fill {
  width: 100%;
  height: 100%;
  background: #ffc300;
  transform: scaleX(0);
  transform-origin: left center;
}
```

```js
const PCT = 0.92; // 92%
tl.to(".fill", { scaleX: PCT, duration: 1.0, ease: "power2.out" }, 0.3);
```

**Ring form** — measured stroke draw (mechanics in [svg-path-draw.md](svg-path-draw.md)):

```js
const ring = document.querySelector("#ring");
const LEN = ring.getTotalLength(); // measure, don't hard-code the circumference
ring.style.strokeDasharray = LEN;
ring.style.strokeDashoffset = LEN; // empty
// rotate the <circle> -90deg in CSS so the fill starts at 12 o'clock
tl.to(ring, { strokeDashoffset: LEN * (1 - 0.92), duration: 1.1, ease: "power2.out" }, 0.3);
```

### 3 — Star-Rating Fill (fractional)

A gold star row revealed left-to-right to a fractional value (e.g. 4.6 / 5) via a clip wipe over a gold layer sitting on a gray layer.

```html
<div class="stars">
  <div class="stars-gray">★★★★★</div>
  <div class="stars-gold" id="goldStars">★★★★★</div>
</div>
```

```css
.stars {
  position: relative;
  font-size: 64px;
  letter-spacing: 8px;
}
.stars-gray {
  color: #2b3548;
}
.stars-gold {
  position: absolute;
  inset: 0;
  color: #ffc300;
  width: 100%;
  clip-path: inset(0 100% 0 0);
}
```

```js
const RATING = 4.6,
  MAX = 5;
tl.to(
  "#goldStars",
  { clipPath: `inset(0 ${100 - (RATING / MAX) * 100}% 0 0)`, duration: 1.0, ease: "power2.out" },
  0.3,
);
```

## Values

| token         | range       | notes                                                                               |
| ------------- | ----------- | ----------------------------------------------------------------------------------- |
| bar count     | 4–6         | reads as "a trend" without clutter; the last bar is the current/accent value        |
| fill duration | 0.8–1.2s    | matched to the paired count-up so number and graphic land together (share the ease) |
| stagger       | 0.06–0.1s   | larger feels sluggish, 0 loses the build                                            |
| accent hue    | exactly one | bars/fill/stars all use the same accent, the rest is muted                          |

## Critical Constraints

- **`scaleY` / `scaleX` / `clipPath`, never `height`/`width` tweens** — author each bar's final height in CSS and scale from 0.
- **`transform-origin`** must be `bottom` (bars grow up) / `left` (fills grow right) — the default center origin scales from the middle and looks wrong.
- **`.fill` needs `width: 100%`** — a zero-width fill scaled by any factor is still invisible, and automated gates may miss it.
- **Measure, don't hard-code** — ring length via `getTotalLength()`; a hard-coded circumference breaks if the radius changes.
- **Match the number's timing** — the fill and the count-up peak together (same start + ease) so the stat resolves as one beat, not two; a paired counter's `onUpdate` must be O(1) (see [counting-dynamic-scale.md](counting-dynamic-scale.md)).
- **One accent hue, consistent blueprint** — see `hyperframes-creative/references/data-in-motion.md`.

## See also

`counting-dynamic-scale` (the number beside the graphic — same ease/duration) · `svg-path-draw` (progress-ring draw mechanics) · `hyperframes-creative/references/data-in-motion.md` (stat layout + visual weight).
