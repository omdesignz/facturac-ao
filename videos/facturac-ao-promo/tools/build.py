#!/usr/bin/env python3
"""
Builds "Tire o til": the facturac.ao promo, storyline C. Every number comes from
SPEC.md; geometry that depends on the font (letter advances, baseline) was
measured once from Hanken Grotesk 700 and is baked in below, so the layout is
the same on every machine.

Run from the project root:  python3 tools/build.py
"""
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FR = ROOT / "compositions"
FR.mkdir(exist_ok=True)

INK, GOLD, WHITE, MUTED = "#171716", "#f9b233", "#ffffff", "#57534e"
LS = -0.035           # tracking, em
BASE = 0.848          # baseline offset inside a line-height:1 box, em
ADV = {"f": .348, "a": .546, "c": .522, "t": .367, "u": .564, "r": .426, "o": .572, "e": .541, "n": .564}
DOT = 0.19            # dot diameter, em
GAP = DOT + 0.15      # gap the dot opens in a word, em

# scene id, start, duration (s). Scenes overlap by 0.27 s where one wipes over the previous.
SCENES = [
    ("s1-til", 0.0, 2.655),
    ("s2-prazo", 2.385, 4.08),
    ("s3-app", 6.195, 11.43),
    ("s4-certificada", 17.625, 3.8),
    ("s5-fim", 21.425, 8.575),
]
TOTAL = 30.0
VOICE = os.environ.get("VOICE", "c")   # which ElevenLabs take to use: c is the approved one

# The phone: iPhone 15 Pro, 393 x 852 pt shown at 620 x 1344 px (1.5776 px/pt).
K = 620 / 393
SW, SH = 620, round(852 * K)
RIM, BEZEL = 12, 13
PW, PH = SW + 2 * (RIM + BEZEL), SH + 2 * (RIM + BEZEL)
PX, PY = (1080 - PW) // 2, 360
SX, SY = PX + RIM + BEZEL, PY + RIM + BEZEL       # screen origin on the frame
STATUS = 85                                         # status bar height, px
IMG_W_BEFORE = 620 * 1185 / 1179                    # the captured page is 1185 px wide before the send
IMG_S = IMG_W_BEFORE / 1185                         # px per source px (3x capture)
# pan stops (source px), see tools notes: A title/bar, B debtor card, C drafts, D the send button
STOP_A, STOP_B, STOP_C, STOP_D = 0, 1250, 3000, 5089
SEND_Y_BEFORE, SEND_Y_AFTER = 6420, 6000            # source y of the send button in each state
BAR_X, BAR_Y = 989, 875                             # centre of the gold share-bar segment, source px (measured on the 7.2 s frame)
BTN_X = 540
BTN_Y = round(SY + STATUS + (SEND_Y_BEFORE + 60 - STOP_D) * IMG_S)   # button centre on the frame
BAR_FX = round(SX + BAR_X * IMG_S)
BAR_FY = round(SY + STATUS + BAR_Y * IMG_S)
P0 = (540, 1200)                                    # where the dot waits between S2 and S3


def positions(chars, ls=LS):
    xs, x = [], 0.0
    for ch in chars:
        xs.append(x)
        x += ADV[ch] + ls
    return xs, x


def shell(scene_id, css, body, js, ground):
    return f"""<template>
  <style>
    @font-face {{ font-family: "Hanken Grotesk"; font-weight: 700; font-style: normal; font-display: block;
      src: url("assets/fonts/HankenGrotesk-700.woff2") format("woff2"); }}
    #root {{ position: absolute; inset: 0; width: 1080px; height: 1920px; overflow: hidden;
      font-family: "Hanken Grotesk", sans-serif; font-weight: 700; color: {INK}; background: {ground}; }}
    .abs {{ position: absolute; }}
    .t {{ position: absolute; white-space: pre; line-height: 1; letter-spacing: {LS}em; }}
    .mask {{ position: absolute; overflow: hidden; }}
    .mask > .t {{ left: 0; }}
{css}
  </style>
  <div id="root" data-composition-id="{scene_id}" data-width="1080" data-height="1920">
{body}
  </div>
  <script>
    (function () {{
      const tl = gsap.timeline({{ paused: true }});
{js}
      window.__timelines["{scene_id}"] = tl;
    }})();
  </script>
</template>
"""


def word_markup(prefix, chars, S, left, baseline, color, accent_idx_tilde, accent_idx_cedilla, gap_before, centered=True, dot_color=GOLD):
    xs, w0 = positions(chars)
    top = baseline - BASE * S
    letters = []
    for i, ch in enumerate(chars):
        cls = f"{prefix}g1" if i < gap_before else f"{prefix}g2"
        letters.append(f'      <span class="t {cls}" style="left:{xs[i]*S:.1f}px; top:0; font-size:{S}px; color:{color}">{ch}</span>')
    tw = S * 0.34
    tilde = (f'<svg class="abs {prefix}tilde" style="left:{(xs[accent_idx_tilde]+ADV["a"]/2)*S - tw/2:.1f}px; top:{(BASE-0.5-0.03-0.14)*S:.1f}px" width="{tw:.1f}" height="{S*0.14:.1f}" viewBox="0 0 36 14">'
             f'<path d="M3 9 C 8 1, 14 1, 18 6 S 28 11, 33 3" fill="none" stroke="{color}" stroke-width="5.2" stroke-linecap="round"/></svg>')
    cw = S * 0.09
    cedilla = (f'<svg class="abs {prefix}ced" style="left:{(xs[accent_idx_cedilla]+ADV["c"]/2)*S - cw/2 + S*0.01:.1f}px; top:{(BASE-0.01)*S:.1f}px" width="{cw:.1f}" height="{S*0.17:.1f}" viewBox="0 0 9 17">'
               f'<path d="M3 1 V 6 C 3 11, 8 11, 8 14 C 8 16, 4 16.5, 1.5 15.5" fill="none" stroke="{color}" stroke-width="2.6" stroke-linecap="round"/></svg>')
    d = DOT * S
    dot = (f'<div class="abs {prefix}dot" style="left:{xs[gap_before]*S - d/2 + (0 if centered else GAP*S/2):.1f}px; top:{(BASE-DOT)*S:.1f}px; width:{d:.1f}px; height:{d:.1f}px; border-radius:50%; background:{dot_color}"></div>')
    return f'    <div class="abs {prefix}word" style="left:{left:.1f}px; top:{top:.1f}px; width:{w0*S:.0f}px; height:{S}px">\n' + "\n".join(letters) + f"\n      {tilde}\n      {cedilla}\n      {dot}\n    </div>", xs, w0


def build():
    # ------------------------------------------------------------------ S1
    S = 200
    chars = list("facturaao")
    # "facturação" is f a c t u r a c(ç) a(ã) o : ten letters
    chars = ["f", "a", "c", "t", "u", "r", "a", "c", "a", "o"]
    xs, w0 = positions(chars)
    left = (1080 - w0 * S) / 2
    base_y = 780
    markup, xs, w0 = word_markup("a", chars, S, left, base_y, INK, 8, 7, 8, centered=True)
    g = GAP * S
    d = DOT * S
    hover = (BASE - DOT + 0.78) * S    # how far above the baseline the dot hovers at frame 0
    css = ""
    js = f"""
      tl.set(".adot", {{ y: -{hover:.1f} }}, 0);
      tl.from(".ag1, .ag2", {{ y: 0, duration: 0.001 }}, 0);
      // the til lifts off the a and leaves the frame
      tl.to(".atilde", {{ y: -{0.16*S:.1f}, rotation: -14, transformOrigin: "50% 50%", duration: 0.12, ease: "power2.out" }}, 0.40);
      tl.to(".atilde", {{ x: 560, y: -900, rotation: 380, opacity: 0, duration: 0.55, ease: "power2.in" }}, 0.52);
      // the cedilla falls out of the bottom
      tl.to(".aced", {{ y: 1250, rotation: 35, transformOrigin: "50% 20%", duration: 0.7, ease: "power2.in" }}, 0.85);
      // the word opens a gap
      tl.to(".ag1", {{ x: -{g/2:.1f}, duration: 0.32, ease: "power3.inOut" }}, 1.28);
      tl.to(".ag2", {{ x: {g/2:.1f}, duration: 0.32, ease: "power3.inOut" }}, 1.28);
      tl.to(".adot", {{ y: 0, duration: 0.3, ease: "power2.in" }}, 1.50);
      tl.to(".adot", {{ scaleX: 1.45, scaleY: 0.62, transformOrigin: "50% 100%", duration: 0.05, ease: "power2.out" }}, 1.80);
      tl.to(".adot", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "back.out(2.6)" }}, 1.85);
"""
    (FR / "s1-til.html").write_text(shell("s1-til", css, markup, js, WHITE))

    # ------------------------------------------------------------------ S2
    S2 = 230
    chars2 = ["a", "t", "e", "n", "c", "a", "o"]     # a t e n ç ã o
    xs2, w02 = positions(chars2)
    left2 = 73
    base2 = 700
    ADV["_"] = 0
    markup2, xs2, _ = word_markup("b", chars2, S2, left2, base2, WHITE, 5, 4, 5, centered=False)
    g2 = GAP * S2
    d2 = DOT * S2
    dot2_x = left2 + xs2[5] * S2 + g2 / 2        # dot centre on the frame once the gap is open
    dot2_y = base2 - d2 / 2
    hover2 = (BASE - DOT + 0.7) * S2
    body2 = f"""    <div class="abs" id="ink" style="inset:0; background:{INK}"></div>
{markup2}
    <div class="mask" style="left:73px; top:{820}px; width:960px; height:130px"><div class="t" data-layout-allow-overflow id="l1" style="top:12px; font-size:110px; color:{WHITE}">1 de janeiro de 2027</div></div>
    <div class="mask" style="left:73px; top:960px; width:900px; height:80px"><div class="t" data-layout-allow-overflow id="l2" style="top:8px; font-size:60px; color:#e7e5e4">Todas as empresas emitem</div></div>
    <div class="mask" style="left:73px; top:1040px; width:900px; height:80px"><div class="t" data-layout-allow-overflow id="l3" style="top:8px; font-size:60px; color:#e7e5e4">factura electrónica.</div></div>
    <div class="abs" id="flydot" style="left:{P0[0]-d2/2:.1f}px; top:{P0[1]-d2/2:.1f}px; width:{d2:.1f}px; height:{d2:.1f}px; border-radius:50%; background:{GOLD}; opacity:0"></div>"""
    js2 = f"""
      tl.set(".bdot", {{ y: -{hover2:.1f} }}, 0);
      tl.fromTo("#ink", {{ x: -1080 }}, {{ x: 0, duration: 0.27, ease: "power4.inOut" }}, 0);
      tl.fromTo(".bg1, .bg2", {{ y: 130, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.4, ease: "power3.out", stagger: 0.03 }}, 0.32);
      tl.to(".btilde", {{ y: -{0.16*S2:.1f}, rotation: -14, transformOrigin: "50% 50%", duration: 0.12, ease: "power2.out" }}, 1.0);
      tl.to(".btilde", {{ x: 640, y: -900, rotation: 380, opacity: 0, duration: 0.5, ease: "power2.in" }}, 1.12);
      tl.to(".bced", {{ y: 1250, rotation: 35, transformOrigin: "50% 20%", duration: 0.65, ease: "power2.in" }}, 1.3);
      tl.to(".bg2", {{ x: {g2:.1f}, duration: 0.3, ease: "power3.inOut" }}, 1.6);
      tl.to(".bdot", {{ y: 0, duration: 0.28, ease: "power2.in" }}, 1.78);
      tl.to(".bdot", {{ scaleX: 1.45, scaleY: 0.62, transformOrigin: "50% 100%", duration: 0.05, ease: "power2.out" }}, 2.06);
      tl.to(".bdot", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "back.out(2.6)" }}, 2.11);
      tl.fromTo("#l1", {{ y: 130 }}, {{ y: 0, duration: 0.45, ease: "power3.out" }}, 2.1);
      tl.fromTo("#l2", {{ y: 90 }}, {{ y: 0, duration: 0.4, ease: "power3.out" }}, 2.45);
      tl.fromTo("#l3", {{ y: 90 }}, {{ y: 0, duration: 0.4, ease: "power3.out" }}, 2.6);
      // the dot leaves the word and waits where the next scene picks it up
      tl.set("#flydot", {{ x: {dot2_x - P0[0]:.1f}, y: {dot2_y - P0[1]:.1f}, opacity: 1 }}, 3.0);
      tl.set(".bdot", {{ opacity: 0 }}, 3.0);
      tl.to("#flydot", {{ x: 0, y: 0, duration: 0.6, ease: "power2.inOut" }}, 3.0);
"""
    (FR / "s2-prazo.html").write_text(shell("s2-prazo", "", body2, js2, INK))

    # ------------------------------------------------------------------ S3
    head = lambda i, text, sz=104: f'<div class="mask" style="left:80px; top:178px; width:920px; height:140px"><div class="t h{i}" data-layout-allow-overflow style="top:16px; font-size:{sz}px; color:{INK}">{text}</div></div>'
    heads = "\n    ".join([head(1, "Veja quem deve."), head(2, "Já está escrito."), head(3, "Reveja o texto."), head(4, "Envie."), head(5, "Enviado.")])
    css3 = f"""
    #phone {{ position: absolute; left: {PX}px; top: {PY}px; width: {PW}px; height: {PH}px; border-radius: 112px; background: #c9c5bb;
      box-shadow: 0 30px 60px rgba(23,23,22,0.18); }}
    #phone .rim {{ position: absolute; inset: 3px; border-radius: 109px; background: #a9a69c; }}
    #phone .bz {{ position: absolute; inset: {RIM}px; border-radius: 100px; background: #0b0b0c; }}
    #screen {{ position: absolute; left: {RIM+BEZEL}px; top: {RIM+BEZEL}px; width: {SW}px; height: {SH}px; border-radius: 87px; overflow: hidden; background: #fff; }}
    #screen img {{ position: absolute; left: 0; top: {STATUS}px; display: block; }}
    #status {{ position: absolute; left: 0; top: 0; width: {SW}px; height: {STATUS}px; background: #fff; z-index: 3; }}
    #status .time {{ position: absolute; left: 62px; top: 22px; font-size: 31px; line-height: 1; letter-spacing: 0; color: #000; }}
    #island {{ position: absolute; left: {(SW-196)//2}px; top: 17px; width: 196px; height: 58px; border-radius: 29px; background: #000; z-index: 4; }}
    #home {{ position: absolute; left: {(SW-211)//2}px; top: {SH-22}px; width: 211px; height: 8px; border-radius: 4px; background: #111; z-index: 4; }}
    .btnside {{ position: absolute; background: #b3afa5; border-radius: 3px; }}
    #tap {{ position: absolute; left: {BTN_X-55}px; top: {BTN_Y-55}px; width: 110px; height: 110px; border-radius: 55px; border: 9px solid {INK}; opacity: 0; z-index: 8; }}
    #flood {{ position: absolute; left: {BTN_X-30}px; top: {BTN_Y-30}px; width: 60px; height: 60px; border-radius: 30px; background: {GOLD}; opacity: 0; z-index: 9; }}
    #dot3 {{ position: absolute; left: {P0[0]-d2/2:.1f}px; top: {P0[1]-d2/2:.1f}px; width: {d2:.1f}px; height: {d2:.1f}px; border-radius: 50%; background: {GOLD}; z-index: 7; }}
"""
    body3 = f"""    <div class="abs" id="wipe" style="inset:0; background:{WHITE}"></div>
    {heads}
    <div id="phone" data-layout-allow-overflow>
      <div class="rim"></div><div class="bz"></div>
      <div class="btnside" style="left:-4px; top:210px; width:4px; height:46px"></div>
      <div class="btnside" style="left:-4px; top:300px; width:4px; height:86px"></div>
      <div class="btnside" style="left:-4px; top:400px; width:4px; height:86px"></div>
      <div class="btnside" style="right:-4px; top:340px; width:4px; height:130px"></div>
      <div id="screen">
        <img data-layout-allow-overflow id="before" src="assets/ui/cobrar-full.png" style="width:{IMG_W_BEFORE:.1f}px" />
        <img data-layout-allow-overflow id="after" src="assets/ui/cobrar-sent-full.png" style="width:620px; opacity:0" />
        <div id="status"><div class="time">9:41</div>
          <svg class="abs" style="right:52px; top:26px" width="150" height="34" viewBox="0 0 150 34"><rect x="0" y="22" width="7" height="10" rx="2" fill="#000"/><rect x="11" y="16" width="7" height="16" rx="2" fill="#000"/><rect x="22" y="9" width="7" height="23" rx="2" fill="#000"/><rect x="33" y="2" width="7" height="30" rx="2" fill="#000"/><path d="M54 14 a22 22 0 0 1 34 0 M60 21 a14 14 0 0 1 22 0" fill="none" stroke="#000" stroke-width="4.5" stroke-linecap="round"/><circle cx="71" cy="27" r="3.2" fill="#000"/><rect x="100" y="5" width="44" height="24" rx="7" fill="none" stroke="#000" stroke-opacity=".4" stroke-width="2.4"/><rect x="103" y="8" width="34" height="18" rx="4.5" fill="#000"/><rect x="146" y="12" width="3" height="10" rx="1.5" fill="#000" fill-opacity=".4"/></svg>
        </div>
        <div id="island"></div><div id="home"></div>
      </div>
    </div>
    <div id="tap"></div>
    <div id="dot3"></div>
    <div id="flood" data-layout-allow-overflow></div>"""
    ib = lambda stop: -stop * IMG_S
    ia = lambda stop: -(stop - (SEND_Y_BEFORE - SEND_Y_AFTER)) * IMG_S
    js3 = f"""
      tl.set("#before", {{ y: {ib(STOP_A):.1f} }}, 0);
      tl.set("#after", {{ y: {ia(STOP_D):.1f} }}, 0);
      tl.fromTo("#wipe", {{ x: 1080 }}, {{ x: 0, duration: 0.27, ease: "power4.inOut" }}, 0);
      tl.fromTo("#phone", {{ y: 1500 }}, {{ y: 0, duration: 0.85, ease: "power3.out" }}, 0.15);
      // the dot finds the gold segment of the share bar and becomes it
      tl.to("#dot3", {{ x: {BAR_FX - P0[0]}, y: {BAR_FY - P0[1]}, duration: 0.55, ease: "power2.inOut" }}, 0.75);
      tl.to("#dot3", {{ scaleX: {145/d2:.2f}, scaleY: {25/d2:.2f}, duration: 0.22, ease: "power2.out" }}, 1.3);
      tl.set("#dot3", {{ opacity: 0 }}, 1.55);
      // four verbs, one per bar
      const rise = (sel, at, out) => {{ tl.fromTo(sel, {{ y: 150, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.3, ease: "power3.out" }}, at); if (out) tl.to(sel, {{ y: -150, opacity: 0, duration: 0.25, ease: "power3.in" }}, out); }};
      rise(".h1", 1.9, 3.62); rise(".h2", 3.81, 5.52); rise(".h3", 5.71, 7.42); rise(".h4", 7.61, 8.4); rise(".h5", 8.57, null);
      tl.to("#before", {{ y: {ib(STOP_B):.1f}, duration: 0.5, ease: "power3.inOut" }}, 1.9);
      tl.to("#before", {{ y: {ib(STOP_C):.1f}, duration: 0.55, ease: "power3.inOut" }}, 3.81);
      tl.to("#before", {{ y: {ib(STOP_D):.1f}, duration: 0.55, ease: "power3.inOut" }}, 5.71);
      // the tap
      tl.fromTo("#tap", {{ x: 560, y: 120, opacity: 0, scale: 1.6 }}, {{ x: 0, y: 0, opacity: 1, scale: 1, duration: 0.5, ease: "power2.out" }}, 7.45);
      tl.to("#tap", {{ scale: 0.8, duration: 0.1, ease: "power2.in" }}, 8.1);
      tl.set("#before", {{ opacity: 0 }}, 8.14);
      tl.set("#after", {{ opacity: 1 }}, 8.14);
      tl.to("#tap", {{ opacity: 0, scale: 1.2, duration: 0.25, ease: "power2.out" }}, 8.2);
      // the button's dot floods the frame
      tl.set("#flood", {{ opacity: 1 }}, 10.35);
      tl.to("#flood", {{ scale: 70, duration: 1.05, ease: "power2.in" }}, 10.35);
"""
    (FR / "s3-app.html").write_text(shell("s3-app", css3, body3, js3, WHITE))

    # ------------------------------------------------------------------ S4
    css4 = f"""
    .sh {{ stroke-dasharray: 520; stroke-dashoffset: 520; }}
"""
    body4 = f"""    <svg class="abs" style="left:80px; top:400px" width="250" height="250" viewBox="0 0 24 24" fill="none" stroke="{INK}" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path class="sh" d="M12 2.5l7.5 2.8v6.2c0 4.6-3.1 8.3-7.5 10-4.4-1.7-7.5-5.4-7.5-10V5.3z"/><path class="sh" d="M8.5 12.2l2.5 2.5 4.6-4.9"/></svg>
    <div class="mask" style="left:80px; top:640px; width:940px; height:210px"><div class="t" data-layout-allow-overflow id="c1" style="top:14px; font-size:180px">Certificada</div></div>
    <div class="mask" style="left:80px; top:810px; width:940px; height:210px"><div class="t" data-layout-allow-overflow id="c2" style="top:14px; font-size:180px">pela AGT<span style="display:inline-block; width:{0.19*180:.0f}px; height:{0.19*180:.0f}px; border-radius:50%; background:{INK}; margin-left:{0.04*180:.0f}px"></span></div></div>
    <div class="mask" style="left:80px; top:1030px; width:940px; height:90px"><div class="t" data-layout-allow-overflow id="c3" style="top:10px; font-size:64px">Certificado n.º</div></div>
    <div class="mask" style="left:80px; top:1110px; width:940px; height:110px"><div class="t" data-layout-allow-overflow id="c4" style="top:10px; font-size:84px">FE/419/AGT/2026</div></div>
"""
    js4 = """
      tl.to(".sh", { strokeDashoffset: 0, duration: 0.7, ease: "power2.out", stagger: 0.2 }, 0.2);
      tl.fromTo("#c1", { y: 220, opacity: 0 }, { y: 0, opacity: 1, duration: 0.45, ease: "power3.out" }, 0.4);
      tl.fromTo("#c2", { y: 220, opacity: 0 }, { y: 0, opacity: 1, duration: 0.45, ease: "power3.out" }, 0.55);
      tl.fromTo("#c3", { y: 220, opacity: 0 }, { y: 0, opacity: 1, duration: 0.45, ease: "power3.out" }, 1.05);
      tl.fromTo("#c4", { y: 220, opacity: 0 }, { y: 0, opacity: 1, duration: 0.45, ease: "power3.out" }, 1.2);
"""
    (FR / "s4-certificada.html").write_text(shell("s4-certificada", css4, body4, js4, GOLD))

    # ------------------------------------------------------------------ S5
    S5 = 118
    claim_w = 6.289 * S5
    stop_x = 80 + claim_w + 0.04 * S5 + (DOT * S5) / 2
    stop_y = 800 - (DOT * S5) / 2
    d5 = DOT * S5
    CS = 150
    cta_w = 2.358 * CS
    cta_x = 80 + cta_w + 0.05 * CS + (DOT * CS) / 2
    cta_y = 880 - (DOT * CS) / 2
    dC = DOT * CS
    css5 = f"""
    #gold {{ position: absolute; inset: 0; background: {GOLD}; z-index: 6; clip-path: circle(1800px at {stop_x:.0f}px {stop_y:.0f}px); }}
    #stop {{ position: absolute; left: {stop_x-d5/2:.1f}px; top: {stop_y-d5/2:.1f}px; width: {d5:.1f}px; height: {d5:.1f}px; border-radius: 50%; background: {GOLD}; z-index: 7; opacity: 0; }}
    #cta {{ position: absolute; left: 80px; top: 1040px; height: 150px; padding: 0 66px; border-radius: 75px; background: {GOLD}; color: {INK};
      display: flex; align-items: center; font-size: 66px; letter-spacing: -0.02em; white-space: nowrap; }}
"""
    body5 = f"""    <div class="mask" style="left:80px; top:520px; width:940px; height:140px"><div class="t" data-layout-allow-overflow id="k1" style="top:8px; font-size:{S5}px">Cobrar, sem a</div></div>
    <div class="mask" style="left:80px; top:670px; width:940px; height:140px"><div class="t" data-layout-allow-overflow id="k2" style="top:8px; font-size:{S5}px">conversa difícil</div></div>
    <img class="abs" id="mark" src="assets/facturac-ao.svg" style="left:80px; top:430px; width:360px; opacity:0" />
    <div class="mask" style="left:80px; top:600px; width:940px; height:190px"><div class="t" data-layout-allow-overflow id="a1" style="top:12px; font-size:{CS}px">Abrir conta</div></div>
    <div class="mask" style="left:80px; top:740px; width:940px; height:190px"><div class="t" data-layout-allow-overflow id="a2" style="top:12px; font-size:{CS}px">grátis</div></div>
    <div id="cta">facturac.ao</div>
    <div class="mask" style="left:80px; top:1215px; width:940px; height:80px"><div class="t" data-layout-allow-overflow id="nf" style="top:10px; font-size:54px; color:{MUTED}">Sem cartão. Sem contrato.</div></div>
    <div id="gold"></div>
    <div id="stop"></div>"""
    js5 = f"""
      // the gold field shrinks to the dot at the end of the claim
      tl.fromTo("#gold", {{ clipPath: "circle(1800px at {stop_x:.0f}px {stop_y:.0f}px)" }}, {{ clipPath: "circle({d5/2:.1f}px at {stop_x:.0f}px {stop_y:.0f}px)", duration: 1.15, ease: "power3.inOut" }}, 0.1);
      tl.set("#gold", {{ opacity: 0 }}, 1.3);
      tl.set("#stop", {{ opacity: 1 }}, 1.3);
      // the claim leaves; the dot becomes the full stop of the call to action
      tl.to("#k1,#k2", {{ y: -160, opacity: 0, duration: 0.3, ease: "power3.in" }}, 3.78);
      tl.to("#stop", {{ x: {cta_x - stop_x:.1f}, y: {cta_y - stop_y:.1f}, scale: {dC/d5:.2f}, duration: 0.6, ease: "power2.inOut" }}, 3.85);
      tl.fromTo("#a1", {{ y: 160, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.4, ease: "power3.out" }}, 4.0);
      tl.fromTo("#a2", {{ y: 160, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.4, ease: "power3.out" }}, 4.12);
      tl.fromTo("#mark", {{ opacity: 0, x: -40 }}, {{ opacity: 1, x: 0, duration: 0.5, ease: "power3.out" }}, 4.5);
      tl.fromTo("#cta", {{ opacity: 0, y: 60 }}, {{ opacity: 1, y: 0, duration: 0.5, ease: "back.out(1.7)" }}, 5.0);
      tl.fromTo("#nf", {{ y: 160, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.4, ease: "power3.out" }}, 5.5);
"""
    (FR / "s5-fim.html").write_text(shell("s5-fim", css5, body5, js5, WHITE))

    # ------------------------------------------------------------------ main
    hosts = "\n".join(
        f'      <div id="el-{sid}" class="clip" data-composition-id="{sid}" data-composition-src="compositions/{sid}.html" data-start="{st}" data-duration="{du}" data-track-index="1"></div>'
        for sid, st, du in SCENES
    )
    DUR = {"drop-thud": 0.792, "impact-soft": 2.758, "pop-confirm": 0.576, "pop-soft": 0.576, "whoosh-soft": 1.58, "whoosh-up": 1.58, "riser-short": 4.97}
    fx = [
        ("whoosh-soft", 0.40, 0.30), ("pop-confirm", 1.85, 0.5),
        ("whoosh-up", 2.38, 0.32), ("pop-confirm", 4.5, 0.5), ("whoosh-soft", 5.4, 0.3),
        ("whoosh-up", 6.2, 0.34), ("pop-soft", 7.55, 0.4),
        ("pop-soft", 8.1, 0.36), ("whoosh-soft", 8.1, 0.26),
        ("pop-soft", 10.0, 0.36), ("whoosh-soft", 10.0, 0.26),
        ("pop-soft", 11.9, 0.36), ("whoosh-soft", 11.9, 0.26),
        ("pop-soft", 13.8, 0.36),
        ("pop-confirm", 14.33, 0.55), ("pop-confirm", 14.77, 0.5),
        ("riser-short", 12.65, 0.22), ("impact-soft", 17.65, 0.36),
        ("pop-soft", 18.0, 0.36), ("pop-soft", 18.6, 0.34),
        ("pop-confirm", 22.7, 0.5), ("whoosh-soft", 25.2, 0.3), ("pop-confirm", 26.45, 0.5),
    ]
    sfx = "\n".join(
        f'      <audio id="fx{i}" src="assets/sfx/{n}.mp3" data-start="{t}" data-duration="{DUR[n]}" data-track-index="{20+i}" data-volume="{v}"></audio>'
        for i, (n, t, v) in enumerate(fx)
    )
    index = f"""<!doctype html>
<html lang="pt-AO">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=1080, height=1920" />
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js"></script>
    <style>
      * {{ margin: 0; padding: 0; box-sizing: border-box; }}
      html, body {{ width: 1080px; height: 1920px; overflow: hidden; background: #fff; }}
      #root {{ position: relative; width: 1080px; height: 1920px; overflow: hidden; background: #fff; }}
    </style>
  </head>
  <body>
    <div id="root" data-composition-id="main" data-start="0" data-duration="{TOTAL}" data-width="1080" data-height="1920">
{hosts}

      <audio id="bgm" src="assets/bgm/track-ducked-{VOICE}.wav" data-start="0" data-duration="{TOTAL}" data-track-index="11" data-volume="1"></audio>
      <audio id="vo" src="assets/vo/vo-{VOICE}.wav" data-start="0" data-duration="{TOTAL}" data-track-index="12" data-volume="1"></audio>
{sfx}
    </div>
    <script>
      window.__timelines = window.__timelines || {{}};
      window.__timelines["main"] = gsap.timeline({{ paused: true }});
    </script>
  </body>
</html>
"""
    (ROOT / "index.html").write_text(index)


if __name__ == "__main__":
    build()
    print("built", len(SCENES), "scenes; phone at", PX, PY, "screen", SX, SY, "bar", (BAR_FX, BAR_FY), "button", BTN_Y)
