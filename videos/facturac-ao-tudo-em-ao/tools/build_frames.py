"""Builds the six frame sub-compositions of "Tudo acaba em .ao" (proposal C).

One rule, repeated until it becomes music: every word that ends in -ção loses
its til and its cedilha and gains the gold dot (atenção -> atenc.ao). The same
morph is applied to every word through cao_morph(), so the gesture is
identical each time and only the message changes. Copy comes from the home
page (resources/js/pages/Landing.vue), shortened for the screen.

Beat grid: 126.05 BPM, beat 0 at 0.174s; bars on beats 2, 6, 10, ...
Run from the project root:  python3 tools/build_frames.py
"""

import json
import math
import os
import sys

sys.path.insert(0, os.path.dirname(__file__))
import type_outlines as T  # noqa: E402

W, H = 1080, 1920
INK, INK_SOFT, PAPER, GOLD, GOLD_DEEP, WHITE = "#171716", "#575756", "#fafaf9", "#f9b233", "#d87706", "#fafaf9"
BEAT = 60 / 126.05
B0 = 0.174
FRAMES = {
    "01-sempre": (0.0, 4.934),
    "02-atencao": (4.934, 6.664),
    "03-migracao": (11.598, 4.76),
    "04-comunicacao": (16.358, 4.76),
    "05-tudo": (21.118, 4.76),
    "06-brevemente": (25.878, 3.122),
}
OUT = os.path.join(os.path.dirname(__file__), "..", "compositions", "frames")
AO_SHIFT = 29.47  # brand units: the gap the dot opens between "c" and "ao"
DOT_R = 10.0


def beat(k):
    return B0 + k * BEAT


def r(v, n=3):
    return round(v, n)


FONT_FACES = """
    @font-face { font-family: "Hanken Grotesk"; font-weight: 600; font-style: normal; font-display: block;
      src: url("assets/fonts/HankenGrotesk-600.woff2") format("woff2"); }
    @font-face { font-family: "Hanken Grotesk"; font-weight: 700; font-style: normal; font-display: block;
      src: url("assets/fonts/HankenGrotesk-700.woff2") format("woff2"); }
"""


def frame_doc(cid, dur, css, stage, js, dark=False, meta_r="Brevemente"):
    fid = "frame-" + cid
    ground = INK if dark else PAPER
    grid = "rgba(249,178,51,0.10)" if dark else "rgba(249,178,51,0.13)"
    rule = "rgba(250,250,249,0.14)" if dark else "rgba(23,23,22,0.12)"
    meta = "rgba(250,250,249,0.6)" if dark else INK_SOFT
    return f"""<template>
  <style>
{FONT_FACES}
    #root {{ position: absolute; inset: 0; width: {W}px; height: {H}px; overflow: hidden;
      font-family: "Hanken Grotesk", sans-serif; color: {WHITE if dark else INK}; }}
    #{fid}-ground {{ position: absolute; inset: 0; background: {ground}; overflow: hidden; }}
    #{fid}-grid {{ position: absolute; left: 0; top: -108px; width: 1080px; height: 2136px;
      background-image: linear-gradient(to right, {grid} 1px, transparent 1px), linear-gradient(to bottom, {grid} 1px, transparent 1px);
      background-size: 54px 54px; }}
    #{fid}-rule-top, #{fid}-rule-bottom {{ position: absolute; left: 54px; right: 54px; height: 1.5px; background: {rule}; }}
    #{fid}-rule-top {{ top: 108px; }}
    #{fid}-rule-bottom {{ top: 1620px; }}
    #{fid}-meta-l, #{fid}-meta-r {{ position: absolute; top: 72px; font: 600 22px/1 "Hanken Grotesk", sans-serif;
      letter-spacing: 0.18em; text-transform: uppercase; color: {meta}; }}
    #{fid}-meta-l {{ left: 54px; }}
    #{fid}-meta-r {{ right: 54px; }}
    .{fid}-dot {{ position: absolute; border-radius: 50%; background: {GOLD}; }}
    .{fid}-label {{ position: absolute; left: 90px; right: 90px; text-align: center; font: 600 28px/1.45 "Hanken Grotesk", sans-serif;
      letter-spacing: 0.14em; text-transform: uppercase; color: {meta}; }}
{css}
  </style>
  <div id="root" data-composition-id="{cid}" data-width="{W}" data-height="{H}">
    <div id="{fid}-ground" class="clip" data-start="0" data-duration="{dur}" data-track-index="0">
      <div id="{fid}-grid" data-layout-allow-overflow></div>
      <div id="{fid}-rule-top"></div>
      <div id="{fid}-rule-bottom"></div>
      <div id="{fid}-meta-l">facturac.ao</div>
      <div id="{fid}-meta-r">{meta_r}</div>
    </div>
    <div id="{fid}-stage" class="clip" data-start="0" data-duration="{dur}" data-track-index="1">
{stage}
    </div>
  </div>
  <script>
    (function () {{
      const tl = gsap.timeline({{ paused: true }});
      tl.set({{}}, {{}}, {dur});
      tl.fromTo("#{fid}-grid", {{ y: 0 }}, {{ y: -54, duration: {dur}, ease: "none" }}, 0);
      {js}
      window.__timelines["{cid}"] = tl;
    }})();
  </script>
</template>
"""


# --------------------------------------------------------------- the morph

class CaoWord:
    """A word ending in -ção, placed centred, ready to become -c.ao."""

    def __init__(self, text, px, prefix, top, color=INK, center_x=W / 2):
        self.text, self.px, self.prefix, self.top = text, px, prefix, top
        self.k = px / T.EM
        self.glyphs, self.width = T.layout(text)
        self.i_c = text.index("ç")
        self.i_a = self.i_c + 1
        self.left = center_x - self.width * self.k / 2
        h, _, self.ids = T.word_html(text, px, prefix, color=color)
        g = self.glyphs[self.i_c]
        self.dot_x_units = g["x"] + g["adv"] + DOT_R - AO_SHIFT / 2
        d = 2 * DOT_R * self.k
        self.html = (
            f'<div style="position:absolute;left:{self.left:.2f}px;top:{top}px">{h}'
            f'<div id="{prefix}-dot" class="DOTCLASS" style="left:{(self.dot_x_units - DOT_R) * self.k:.2f}px;'
            f'top:{(T.ASCENT - 2 * DOT_R) * self.k:.2f}px;width:{d:.2f}px;height:{d:.2f}px"></div></div>'
        )

    def html_for(self, fid):
        return self.html.replace("DOTCLASS", f"{fid}-dot")

    def enter(self, js, t, stagger=0.035):
        for j, gid in enumerate(self.ids):
            js.append(f'tl.fromTo("#{gid}", {{ y: 110, opacity: 0, scaleY: 1.3, transformOrigin: "50% 90%" }}, '
                      f'{{ y: 0, opacity: 1, scaleY: 1, duration: 0.38, ease: "back.out(2.4)" }}, {r(t + j * stagger)});')

    def morph(self, js, t_til, t_ced, t_dot, fast=False):
        p, k = self.prefix, self.k
        til, ced = f"#{p}-g{self.i_a}-tilde", f"#{p}-g{self.i_c}-cedilla"
        fly = 0.32 if fast else 0.6
        js.append(f'tl.to("{til}", {{ y: -20, rotation: -12, duration: {0.08 if fast else 0.16}, ease: "power2.out", transformOrigin: "50% 60%" }}, {r(t_til - (0.08 if fast else 0.16))});')
        js.append(f'tl.to("{til}", {{ x: 420, y: -820, rotation: 420, scale: 0.5, opacity: 0, duration: {fly}, ease: "power2.in" }}, {r(t_til + 0.002)});')
        js.append(f'tl.to("{ced}", {{ y: 1100, rotation: 40, duration: {0.4 if fast else 0.7}, ease: "power2.in" }}, {r(t_ced)});')
        js.append(f'tl.set("{ced}", {{ opacity: 0 }}, {r(t_ced + (0.42 if fast else 0.72))});')
        shift = AO_SHIFT / 2 * k
        open_t = t_dot - (0.26 if fast else 0.42)
        for i, gid in enumerate(self.ids):
            js.append(f'tl.to("#{gid}", {{ x: {r(shift if i >= self.i_a else -shift, 2)}, duration: {0.2 if fast else 0.34}, ease: "power3.inOut" }}, {r(open_t)});')
        # The dot falls in on its beat, squashes, settles.
        js.append(f'tl.set("#{p}-dot", {{ opacity: 0 }}, 0);')
        fall = 0.18 if fast else 0.3
        js.append(f'tl.fromTo("#{p}-dot", {{ y: {-180 if fast else -420}, opacity: 1 }}, {{ y: 0, opacity: 1, duration: {fall}, ease: "power2.in", immediateRender: false }}, {r(t_dot - fall)});')
        js.append(f'tl.to("#{p}-dot", {{ scaleX: 1.5, scaleY: 0.6, duration: 0.05, ease: "power2.out", transformOrigin: "50% 100%" }}, {r(t_dot)});')
        js.append(f'tl.to("#{p}-dot", {{ scaleX: 1, scaleY: 1, duration: 0.28, ease: "back.out(3)" }}, {r(t_dot + 0.052)});')


def phrase(text, px, prefix, color=INK, gold_period=False):
    words = text.split(" ")
    space = (T.advance(" ") + T.TRACKING) * px / T.EM
    parts, ids = [], []
    for i, w in enumerate(words):
        h, _, gids = T.word_html(w, px, f"{prefix}-w{i}", color=color)
        if gold_period:
            h = h.replace(f'data-ch="."', 'data-ch="." data-gold="1"')
        parts.append(f'<div id="{prefix}-w{i}-box" style="position:relative">{h}</div>')
        ids.append(f"{prefix}-w{i}-box")
    row = f'<div id="{prefix}" style="display:flex;gap:{space:.2f}px;justify-content:center;width:{W}px">' + "".join(parts) + "</div>"
    if gold_period:
        # The full stop in ".ao" is the brand dot: paint it gold.
        import re
        row = re.sub(r'(data-gold="1"[^>]*>(?:<svg[^>]*>)<path d="[^"]*" fill=")#[0-9a-f]{6}', r"\g<1>" + GOLD, row)
    return row, ids


def pop_in(js, ids, t, stagger=0.1):
    for j, wid in enumerate(ids):
        js.append(f'tl.fromTo("#{wid}", {{ opacity: 0, scale: 1.45, y: -10 }}, {{ opacity: 1, scale: 1, y: 0, duration: 0.24, ease: "power4.out", transformOrigin: "50% 60%" }}, {r(t + j * stagger)});')


def squash_land(js, sel, t, fall_from=-400, fall=0.3):
    js.append(f'tl.set("{sel}", {{ opacity: 0 }}, 0);')
    js.append(f'tl.fromTo("{sel}", {{ y: {fall_from}, opacity: 1 }}, {{ y: 0, opacity: 1, duration: {fall}, ease: "power2.in", immediateRender: false }}, {r(t - fall)});')
    js.append(f'tl.to("{sel}", {{ scaleX: 1.5, scaleY: 0.6, duration: 0.05, transformOrigin: "50% 100%" }}, {r(t)});')
    js.append(f'tl.to("{sel}", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "back.out(3)" }}, {r(t + 0.052)});')


# ------------------------------------------------------------ Frame 1

def frame1():
    cid, (g0, dur) = "01-sempre", FRAMES["01-sempre"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    js = []
    l1, ids1 = phrase("Facturação", 120, f"{fid}-l1")
    l2, ids2 = phrase("sempre acabou em", 100, f"{fid}-l2")
    # The huge ending: "ão", which becomes ".ao".
    px = 420
    k = px / T.EM
    glyphs, width = T.layout("ão")
    dot_cx = -14.47  # brand: the dot sits 14.47 units before the "a"
    total = width - (dot_cx - DOT_R)
    left = (W - width * k) / 2
    h, _, gids = T.word_html("ão", px, f"{fid}-end")
    d = 2 * DOT_R * k
    end = (f'<div style="position:absolute;left:{left:.2f}px;top:820px">{h}'
           f'<div id="{fid}-end-dot" class="{fid}-dot" style="left:{(dot_cx - DOT_R) * k:.2f}px;top:{(T.ASCENT - 2 * DOT_R) * k:.2f}px;width:{d:.2f}px;height:{d:.2f}px"></div></div>')
    shift = (total - width) / 2 * k  # recentre once the dot is in
    css = ""
    stage = f'      <div style="position:absolute;left:0;top:420px">{l1}</div>\n      <div style="position:absolute;left:0;top:590px">{l2}</div>\n      {end}'
    pop_in(js, ids1, L(0))
    pop_in(js, ids2, L(1), stagger=0.16)
    for j, gid in enumerate(gids):
        js.append(f'tl.fromTo("#{gid}", {{ y: 200, opacity: 0, scaleY: 1.35, transformOrigin: "50% 90%" }}, {{ y: 0, opacity: 1, scaleY: 1, duration: 0.42, ease: "back.out(2.2)" }}, {r(L(4) + j * 0.06)});')
    til = f"#{fid}-end-g0-tilde"
    js.append(f'tl.to("{til}", {{ y: -40, rotation: -12, duration: 0.2, ease: "power2.out", transformOrigin: "50% 60%" }}, {r(L(5) + 0.2)});')
    js.append(f'tl.to("{til}", {{ x: 520, y: -1000, rotation: 400, scale: 0.6, opacity: 0, duration: 0.6, ease: "power2.in" }}, {r(L(6))});')
    js.append(f'tl.to("#{fid}-end-g0, #{fid}-end-g1, #{fid}-end-dot", {{ x: {r(shift, 2)}, duration: 0.36, ease: "power3.inOut" }}, {r(L(7))});')
    squash_land(js, f"#{fid}-end-dot", L(8), fall_from=-700, fall=0.36)
    js.append(f'tl.fromTo("#{fid}-stage", {{ scale: 1 }}, {{ scale: 1.035, duration: 0.07, ease: "power2.out", transformOrigin: "50% 55%" }}, {r(L(8))});')
    js.append(f'tl.to("#{fid}-stage", {{ scale: 1, duration: 0.5, ease: "power3.out" }}, {r(L(8) + 0.072)});')
    return cid, frame_doc(cid, dur, css, stage, "\n      ".join(js))


# ------------------------------------------------------------ Frame 2

def frame2():
    cid, (g0, dur) = "02-atencao", FRAMES["02-atencao"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    js = []
    word = CaoWord("atenção", 220, f"{fid}-w", 300, color=WHITE)
    d1, ids_d1 = phrase("1 de janeiro", 118, f"{fid}-d1", color=WHITE)
    d2, ids_d2 = phrase("de 2027", 118, f"{fid}-d2", color=WHITE)
    d2 = d2.replace(f'fill="{WHITE}"', f'fill="{WHITE}"')
    lines = ["Regime Geral.", "Regime Simplificado.", "Factura electrónica para todos."]
    lines_html = "".join(f'<div id="{fid}-ln{i}" class="{fid}-ln" style="top:{1070 + i * 74}px">{t}</div>' for i, t in enumerate(lines))
    css = f"""
    .{fid}-ln {{ position: absolute; left: 0; right: 0; text-align: center; font: 700 50px/1.1 "Hanken Grotesk", sans-serif; color: {WHITE}; }}
    #{fid}-ln2 {{ color: {GOLD}; }}
    #{fid}-bar {{ position: absolute; left: 400px; top: 1010px; width: 280px; height: 8px; border-radius: 4px; background: {GOLD}; }}
    """
    stage = (
        f"      {word.html_for(fid)}\n"
        f'      <div style="position:absolute;left:0;top:640px">{d1}</div>\n'
        f'      <div style="position:absolute;left:0;top:800px">{d2}</div>\n'
        f'      <div id="{fid}-bar"></div>\n      {lines_html}\n'
        f'      <div id="{fid}-warn" class="{fid}-label" style="top:1330px">Quem ainda factura em papel ou em Excel tem de mudar.</div>'
    )
    word.enter(js, 0.0)
    word.morph(js, L(11), L(12), L(14))
    pop_in(js, ids_d1, L(15))
    pop_in(js, ids_d2, L(16), stagger=0.14)
    js.append(f'tl.fromTo("#{fid}-bar", {{ scaleX: 0, transformOrigin: "50% 50%" }}, {{ scaleX: 1, duration: 0.45, ease: "power3.out" }}, {r(L(17))});')
    for i, k_ in enumerate([18, 19, 20]):
        js.append(f'tl.fromTo("#{fid}-ln{i}", {{ opacity: 0, y: 30 }}, {{ opacity: 1, y: 0, duration: 0.32, ease: "back.out(2)" }}, {r(L(k_))});')
    js.append(f'tl.fromTo("#{fid}-warn", {{ opacity: 0, y: 18 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }}, {r(L(21))});')
    return cid, frame_doc(cid, dur, css, stage, "\n      ".join(js), dark=True, meta_r="1 de janeiro de 2027")


# ------------------------------------------------------------ Frame 3

def frame3():
    cid, (g0, dur) = "03-migracao", FRAMES["03-migracao"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    js = []
    word = CaoWord("migração", 196, f"{fid}-w", 300)
    head, ids_h = phrase("Traga o que já tem.", 92, f"{fid}-h")
    # Two cards: the file it comes from, and the place it goes.
    css = f"""
    .{fid}-card {{ position: absolute; top: 860px; width: 380px; height: 400px; border-radius: 30px; }}
    #{fid}-from {{ left: 70px; background: #ffffff; box-shadow: 0 0 0 2px rgba(23,23,22,0.1); }}
    #{fid}-to {{ left: 630px; background: #ffffff; box-shadow: 0 0 0 3px {INK}, 0 40px 80px -40px rgba(23,23,22,0.35); }}
    .{fid}-cap {{ position: absolute; left: 0; right: 0; top: 30px; text-align: center; font: 600 22px/1 "Hanken Grotesk", sans-serif;
      letter-spacing: 0.16em; text-transform: uppercase; color: {INK_SOFT}; }}
    .{fid}-sheet {{ position: absolute; left: 44px; right: 44px; height: 2px; background: rgba(23,23,22,0.09); }}
    .{fid}-chip {{ position: absolute; height: 76px; padding: 0 28px; border-radius: 38px; display: flex; align-items: center;
      font: 700 32px/1 "Hanken Grotesk", sans-serif; color: {INK}; background: #fef0c7; box-shadow: inset 0 0 0 2px rgba(216,119,6,0.25); }}
    #{fid}-check {{ position: absolute; left: 470px; top: 1330px; width: 140px; height: 140px; }}
    """
    sheet = "".join(f'<div class="{fid}-sheet" style="top:{90 + i * 52}px"></div>' for i in range(6))
    wm, _, _ = T.word_html("facturacao", 50, f"{fid}-wm")
    stage = (
        f"      {word.html_for(fid)}\n"
        f'      <div style="position:absolute;left:0;top:640px">{head}</div>\n'
        f'      <div id="{fid}-from" class="{fid}-card"><div class="{fid}-cap">Excel · programa actual</div>{sheet}</div>\n'
        f'      <div id="{fid}-to" class="{fid}-card"><div class="{fid}-cap">facturac.ao</div></div>\n'
        f'      <div id="{fid}-chip0" class="{fid}-chip">Clientes</div>\n'
        f'      <div id="{fid}-chip1" class="{fid}-chip">Artigos</div>\n'
        f'      <svg id="{fid}-check" viewBox="0 0 140 140"><circle cx="70" cy="70" r="62" fill="{GOLD}"/>'
        f'<path id="{fid}-tick" d="M40 72 L62 94 L102 50" fill="none" stroke="{INK}" stroke-width="12" stroke-linecap="round" stroke-linejoin="round"/></svg>\n'
        f'      <div id="{fid}-note" class="{fid}-label" style="top:1500px">Nada é gravado sem a sua confirmação.</div>'
    )
    word.enter(js, 0.0)
    word.morph(js, L(25), L(26), L(27))
    pop_in(js, ids_h, L(28), stagger=0.09)
    js.append(f'tl.fromTo("#{fid}-from, #{fid}-to", {{ opacity: 0, y: 60 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "back.out(1.8)", stagger: 0.08 }}, {r(L(28) + 0.12)});')
    # The chips start in the file and jump across, one per beat.
    for i, (k_, y) in enumerate([(29, 960), (30, 1080)]):
        start_x, end_x = 105, 665
        js.append(f'tl.set("#{fid}-chip{i}", {{ x: {start_x}, y: {y}, opacity: 0 }}, 0);')
        js.append(f'tl.to("#{fid}-chip{i}", {{ opacity: 1, duration: 0.15 }}, {r(L(28) + 0.3 + i * 0.1)});')
        t = L(k_)
        js.append(f'tl.to("#{fid}-chip{i}", {{ x: {end_x}, duration: 0.42, ease: "power1.inOut" }}, {r(t)});')
        js.append(f'tl.to("#{fid}-chip{i}", {{ y: {y - 170}, duration: 0.21, ease: "power2.out" }}, {r(t)});')
        js.append(f'tl.to("#{fid}-chip{i}", {{ y: {y}, duration: 0.21, ease: "power2.in" }}, {r(t + 0.21)});')
        js.append(f'tl.to("#{fid}-chip{i}", {{ scaleX: 1.12, scaleY: 0.88, duration: 0.05, transformOrigin: "50% 100%" }}, {r(t + 0.42)});')
        js.append(f'tl.to("#{fid}-chip{i}", {{ scaleX: 1, scaleY: 1, duration: 0.25, ease: "back.out(3)" }}, {r(t + 0.472)});')
    js.append(f'tl.fromTo("#{fid}-check", {{ opacity: 0, scale: 0.4 }}, {{ opacity: 1, scale: 1, duration: 0.35, ease: "back.out(2.5)", transformOrigin: "50% 50%" }}, {r(L(31))});')
    js.append(f'tl.fromTo("#{fid}-tick", {{ strokeDasharray: 100, strokeDashoffset: 100 }}, {{ strokeDasharray: 100, strokeDashoffset: 0, duration: 0.3, ease: "power2.out" }}, {r(L(31) + 0.12)});')
    js.append(f'tl.fromTo("#{fid}-note", {{ opacity: 0, y: 16 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }}, {r(L(32))});')
    return cid, frame_doc(cid, dur, css, stage, "\n      ".join(js))


# ------------------------------------------------------------ Frame 4

def frame4():
    cid, (g0, dur) = "04-comunicacao", FRAMES["04-comunicacao"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    js = []
    word = CaoWord("comunicação", 138, f"{fid}-w", 330)
    items = ["O número.", "A assinatura.", "A AGT.", "O comprovativo."]
    rows = "".join(
        f'<div id="{fid}-row{i}" class="{fid}-row" style="top:{700 + i * 130}px">'
        f'<svg class="{fid}-box" viewBox="0 0 76 76"><circle id="{fid}-c{i}" cx="38" cy="38" r="33" fill="none" stroke="{INK}" stroke-width="5"/>'
        f'<path id="{fid}-t{i}" d="M22 39 L34 51 L55 27" fill="none" stroke="{INK}" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        f'<span>{t}</span></div>'
        for i, t in enumerate(items)
    )
    css = f"""
    .{fid}-row {{ position: absolute; left: 230px; height: 76px; display: flex; align-items: center; gap: 32px;
      font: 700 56px/1 "Hanken Grotesk", sans-serif; color: {INK}; }}
    .{fid}-box {{ width: 76px; height: 76px; flex: none; }}
    """
    stage = (
        f"      {word.html_for(fid)}\n      {rows}\n"
        f'      <div id="{fid}-note" class="{fid}-label" style="top:1300px">Acontecem sozinhos, enquanto atende o cliente seguinte.</div>'
    )
    word.enter(js, 0.0, stagger=0.028)
    word.morph(js, L(35), L(36), L(37))
    for i, k_ in enumerate([38, 39, 40, 41]):
        t = L(k_)
        js.append(f'tl.fromTo("#{fid}-row{i}", {{ opacity: 0, x: -40 }}, {{ opacity: 1, x: 0, duration: 0.3, ease: "power3.out" }}, {r(t - 0.12)});')
        js.append(f'tl.fromTo("#{fid}-c{i}", {{ fill: "rgba(249,178,51,0)" }}, {{ fill: "{GOLD}", duration: 0.18, ease: "power1.out" }}, {r(t)});')
        js.append(f'tl.fromTo("#{fid}-t{i}", {{ strokeDasharray: 60, strokeDashoffset: 60 }}, {{ strokeDasharray: 60, strokeDashoffset: 0, duration: 0.24, ease: "power2.out" }}, {r(t + 0.05)});')
    js.append(f'tl.fromTo("#{fid}-note", {{ opacity: 0, y: 16 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }}, {r(L(42))});')
    return cid, frame_doc(cid, dur, css, stage, "\n      ".join(js))


# ------------------------------------------------------------ Frame 5

def frame5():
    cid, (g0, dur) = "05-tudo", FRAMES["05-tudo"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    js = []
    ticker = ["obrigação", "organização", "validação", "aprovação"]
    words = [CaoWord(t, 158, f"{fid}-k{i}", 820) for i, t in enumerate(ticker)]
    final = CaoWord("facturação", 158, f"{fid}-fin", 820)
    head, ids_h = phrase("Tudo acaba em .ao", 98, f"{fid}-h", gold_period=True)
    stage = "\n      ".join(f'<div id="{fid}-slot{i}" style="position:absolute;inset:0">{w.html_for(fid)}</div>' for i, w in enumerate(words))
    stage = "      " + stage + f'\n      <div id="{fid}-slotf" style="position:absolute;inset:0">{final.html_for(fid)}</div>\n      <div style="position:absolute;left:0;top:520px">{head}</div>'
    # Each word: in on its beat, its c.ao in a flash, out as the next arrives.
    for i, w in enumerate(words):
        t = L(44 + i)
        js.append(f'tl.set("#{fid}-slot{i}", {{ opacity: 0 }}, 0);')
        js.append(f'tl.set("#{fid}-slot{i}", {{ opacity: 1 }}, {r(t)});')
        js.append(f'tl.fromTo("#{fid}-slot{i}", {{ y: 140 }}, {{ y: 0, duration: 0.2, ease: "back.out(2)", immediateRender: false }}, {r(t)});')
        w.morph(js, t + 0.12, t + 0.16, t + 0.34, fast=True)
        js.append(f'tl.to("#{fid}-slot{i}", {{ y: -140, opacity: 0, duration: 0.1, ease: "power2.in" }}, {r(t + BEAT - 0.1)});')
    tf = L(48)
    js.append(f'tl.set("#{fid}-slotf", {{ opacity: 0 }}, 0);')
    js.append(f'tl.set("#{fid}-slotf", {{ opacity: 1 }}, {r(tf)});')
    final.enter(js, tf, stagger=0.025)
    final.morph(js, L(49), L(49) + 0.24, L(50))
    pop_in(js, ids_h, L(51), stagger=0.1)
    return cid, frame_doc(cid, dur, "", stage, "\n      ".join(js), meta_r="Tudo acaba em .ao")


# ------------------------------------------------------------ Frame 6

def frame6():
    cid, (g0, dur) = "06-brevemente", FRAMES["06-brevemente"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    js = []
    px1 = 156
    k1 = px1 / T.EM
    h1, w1, ids1 = T.word_html("Brevemente", px1, f"{fid}-bre")
    d1 = 2 * DOT_R * k1
    left1 = (W - (w1 + d1)) / 2
    px2 = 116
    k2 = px2 / T.EM
    glyphs, _ = T.layout("facturacao")
    wm_w = 524.51 * k2
    left2 = (W - wm_w) / 2
    parts, ids2 = [], []
    for i, g in enumerate(glyphs):
        x = g["x"] + (AO_SHIFT if i >= 8 else 0)
        gid = f"{fid}-wm-g{i}"
        ids2.append(gid)
        parts.append(f'<svg id="{gid}" viewBox="0 0 {g["adv"]:.2f} 125" style="position:absolute;left:{x * k2:.2f}px;top:0;width:{g["adv"] * k2:.2f}px;height:{125 * k2:.2f}px;overflow:visible" aria-hidden="true"><path d="{g["parts"][0][1]}" fill="{INK}"/></svg>')
    d2 = 2 * DOT_R * k2
    wm = (f'<div aria-label="facturac.ao" style="position:absolute;left:{left2:.2f}px;top:930px;width:{wm_w:.2f}px;height:{125 * k2:.2f}px">' + "".join(parts)
          + f'<div id="{fid}-wm-dot" class="{fid}-dot" style="left:{(385.01 - DOT_R) * k2:.2f}px;top:{(T.ASCENT - 2 * DOT_R) * k2:.2f}px;width:{d2:.2f}px;height:{d2:.2f}px"></div></div>')
    css = f"""
    #{fid}-sig {{ position: absolute; left: 0; right: 0; top: 1250px; text-align: center; font: 700 34px/1 "Hanken Grotesk", sans-serif; color: {INK}; }}
    #{fid}-sig span {{ display: inline-block; }}
    #{fid}-sig b {{ display: inline-block; width: 11px; height: 11px; border-radius: 50%; background: {GOLD}; margin: 0 16px 2px 3px; }}
    """
    stage = (
        f'      <div style="position:absolute;left:{left1:.2f}px;top:600px">{h1}'
        f'<div id="{fid}-bre-dot" class="{fid}-dot" style="left:{w1:.2f}px;top:{(T.ASCENT - 2 * DOT_R) * k1:.2f}px;width:{d1:.2f}px;height:{d1:.2f}px"></div></div>\n'
        f"      {wm}\n"
        f'      <div id="{fid}-sub" class="{fid}-label" style="top:1190px">Facturação electrónica para Angola</div>\n'
        f'      <div id="{fid}-sig"><span>Emitida</span><b></b><span>Validada</span><b></b><span>Paga</span><b></b></div>'
    )
    for j, gid in enumerate(ids1):
        js.append(f'tl.fromTo("#{gid}", {{ y: -120, opacity: 0, rotation: {(-1) ** j * 6}, transformOrigin: "50% 50%" }}, {{ y: 0, opacity: 1, rotation: 0, duration: 0.42, ease: "back.out(2.4)" }}, {r(0.02 + j * 0.03)});')
    squash_land(js, f"#{fid}-bre-dot", L(55), fall_from=-620, fall=0.34)
    for j, gid in enumerate(ids2):
        js.append(f'tl.fromTo("#{gid}", {{ y: 90, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.36, ease: "back.out(2)" }}, {r(L(56) + j * 0.045)});')
    squash_land(js, f"#{fid}-wm-dot", L(58), fall_from=-300, fall=0.3)
    js.append(f'tl.fromTo("#{fid}-sub", {{ opacity: 0, y: 14 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }}, {r(L(58) + 0.15)});')
    js.append(f'tl.fromTo("#{fid}-sig span, #{fid}-sig b", {{ opacity: 0, y: 12 }}, {{ opacity: 1, y: 0, duration: 0.3, ease: "power3.out", stagger: 0.07 }}, {r(L(58) + 0.35)});')
    return cid, frame_doc(cid, dur, css, stage, "\n      ".join(js))


if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    for build in (frame1, frame2, frame3, frame4, frame5, frame6):
        cid, doc = build()
        with open(os.path.join(OUT, f"{cid}.html"), "w") as fh:
            fh.write(doc)
        print("wrote", cid, len(doc), "bytes")
