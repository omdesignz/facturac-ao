"""Builds the four frame sub-compositions of "O Endereço" (proposal B).

A phone sits on the cream canvas. Someone types "facturação" in its address
bar, the browser refuses, and the instructions above the phone are carried out
in the bar, keystroke by keystroke, until it reads facturac.ao. The address
text is Century Gothic Bold as outlines (type_outlines.py), so the til and the
cedilha can leave their letters; the dot that lands in the bar is the full stop
of the caption "Ponha um ponto." itself.

Beat grid of the track: 109.96 BPM, beat 0 at 0.464s, bars on beats 3, 7, 11...
The drop is the bar at 8.649s (beat 15): the dot lands on it.

Run from the project root:  python3 tools/build_frames.py
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(__file__))
import type_outlines as T  # noqa: E402

W, H = 1080, 1920
INK, MUTED, LIGHT, CREAM, GOLD, GOLD_DEEP = "#171716", "#575756", "#92928c", "#fafaf9", "#f9b233", "#d87706"
RED, RED_BG, LIME_BG, LIME_INK = "#dc2626", "#fef2f2", "#d9f99d", "#3f6212"
BEAT = 60 / 109.96
B0 = 0.464
FRAMES = {
    "01-endereco": (0.0, 4.284),
    "02-correccao": (4.284, 8.731),
    "03-pagina": (13.015, 6.549),
    "04-brevemente": (19.564, 4.436),
}
OUT = os.path.join(os.path.dirname(__file__), "..", "compositions", "frames")


def beat(k):
    return B0 + k * BEAT


def r(v, n=3):
    return round(v, n)


# ---------------------------------------------------------------- geometry

PHONE = dict(left=240, top=330, w=600, h=1220, radius=78, bezel=14)
SCREEN = dict(left=PHONE["left"] + PHONE["bezel"], top=PHONE["top"] + PHONE["bezel"],
              w=PHONE["w"] - 2 * PHONE["bezel"], h=PHONE["h"] - 2 * PHONE["bezel"])
BAR = dict(left=SCREEN["left"] + 20, top=SCREEN["top"] + 62, w=SCREEN["w"] - 40, h=96)
BAR_CY = BAR["top"] + BAR["h"] / 2
TEXT_PX = 52
TK = TEXT_PX / T.EM
TEXT_LEFT = BAR["left"] + 78
TEXT_BASELINE = BAR_CY + 19
TEXT_TOP = TEXT_BASELINE - T.ASCENT * TK
ZOOM = 1.6
ZOOM_Y = 820 - BAR_CY  # the zoomed bar sits at y=820
AO_SHIFT = 29.47
DOT_CX, DOT_R = 385.01, 10.0
WORD = "facturação"
GLYPHS, _ = T.layout(WORD)


def zoomed(x, y):
    """Where a point inside the camera lands on screen while zoomed in."""
    return 540 + (x - 540) * ZOOM, BAR_CY + (y - BAR_CY) * ZOOM + ZOOM_Y


FONT_FACES = """
    @font-face { font-family: "Hanken Grotesk"; font-weight: 500; font-style: normal; font-display: block;
      src: url("assets/fonts/HankenGrotesk-500.woff2") format("woff2"); }
    @font-face { font-family: "Hanken Grotesk"; font-weight: 600; font-style: normal; font-display: block;
      src: url("assets/fonts/HankenGrotesk-600.woff2") format("woff2"); }
    @font-face { font-family: "Hanken Grotesk"; font-weight: 700; font-style: normal; font-display: block;
      src: url("assets/fonts/HankenGrotesk-700.woff2") format("woff2"); }
"""


def ground(fid, dur):
    css = f"""
    #{fid}-ground {{ position: absolute; inset: 0; background: {CREAM}; overflow: hidden; }}
    #{fid}-bloom {{ position: absolute; left: -260px; top: 260px; width: 1600px; height: 1600px; border-radius: 50%;
      background: radial-gradient(circle, rgba(249,178,51,0.20) 0%, rgba(249,178,51,0.07) 38%, rgba(249,178,51,0) 66%); }}
    #{fid}-meta-l, #{fid}-meta-r {{ position: absolute; top: 72px; font: 600 22px/1 "Hanken Grotesk", sans-serif;
      letter-spacing: 0.18em; text-transform: uppercase; color: {MUTED}; }}
    #{fid}-meta-l {{ left: 54px; }}
    #{fid}-meta-r {{ right: 54px; }}
    """
    html = f"""
    <div id="{fid}-ground" class="clip" data-start="0" data-duration="{dur}" data-track-index="0">
      <div id="{fid}-bloom" data-layout-allow-overflow></div>
      <div id="{fid}-meta-l">facturac.ao</div>
      <div id="{fid}-meta-r">Brevemente</div>
    </div>"""
    js = f'tl.fromTo("#{fid}-bloom", {{ scale: 0.96 }}, {{ scale: 1.04, duration: {dur}, ease: "sine.inOut", transformOrigin: "50% 50%" }}, 0);'
    return css, html, js


def frame_doc(cid, fid, dur, css, stage, js):
    gcss, ghtml, gjs = ground(fid, dur)
    return f"""<template>
  <style>
{FONT_FACES}
    #root {{ position: absolute; inset: 0; width: {W}px; height: {H}px; overflow: hidden;
      font-family: "Hanken Grotesk", sans-serif; color: {INK}; }}
{gcss}
{css}
  </style>
  <div id="root" data-composition-id="{cid}" data-width="{W}" data-height="{H}">
{ghtml}
    <div id="{fid}-stage" class="clip" data-start="0" data-duration="{dur}" data-track-index="1">
{stage}
    </div>
  </div>
  <script>
    (function () {{
      const tl = gsap.timeline({{ paused: true }});
      tl.set({{}}, {{}}, {dur});
      {gjs}
      {js}
      window.__timelines["{cid}"] = tl;
    }})();
  </script>
</template>
"""


def phrase(text, px, prefix, color=INK):
    words = text.split(" ")
    space = (T.advance(" ") + T.TRACKING) * px / T.EM
    parts, ids, widths = [], [], []
    for i, w in enumerate(words):
        h, wpx, gids = T.word_html(w, px, f"{prefix}-w{i}", color=color)
        parts.append(f'<div id="{prefix}-w{i}-box" style="position:relative">{h}</div>')
        ids.append(f"{prefix}-w{i}-box")
        widths.append((wpx, gids))
    total = sum(w for w, _ in widths) + space * (len(words) - 1)
    row = f'<div id="{prefix}" style="display:flex;gap:{space:.2f}px;justify-content:center;width:{W}px">' + "".join(parts) + "</div>"
    return row, ids, total, widths, space


def phone_css(fid):
    p, s, b = PHONE, SCREEN, BAR
    return f"""
    #{fid}-cam {{ position: absolute; inset: 0; }}
    #{fid}-phone {{ position: absolute; left: {p["left"]}px; top: {p["top"]}px; width: {p["w"]}px; height: {p["h"]}px;
      border-radius: {p["radius"]}px; background: {INK};
      box-shadow: 0 60px 120px -40px rgba(23,23,22,0.45), 0 24px 40px -24px rgba(23,23,22,0.35); }}
    #{fid}-screen {{ position: absolute; left: {s["left"]}px; top: {s["top"]}px; width: {s["w"]}px; height: {s["h"]}px;
      border-radius: {p["radius"] - p["bezel"]}px; background: #ffffff; overflow: hidden; }}
    #{fid}-notch {{ position: absolute; left: {540 - 70}px; top: {s["top"] + 16}px; width: 140px; height: 30px; border-radius: 15px; background: {INK}; }}
    #{fid}-bar {{ position: absolute; left: {b["left"]}px; top: {b["top"]}px; width: {b["w"]}px; height: {b["h"]}px;
      border-radius: {b["h"] / 2}px; background: #f3f3f1; box-shadow: inset 0 0 0 2px rgba(23,23,22,0.10); overflow: hidden; }}
    #{fid}-bar-ring {{ position: absolute; left: {b["left"] - 3}px; top: {b["top"] - 3}px; width: {b["w"] + 6}px; height: {b["h"] + 6}px;
      border-radius: {b["h"] / 2 + 3}px; box-shadow: inset 0 0 0 4px {RED}; opacity: 0; }}
    #{fid}-progress {{ position: absolute; left: 0; bottom: 0; width: 100%; height: 7px; background: {GOLD}; }}
    #{fid}-icon {{ position: absolute; left: {b["left"] + 28}px; top: {BAR_CY - 17}px; width: 34px; height: 34px; }}
    #{fid}-caret {{ position: absolute; left: 0; top: {TEXT_BASELINE - 54}px; width: 4px; height: 64px; border-radius: 2px; background: {INK}; }}
    """


def phone_html(fid, text_html, extra_screen="", icon="search"):
    search = (f'<svg id="{fid}-search" viewBox="0 0 34 34" style="position:absolute;inset:0"><circle cx="14" cy="14" r="9.5" fill="none" stroke="{MUTED}" stroke-width="3.4"/>'
              f'<path d="M21 21 L30 30" stroke="{MUTED}" stroke-width="3.6" stroke-linecap="round"/></svg>')
    lock = (f'<svg id="{fid}-lock" viewBox="0 0 34 34" style="position:absolute;inset:0"><rect x="6" y="15" width="22" height="16" rx="4" fill="{INK}"/>'
            f'<path d="M11 15 V11 a6 6 0 0 1 12 0 V15" fill="none" stroke="{INK}" stroke-width="3.4"/></svg>')
    return f"""
      <div id="{fid}-cam" data-layout-allow-overflow>
        <div id="{fid}-phone" data-layout-allow-overflow></div>
        <div id="{fid}-screen" data-layout-allow-overflow>{extra_screen}</div>
        <div id="{fid}-notch"></div>
        <div id="{fid}-bar"><div id="{fid}-progress"></div></div>
        <div id="{fid}-bar-ring"></div>
        <div id="{fid}-icon">{search}{lock}</div>
        <div style="position:absolute;left:{TEXT_LEFT}px;top:{TEXT_TOP:.2f}px">{text_html}</div>
        <div id="{fid}-caret"></div>
      </div>"""


def address(fid, with_marks=True, final=False):
    """The bar's text: one outline glyph per letter, positioned by brand geometry."""
    parts = []
    for i, g in enumerate(GLYPHS):
        x = g["x"] + (AO_SHIFT if final and i >= 8 else 0)
        gid = f"{fid}-t{i}"
        inner = []
        for role, d in g["parts"]:
            if role != "base" and not with_marks:
                continue
            inner.append(f'<svg id="{gid}-{role}" viewBox="0 0 {g["adv"]:.2f} 125" style="position:absolute;left:0;top:0;width:{g["adv"] * TK:.2f}px;height:{125 * TK:.2f}px;overflow:visible" aria-hidden="true"><path d="{d}" fill="{INK}"/></svg>')
        parts.append(f'<div id="{gid}" style="position:absolute;left:{x * TK:.2f}px;top:0;width:{g["adv"] * TK:.2f}px;height:{125 * TK:.2f}px">{"".join(inner)}</div>')
    dot = f'<div id="{fid}-bar-dot" style="position:absolute;left:{(DOT_CX - DOT_R) * TK:.2f}px;top:{(T.ASCENT - 2 * DOT_R) * TK:.2f}px;width:{2 * DOT_R * TK:.2f}px;height:{2 * DOT_R * TK:.2f}px;border-radius:50%;background:{GOLD}"></div>'
    return f'<div aria-label="{"facturac.ao" if final else WORD}" style="position:relative;width:{(524.51 if final else 495.04) * TK:.2f}px;height:{125 * TK:.2f}px">' + "".join(parts) + dot + "</div>"


def caret_x(after_glyph, shifted=False):
    """Caret position (camera coords) just after glyph i (-1 = before the first)."""
    if after_glyph < 0:
        return TEXT_LEFT - 2
    g = GLYPHS[after_glyph]
    x = g["x"] + g["adv"] + (AO_SHIFT if shifted and after_glyph >= 8 else 0) + T.TRACKING / 2
    return TEXT_LEFT + x * TK - 2


def blink(js, sel, t0, t1, period=BEAT):
    """A finite caret blink between t0 and t1 (no repeat: the renderer seeks)."""
    t, on = t0, True
    while t < t1:
        js.append(f'tl.set("{sel}", {{ opacity: {1 if on else 0} }}, {r(t)});')
        t += period / 2
        on = not on
    js.append(f'tl.set("{sel}", {{ opacity: 1 }}, {r(t1)});')


# ------------------------------------------------------------ Frame 1

def frame1():
    cid, (g0, dur) = "01-endereco", FRAMES["01-endereco"]
    fid = "frame-" + cid
    js = []
    q, qids, _, _, _ = phrase("Onde fica a sua facturação?", 72, f"{fid}-q")
    toast_css = f"""
    #{fid}-toast {{ position: absolute; left: {BAR["left"] + 26}px; top: {BAR["top"] + BAR["h"] + 22}px; padding: 14px 22px; border-radius: 16px;
      background: {RED_BG}; color: #b91c1c; font: 600 25px/1.2 "Hanken Grotesk", sans-serif; box-shadow: inset 0 0 0 2px rgba(220,38,38,0.18); }}
    .{fid}-under {{ position: absolute; top: {TEXT_BASELINE + 14}px; height: 5px; border-radius: 3px; background: {RED}; }}
    """
    unders = "".join(
        f'<div id="{fid}-under{i}" class="{fid}-under" style="left:{TEXT_LEFT + (GLYPHS[i]["x"] + 2) * TK:.2f}px;width:{(GLYPHS[i]["adv"] - 8) * TK:.2f}px"></div>'
        for i in (7, 8)
    )
    stage = (
        f'      <div id="{fid}-qbox" style="position:absolute;left:0;top:178px">{q}</div>\n'
        + phone_html(fid, address(fid), extra_screen="").replace(
            f'<div id="{fid}-caret"></div>',
            f'<div id="{fid}-caret"></div>{unders}<div id="{fid}-toast">Endereço não encontrado</div>',
        )
    )
    css = phone_css(fid) + toast_css
    js.append(f'tl.set("#{fid}-lock, #{fid}-bar-dot", {{ opacity: 0 }}, 0);')
    js.append(f'tl.set("#{fid}-progress", {{ scaleX: 0, transformOrigin: "0% 50%" }}, 0);')
    js.append(f'tl.set(".{fid}-under, #{fid}-toast", {{ opacity: 0 }}, 0);')
    # The phone rises and settles, the question appears over it.
    js.append(f'tl.fromTo("#{fid}-cam", {{ y: 900, rotation: 4, scale: 1 }}, {{ y: 0, rotation: 0, duration: 0.75, ease: "back.out(1.4)", transformOrigin: "540px {BAR_CY}px" }}, 0);')
    for j, wid in enumerate(qids):
        js.append(f'tl.fromTo("#{wid}", {{ opacity: 0, y: 24 }}, {{ opacity: 1, y: 0, duration: 0.32, ease: "power3.out" }}, {r(0.15 + j * 0.07)});')
    # Push in on the bar.
    js.append(f'tl.to("#{fid}-cam", {{ y: {r(ZOOM_Y)}, scale: {ZOOM}, duration: 0.6, ease: "power3.inOut" }}, {r(beat(1))});')
    # Typing: one letter per sixteenth from beat 2, the caret following.
    for i in range(len(GLYPHS)):
        js.append(f'tl.set("#{fid}-t{i}", {{ opacity: 0 }}, 0);')
    t_type = beat(2)
    blink(js, f"#{fid}-caret", 0.0, t_type)
    js.append(f'tl.set("#{fid}-caret", {{ x: {r(caret_x(-1), 2)} }}, 0);')
    step = BEAT / 4
    for i in range(len(GLYPHS)):
        t = t_type + i * step
        js.append(f'tl.set("#{fid}-t{i}", {{ opacity: 1 }}, {r(t)});')
        js.append(f'tl.fromTo("#{fid}-t{i}", {{ y: 6 }}, {{ y: 0, duration: 0.12, ease: "power2.out", immediateRender: false }}, {r(t)});')
        js.append(f'tl.set("#{fid}-caret", {{ x: {r(caret_x(i), 2)} }}, {r(t)});')
    t_done = t_type + len(GLYPHS) * step
    blink(js, f"#{fid}-caret", t_done, beat(6))
    # Enter: the browser refuses.
    te = beat(6)
    shake = [18, -15, 11, -7, 4, 0]
    for j, dx in enumerate(shake):
        js.append(f'tl.to("#{fid}-bar, #{fid}-bar-ring, #{fid}-icon", {{ x: {dx}, duration: 0.05, ease: "power1.inOut" }}, {r(te + j * 0.05)});')
    js.append(f'tl.to("#{fid}-bar-ring", {{ opacity: 1, duration: 0.12 }}, {r(te)});')
    js.append(f'tl.fromTo("#{fid}-toast", {{ opacity: 0, y: -16 }}, {{ opacity: 1, y: 0, duration: 0.3, ease: "back.out(2)", immediateRender: false }}, {r(te + 0.12)});')
    js.append(f'tl.fromTo(".{fid}-under", {{ opacity: 0, scaleX: 0, transformOrigin: "0% 50%" }}, {{ opacity: 1, scaleX: 1, duration: 0.22, ease: "power2.out", stagger: 0.06, immediateRender: false }}, {r(te + 0.2)});')
    for i in (7, 8):
        js.append(f'tl.to("#{fid}-t{i} path", {{ fill: "{RED}", duration: 0.15 }}, {r(te + 0.2)});')
    return cid, frame_doc(cid, fid, dur, css, stage, "\n      ".join(js))


# ------------------------------------------------------------ Frame 2

def frame2():
    cid, (g0, dur) = "02-correccao", FRAMES["02-correccao"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    js = []
    caps = ["Tire o til.", "Tire a cedilha.", "Ponha um ponto.", "É só isso."]
    cap_html, cap_ids, cap_geo = [], [], []
    for i, txt in enumerate(caps):
        row, ids, total, widths, space = phrase(txt, 108, f"{fid}-cap{i}")
        cap_html.append(f'<div id="{fid}-capbox{i}" style="position:absolute;left:0;top:300px">{row}</div>')
        cap_ids.append(ids)
        cap_geo.append((total, widths, space))
    css = phone_css(fid) + f"""
    #{fid}-fly {{ position: absolute; left: 0; top: 0; width: 20px; height: 20px; border-radius: 50%; background: {GOLD}; }}
    #{fid}-toast {{ position: absolute; left: {BAR["left"] + 26}px; top: {BAR["top"] + BAR["h"] + 22}px; padding: 14px 22px; border-radius: 16px;
      background: {RED_BG}; color: #b91c1c; font: 600 25px/1.2 "Hanken Grotesk", sans-serif; box-shadow: inset 0 0 0 2px rgba(220,38,38,0.18); }}
    #{fid}-ok {{ position: absolute; left: {BAR["left"] + 26}px; top: {BAR["top"] + BAR["h"] + 22}px; padding: 14px 22px; border-radius: 16px;
      background: #f7fee7; color: {LIME_INK}; font: 600 25px/1.2 "Hanken Grotesk", sans-serif; box-shadow: inset 0 0 0 2px rgba(77,124,15,0.2); }}
    .{fid}-under {{ position: absolute; top: {TEXT_BASELINE + 14}px; height: 5px; border-radius: 3px; background: {RED}; }}
    .{fid}-spark {{ position: absolute; width: 12px; height: 12px; border-radius: 50%; background: {GOLD}; }}
    """
    unders = "".join(
        f'<div id="{fid}-under{i}" class="{fid}-under" style="left:{TEXT_LEFT + (GLYPHS[i]["x"] + 2) * TK:.2f}px;width:{(GLYPHS[i]["adv"] - 8) * TK:.2f}px"></div>'
        for i in (7, 8)
    )
    # Landing point of the dot, in camera and screen space.
    dot_cam_x = TEXT_LEFT + DOT_CX * TK
    dot_cam_y = TEXT_BASELINE - DOT_R * TK
    dot_scr = zoomed(dot_cam_x, dot_cam_y)
    sparks = "".join(f'<div class="{fid}-spark" id="{fid}-spark{i}" style="left:{dot_scr[0] - 6:.2f}px;top:{dot_scr[1] - 6:.2f}px"></div>' for i in range(10))
    stage = (
        "\n      ".join(cap_html)
        + phone_html(fid, address(fid)).replace(
            f'<div id="{fid}-caret"></div>',
            f'<div id="{fid}-caret"></div>{unders}<div id="{fid}-toast">Endereço não encontrado</div><div id="{fid}-ok">facturac.ao · ligação segura</div>',
        )
        + f"\n      {sparks}\n      <div id=\"{fid}-fly\"></div>"
    )
    # Handoff in: zoomed, red ring, marks red, toast showing.
    js.append(f'tl.set("#{fid}-cam", {{ y: {r(ZOOM_Y)}, scale: {ZOOM}, transformOrigin: "540px {BAR_CY}px" }}, 0);')
    js.append(f'tl.set("#{fid}-bar-ring", {{ opacity: 1 }}, 0);')
    js.append(f'tl.set("#{fid}-t7 path, #{fid}-t8 path", {{ fill: "{RED}" }}, 0);')
    js.append(f'tl.set("#{fid}-lock, #{fid}-bar-dot, #{fid}-ok, .{fid}-spark, #{fid}-fly", {{ opacity: 0 }}, 0);')
    js.append(f'tl.set("#{fid}-progress", {{ scaleX: 0, transformOrigin: "0% 50%" }}, 0);')
    js.append(f'tl.set("#{fid}-caret", {{ x: {r(caret_x(9), 2)} }}, 0);')
    js.append(f'tl.to("#{fid}-toast", {{ opacity: 0, y: -12, duration: 0.25, ease: "power2.in" }}, 0.05);')

    def cap_in(i, t):
        for j, wid in enumerate(cap_ids[i]):
            js.append(f'tl.fromTo("#{wid}", {{ opacity: 0, scale: 1.5, y: -10 }}, {{ opacity: 1, scale: 1, y: 0, duration: 0.24, ease: "power4.out", transformOrigin: "50% 60%" }}, {r(t + j * 0.1)});')

    def cap_out(i, t):
        js.append(f'tl.to("#{fid}-capbox{i}", {{ opacity: 0, scale: 0.86, duration: 0.12, ease: "power2.in", transformOrigin: "50% 50%" }}, {r(t)});')

    # Tire o til.
    cap_in(0, 0.0)
    js.append(f'tl.set("#{fid}-caret", {{ x: {r(caret_x(8), 2)} }}, 0.3);')
    js.append(f'tl.to("#{fid}-under8", {{ opacity: 0, duration: 0.15 }}, {r(L(8))});')
    js.append(f'tl.to("#{fid}-t8-tilde", {{ y: -24, rotation: -14, duration: 0.18, ease: "power2.out", transformOrigin: "50% 60%" }}, {r(L(8))});')
    js.append(f'tl.to("#{fid}-t8-tilde", {{ x: 340, y: -640, rotation: 380, scale: 2.2, opacity: 0, duration: 0.6, ease: "power2.in" }}, {r(L(8) + 0.182)});')
    js.append(f'tl.to("#{fid}-t8 path", {{ fill: "{INK}", duration: 0.2 }}, {r(L(8) + 0.2)});')
    # Tire a cedilha.
    cap_out(0, L(9) - 0.12)
    cap_in(1, L(9))
    js.append(f'tl.set("#{fid}-caret", {{ x: {r(caret_x(7), 2)} }}, {r(L(9) + 0.3)});')
    js.append(f'tl.to("#{fid}-under7", {{ opacity: 0, duration: 0.15 }}, {r(L(10))});')
    js.append(f'tl.to("#{fid}-t7-cedilla", {{ rotation: 14, duration: 0.1, transformOrigin: "40% 0%" }}, {r(L(10))});')
    js.append(f'tl.to("#{fid}-t7-cedilla", {{ y: 900, rotation: 50, duration: 0.7, ease: "power2.in" }}, {r(L(10) + 0.1)});')
    js.append(f'tl.set("#{fid}-t7-cedilla", {{ opacity: 0 }}, {r(L(10) + 0.82)});')
    js.append(f'tl.to("#{fid}-t7 path", {{ fill: "{INK}", duration: 0.2 }}, {r(L(10) + 0.15)});')
    # Ponha um ponto.
    cap_out(1, L(11) - 0.12)
    cap_in(2, L(11))
    js.append(f'tl.to("#{fid}-t8, #{fid}-t9", {{ x: {r(AO_SHIFT * TK, 2)}, duration: 0.4, ease: "power3.inOut" }}, {r(L(12))});')
    js.append(f'tl.to("#{fid}-caret", {{ x: {r(TEXT_LEFT + DOT_CX * TK - 2, 2)}, duration: 0.4, ease: "power3.inOut" }}, {r(L(12))});')
    # The caption's own full stop lifts off and flies into the bar.
    total, widths, space = cap_geo[2]
    left = (W - total) / 2
    word_left = left + sum(w for w, _ in widths[:2]) + 2 * space
    k = 108 / T.EM
    ponto, _ = T.layout("ponto.")
    per = ponto[-1]
    src_x = word_left + (per["x"] + 14.0) * k
    src_y = 300 + 93.7 * k
    period_gid = widths[2][1][-1]
    t_lift = L(13)
    js.append(f'tl.set("#{fid}-fly", {{ x: {r(src_x - 10, 2)}, y: {r(src_y - 10, 2)}, scale: {r(19 / 20, 2)} }}, 0);')
    js.append(f'tl.set("#{period_gid}", {{ opacity: 0 }}, {r(t_lift)});')
    js.append(f'tl.set("#{fid}-fly", {{ opacity: 1 }}, {r(t_lift)});')
    js.append(f'tl.to("#{fid}-fly", {{ y: {r(src_y - 150, 2)}, duration: 0.28, ease: "power2.out" }}, {r(t_lift)});')
    t_land = L(15)
    fly = t_land - (t_lift + 0.28)
    js.append(f'tl.to("#{fid}-fly", {{ x: {r(dot_scr[0] - 10, 2)}, duration: {r(fly)}, ease: "power1.inOut" }}, {r(t_lift + 0.28)});')
    js.append(f'tl.to("#{fid}-fly", {{ y: {r(dot_scr[1] - 10, 2)}, duration: {r(fly)}, ease: "power3.in" }}, {r(t_lift + 0.28)});')
    js.append(f'tl.to("#{fid}-fly", {{ scale: {r(2 * DOT_R * TK * ZOOM / 20, 3)}, duration: {r(fly)}, ease: "none" }}, {r(t_lift + 0.28)});')
    js.append(f'tl.set("#{fid}-fly", {{ opacity: 0 }}, {r(t_land)});')
    js.append(f'tl.set("#{fid}-bar-dot", {{ opacity: 1 }}, {r(t_land)});')
    js.append(f'tl.fromTo("#{fid}-bar-dot", {{ scaleX: 1.5, scaleY: 0.6, transformOrigin: "50% 100%" }}, {{ scaleX: 1, scaleY: 1, duration: 0.35, ease: "back.out(3)", immediateRender: false }}, {r(t_land)});')
    js.append(f'tl.to("#{fid}-bar-ring", {{ opacity: 0, duration: 0.25 }}, {r(t_land)});')
    js.append(f'tl.to("#{fid}-search", {{ opacity: 0, duration: 0.2 }}, {r(t_land)});')
    js.append(f'tl.to("#{fid}-lock", {{ opacity: 1, duration: 0.2 }}, {r(t_land + 0.1)});')
    for i in range(10):
        import math
        a = math.radians(i * 36 + 18)
        d = 120 + (i % 3) * 35
        js.append(f'tl.fromTo("#{fid}-spark{i}", {{ opacity: 1, x: 0, y: 0, scale: 1 }}, {{ opacity: 0, x: {r(math.cos(a) * d, 1)}, y: {r(math.sin(a) * d, 1)}, scale: 0.3, duration: 0.7, ease: "power3.out", immediateRender: false }}, {r(t_land)});')
    js.append(f'tl.fromTo("#{fid}-cam", {{ scale: {ZOOM} }}, {{ scale: {ZOOM * 1.04}, duration: 0.07, ease: "power2.out", immediateRender: false }}, {r(t_land)});')
    js.append(f'tl.to("#{fid}-cam", {{ scale: {ZOOM}, duration: 0.55, ease: "power3.out" }}, {r(t_land + 0.072)});')
    js.append(f'tl.set("#{fid}-caret", {{ x: {r(caret_x(9, shifted=True), 2)} }}, {r(t_land + 0.2)});')
    blink(js, f"#{fid}-caret", t_land + 0.2, L(17))
    # É só isso. Enter, and the page loads.
    cap_out(2, L(16) - 0.12)
    cap_in(3, L(16))
    te = L(17)
    js.append(f'tl.set("#{fid}-caret", {{ opacity: 0 }}, {r(te)});')
    js.append(f'tl.to("#{fid}-progress", {{ scaleX: 1, duration: 1.2, ease: "power2.inOut" }}, {r(te)});')
    js.append(f'tl.fromTo("#{fid}-ok", {{ opacity: 0, y: -14 }}, {{ opacity: 1, y: 0, duration: 0.3, ease: "back.out(2)", immediateRender: false }}, {r(te + 1.2)});')
    js.append(f'tl.to("#{fid}-capbox3", {{ opacity: 0, y: -40, duration: 0.3, ease: "power2.in" }}, {r(L(20) - 0.05)});')
    js.append(f'tl.to("#{fid}-cam", {{ y: 0, scale: 1, duration: 0.85, ease: "power3.inOut" }}, {r(L(20))});')
    js.append(f'tl.to("#{fid}-ok", {{ opacity: 0, duration: 0.3 }}, {r(L(21) + 0.2)});')
    return cid, frame_doc(cid, fid, dur, css, stage, "\n      ".join(js))


# ------------------------------------------------------------ Frame 3

def card_html(fid):
    return f"""
        <div id="{fid}-page" style="position:absolute;inset:0">
          <div id="{fid}-wm" style="position:absolute;left:0;right:0;top:220px;display:flex;justify-content:center">{T.word_html("facturacao", 64, f"{fid}-wmw")[0]}</div>
          <div id="{fid}-tag" style="position:absolute;left:0;right:0;top:318px;text-align:center;font:600 21px/1 'Hanken Grotesk',sans-serif;letter-spacing:0.14em;text-transform:uppercase;color:{MUTED}">Facturação electrónica</div>
          <div id="{fid}-card" style="position:absolute;left:40px;right:40px;top:410px;height:470px;border-radius:30px;background:#ffffff;
               box-shadow:0 0 0 2px rgba(23,23,22,0.06), 0 40px 80px -36px rgba(23,23,22,0.35)">
            <div style="position:absolute;left:36px;top:36px;font:600 20px/1 'Hanken Grotesk',sans-serif;letter-spacing:0.16em;text-transform:uppercase;color:{MUTED}">Factura</div>
            <div style="position:absolute;left:36px;top:70px;font:700 34px/1.1 'Hanken Grotesk',sans-serif;color:{INK}">FT 2026SEDE/12</div>
            <div style="position:absolute;left:36px;top:132px;font:500 26px/1.2 'Hanken Grotesk',sans-serif;color:{MUTED}">Kwanza Mercantil, Lda.</div>
            <div style="position:absolute;left:36px;right:36px;top:196px;height:2px;background:rgba(23,23,22,0.07)"></div>
            <div style="position:absolute;left:36px;top:228px;font:600 20px/1 'Hanken Grotesk',sans-serif;letter-spacing:0.16em;text-transform:uppercase;color:{MUTED}">Total</div>
            <div style="position:absolute;left:36px;top:262px;font:700 52px/1 'Hanken Grotesk',sans-serif;color:{INK};letter-spacing:-0.02em">1 368 000,00 <span style="font-size:30px;color:{MUTED}">Kz</span></div>
            <div style="position:absolute;left:36px;top:368px;height:62px;width:240px">
              <div id="{fid}-pill0" class="{fid}-pill" style="background:#f4f4f2;color:{INK};box-shadow:inset 0 0 0 2px rgba(23,23,22,0.14)">Emitida</div>
              <div id="{fid}-pill1" class="{fid}-pill" style="background:{LIME_BG};color:{LIME_INK}">Validada</div>
              <div id="{fid}-pill2" class="{fid}-pill" style="background:{GOLD};color:{INK}">Paga</div>
            </div>
          </div>
        </div>"""


def wordmark_dot_fix(html, fid, prefix, px):
    """Turn generated 'facturacao' into the wordmark: shift 'ao', add the gold dot."""
    k = px / T.EM
    for i in (8, 9):
        gx = GLYPHS[i]["x"]
        html = html.replace(f'id="{prefix}-g{i}" class="{prefix}-glyph" data-ch="{"a" if i == 8 else "o"}" style="position:absolute;left:{gx * k:.2f}px',
                            f'id="{prefix}-g{i}" class="{prefix}-glyph" data-ch="{"a" if i == 8 else "o"}" style="position:absolute;left:{(gx + AO_SHIFT) * k:.2f}px')
    html = html.replace(f'width:{495.04 * k:.2f}px', f'width:{524.51 * k:.2f}px')
    dot = f'<div id="{prefix}-dot" style="position:absolute;left:{(DOT_CX - DOT_R) * k:.2f}px;top:{(T.ASCENT - 2 * DOT_R) * k:.2f}px;width:{2 * DOT_R * k:.2f}px;height:{2 * DOT_R * k:.2f}px;border-radius:50%;background:{GOLD}"></div>'
    return html.replace('</div></div>', '</div>' + dot + '</div>', 1) if False else html[: html.rfind("</div>")] + dot + "</div>"


def frame3():
    cid, (g0, dur) = "03-pagina", FRAMES["03-pagina"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    js = []
    page = card_html(fid)
    wm_inner = T.word_html("facturacao", 64, f"{fid}-wmw")[0]
    page = page.replace(wm_inner, wordmark_dot_fix(wm_inner, fid, f"{fid}-wmw", 64))
    words = ["Emitida", "Validada", "Paga"]
    px = 74
    k = px / T.EM
    row_parts = []
    for i, w in enumerate(words):
        h, wpx, _ = T.word_html(w, px, f"{fid}-s{i}")
        row_parts.append(
            f'<div id="{fid}-sw{i}" style="position:relative;width:{wpx + 2 * DOT_R * k + 4:.2f}px">{h}'
            f'<div id="{fid}-sdot{i}" style="position:absolute;left:{wpx:.2f}px;top:{(T.ASCENT - 2 * DOT_R) * k:.2f}px;width:{2 * DOT_R * k:.2f}px;height:{2 * DOT_R * k:.2f}px;border-radius:50%;background:{GOLD}"></div></div>'
        )
    row = f'<div id="{fid}-sig" style="position:absolute;left:0;right:0;top:170px;display:flex;justify-content:center;gap:28px">' + "".join(row_parts) + "</div>"
    css = phone_css(fid) + f"""
    .{fid}-pill {{ position: absolute; left: 0; top: 0; height: 62px; padding: 0 30px; border-radius: 31px; display: flex; align-items: center;
      font: 700 28px/1 "Hanken Grotesk", sans-serif; }}
    #{fid}-glow {{ position: absolute; left: {SCREEN["left"] + 76 - 90}px; top: {SCREEN["top"] + 410 + 368 - 90}px; width: 360px; height: 240px; border-radius: 50%;
      background: radial-gradient(ellipse, rgba(249,178,51,0.55) 0%, rgba(249,178,51,0) 70%); }}
    """
    stage = (
        f"      {row}\n"
        + phone_html(fid, address(fid, with_marks=False, final=True), extra_screen=page)
        .replace(f'<div id="{fid}-caret"></div>', f'<div id="{fid}-caret"></div><div id="{fid}-glow"></div>')
    )
    # Handoff in: scale 1, valid bar, progress full.
    js.append(f'tl.set("#{fid}-search, #{fid}-bar-ring, #{fid}-caret", {{ opacity: 0 }}, 0);')
    js.append(f'tl.set("#{fid}-progress", {{ scaleX: 1, transformOrigin: "0% 50%" }}, 0);')
    js.append(f'tl.to("#{fid}-progress", {{ opacity: 0, duration: 0.3 }}, 0.1);')
    js.append(f'tl.set(".{fid}-pill, #{fid}-glow", {{ opacity: 0 }}, 0);')
    js.append(f'tl.fromTo("#{fid}-wm", {{ opacity: 0, y: 30 }}, {{ opacity: 1, y: 0, duration: 0.45, ease: "back.out(2)" }}, 0.05);')
    js.append(f'tl.fromTo("#{fid}-tag", {{ opacity: 0 }}, {{ opacity: 1, duration: 0.4 }}, 0.25);')
    js.append(f'tl.fromTo("#{fid}-card", {{ opacity: 0, y: 160, scale: 0.94 }}, {{ opacity: 1, y: 0, scale: 1, duration: 0.6, ease: "back.out(1.6)", transformOrigin: "50% 100%" }}, {r(L(24) - g0 + g0 - 0.0 if False else 0.4)});')
    for i, b in enumerate([27, 29, 31]):
        t = L(b)
        js.append(f'tl.set("#{fid}-pill{i}", {{ opacity: 1 }}, {r(t)});')
        js.append(f'tl.fromTo("#{fid}-pill{i}", {{ scale: 0.6 }}, {{ scale: 1, duration: 0.35, ease: "back.out(3)", transformOrigin: "0% 50%", immediateRender: false }}, {r(t)});')
        if i > 0:
            js.append(f'tl.set("#{fid}-pill{i - 1}", {{ opacity: 0 }}, {r(t)});')
        js.append(f'tl.fromTo("#{fid}-sw{i}", {{ opacity: 0, y: 30, scale: 1.25 }}, {{ opacity: 1, y: 0, scale: 1, duration: 0.3, ease: "power4.out", transformOrigin: "50% 60%" }}, {r(t)});')
        js.append(f'tl.fromTo("#{fid}-sdot{i}", {{ y: -200, opacity: 1 }}, {{ y: 0, duration: 0.2, ease: "power2.in", immediateRender: false }}, {r(t + 0.12)});')
        js.append(f'tl.set("#{fid}-sdot{i}", {{ opacity: 0 }}, 0);')
    js.append(f'tl.fromTo("#{fid}-glow", {{ opacity: 0, scale: 0.6 }}, {{ opacity: 1, scale: 1.25, duration: 0.5, ease: "power2.out", immediateRender: false }}, {r(L(31) + 0.05)});')
    js.append(f'tl.to("#{fid}-glow", {{ opacity: 0.35, scale: 1, duration: 1.2, ease: "power2.inOut" }}, {r(L(31) + 0.55)});')
    js.append(f'tl.fromTo("#{fid}-cam", {{ scale: 1 }}, {{ scale: 1.03, duration: {r(dur - 0.1)}, ease: "sine.inOut", transformOrigin: "540px 900px" }}, 0.1);')
    return cid, frame_doc(cid, fid, dur, css, stage, "\n      ".join(js))


# ------------------------------------------------------------ Frame 4

def frame4():
    cid, (g0, dur) = "04-brevemente", FRAMES["04-brevemente"]
    fid = "frame-" + cid
    L = lambda k: beat(k) - g0  # noqa: E731
    js = []
    px1 = 156
    k1 = px1 / T.EM
    h1, w1, ids1 = T.word_html("Brevemente", px1, f"{fid}-bre")
    d1 = 2 * DOT_R * k1
    left1 = (W - (w1 + d1)) / 2
    # The final card: an address bar, big, with the wordmark in it.
    px2 = 92
    k2 = px2 / T.EM
    wm = T.word_html("facturacao", px2, f"{fid}-wm")[0]
    wm = wordmark_dot_fix(wm, fid, f"{fid}-wm", px2)
    bar_w, bar_h = 800, 156
    css = f"""
    #{fid}-bigbar {{ position: absolute; left: {(W - bar_w) / 2}px; top: 880px; width: {bar_w}px; height: {bar_h}px; border-radius: {bar_h / 2}px;
      background: #ffffff; box-shadow: 0 0 0 3px {INK}, 0 40px 80px -40px rgba(23,23,22,0.4); }}
    #{fid}-biglock {{ position: absolute; left: 48px; top: {bar_h / 2 - 27}px; width: 54px; height: 54px; }}
    #{fid}-bigwm {{ position: absolute; left: 132px; top: {bar_h / 2 + 33 - 100 * k2:.2f}px; }}
    #{fid}-sub {{ position: absolute; left: 0; right: 0; top: 1110px; text-align: center; font: 600 30px/1 "Hanken Grotesk", sans-serif;
      letter-spacing: 0.16em; text-transform: uppercase; color: {MUTED}; }}
    #{fid}-sigrow {{ position: absolute; left: 0; right: 0; top: 1172px; text-align: center; font: 700 34px/1 "Hanken Grotesk", sans-serif; color: {INK}; }}
    #{fid}-sigrow span {{ display: inline-block; }}
    #{fid}-sigrow b {{ display: inline-block; width: 11px; height: 11px; border-radius: 50%; background: {GOLD}; margin: 0 16px 2px 3px; }}
    """
    lock = (f'<svg viewBox="0 0 34 34" style="position:absolute;inset:0"><rect x="6" y="15" width="22" height="16" rx="4" fill="{INK}"/>'
            f'<path d="M11 15 V11 a6 6 0 0 1 12 0 V15" fill="none" stroke="{INK}" stroke-width="3.4"/></svg>')
    stage = (
        phone_html(fid, address(fid, with_marks=False, final=True))
        + f'\n      <div id="{fid}-brebox" style="position:absolute;left:{left1:.2f}px;top:600px">{h1}'
        f'<div id="{fid}-bre-dot" style="position:absolute;left:{w1:.2f}px;top:{(T.ASCENT - 2 * DOT_R) * k1:.2f}px;width:{d1:.2f}px;height:{d1:.2f}px;border-radius:50%;background:{GOLD}"></div></div>'
        f'\n      <div id="{fid}-bigbar"><div id="{fid}-biglock">{lock}</div><div id="{fid}-bigwm">{wm}</div></div>'
        f'\n      <div id="{fid}-sub">Facturação electrónica para Angola</div>'
        f'\n      <div id="{fid}-sigrow"><span>Emitida</span><b></b><span>Validada</span><b></b><span>Paga</span><b></b></div>'
    )
    css = phone_css(fid) + css
    js.append(f'tl.set("#{fid}-search, #{fid}-bar-ring, #{fid}-caret, #{fid}-progress", {{ opacity: 0 }}, 0);')
    js.append(f'tl.to("#{fid}-cam", {{ y: 1750, rotation: -6, duration: 0.6, ease: "power3.in", transformOrigin: "540px 900px" }}, 0);')
    js.append(f'tl.to("#{fid}-cam", {{ opacity: 0, duration: 0.2, ease: "power1.in" }}, 0.45);')
    for j, gid in enumerate(ids1):
        js.append(f'tl.fromTo("#{gid}", {{ y: -120, opacity: 0, rotation: {(-1) ** j * 6} , transformOrigin: "50% 50%" }}, {{ y: 0, opacity: 1, rotation: 0, duration: 0.42, ease: "back.out(2.4)" }}, {r(0.3 + j * 0.035)});')
    t = L(37)
    js.append(f'tl.set("#{fid}-bre-dot", {{ opacity: 0 }}, 0);')
    js.append(f'tl.fromTo("#{fid}-bre-dot", {{ y: -620, opacity: 1 }}, {{ y: 0, duration: 0.34, ease: "power2.in", immediateRender: false }}, {r(t - 0.34)});')
    js.append(f'tl.to("#{fid}-bre-dot", {{ scaleX: 1.5, scaleY: 0.6, duration: 0.05, transformOrigin: "50% 100%" }}, {r(t)});')
    js.append(f'tl.to("#{fid}-bre-dot", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "back.out(3)" }}, {r(t + 0.052)});')
    t2 = L(38)
    js.append(f'tl.fromTo("#{fid}-bigbar", {{ opacity: 0, scaleX: 0.3, scaleY: 0.6 }}, {{ opacity: 1, scaleX: 1, scaleY: 1, duration: 0.45, ease: "back.out(1.8)", transformOrigin: "50% 50%" }}, {r(t2)});')
    for j in range(10):
        js.append(f'tl.fromTo("#{fid}-wm-g{j}", {{ y: 60, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.3, ease: "back.out(2)" }}, {r(t2 + 0.2 + j * 0.035)});')
    t3 = L(39)
    js.append(f'tl.set("#{fid}-wm-dot", {{ opacity: 0 }}, 0);')
    js.append(f'tl.fromTo("#{fid}-wm-dot", {{ y: -260, opacity: 1 }}, {{ y: 0, duration: 0.3, ease: "power2.in", immediateRender: false }}, {r(t3 - 0.3)});')
    js.append(f'tl.to("#{fid}-wm-dot", {{ scaleX: 1.5, scaleY: 0.6, duration: 0.05, transformOrigin: "50% 100%" }}, {r(t3)});')
    js.append(f'tl.to("#{fid}-wm-dot", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "back.out(3)" }}, {r(t3 + 0.052)});')
    js.append(f'tl.fromTo("#{fid}-sub", {{ opacity: 0, y: 14 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }}, {r(t3 + 0.2)});')
    js.append(f'tl.fromTo("#{fid}-sigrow span, #{fid}-sigrow b", {{ opacity: 0, y: 12 }}, {{ opacity: 1, y: 0, duration: 0.32, ease: "power3.out", stagger: 0.09 }}, {r(t3 + 0.45)});')
    return cid, frame_doc(cid, fid, dur, css, stage, "\n      ".join(js))


if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    for build in (frame1, frame2, frame3, frame4):
        cid, doc = build()
        with open(os.path.join(OUT, f"{cid}.html"), "w") as fh:
            fh.write(doc)
        print("wrote", cid, len(doc), "bytes")
    print(json.dumps({"bar_cy": BAR_CY, "text_top": round(TEXT_TOP, 2), "zoom_y": ZOOM_Y}))
