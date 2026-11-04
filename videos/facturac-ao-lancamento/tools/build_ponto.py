#!/usr/bin/env python3
"""Writes index.html and compositions/ for the launch cut of "O Ponto". Run from the project root.

The phone lives in the root composition so it can travel between scenes as the one
object the eye follows; the scenes carry the type. Inside the phone everything is
real: the home screen and the launch are simulator captures of the iPhone app, the
splash is the app's own, and Hoje shows the Distribuidora Ngola demo company
(tools/demo). The frame is Apple's iPhone 17 bezel (assets/apple, see SPEC.md).

Timings follow the track's beats (114.8 BPM) and the capture's own events: the icon
is pressed 3.0 s into assets/capture/launch-60.mp4, which plays from that press.
"""

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "tools"))
import type_outlines as T  # noqa: E402

W, H = 1080, 1920
INK, INK_SOFT, GOLD = "#171716", "#575756", "#f9b233"
END = 15.0
BEATS = [0.535, 1.055, 1.575, 2.105, 2.625, 3.145, 3.665, 4.185, 4.715, 5.235, 5.745, 6.275,
         6.795, 7.315, 7.845, 8.355, 8.885, 9.405, 9.935, 10.445, 10.965, 11.485, 12.015]
B = lambda n: BEATS[n]  # noqa: E731


def r(v, n=3):
    return round(v, n)


# ----------------------------------------------------------------- the phone

FRAME_W, FRAME_H = 1350, 2760
SCREEN_X, SCREEN_Y, SCREEN_W, SCREEN_H = 72, 69, 1206, 2622
ICON = (SCREEN_X + 865, SCREEN_Y + 2413)  # the app's dock icon, in frame pixels

# The capture: pressed at 3.0 s in the file, pressed at the dot's landing in the film.
TAP = B(6)
MEDIA_FROM = 2.9
LAUNCH_AT = TAP - (3.0 - MEDIA_FROM)
LAUNCH_LEN = 7.4 - MEDIA_FROM
HOJE_AT = TAP + (6.6 - 3.0)  # the still takes over once Hoje has settled


def placed(scale, x=None, y=None):
    """Top-left of the frame for a scale, centred horizontally unless told otherwise."""
    if x is None:
        x = (W - FRAME_W * scale) / 2
    return {"x": r(x, 2), "y": r(y, 2), "scale": scale}


def focus(scale, px, py, cx=W / 2, cy=H / 2):
    """Puts the frame point (px, py) at canvas (cx, cy) at this scale."""
    return {"x": r(cx - px * scale, 2), "y": r(cy - py * scale, 2), "scale": scale}


STATE_WORD = placed(0.5, y=600)
STATE_LAUNCH = placed(0.62, y=(H - FRAME_H * 0.62) / 2)
STATE_SPLASH = focus(0.899, SCREEN_X + SCREEN_W / 2, SCREEN_Y + SCREEN_H / 2)
STATE_HOJE = placed(0.56, y=330)
STATE_NUMBER = focus(0.84, SCREEN_X + 603, SCREEN_Y + 700, cy=980)

ICON_ON_CANVAS = (STATE_WORD["x"] + ICON[0] * 0.5, STATE_WORD["y"] + ICON[1] * 0.5)


def gsap_state(s):
    return f'x: {s["x"]}, y: {s["y"]}, scale: {s["scale"]}'


FONT_FACES = """
    @font-face { font-family: "Hanken Grotesk"; font-weight: 600; font-style: normal; font-display: block;
      src: url("assets/fonts/HankenGrotesk-600.woff2") format("woff2"); }
    @font-face { font-family: "Hanken Grotesk"; font-weight: 700; font-style: normal; font-display: block;
      src: url("assets/fonts/HankenGrotesk-700.woff2") format("woff2"); }
"""


def scene_doc(cid, dur, css, body, js):
    return f"""<template>
  <style>
{FONT_FACES}
    #root {{ position: absolute; inset: 0; width: {W}px; height: {H}px; overflow: hidden;
      font-family: "Hanken Grotesk", sans-serif; color: {INK}; }}
{css}
  </style>
  <div id="root" data-composition-id="{cid}" data-width="{W}" data-height="{H}">
    <div id="frame-{cid}-stage" class="clip" data-start="0" data-duration="{dur}" data-layout-allow-overflow>
{body}
    </div>
  </div>
  <script>
    (function () {{
      const tl = gsap.timeline({{ paused: true }});
      tl.set({{}}, {{}}, {dur});
{js}
      window.__timelines["{cid}"] = tl;
    }})();
  </script>
</template>
"""


# ------------------------------------------------------------- 01 the word

WORD_PX = 150
K = WORD_PX / T.EM
WORD_BASELINE = 330
WORD_TOP = WORD_BASELINE - T.ASCENT * K
WORD_TEXT = "facturação"
GLYPHS, WORD_W = T.layout(WORD_TEXT)
WORD_LEFT = (W - WORD_W * K) / 2
AO_SHIFT = 29.47  # brand units: where "ao" sits in the logo vs. the unbroken word
GROUP_SHIFT = -AO_SHIFT / 2
DOT_CX, DOT_R, X_HEIGHT_TOP = 385.01, 10.0, 45.51


def word_scene():
    cid, start, dur = "01-a-palavra", 0.0, 4.2
    fid = "frame-" + cid
    word, _, ids = T.word_html(WORD_TEXT, WORD_PX, f"{fid}-word")
    dot_d = 2 * DOT_R * K
    land_x = WORD_LEFT + (DOT_CX + GROUP_SHIFT) * K
    land_y = WORD_TOP + (T.ASCENT - DOT_R) * K
    o_x = WORD_LEFT + (GLYPHS[9]["x"] + AO_SHIFT + GROUP_SHIFT + 32) * K
    top_y = WORD_TOP + (X_HEIGHT_TOP - DOT_R) * K
    icon_x, icon_y = ICON_ON_CANVAS
    caps = ["Tire o til.", "Tire a cedilha.", "Ponha um ponto."]
    caps_html = "".join(f'<div id="{fid}-cap{i}" class="{fid}-cap">{c}</div>' for i, c in enumerate(caps))
    css = f"""
    #{fid}-word {{ position: absolute; left: {WORD_LEFT:.2f}px; top: {WORD_TOP:.2f}px; }}
    .{fid}-cap {{ position: absolute; left: 0; right: 0; top: 418px; text-align: center;
      font: 600 52px/1 "Hanken Grotesk", sans-serif; color: {INK_SOFT}; letter-spacing: -0.005em; opacity: 0; }}
    .{fid}-dot {{ position: absolute; left: 0; top: 0; width: {dot_d:.2f}px; height: {dot_d:.2f}px; border-radius: 50%; background: {GOLD}; }}
    #{fid}-tapdot {{ opacity: 0; }}
    #{fid}-ring {{ position: absolute; left: {icon_x - 60:.2f}px; top: {icon_y - 60:.2f}px; width: 120px; height: 120px;
      border-radius: 50%; border: 6px solid {GOLD}; opacity: 0; }}
    """
    body = (
        f'      <div id="{fid}-words">\n        <div id="{fid}-word">{word}</div>\n        {caps_html}\n      </div>\n'
        f'      <div id="{fid}-ring"></div>\n'
        f'      <div id="{fid}-dot" class="{fid}-dot"></div>\n'
        f'      <div id="{fid}-tapdot" class="{fid}-dot"></div>'
    )
    js = []
    js.append(f'tl.set("#{fid}-dot", {{ x: 1240, y: 180, opacity: 1 }}, 0);')
    js.append(f'tl.set("#{fid}-tapdot", {{ x: {r(land_x - dot_d / 2, 2)}, y: {r(land_y - dot_d / 2, 2)} }}, 0);')

    def cap(i, t_in, t_out):
        js.append(f'tl.fromTo("#{fid}-cap{i}", {{ opacity: 0, y: 18 }}, {{ opacity: 1, y: 0, duration: 0.28, ease: "power3.out" }}, {r(t_in)});')
        js.append(f'tl.to("#{fid}-cap{i}", {{ opacity: 0, y: -14, duration: 0.16, ease: "power2.in" }}, {r(t_out - 0.16)});')

    # The marks turn gold: they are about to leave.
    for mark in ("g8-tilde", "g7-cedilla"):
        js.append(f'tl.to("#{fid}-word-{mark} path", {{ fill: "{GOLD}", duration: 0.2, ease: "power1.out" }}, 0.22);')

    # Tire o til.
    cap(0, B(0) - 0.08, B(2))
    til = f"#{fid}-word-g8-tilde"
    js.append(f'tl.to("{til}", {{ y: -26, rotation: -14, scale: 1.12, duration: 0.22, ease: "power2.out", transformOrigin: "50% 60%" }}, {r(B(0) - 0.2)});')
    js.append(f'tl.to("{til}", {{ x: 560, y: -700, rotation: 420, scale: 0.55, duration: 0.6, ease: "power2.in" }}, {r(B(0) + 0.02)});')
    js.append(f'tl.to("#{fid}-word-g8", {{ y: -16, duration: 0.12, ease: "power2.out" }}, {r(B(0) + 0.06)});')
    js.append(f'tl.to("#{fid}-word-g8", {{ y: 0, duration: 0.45, ease: "bounce.out" }}, {r(B(0) + 0.18)});')

    # Tire a cedilha.
    cap(1, B(2) - 0.06, B(4))
    ced = f"#{fid}-word-g7-cedilla"
    js.append(f'tl.to("{ced}", {{ rotation: -12, duration: 0.1, ease: "power1.inOut", transformOrigin: "40% 0%" }}, {r(B(2) - 0.22)});')
    js.append(f'tl.to("{ced}", {{ rotation: 10, duration: 0.12, ease: "power1.inOut" }}, {r(B(2) - 0.12)});')
    js.append(f'tl.to("{ced}", {{ y: 1700, rotation: 38, duration: 0.8, ease: "power2.in" }}, {r(B(2))});')
    js.append(f'tl.to("{ced}", {{ opacity: 0, duration: 0.15 }}, {r(B(2) + 0.65)});')
    js.append(f'tl.to("#{fid}-word-g7", {{ y: -20, duration: 0.12, ease: "power2.out" }}, {r(B(2) + 0.05)});')
    js.append(f'tl.to("#{fid}-word-g7", {{ y: 0, duration: 0.45, ease: "bounce.out" }}, {r(B(2) + 0.17)});')

    # Ponha um ponto: "ao" opens, the dot bounces in off the "o".
    cap(2, B(4) - 0.06, B(6))
    for i in range(8):
        js.append(f'tl.to("#{fid}-word-g{i}", {{ x: {r(GROUP_SHIFT * K, 2)}, duration: 0.42, ease: "power3.inOut" }}, {r(B(3) - 0.1)});')
    for i in (8, 9):
        js.append(f'tl.to("#{fid}-word-g{i}", {{ x: {r((AO_SHIFT + GROUP_SHIFT) * K, 2)}, duration: 0.42, ease: "power3.inOut" }}, {r(B(3) - 0.1)});')
    hops = [((1240, 180), B(3)), ((o_x, top_y), (B(3) + B(4)) / 2), ((land_x, land_y), B(4))]
    for ((x0, y0), t0), ((x1, y1), t1), apex in zip(hops, hops[1:], [180, 220]):
        d = t1 - t0
        js.append(f'tl.to("#{fid}-dot", {{ x: {r(x1 - dot_d / 2, 2)}, duration: {r(d)}, ease: "none" }}, {r(t0)});')
        js.append(f'tl.to("#{fid}-dot", {{ y: {r(min(y0, y1) - apex - dot_d / 2, 2)}, duration: {r(d / 2)}, ease: "power2.out" }}, {r(t0)});')
        js.append(f'tl.to("#{fid}-dot", {{ y: {r(y1 - dot_d / 2, 2)}, duration: {r(d / 2)}, ease: "power2.in" }}, {r(t0 + d / 2)});')
        js.append(f'tl.to("#{fid}-dot", {{ scaleX: 1.45, scaleY: 0.62, duration: 0.05, ease: "power2.out", transformOrigin: "50% 100%" }}, {r(t1)});')
        js.append(f'tl.to("#{fid}-dot", {{ scaleX: 1, scaleY: 1, duration: 0.2, ease: "back.out(3)" }}, {r(t1 + 0.05)});')
    js.append(f'tl.to("#{fid}-word-g9", {{ y: 8, duration: 0.06, ease: "power2.out" }}, {r(hops[1][1])});')
    js.append(f'tl.to("#{fid}-word-g9", {{ y: 0, duration: 0.28, ease: "back.out(3)" }}, {r(hops[1][1] + 0.06)});')

    # A second dot leaves the finished name and taps the app open.
    t0, t1 = B(5), TAP
    js.append(f'tl.set("#{fid}-tapdot", {{ opacity: 1 }}, {r(t0)});')
    js.append(f'tl.to("#{fid}-tapdot", {{ x: {r(icon_x - dot_d / 2, 2)}, duration: {r(t1 - t0)}, ease: "power1.inOut" }}, {r(t0)});')
    js.append(f'tl.to("#{fid}-tapdot", {{ y: {r(land_y - 200 - dot_d / 2, 2)}, duration: {r((t1 - t0) * 0.32)}, ease: "power2.out" }}, {r(t0)});')
    js.append(f'tl.to("#{fid}-tapdot", {{ y: {r(icon_y - dot_d / 2, 2)}, duration: {r((t1 - t0) * 0.68)}, ease: "power2.in" }}, {r(t0 + (t1 - t0) * 0.32)});')
    js.append(f'tl.to("#{fid}-tapdot", {{ scale: 0, duration: 0.12, ease: "power2.in" }}, {r(t1)});')
    js.append(f'tl.fromTo("#{fid}-ring", {{ opacity: 0.95, scale: 0.35 }}, {{ opacity: 0, scale: 1.9, duration: 0.5, ease: "power2.out" }}, {r(t1)});')

    # Out of the way of the launch.
    js.append(f'tl.to("#{fid}-words, #{fid}-dot", {{ y: "-=160", opacity: 0, duration: 0.3, ease: "power2.in" }}, {r(TAP + 0.05)});')
    return cid, start, dur, scene_doc(cid, dur, css, body, "      " + "\n      ".join(js))


# ------------------------------------------------------------ 02 the claim

def claim_scene():
    cid, start, dur = "02-no-bolso", 7.3, 2.95
    fid = "frame-" + cid
    text = "A sua empresa no bolso."
    px = 68
    words = text.split(" ")
    space = (T.advance(" ") + T.TRACKING) * px / T.EM
    parts = []
    for i, w in enumerate(words):
        h, _, _ = T.word_html(w, px, f"{fid}-w{i}")
        parts.append(f'<div id="{fid}-w{i}" style="position:relative">{h}</div>')
    css = f"""
    #{fid}-line {{ position: absolute; left: 0; top: 168px; width: {W}px; display: flex; justify-content: center; gap: {space:.2f}px; }}
    """
    body = f'      <div id="{fid}-line">{"".join(parts)}</div>'
    js = []
    for i in range(len(words)):
        js.append(f'tl.fromTo("#{fid}-w{i}", {{ opacity: 0, y: 34 }}, {{ opacity: 1, y: 0, duration: 0.34, ease: "power3.out" }}, {r(0.2 + i * 0.07)});')
    js.append(f'tl.to("#{fid}-line", {{ opacity: 0, y: -24, duration: 0.24, ease: "power2.in" }}, {r(dur - 0.3)});')
    return cid, start, dur, scene_doc(cid, dur, css, body, "      " + "\n      ".join(js))


# --------------------------------------------------------------- 03 the end

def end_scene():
    cid, start, dur = "03-disponivel", 10.2, END - 10.2
    fid = "frame-" + cid
    px = 172
    k = px / T.EM
    word, w1, _ = T.word_html("Disponível", px, f"{fid}-word")
    dot_d = 2 * DOT_R * k
    total = w1 + dot_d * 0.9
    left = (W - total) / 2
    baseline = 860
    top = baseline - T.ASCENT * k
    dot_x = left + w1
    dot_y = top + (T.ASCENT - 2 * DOT_R) * k
    land = B(20) - start
    mark_w = 470
    mark_h = mark_w * 80.4 / 524
    css = f"""
    #{fid}-word {{ position: absolute; left: {left:.2f}px; top: {top:.2f}px; }}
    #{fid}-dot {{ position: absolute; left: {dot_x:.2f}px; top: {dot_y:.2f}px; width: {dot_d:.2f}px; height: {dot_d:.2f}px; border-radius: 50%; background: {GOLD}; }}
    #{fid}-mark {{ position: absolute; left: {(W - mark_w) / 2:.2f}px; top: 990px; width: {mark_w}px; height: {mark_h:.2f}px; }}
    #{fid}-cta {{ position: absolute; left: 50%; top: 1170px; transform: translateX(-50%); padding: 30px 58px; border-radius: 999px;
      background: {GOLD}; color: {INK}; font: 600 44px/1 "Hanken Grotesk", sans-serif; white-space: nowrap;
      box-shadow: 0 14px 30px rgba(249,178,51,0.32); }}
    """
    body = (
        f'      <div id="{fid}-word">{word}</div>\n'
        f'      <div id="{fid}-dot"></div>\n'
        f'      <img id="{fid}-mark" src="assets/facturac-ao.svg" alt="facturac.ao">\n'
        f'      <div id="{fid}-cta-wrap"><div id="{fid}-cta">Abra a sua conta grátis</div></div>'
    )
    js = []
    js.append(f'tl.fromTo("#{fid}-word", {{ opacity: 0, y: 46 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }}, {r(land - 0.5)});')
    js.append(f'tl.fromTo("#{fid}-dot", {{ y: -900 }}, {{ y: 0, duration: 0.36, ease: "power2.in", immediateRender: true }}, {r(land - 0.36)});')
    js.append(f'tl.to("#{fid}-dot", {{ scaleX: 1.5, scaleY: 0.6, duration: 0.05, transformOrigin: "50% 100%" }}, {r(land)});')
    js.append(f'tl.to("#{fid}-dot", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "back.out(3)" }}, {r(land + 0.05)});')
    js.append(f'tl.fromTo("#{fid}-mark", {{ clipPath: "inset(0 100% 0 0)" }}, {{ clipPath: "inset(0 0% 0 0)", duration: 0.55, ease: "power3.out" }}, {r(land + 0.45)});')
    js.append(f'tl.fromTo("#{fid}-cta-wrap", {{ opacity: 0, y: 30, scale: 0.94 }}, {{ opacity: 1, y: 0, scale: 1, duration: 0.42, ease: "back.out(1.8)", transformOrigin: "50% 1200px" }}, {r(land + 1.0)});')
    return cid, start, dur, scene_doc(cid, dur, css, body, "      " + "\n      ".join(js))


# ------------------------------------------------------------------- root

SFX = [  # (file, at, volume)
    ("whoosh-up", B(0) - 0.05, 0.22),
    ("drop-thud", B(2) + 0.55, 0.18),
    ("bounce-pop", (B(3) + B(4)) / 2, 0.16),
    ("pop-confirm", B(4), 0.22),
    ("pop-soft", TAP, 0.3),
    ("whoosh-soft", TAP + 0.12, 0.2),
    ("pop-soft", 7.5, 0.12),
    ("whoosh-soft", 10.2, 0.16),
    ("impact-soft", B(20), 0.26),
    ("pop-confirm", B(20) + 1.0, 0.16),
]
SFX_LEN = {"whoosh-up": 1.579, "drop-thud": 0.792, "bounce-pop": 0.8, "pop-confirm": 0.575, "pop-soft": 0.575, "whoosh-soft": 1.579, "impact-soft": 2.0}


def root(scenes):
    hosts = "\n".join(
        f'      <div id="el-{cid}" class="scene" data-composition-id="{cid}" data-composition-src="compositions/{cid}.html" '
        f'data-start="{r(start)}" data-duration="{r(dur)}" data-track-index="{2 + i}" data-track-kind="graphics"></div>'
        for i, (cid, start, dur, _) in enumerate(scenes)
    )
    sfx = "\n".join(
        f'      <audio id="sfx-{i}" src="assets/sfx/{name}.mp3" data-start="{r(at)}" data-duration="{SFX_LEN[name]}" data-volume="{vol}" data-track-index="{10 + i % 3}"></audio>'
        for i, (name, at, vol) in enumerate(SFX)
    )
    js = []
    js.append(f'tl.set("#phone", {{ {gsap_state(STATE_WORD)}, transformOrigin: "0 0" }}, 0);')
    js.append(f'tl.to("#phone", {{ {gsap_state(STATE_LAUNCH)}, duration: 0.7, ease: "power3.inOut" }}, {r(TAP + 0.05)});')
    js.append(f'tl.to("#phone", {{ {gsap_state(STATE_SPLASH)}, duration: 0.5, ease: "power3.inOut" }}, 4.6);')
    js.append(f'tl.to("#phone", {{ {gsap_state(STATE_HOJE)}, duration: 0.6, ease: "power3.inOut" }}, 6.75);')
    js.append(f'tl.to("#phone", {{ {gsap_state(STATE_NUMBER)}, duration: 0.6, ease: "power3.inOut" }}, 8.6);')
    js.append(f'tl.to("#phone", {{ y: {H + 120}, duration: 0.48, ease: "power2.in" }}, 10.0);')
    return f"""<!doctype html>
<html lang="pt">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width={W}, height={H}" />
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js" integrity="sha384-sG0Hv1tP1lZCk9KQmrIbY/XNwi+OY84GQqhMscbnsoBFqAz8KNCil1kvfL3Hbbk2" crossorigin="anonymous"></script>
    <style>
      * {{ margin: 0; padding: 0; box-sizing: border-box; }}
      html, body {{ width: {W}px; height: {H}px; overflow: hidden; background: #fff; }}
      #root {{ position: relative; width: {W}px; height: {H}px; overflow: hidden; background: #ffffff; }}
      .scene {{ position: absolute; inset: 0; width: 100%; height: 100%; }}
      #phone {{ position: absolute; left: 0; top: 0; width: {FRAME_W}px; height: {FRAME_H}px;
        filter: drop-shadow(0 46px 70px rgba(0,0,0,0.20)) drop-shadow(0 10px 18px rgba(0,0,0,0.14)); }}
      #screen {{ position: absolute; left: {SCREEN_X}px; top: {SCREEN_Y}px; width: {SCREEN_W}px; height: {SCREEN_H}px;
        overflow: hidden; border-radius: 150px; background: #fff; }}
      #screen img, #screen video {{ position: absolute; inset: 0; width: {SCREEN_W}px; height: {SCREEN_H}px; display: block; }}
      #bezel {{ position: absolute; inset: 0; width: {FRAME_W}px; height: {FRAME_H}px; }}
    </style>
  </head>
  <body>
    <div id="root" data-composition-id="main" data-start="0" data-duration="{END}" data-width="{W}" data-height="{H}">
      <div id="phone" data-layout-allow-overflow>
        <div id="screen">
          <img id="shot-home" class="clip" src="assets/capture/home.png" alt="" data-start="0" data-duration="{r(LAUNCH_AT + 0.1)}" data-track-index="0">
          <video id="shot-launch" class="clip" src="assets/capture/launch-60.mp4" muted playsinline data-start="{r(LAUNCH_AT)}" data-duration="{r(LAUNCH_LEN)}" data-media-start="{MEDIA_FROM}" data-track-index="0"></video>
          <img id="shot-hoje" class="clip" src="assets/capture/hoje.png" alt="" data-start="{r(HOJE_AT)}" data-duration="{r(END - HOJE_AT)}" data-track-index="1">
        </div>
        <img id="bezel" src="assets/apple/iphone-17-black.png" alt="">
      </div>
{hosts}
      <audio id="music" src="assets/bgm/track.mp3" data-start="0" data-duration="{END}" data-volume="0.9" data-fade-out="1.2" data-track-index="9"></audio>
{sfx}
    </div>
    <script>
      window.__timelines = window.__timelines || {{}};
      const tl = gsap.timeline({{ paused: true }});
      tl.set({{}}, {{}}, {END});
      {chr(10).join("      " + line for line in js).lstrip()}
      window.__timelines["main"] = tl;
    </script>
  </body>
</html>
"""


def main():
    scenes = [word_scene(), claim_scene(), end_scene()]
    out = ROOT / "compositions"
    out.mkdir(exist_ok=True)
    for cid, _, _, doc in scenes:
        (out / f"{cid}.html").write_text(doc)
    (ROOT / "index.html").write_text(root(scenes))
    print("wrote index.html and", ", ".join(cid for cid, *_ in scenes))


if __name__ == "__main__":
    main()
