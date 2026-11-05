# O Ponto (launch cut): spec

Storyline A, "Um telefone, um dia" (see STORYLINES.md), applied to O Ponto. This is
the pilot: the look, the phone and the pipeline here carry to the other three films.

```text
GOAL:    the viewer sees that the name is the instruction (tire o til, tire a cedilha,
         ponha um ponto) and that the app itself performs it, then lands on the app
         alive with a real company's figures; they leave knowing facturac.ao is out.

OUTPUT:  HyperFrames, 1080 x 1920, 30 fps; 1:1 cut later from the same build.

TIME:    15.0 s x 30 = 450 frames. Music 114.8 BPM, a beat every 0.522 s (15.7
         frames); beats at 0.535, 1.055, 1.575, 2.105, 2.625, 3.145, 3.665, ...

LOOK:    ground #FFFFFF, flat, no texture (the app's own white canvas); ink #171716;
         one accent, gold #F9B233, only on the dot, the til and cedilha as they
         leave, the tap ring and the call to action.
         Display: Century Gothic Bold as outlines (tools/type_outlines.py), the word
         at 150 px, -5 units tracking (the brand's); body: Hanken Grotesk 600, 52 px
         captions, ink #575756.
         Medium: Apple's iPhone 17 Black frame (assets/apple, licensed for mock-ups of
         iOS-only software; not in git) around real simulator footage, pixel for pixel
         (screen opening 1206 x 2622 at 72, 69 in the 1350 x 2760 frame).
         Light: the phone casts two shadows on the ground: 0 46px 70px rgba(0,0,0,0.20)
         and 0 10px 18px rgba(0,0,0,0.14). No grain, no vignette.

OBJECTS: the phone (Apple's frame, its own Dynamic Island, the real home screen in
         Portuguese with Luanda on the Maps widget and the app in the dock); the word
         "facturação" whose til and cedilha are separate outline parts; the gold dot,
         15 px radius at phone scale 0.5; the app's real splash and Hoje (Distribuidora
         Ngola demo company: 26 906 277 Kz owed, Mavinga & Filhos 3 644 259 Kz late).

MOTION:  enters: power3.out 0.34 s; exits: power2.in 0.24 s; camera: power3.inOut
         0.5 s; the dot: arcs on power2.out up / power2.in down, squash 1.45 x 0.62 on
         landing then back.out(3). Every landing is on a beat.

SHOTS:
  1  0.000-3.665  Word over phone. Phone at scale 0.50, top 600, centred; the word at
                  baseline 330. 0.535 til lifts and flies off (whoosh); 1.575 cedilha
                  drops (thud); 2.625 the dot bounces in and the word closes to
                  facturac.ao (pop). Captions one at a time under the word: "Tire o
                  til." / "Tire a cedilha." / "Ponha um ponto." (1.04 s each).
                  3.145 the dot hops out and lands on the dock icon at 3.665 (tap).
  2  3.665-7.065  The tap opens the real app: the footage starts at the press. The
                  word lifts away, the phone rises to centre at scale 0.62 while the
                  gold launch fills the screen; 4.6-5.0 push to 1.45x on the screen so
                  the app's own "facturação -> facturac.ao" reads large; hold.
  3  7.065-10.20  Pull back as Hoje crossfades in. Phone settles at 0.56, title "A sua
                  empresa no bolso." (the app's own line) above it at 7.5; 8.6 push to
                  1.4x on "Por receber 26 906 277 Kz"; hold.
  4  10.20-15.00  The phone drops out of frame; 10.965 "Disponível." lands with the dot
                  as its full stop; the facturac.ao wordmark; "Abra a sua conta
                  grátis" as the app's gold button; hold 2.5 s; music fades from 14.0.

BANNED:  the defaults in the HyperFrames spec list; product UI tilted in 3D; a drawn
         or invented screen; text moving while read; any App Store claim (the app is
         not published yet); the logo docked in a corner.

QA:      0.0 (thumbnail: word + phone), 1.2 (til gone), 2.8 (facturac.ao closed),
         3.7 (tap), 5.6 (splash pushed in), 8.0 (Hoje, title), 9.4 (number pushed in),
         12.5 (end card complete). Each must show the real screen sharp, nothing cut
         off by the frame, and type at least 28 px.
```

## Voiced cuts (2026-11-05)

The four films were recreated with the redesigned app, and the voice now leads: each is
one continuous ElevenLabs read (Clara, eleven_v4, assets/vo), and tools/build_films.py
places every cut, camera move and landing on a word of that read. The shot list above is
the pilot's history; the timings in build_films.py are what is current. Type is Inter,
as in the app; only the word that becomes the name stays in Century Gothic outlines. The
gold button is flat and square-cornered like the app's, the camera does not bounce, and
a highlight dims the screen around what the voice is naming.

Takes in use: o-ponto ponto-a, tudo-em-ao tudo-c, mudar mudar-a, endereco endereco-a. To
swap one, re-read its word timings and adjust the `c(...)` cues.

## The older teasers, recreated (2026-11-05)

"Quem me deve", the four "Por ofício" cuts and "O ponto" (vertical `status`, landscape
`teaser`, one read and the same cuts) replace the drawn phones of `../facturac-ao-quem-deve`,
`../facturac-ao-oficios`, `../facturac-ao-status` and `../facturac-ao-teaser`, which stay as
they were. tools/build_teasers.py writes them under films/, reusing build_films.py; captures
are in assets/capture-teasers. The app has no till, so a trade's film shows its catalogue and
the gold plus, not the old charge screen. Each trade's shelf comes from tools/demo/trade.php;
tools/demo/agt_replay.php replays the AGT's answer for a document issued in the simulator.
Issuing too quickly hits the app's real "demasiadas tentativas" limit: leave a minute.

Takes in use: quem-deve quemdeve-a, oficina oficina-a, farmacia farmacia-b ("Aviu" in take a), restaurante
restaurante-a, loja loja-a, status and teaser status-a. Alternates for auditioning the name
are the other files of each pair in assets/vo. Build with `/usr/bin/python3 tools/build_teasers.py` (it has fontTools).

## Rebuilding

1. Apple's frame: mount Bezel-iPhone-17.dmg from developer.apple.com/design/resources (its
   licence allows mock-ups of iOS-only software, so never use it around the web app) and
   copy `PNG/iPhone 17/iPhone 17 - Black - Portrait.png` to `assets/apple/iphone-17-black.png`.
2. Footage, only if it must be recaptured: `tools/demo/setup.sh <scratch dir>`, serve it
   with `<scratch dir>/run.sh`, sign the simulator's app in as ana@ngola.test, record with
   `xcrun simctl io <device> recordVideo`, then `ffmpeg -i clip.mov -vf fps=60 -crf 12
   assets/capture/clip-60.mp4`. Status bar: `--batteryState discharging`, or the battery
   shows green. AGT status turns "(observação antiga)" within minutes of seeding, so capture
   a validated document straight after replaying its acceptance.
3. `python3 tools/build_films.py` (needs fontTools and Century Gothic Bold installed). From
   each `films/<film>`: `npx hyperframes render --fps 30 -q delivery --crf 12 -o renders/raw.mp4`.
4. `python3 tools/mix_voice.py <film> <take>` lays the read over the render, masters it and
   writes `renders/social/<film>-lancamento-{instagram,whatsapp}.mp4`.
