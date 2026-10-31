# SPEC — facturac.ao, "Tire o til" (storyline C)

GOAL:    The viewer sees the hard conversation of chasing a debt written for them and sent, ends on "certificada pela AGT", and wants to open an account.
OUTPUT:  HyperFrames, 1080 x 1920, 30 fps, 30.0 s = 900 frames. Safe band for everything read: y 168 to 1330. Below 1330 only the phone body, never text.
TIME:    music bed at 126 bpm (measured), beat 0.4775 s = 14.3 frames, bar 1.91 s = 57.3 frames; first beat 0.485 s. Cuts on bar lines.
  S1 hook          0.000 - 2.385  (5 beats)   white  "facturação" loses til + cedilla, gold dot lands -> "facturac.ao"
  S2 deadline      2.385 - 6.195  (2 bars)    ink    "atenção" -> "atenc.ao"; 1 de janeiro de 2027
  S3 proof         6.195 - 17.625 (6 bars)    white  the real app on an iPhone; dot -> share bar; 4 verbs; tap; "Enviado."
  S4 payoff        17.625 - 21.425 (2 bars)   gold   the button's dot floods the frame: shield, "Certificada pela AGT.", FE/419/AGT/2026
  S5 claim + end   21.425 - 30.000            white  the gold field shrinks to a dot = full stop of "conversa difícil."; end card
LOOK:    ground white #ffffff (S1, S3, S5), ink #171716 (S2 ground, all type), one accent gold #f9b233 (the dot, the bar segment, S4 field). No gradients, no grain, no glow.
         Display: Hanken Grotesk 700, tracking -3.5%, sizes 200 (S1 word), 230 (S2 word), 104 (S3 headlines), 110-118 (S2/S5 lines), 150 (CTA). Nothing under 52 px outside the phone.
         Medium: the real product on a real-proportion iPhone 15 Pro (screen 393 x 852 pt shown at 620 x 1344 px, 1.578 px/pt; titanium rim 12 px, black bezel 13 px, outer radius 112 px, screen radius 87 px, Dynamic Island 196 x 58 px, status bar 85 px, home indicator 211 x 8 px). Contact shadow 0 30px 60px at 18% black.
OBJECTS: the phone (above); the gold dot (diameter 0.19 em of the word it lives in, always sitting on the baseline); the tilde and cedilla as vector marks, so they can fly off.
MOTION:  enter  power3.out 0.8 s | move power2.inOut 0.5 s | exit power3.in 0.35 s | dot back.out(2.4) | wipes power4.inOut 8 frames.
         Headline changes are masked rises (translateY 110 -> 0 in 8 frames), never a fade. The phone pans between four stops in 14 frames each, power3.inOut.
SHOTS:   see SCENES in tools/build.py; each beat has one hero move; the dot is the carrier between scenes (S2 -> S3 -> S4 -> S5).
         S3 verbs: "Veja quem deve." 8.095 | "Já está escrito." 10.005 | "Reveja o texto." 11.905 | "Envie." 13.805, tap 14.29 | "Enviado." 14.765.
SOUND:   the bed from frame 0, volume 0.85, fade out 29.0-30.0. Effects only on visible causes: whoosh on wipes and pans, pop on the dot landing and on the tap, one riser (12.65 -> 17.62) into one impact on the gold flood (17.625).
BANNED:  dark void with small things floating; glow, glass, gradients; cream paper + serif + mono labels; everything centred and fading up; titles that drift after landing; the logo docked in a corner; three cards in a row; a fake notification; any screen that is not the real app; the words "em segundos", "unlock", "seamless".
         Added by this film: the AGT logo or seal (never used), a made-up customer, any number not in the real app or the certificate.
QA:      f0 (thumbnail: the word, dot hovering); 1.9 s (dot landing); 5.9 s (dot leaving S2); 7.2 s (dot merging with the bar); 9.5 s (pan to the debtor card); 12.5 s (draft text); 14.4 s (tap); 16.0 s ("Enviado." pills); 17.9 s (gold flood + shield); 21.9 s (the gold dot as full stop); 28 s (end card). Each must keep every readable thing inside y 168-1330.
