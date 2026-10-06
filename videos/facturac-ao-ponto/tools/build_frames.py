"""Builds the four frame sub-compositions of "O Ponto" (proposal A).

Every hero word is Century Gothic Bold as outlines (type_outlines.py), so the
til and the cedilha can leave their letters and the result lands exactly on
the brand wordmark. Timings are the music's beat grid: 114.84 BPM, first
downbeat 0.441s, the drop at 8.278s (global). Each frame converts global beat
times to its own local clock.

Run from the project root:  python3 tools/build_frames.py
"""

import json
import math
import os
import sys

sys.path.insert(0, os.path.dirname(__file__))
import type_outlines as T  # noqa: E402

W, H = 1080, 1920
INK, INK_SOFT, PAPER, GOLD, GOLD_DEEP = "#171716", "#575756", "#fafaf9", "#f9b233", "#d87706"
BEAT = 60 / 114.84
B0 = 0.441
FRAMES = {  # id: (global start, duration)
    "01-a-palavra": (0.0, 3.053),
    "02-a-licao": (3.053, 7.837),
    "03-assinatura": (10.89, 2.61),
    "04-brevemente": (13.5, 3.7),
}
OUT = os.path.join(os.path.dirname(__file__), "..", "compositions", "frames")


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


def ground_css(fid):
    return f"""
    #{fid}-ground {{ position: absolute; inset: 0; background: {PAPER}; overflow: hidden; }}
    #{fid}-grid {{ position: absolute; left: 0; top: -108px; width: 1080px; height: 2136px;
      background-image:
        linear-gradient(to right, rgba(249,178,51,0.13) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(249,178,51,0.13) 1px, transparent 1px);
      background-size: 54px 54px; }}
    #{fid}-rule-top, #{fid}-rule-bottom {{ position: absolute; left: 54px; right: 54px; height: 1.5px; background: rgba(23,23,22,0.12); }}
    #{fid}-rule-top {{ top: 108px; }}
    #{fid}-rule-bottom {{ top: 1620px; }}
    #{fid}-meta-l, #{fid}-meta-r {{ position: absolute; top: 72px; font: 600 22px/1 "Hanken Grotesk", sans-serif;
      letter-spacing: 0.18em; text-transform: uppercase; color: {INK_SOFT}; }}
    #{fid}-meta-l {{ left: 54px; }}
    #{fid}-meta-r {{ right: 54px; }}
"""


def ground_html(fid, dur, meta_r):
    return f"""
    <div id="{fid}-ground" class="clip" data-start="0" data-duration="{dur}" data-track-index="0">
      <div id="{fid}-grid" data-layout-allow-overflow></div>
      <div id="{fid}-rule-top"></div>
      <div id="{fid}-rule-bottom"></div>
      <div id="{fid}-meta-l">facturac.ao</div>
      <div id="{fid}-meta-r">{meta_r}</div>
    </div>"""


def frame_doc(cid, fid, dur, css, body, js):
    return f"""<template>
  <style>
{FONT_FACES}
    #root {{ position: absolute; inset: 0; width: {W}px; height: {H}px; overflow: hidden;
      font-family: "Hanken Grotesk", sans-serif; color: {INK}; }}
{ground_css(fid)}
{css}
  </style>
  <div id="root" data-composition-id="{cid}" data-width="{W}" data-height="{H}">
{ground_html(fid, dur, body[1])}
    <div id="{fid}-stage" class="clip" data-start="0" data-duration="{dur}" data-track-index="1">
{body[0]}
    </div>
  </div>
  <script>
    (function () {{
      const tl = gsap.timeline({{ paused: true }});
      tl.set({{}}, {{}}, {dur});
      // Grid drifts upward the whole frame: the page breathes under the type.
      tl.fromTo("#{fid}-grid", {{ y: 0 }}, {{ y: -54, duration: {dur}, ease: "none" }}, 0);
{js}
      window.__timelines["{cid}"] = tl;
    }})();
  </script>
</template>
"""


def phrase(text, px, prefix, color=INK):
    """A phrase as one centred row of words, each word its own outline group."""
    words = text.split(" ")
    space = (T.advance(" ") + T.TRACKING) * px / T.EM
    parts, ids, total = [], [], 0.0
    for i, w in enumerate(words):
        h, wpx, _ = T.word_html(w, px, f"{prefix}-w{i}", color=color)
        parts.append(f'<div id="{prefix}-w{i}-box" class="{prefix}-word" style="position:relative">{h}</div>')
        ids.append(f"{prefix}-w{i}-box")
        total += wpx
    total += space * (len(words) - 1)
    row = f'<div id="{prefix}" style="display:flex;gap:{space:.2f}px;align-items:flex-start;justify-content:center;width:{W}px">' + "".join(parts) + "</div>"
    return row, ids, total


# ----------------------------------------------------------------- the word

WORD_PX = 180
K = WORD_PX / T.EM
WORD_BASELINE_Y = 1010
WORD_TOP = WORD_BASELINE_Y - T.ASCENT * K
WORD_TEXT = "facturação"
_glyphs, WORD_W = T.layout(WORD_TEXT)
WORD_LEFT = (W - WORD_W * K) / 2
AO_SHIFT = 29.47  # brand units: where "ao" sits in the logo vs. the unbroken word
GROUP_SHIFT = -AO_SHIFT / 2  # keep the finished wordmark centred
DOT_CX = 385.01  # brand units, from public/brand/facturac-ao.svg
DOT_R = 10.0
X_HEIGHT_TOP = 45.51


def word_block(fid):
    h, _, ids = T.word_html(WORD_TEXT, WORD_PX, f"{fid}-word")
    return (
        f'<div id="{fid}-cam" style="position:absolute;inset:0">'
        f'<div style="position:absolute;left:{WORD_LEFT:.2f}px;top:{WORD_TOP:.2f}px">{h}</div>'
        "</div>"
    ), ids


# ------------------------------------------------------------ Frame 1

def frame1():
    cid, (g0, dur) = "01-a-palavra", FRAMES["01-a-palavra"]
    fid = "frame-" + cid
    word, ids = word_block(fid)
    label = ["Todos", "os", "dias,", "em", "todo", "o", "negócio"]
    label_html = "".join(f'<span id="{fid}-l{i}" class="{fid}-lw">{w}</span>' for i, w in enumerate(label))
    css = f"""
    #{fid}-label {{ position: absolute; left: 0; right: 0; top: 690px; display: flex; justify-content: center; gap: 14px;
      font: 600 30px/1 "Hanken Grotesk", sans-serif; letter-spacing: 0.16em; text-transform: uppercase; color: {INK_SOFT}; }}
    .{fid}-lw {{ display: block; }}
    """
    body = (f'      <div id="{fid}-label">{label_html}</div>\n      {word}', "Brevemente")
    js = []
    for i in range(len(label)):
        js.append(f'tl.fromTo("#{fid}-l{i}", {{ opacity: 0, y: 22 }}, {{ opacity: 1, y: 0, duration: 0.34, ease: "power3.out" }}, {r(0.04 + i * 0.05)});')
    # Letters land on the eighth notes, each with a little character.
    tilts = [-7, 5, -4, 6, -5, 4, -6, 5, -4, 7]
    for i, gid in enumerate(ids):
        t = beat(0) + i * BEAT / 2 - g0
        js.append(
            f'tl.fromTo("#{gid}", {{ y: 150, opacity: 0, scaleY: 1.4, scaleX: 0.78, rotation: {tilts[i]}, transformOrigin: "50% 80%" }}, '
            f'{{ y: 0, opacity: 1, scaleY: 1, scaleX: 1, rotation: 0, duration: 0.44, ease: "back.out(2.6)" }}, {r(t)});'
        )
    last = beat(0) + 9 * BEAT / 2 - g0
    js.append(f'tl.fromTo("#{fid}-cam", {{ scale: 1 }}, {{ scale: 1.035, duration: 0.08, ease: "power2.out", transformOrigin: "50% 48%" }}, {r(last + 0.04)});')
    js.append(f'tl.to("#{fid}-cam", {{ scale: 1, duration: 0.45, ease: "power3.out" }}, {r(last + 0.12)});')
    js.append(f'tl.to("#{fid}-label", {{ opacity: 0, y: -26, duration: 0.3, ease: "power2.in" }}, 2.5);')
    # The two marks turn gold: this is where something is about to happen.
    for mark in ("g8-tilde", "g7-cedilla"):
        js.append(f'tl.to("#{fid}-word-{mark} path", {{ fill: "{GOLD}", duration: 0.25, ease: "power1.out" }}, 2.72);')
        js.append(f'tl.fromTo("#{fid}-word-{mark}", {{ rotation: 0, transformOrigin: "50% 50%" }}, {{ rotation: -9, duration: 0.09, ease: "power1.inOut" }}, 2.78);')
        js.append(f'tl.to("#{fid}-word-{mark}", {{ rotation: 7, duration: 0.11, ease: "power1.inOut" }}, 2.87);')
        js.append(f'tl.to("#{fid}-word-{mark}", {{ rotation: 0, duration: 0.12, ease: "power1.out" }}, 2.98);')
    return cid, frame_doc(cid, fid, dur, css, body, "      " + "\n      ".join(js))


# ------------------------------------------------------------ Frame 2

def frame2():
    cid, (g0, dur) = "02-a-licao", FRAMES["02-a-licao"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    word, ids = word_block(fid)
    caps = [("Tire o til.", 104), ("Tire a cedilha.", 104), ("Ponha um ponto.", 104), ("É só isso.", 104)]
    cap_html, cap_ids = [], []
    for i, (txt, px) in enumerate(caps):
        row, wids, _ = phrase(txt, px, f"{fid}-cap{i}")
        top = 520
        cap_html.append(f'<div id="{fid}-capbox{i}" style="position:absolute;left:0;top:{top}px">{row}</div>')
        cap_ids.append(wids)
    k = K
    left0 = WORD_LEFT
    dot_d = 2 * DOT_R * k
    # Dot landing point (final wordmark, after the group shifts).
    land_x = left0 + (DOT_CX + GROUP_SHIFT) * k
    land_y = WORD_TOP + (T.ASCENT - DOT_R) * k
    o_x = left0 + (_glyphs[9]["x"] + AO_SHIFT + GROUP_SHIFT + 32) * k
    a_x = left0 + (_glyphs[8]["x"] + AO_SHIFT + GROUP_SHIFT + 31.8) * k
    top_y = WORD_TOP + (X_HEIGHT_TOP - DOT_R) * k
    burst = "".join(f'<div class="{fid}-spark" id="{fid}-spark{i}"></div>' for i in range(10))
    ghosts = "".join(
        f'<svg id="{fid}-ghost{i}" class="{fid}-ghost" viewBox="0 0 {_glyphs[8]["adv"]:.2f} 125" '
        f'style="position:absolute;left:{left0 + _glyphs[8]["x"] * k:.2f}px;top:{WORD_TOP:.2f}px;width:{_glyphs[8]["adv"] * k:.2f}px;height:{125 * k:.2f}px;overflow:visible">'
        f'<path d="{_glyphs[8]["parts"][1][1]}" fill="{GOLD}"/></svg>'
        for i in range(4)
    )
    css = f"""
    #{fid}-dot {{ position: absolute; left: 0; top: 0; width: {dot_d:.2f}px; height: {dot_d:.2f}px; border-radius: 50%; background: {GOLD}; }}
    #{fid}-bloom {{ position: absolute; left: {land_x - 60:.2f}px; top: {land_y - 60:.2f}px; width: 120px; height: 120px; border-radius: 50%;
      background: radial-gradient(circle, rgba(249,178,51,0.55) 0%, rgba(249,178,51,0.18) 45%, rgba(249,178,51,0) 70%); }}
    .{fid}-spark {{ position: absolute; left: {land_x - 7:.2f}px; top: {land_y - 7:.2f}px; width: 14px; height: 14px; border-radius: 50%; background: {GOLD}; }}
    .{fid}-ghost {{ opacity: 0; }}
    """
    sum_tokens = [("op", "−"), ("w", "til"), ("op", "−"), ("w", "cedilha"), ("op", "+"), ("w", "ponto"), ("op", "="), ("w", "facturac.ao")]
    sum_html = "".join(
        f'<span id="{fid}-sum{i}" class="{fid}-sum-{kind}">{txt}</span>' for i, (kind, txt) in enumerate(sum_tokens)
    )
    css += f"""
    #{fid}-sum {{ position: absolute; left: 0; right: 0; top: 1300px; display: flex; justify-content: center; align-items: baseline; gap: 14px;
      font: 600 40px/1 "Hanken Grotesk", sans-serif; color: {INK_SOFT}; }}
    #{fid}-sum span {{ display: block; }}
    .{fid}-sum-op {{ color: {GOLD_DEEP}; font-weight: 700; }}
    #{fid}-sum7 {{ color: {INK}; font-weight: 700; }}
    """
    body = (
        f'      <div id="{fid}-sum">{sum_html}</div>\n      '
        + "\n      ".join(cap_html)
        + f'\n      <div id="{fid}-bloom"></div>\n      {burst}\n      {word}\n      {ghosts}\n      <div id="{fid}-dot"></div>',
        "Tire. Tire. Ponha.",
    )
    js = []
    # Handoff in: the marks are already gold, exactly as Frame 1 left them.
    for mark in ("g8-tilde", "g7-cedilla"):
        js.append(f'tl.set("#{fid}-word-{mark} path", {{ fill: "{GOLD}" }}, 0);')
    js.append(f'tl.set("#{fid}-bloom", {{ opacity: 0, scale: 0.2 }}, 0);')
    js.append(f'tl.set(".{fid}-spark", {{ opacity: 0 }}, 0);')
    js.append(f'tl.set("#{fid}-dot", {{ x: 1240, y: 360, opacity: 1 }}, 0);')

    def cap_in(i, t):
        for j, wid in enumerate(cap_ids[i]):
            js.append(f'tl.fromTo("#{wid}", {{ opacity: 0, scale: 1.45, y: -10 }}, {{ opacity: 1, scale: 1, y: 0, duration: 0.24, ease: "power4.out", transformOrigin: "50% 60%" }}, {r(t + j * 0.11)});')

    def cap_out(i, t):
        js.append(f'tl.to("#{fid}-capbox{i}", {{ opacity: 0, scale: 0.86, duration: 0.12, ease: "power2.in", transformOrigin: "50% 50%" }}, {r(t)});')

    # Scene 1 — Tire o til.
    cap_in(0, 0.0)
    til = f"#{fid}-word-g8-tilde"
    js.append(f'tl.to("{til}", {{ y: -26, rotation: -14, scale: 1.12, duration: 0.3, ease: "power2.out", transformOrigin: "50% 60%" }}, {r(L(6))});')
    js.append(f'tl.to("{til}", {{ x: 560, y: -900, rotation: 420, scale: 0.55, duration: 0.62, ease: "power2.in" }}, {r(L(7))});')
    for i in range(4):  # motion-blur streak: lagging ghosts of the tilde
        lag = 0.035 * (i + 1)
        js.append(f'tl.fromTo("#{fid}-ghost{i}", {{ opacity: 0, x: 0, y: -26, rotation: -14, scale: 1.12, transformOrigin: "50% 60%" }}, '
                  f'{{ opacity: {r(0.34 - i * 0.07, 2)}, x: 0, y: -26, rotation: -14, scale: 1.12, duration: 0.05 }}, {r(L(7) + lag)});')
        js.append(f'tl.to("#{fid}-ghost{i}", {{ x: 560, y: -900, rotation: 420, scale: 0.55, opacity: 0, duration: 0.57, ease: "power2.in" }}, {r(L(7) + lag + 0.052)});')
    js.append(f'tl.to("#{fid}-word-g8", {{ y: -18, duration: 0.12, ease: "power2.out" }}, {r(L(7) + 0.04)});')
    js.append(f'tl.to("#{fid}-word-g8", {{ y: 0, duration: 0.5, ease: "bounce.out" }}, {r(L(7) + 0.16)});')

    # Scene 2 — Tire a cedilha.
    cap_out(0, L(8) - 0.12)
    cap_in(1, L(8))
    ced = f"#{fid}-word-g7-cedilla"
    js.append(f'tl.to("{ced}", {{ rotation: -12, duration: 0.12, ease: "power1.inOut", transformOrigin: "40% 0%" }}, {r(L(9) + 0.2)});')
    js.append(f'tl.to("{ced}", {{ rotation: 10, duration: 0.14, ease: "power1.inOut" }}, {r(L(9) + 0.32)});')
    js.append(f'tl.to("{ced}", {{ y: 1250, rotation: 38, duration: 0.75, ease: "power2.in" }}, {r(L(10))});')
    js.append(f'tl.to("#{fid}-word-g7", {{ y: -24, duration: 0.13, ease: "power2.out" }}, {r(L(10) + 0.05)});')
    js.append(f'tl.to("#{fid}-word-g7", {{ y: 0, duration: 0.5, ease: "bounce.out" }}, {r(L(10) + 0.18)});')

    # Scene 3 — Ponha um ponto.
    cap_out(1, L(11) - 0.12)
    cap_in(2, L(11))
    for i in range(8):
        js.append(f'tl.to("#{fid}-word-g{i}", {{ x: {r(GROUP_SHIFT * k, 2)}, duration: 0.5, ease: "power3.inOut" }}, {r(L(12) - 0.1)});')
    for i in (8, 9):
        js.append(f'tl.to("#{fid}-word-g{i}", {{ x: {r((AO_SHIFT + GROUP_SHIFT) * k, 2)}, duration: 0.5, ease: "power3.inOut" }}, {r(L(12) - 0.1)});')
    # The dot bounces in: right edge -> top of "o" -> top of "a" -> into the gap on the drop.
    pts = [(1240, 360, L(12)), (o_x, top_y, L(13)), (a_x, top_y, L(14)), (land_x, land_y, L(15))]
    apex = [None, 300, 520, 560]
    for (x0, y0, t0), (x1, y1, t1), ap in zip(pts, pts[1:], apex[1:]):
        d = t1 - t0
        js.append(f'tl.to("#{fid}-dot", {{ x: {r(x1 - dot_d / 2, 2)}, duration: {r(d)}, ease: "none" }}, {r(t0)});')
        js.append(f'tl.to("#{fid}-dot", {{ y: {r(min(y0, y1) - ap - dot_d / 2, 2)}, duration: {r(d * 0.5)}, ease: "power2.out" }}, {r(t0)});')
        js.append(f'tl.to("#{fid}-dot", {{ y: {r(y1 - dot_d / 2, 2)}, duration: {r(d * 0.5)}, ease: "power2.in" }}, {r(t0 + d * 0.5)});')
        js.append(f'tl.to("#{fid}-dot", {{ scaleX: 1.45, scaleY: 0.62, duration: 0.05, ease: "power2.out", transformOrigin: "50% 100%" }}, {r(t1)});')
        js.append(f'tl.to("#{fid}-dot", {{ scaleX: 1, scaleY: 1, duration: 0.28, ease: "back.out(3)" }}, {r(t1 + 0.052)});')
        if ap != 560:  # the letter it lands on gives a little
            target = 9 if ap == 300 else 8
            js.append(f'tl.to("#{fid}-word-g{target}", {{ y: 10, duration: 0.06, ease: "power2.out" }}, {r(t1)});')
            js.append(f'tl.to("#{fid}-word-g{target}", {{ y: 0, duration: 0.3, ease: "back.out(3)" }}, {r(t1 + 0.06)});')
    land = L(15)
    js.append(f'tl.fromTo("#{fid}-bloom", {{ opacity: 0.9, scale: 0.2 }}, {{ opacity: 0, scale: 6.5, duration: 0.9, ease: "power2.out" }}, {r(land)});')
    for i in range(10):
        ang = math.radians(i * 36 + 18)
        dist = 150 + (i % 3) * 40
        js.append(f'tl.fromTo("#{fid}-spark{i}", {{ opacity: 1, x: 0, y: 0, scale: 1 }}, '
                  f'{{ opacity: 0, x: {r(math.cos(ang) * dist, 1)}, y: {r(math.sin(ang) * dist, 1)}, scale: 0.3, duration: 0.7, ease: "power3.out" }}, {r(land)});')
    js.append(f'tl.fromTo("#{fid}-cam", {{ scale: 1 }}, {{ scale: 1.045, duration: 0.07, ease: "power2.out", transformOrigin: "50% 48%" }}, {r(land)});')
    js.append(f'tl.to("#{fid}-cam", {{ scale: 1, duration: 0.6, ease: "power3.out" }}, {r(land + 0.07)});')

    for i, t in [(0, L(7) + 0.1), (1, L(7) + 0.18), (2, L(10) + 0.1), (3, L(10) + 0.18), (4, L(15) + 0.05), (5, L(15) + 0.13), (6, L(16) + 0.35), (7, L(16) + 0.45)]:
        js.append(f'tl.fromTo("#{fid}-sum{i}", {{ opacity: 0, y: 18, scale: 0.9 }}, {{ opacity: 1, y: 0, scale: 1, duration: 0.3, ease: "back.out(2.5)" }}, {r(t)});')
    # Scene 4 — É só isso. A beat of quiet, then the dot's little hop of satisfaction.
    cap_out(2, L(16) - 0.12)
    cap_in(3, L(16))
    js.append(f'tl.to("#{fid}-dot", {{ y: {r(land_y - dot_d / 2 - 70, 2)}, duration: 0.2, ease: "power2.out" }}, {r(L(18))});')
    js.append(f'tl.to("#{fid}-dot", {{ y: {r(land_y - dot_d / 2, 2)}, duration: 0.2, ease: "power2.in" }}, {r(L(18) + 0.2)});')
    js.append(f'tl.to("#{fid}-dot", {{ scaleX: 1.3, scaleY: 0.7, duration: 0.05, transformOrigin: "50% 100%" }}, {r(L(18) + 0.4)});')
    js.append(f'tl.to("#{fid}-dot", {{ scaleX: 1, scaleY: 1, duration: 0.25, ease: "back.out(3)" }}, {r(L(18) + 0.452)});')
    return cid, frame_doc(cid, fid, dur, css, body, "      " + "\n      ".join(js))


# ------------------------------------------------------------ Frame 3

def frame3():
    cid, (g0, dur) = "03-assinatura", FRAMES["03-assinatura"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    px = 150
    k = px / T.EM
    rows, js = [], []
    dot_d = 2 * DOT_R * k
    for i, w in enumerate(["Emitida", "Validada", "Paga"]):
        h, wpx, ids = T.word_html(w, px, f"{fid}-w{i}")
        top = 520 + i * 230
        left = (W - (wpx + 2 * DOT_R * k)) / 2
        rows.append(
            f'<div style="position:absolute;left:{left:.2f}px;top:{top}px">{h}'
            f'<div id="{fid}-dot{i}" class="{fid}-dot" style="position:absolute;left:{wpx:.2f}px;top:{(T.ASCENT - 2 * DOT_R) * k:.2f}px;width:{dot_d:.2f}px;height:{dot_d:.2f}px"></div></div>'
        )
        t = L(20 + i)
        for j, gid in enumerate(ids):
            js.append(f'tl.fromTo("#{gid}", {{ y: 70, opacity: 0, scaleY: 1.25, transformOrigin: "50% 90%" }}, '
                      f'{{ y: 0, opacity: 1, scaleY: 1, duration: 0.32, ease: "back.out(2.2)" }}, {r(t + j * 0.028)});')
        drop = t + len(ids) * 0.028 + 0.1
        js.append(f'tl.set("#{fid}-dot{i}", {{ opacity: 0 }}, 0);')
        js.append(f'tl.fromTo("#{fid}-dot{i}", {{ y: -260, opacity: 1 }}, {{ y: 0, opacity: 1, duration: 0.22, ease: "power2.in", immediateRender: false }}, {r(drop)});')
        js.append(f'tl.to("#{fid}-dot{i}", {{ scaleX: 1.45, scaleY: 0.62, duration: 0.05, transformOrigin: "50% 100%" }}, {r(drop + 0.221)});')
        js.append(f'tl.to("#{fid}-dot{i}", {{ scaleX: 1, scaleY: 1, duration: 0.26, ease: "back.out(3)" }}, {r(drop + 0.272)});')
    css = f"""
    .{fid}-dot {{ border-radius: 50%; background: {GOLD}; }}
    #{fid}-sub {{ position: absolute; left: 0; right: 0; top: 1265px; text-align: center; font: 600 30px/1 "Hanken Grotesk", sans-serif;
      letter-spacing: 0.16em; text-transform: uppercase; color: {INK_SOFT}; }}
    #{fid}-bar {{ position: absolute; left: 470px; top: 1225px; width: 140px; height: 6px; border-radius: 3px; background: {GOLD}; }}
    """
    js.append(f'tl.fromTo("#{fid}-bar", {{ scaleX: 0, transformOrigin: "50% 50%" }}, {{ scaleX: 1, duration: 0.4, ease: "power3.out" }}, {r(L(23))});')
    js.append(f'tl.fromTo("#{fid}-sub", {{ opacity: 0, y: 16 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }}, {r(L(23) + 0.08)});')
    body = ("\n      ".join(rows) + f'\n      <div id="{fid}-bar"></div>\n      <div id="{fid}-sub">Facturação electrónica para Angola</div>', "A assinatura")
    return cid, frame_doc(cid, fid, dur, css, body, "      " + "\n      ".join(js))


# ------------------------------------------------------------ Frame 4

def frame4():
    cid, (g0, dur) = "04-brevemente", FRAMES["04-brevemente"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    js = []
    # "Brevemente" with the dot as its full stop.
    px1 = 156
    k1 = px1 / T.EM
    h1, w1, ids1 = T.word_html("Brevemente", px1, f"{fid}-bre")
    left1 = (W - (w1 + 2 * DOT_R * k1)) / 2
    top1 = 600
    d1 = 2 * DOT_R * k1
    # The wordmark: the same letters and geometry as public/brand/facturac-ao.svg.
    px2 = 116
    k2 = px2 / T.EM
    wm_glyphs, _ = T.layout("facturacao")
    wm_w = 524.51 * k2
    left2 = (W - wm_w) / 2
    top2 = 930
    parts = []
    ids2 = []
    for i, g in enumerate(wm_glyphs):
        x = g["x"] + (AO_SHIFT if i >= 8 else 0)
        gid = f"{fid}-wm-g{i}"
        ids2.append(gid)
        parts.append(
            f'<svg id="{gid}" viewBox="0 0 {g["adv"]:.2f} 125" style="position:absolute;left:{x * k2:.2f}px;top:0;width:{g["adv"] * k2:.2f}px;height:{125 * k2:.2f}px;overflow:visible" aria-hidden="true">'
            f'<path d="{g["parts"][0][1]}" fill="{INK}"/></svg>'
        )
    d2 = 2 * DOT_R * k2
    wm = (
        f'<div aria-label="facturac.ao" style="position:absolute;left:{left2:.2f}px;top:{top2}px;width:{wm_w:.2f}px;height:{125 * k2:.2f}px">'
        + "".join(parts)
        + f'<div id="{fid}-wm-dot" class="{fid}-dot" style="position:absolute;left:{(DOT_CX - DOT_R) * k2:.2f}px;top:{(T.ASCENT - 2 * DOT_R) * k2:.2f}px;width:{d2:.2f}px;height:{d2:.2f}px"></div>'
        + "</div>"
    )
    css = f"""
    .{fid}-dot {{ border-radius: 50%; background: {GOLD}; }}
    #{fid}-sub {{ position: absolute; left: 0; right: 0; top: 1190px; text-align: center; font: 600 30px/1 "Hanken Grotesk", sans-serif;
      letter-spacing: 0.16em; text-transform: uppercase; color: {INK_SOFT}; }}
    #{fid}-sig {{ position: absolute; left: 0; right: 0; top: 1250px; text-align: center; font: 700 34px/1 "Hanken Grotesk", sans-serif; color: {INK}; }}
    #{fid}-sig span {{ display: inline-block; }}
    #{fid}-sig b {{ display: inline-block; width: 11px; height: 11px; border-radius: 50%; background: {GOLD}; margin: 0 16px 2px 3px; }}
    """
    body = (
        f'      <div style="position:absolute;left:{left1:.2f}px;top:{top1}px">{h1}'
        f'<div id="{fid}-bre-dot" class="{fid}-dot" style="position:absolute;left:{w1:.2f}px;top:{(T.ASCENT - 2 * DOT_R) * k1:.2f}px;width:{d1:.2f}px;height:{d1:.2f}px"></div></div>\n'
        f"      {wm}\n"
        f'      <div id="{fid}-sub">Facturação electrónica para Angola</div>\n'
        f'      <div id="{fid}-sig"><span>Emitida</span><b></b><span>Validada</span><b></b><span>Paga</span><b></b></div>',
        "Brevemente",
    )
    for j, gid in enumerate(ids1):
        js.append(f'tl.fromTo("#{gid}", {{ y: -120, opacity: 0, rotation: {(-1) ** j * 6} , transformOrigin: "50% 50%" }}, '
                  f'{{ y: 0, opacity: 1, rotation: 0, duration: 0.42, ease: "back.out(2.4)" }}, {r(0.02 + j * 0.035)});')
    t = L(26)
    js.append(f'tl.set("#{fid}-bre-dot, #{fid}-wm-dot", {{ opacity: 0 }}, 0);')
    js.append(f'tl.fromTo("#{fid}-bre-dot", {{ y: -620, opacity: 1 }}, {{ y: 0, opacity: 1, duration: 0.34, ease: "power2.in", immediateRender: false }}, {r(t - 0.34)});')
    js.append(f'tl.to("#{fid}-bre-dot", {{ scaleX: 1.5, scaleY: 0.6, duration: 0.05, transformOrigin: "50% 100%" }}, {r(t)});')
    js.append(f'tl.to("#{fid}-bre-dot", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "back.out(3)" }}, {r(t + 0.052)});')
    t2 = L(27)
    for j, gid in enumerate(ids2):
        js.append(f'tl.fromTo("#{gid}", {{ y: 90, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.36, ease: "back.out(2)" }}, {r(t2 + j * 0.045)});')
    t3 = L(29)
    js.append(f'tl.set("#{fid}-wm-dot", {{ x: 260, y: -240, opacity: 1 }}, {r(t3 - 0.5)});')
    js.append(f'tl.to("#{fid}-wm-dot", {{ x: 0, duration: 0.5, ease: "none" }}, {r(t3 - 0.5)});')
    js.append(f'tl.to("#{fid}-wm-dot", {{ y: -150, duration: 0.25, ease: "power2.out" }}, {r(t3 - 0.5)});')
    js.append(f'tl.to("#{fid}-wm-dot", {{ y: 0, duration: 0.25, ease: "power2.in" }}, {r(t3 - 0.25)});')
    js.append(f'tl.to("#{fid}-wm-dot", {{ scaleX: 1.5, scaleY: 0.6, duration: 0.05, transformOrigin: "50% 100%" }}, {r(t3)});')
    js.append(f'tl.to("#{fid}-wm-dot", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "back.out(3)" }}, {r(t3 + 0.052)});')
    js.append(f'tl.fromTo("#{fid}-sub", {{ opacity: 0, y: 14 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }}, {r(t3 + 0.2)});')
    js.append(f'tl.fromTo("#{fid}-sig span, #{fid}-sig b", {{ opacity: 0, y: 12 }}, {{ opacity: 1, y: 0, duration: 0.32, ease: "power3.out", stagger: 0.09 }}, {r(t3 + 0.45)});')
    return cid, frame_doc(cid, fid, dur, css, body, "      " + "\n      ".join(js))


if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    for build in (frame1, frame2, frame3, frame4):
        fid, html_doc = build()
        with open(os.path.join(OUT, f"{fid}.html"), "w") as fh:
            fh.write(html_doc)
        print("wrote", fid, len(html_doc), "bytes")
    print(json.dumps({"word_left": round(WORD_LEFT, 2), "word_width": round(WORD_W * K, 2), "word_top": round(WORD_TOP, 2)}))
