#!/usr/bin/env python3
"""Writes index.html and compositions/ for the teaser "O ponto". Run from the project root."""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INK, GOLD, WHITE = "#171716", "#f9b233", "#ffffff"
GREY = "#a1a1aa"
CLOCK = "09:46"

# The track's own beats, measured with `hyperframes beats` (126 BPM).
BEATS = [
    0.485, 0.965, 1.445, 1.915, 2.385, 2.865, 3.345, 3.815, 4.285, 4.775, 5.245, 5.725,
    6.195, 6.675, 7.145, 7.625, 8.095, 8.575, 9.055, 9.535, 10.005, 10.485, 10.955, 11.435,
    11.905, 12.385, 12.865, 13.335, 13.805, 14.295, 14.765, 15.245, 15.725, 16.205, 16.675,
    17.155, 17.625, 18.105, 18.575, 19.055, 19.525, 20.005, 20.485, 20.955, 21.425, 21.905,
    22.385, 22.865, 23.335, 23.815, 24.285, 24.765, 25.235, 25.715, 26.195, 26.675, 27.145,
    27.625, 28.105,
]
B = lambda n: BEATS[n]
END = 30.0

# ---------------------------------------------------------------- shared pieces

BASE_CSS = f"""
    @font-face {{ font-family: "CG"; font-weight: 700; font-display: block;
      src: local("Century Gothic Bold"), local("CenturyGothic-Bold"); }}
    @font-face {{ font-family: "Hanken Grotesk"; font-weight: 600; font-display: block;
      src: url("assets/fonts/HankenGrotesk-600.woff2") format("woff2"); }}
    #root {{ position: absolute; inset: 0; width: 1920px; height: 1080px; overflow: hidden;
      font-family: "CG", sans-serif; font-weight: 700; font-variant-ligatures: none; color: {INK}; }}
    .abs {{ position: absolute; }}
    .t {{ position: absolute; white-space: nowrap; line-height: 1; letter-spacing: -0.018em; }}
    .w {{ display: inline-block; }}
    .pt {{ display: inline-block; width: .19em; height: .19em; margin-left: .065em; border-radius: 50%; background: {GOLD}; }}
    .shot {{ position: absolute; overflow: hidden; background: #fff; border-radius: 26px;
      box-shadow: 0 0 0 1px rgb(23 23 22 / .12), 0 44px 80px -30px rgb(70 42 0 / .55); }}
    .shot img {{ position: absolute; left: 0; top: 0; width: 100%; height: auto; display: block; }}
"""

DEVICE_CSS = """
    .device { position: absolute; container-type: inline-size; color: #000; }
    .device-iphone { --pts: 417; } .device-android { --pts: 432; }
    .rim { --u: calc(100cqw / var(--pts)); position: relative; padding: calc(3 * var(--u)); border-radius: calc(67 * var(--u));
      background: linear-gradient(135deg, #8a8a8f 0%, #3c3c3f 18%, #1d1d1f 50%, #3c3c3f 82%, #7a7a7f 100%);
      box-shadow: 0 0 0 1px rgb(0 0 0 / .35), 0 calc(46 * var(--u)) calc(80 * var(--u)) calc(-26 * var(--u)) rgb(40 25 0 / .6); }
    .bezel { padding: calc(9 * var(--u)); border-radius: calc(64 * var(--u)); background: #050506; }
    .screen { position: relative; overflow: hidden; border-radius: calc(55 * var(--u)); background: #fff; }
    .status { position: relative; display: flex; height: calc(54 * var(--u)); align-items: center; justify-content: space-between;
      padding: calc(6 * var(--u)) calc(24 * var(--u)) 0 calc(30 * var(--u)); font-family: "Hanken Grotesk", sans-serif; font-size: calc(16 * var(--u)); line-height: 1; font-weight: 600; letter-spacing: 0; }
    .island { position: absolute; top: calc(11 * var(--u)); left: 50%; width: calc(122 * var(--u)); height: calc(35 * var(--u));
      margin-left: calc(-61 * var(--u)); border-radius: 999px; background: #000; }
    .icons { display: flex; align-items: center; gap: calc(5 * var(--u)); }
    .icons svg { display: block; height: calc(12 * var(--u)); width: auto; }
    .screen img { display: block; width: 100%; height: auto; }
    .gesture { position: relative; height: calc(22 * var(--u)); background: #fff; }
    .gesture span { position: absolute; bottom: calc(8 * var(--u)); left: 50%; width: calc(136 * var(--u)); height: calc(5 * var(--u));
      margin-left: calc(-68 * var(--u)); border-radius: 999px; background: #000; }
    .btn { position: absolute; width: calc(3 * var(--u)); background: linear-gradient(90deg, #55555a, #232325); }
    .b-l { left: calc(-2.5 * var(--u)); border-radius: calc(2 * var(--u)) 0 0 calc(2 * var(--u)); }
    .b-r { right: calc(-2.5 * var(--u)); border-radius: 0 calc(2 * var(--u)) calc(2 * var(--u)) 0; }
    .device-android .rim { border-radius: calc(40 * var(--u)); background: linear-gradient(135deg, #55565a, #26272a 45%, #44454a); }
    .device-android .bezel { padding: calc(7 * var(--u)); border-radius: calc(37 * var(--u)); }
    .device-android .screen { border-radius: calc(30 * var(--u)); }
    .device-android .status { height: calc(40 * var(--u)); padding: calc(2 * var(--u)) calc(22 * var(--u)) 0 calc(26 * var(--u)); font-size: calc(14 * var(--u)); }
    .device-android .island { top: calc(9 * var(--u)); width: calc(22 * var(--u)); height: calc(22 * var(--u)); margin-left: calc(-11 * var(--u)); }
    .device-android .icons svg { height: calc(11 * var(--u)); }
    .device-android .gesture { height: calc(20 * var(--u)); }
    .device-android .gesture span { bottom: calc(7 * var(--u)); width: calc(108 * var(--u)); height: calc(4 * var(--u)); margin-left: calc(-54 * var(--u)); background: #1f1f1f; }
"""

SIGNAL = '<svg viewBox="0 0 18 12" fill="currentColor"><rect x="0" y="8" width="3" height="4" rx=".8"/><rect x="5" y="5.5" width="3" height="6.5" rx=".8"/><rect x="10" y="3" width="3" height="9" rx=".8"/><rect x="15" y="0" width="3" height="12" rx=".8"/></svg>'
WIFI = '<svg viewBox="0 0 17 12" fill="currentColor"><path d="M8.5 2.3c2.4 0 4.6.9 6.2 2.5.1.1.3.1.4 0l1.2-1.2c.2-.2.2-.4 0-.5C14.2 1 11.5 0 8.5 0S2.8 1 .7 3.1c-.2.1-.2.3 0 .5l1.2 1.2c.1.1.3.1.4 0 1.6-1.6 3.8-2.5 6.2-2.5Z"/><path d="M8.5 6.2c1.3 0 2.5.5 3.5 1.4.1.1.3.1.4 0l1.2-1.2c.2-.2.2-.4 0-.5-1.4-1.3-3.2-2.1-5.1-2.1s-3.7.8-5.1 2.1c-.2.1-.2.3 0 .5l1.2 1.2c.1.1.3.1.4 0 1-.9 2.2-1.4 3.5-1.4Z"/><path d="M10.9 9c.1-.1.1-.3 0-.5-.7-.6-1.5-.9-2.4-.9s-1.700.3-2.400.9c-.1.2-.1.4 0 .5l2.100 2.100c.2.2.4.2.6 0L10.900 9Z"/></svg>'
BATTERY_I = '<svg viewBox="0 0 27 12" fill="none"><rect x=".5" y=".5" width="22" height="11" rx="3.2" stroke="currentColor" opacity=".4"/><rect x="2" y="2" width="17" height="8" rx="1.9" fill="currentColor"/><path d="M24 4v4c.8-.3 1.400-1.100 1.400-2S24.800 4.300 24 4Z" fill="currentColor" opacity=".45"/></svg>'
BATTERY_A = '<svg viewBox="0 0 8 13" fill="currentColor"><path d="M2.800 0h2.400v1.300h1.600c.6 0 1.200.5 1.200 1.200v9.300c0 .6-.5 1.200-1.200 1.200H1.200C.5 13 0 12.500 0 11.800V2.500c0-.7.5-1.200 1.200-1.200h1.600V0Z"/></svg>'


def device(el_id, kind, src, left, top, width):
    if kind == "iphone":
        buttons = (
            '<span class="btn b-l" style="top:calc(120*var(--u));height:calc(32*var(--u))"></span>'
            '<span class="btn b-l" style="top:calc(178*var(--u));height:calc(62*var(--u))"></span>'
            '<span class="btn b-l" style="top:calc(254*var(--u));height:calc(62*var(--u))"></span>'
            '<span class="btn b-r" style="top:calc(210*var(--u));height:calc(100*var(--u))"></span>'
        )
        battery = BATTERY_I
    else:
        buttons = (
            '<span class="btn b-r" style="top:calc(190*var(--u));height:calc(76*var(--u))"></span>'
            '<span class="btn b-r" style="top:calc(300*var(--u));height:calc(120*var(--u))"></span>'
        )
        battery = BATTERY_A
    return (
        f'<div id="{el_id}" class="device device-{kind}" style="left:{left}px;top:{top}px;width:{width}px" data-layout-allow-overflow>'
        f'<div class="rim">{buttons}<div class="bezel"><div class="screen">'
        f'<div class="status"><span>{CLOCK}</span><span class="island"></span><span class="icons">{SIGNAL}{WIFI}{battery}</span></div>'
        f'<img src="{src}" alt="" /><div class="gesture"><span></span></div>'
        "</div></div></div></div>"
    )


def scene(scene_id, ground, css, body, js):
    return f"""<template>
  <style>{BASE_CSS}
    #root {{ background: {ground}; }}
{css}
  </style>
  <div id="root" data-composition-id="{scene_id}" data-width="1920" data-height="1080">
{body}
  </div>
  <script>
    (function () {{
      const tl = gsap.timeline({{ paused: true }});
      // Every word lands blurred and sharpens, then holds dead still.
      const land = (sel, at, stagger = 0.07) => tl.fromTo(sel,
        {{ opacity: 0, filter: "blur(10px)" }},
        {{ opacity: 1, filter: "blur(0px)", duration: 0.16, stagger, ease: "power1.out" }}, at);
      const leave = (sel, at) => tl.to(sel,
        {{ opacity: 0, filter: "blur(10px)", duration: 0.14, ease: "power2.in" }}, at);
      const tap = (at) => {{
        tl.to("#hop", {{ opacity: 0, duration: 0.1, ease: "power1.in" }}, at + 0.1);
        tl.fromTo("#ring", {{ scale: 0.4, opacity: 0.9 }}, {{ scale: 2.6, opacity: 0, duration: 0.46, ease: "expo.out", immediateRender: false }}, at);
      }};
{js}
      window.__timelines["{scene_id}"] = tl;
    }})();
  </script>
</template>
"""


def words(el_id, text, left, top, size, color=INK, dot=False, dot_color=GOLD, extra=""):
    parts = text.split(" ")
    spans = " ".join(f'<span class="w {el_id}-w">{w}</span>' for w in parts)
    point = f'<span class="w {el_id}-w"><span class="pt" style="background:{dot_color}"></span></span>' if dot else ""
    return f'<div class="t" id="{el_id}" style="left:{left}px;top:{top}px;font-size:{size}px;color:{color};{extra}">{spans}{point}</div>'


def camera_to(x, y, scale):
    """The transform that puts frame point (x, y) at the centre, at this scale, from origin 0 0."""
    return {"x": round(960 - scale * x, 1), "y": round(540 - scale * y, 1), "scale": scale}


def cam_js(sel, focus, at, duration, ease):
    c = camera_to(*focus)
    return f'tl.to("{sel}", {{ x: {c["x"]}, y: {c["y"]}, scale: {c["scale"]}, duration: {duration}, ease: "{ease}" }}, {round(at, 3)});'


SCENES = {}

# ------------------------------------------------------------ S1: the dot arrives
s = 0.0
# Word boxes measured in the browser: width, then the dot's slot inside the word.
WORDS = [("Emitida", 1106.7, 1049.7), ("Validada", 1377.6, 1320.6), ("Paga", 817.2, 760.2)]
TOP, SLOT_Y, DOT = 390, 210, 57
def dot_x(i):
    return round((1920 - WORDS[i][1]) / 2 + WORDS[i][2], 1)
LAND_Y = TOP + SLOT_Y
word_html = "\n".join(
    f'<div class="t" id="w{i}" style="left:{(1920 - w) / 2}px;top:{TOP}px;font-size:300px;opacity:0">{t}<span class="pt" style="opacity:0"></span></div>'
    for i, (t, w, _) in enumerate(WORDS)
)
SCENES["s1-ponto"] = scene(
    "s1-ponto", WHITE,
    f"#dot {{ position: absolute; left: 0; top: 0; width: {DOT}px; height: {DOT}px; border-radius: 50%; background: {GOLD}; }}"
    f"#flood {{ position: absolute; left: {dot_x(2) - 1400 + DOT / 2}px; top: {LAND_Y - 1400 + DOT / 2}px; width: 2800px; height: 2800px; border-radius: 50%; background: {GOLD}; }}",
    word_html + f'\n<div id="flood" data-layout-allow-overflow></div>\n<div id="dot"></div>',
    f"""
      gsap.set("#dot", {{ x: {960 - DOT / 2}, y: 380, scaleX: 3.6, scaleY: 3.6, transformOrigin: "50% 100%" }});
      gsap.set("#flood", {{ scale: 0, opacity: 0 }});
      // Falls onto the first beat, and the word is there when it lands.
      tl.to("#dot", {{ x: {dot_x(0)}, y: {LAND_Y}, scaleX: 1, scaleY: 1, duration: {B(0)}, ease: "power2.in" }}, 0);
      const landing = (at) => {{
        tl.to("#dot", {{ scaleX: 1.34, scaleY: 0.62, duration: 0.05, ease: "power2.out" }}, at);
        tl.to("#dot", {{ scaleX: 1, scaleY: 1, duration: 0.42, ease: "elastic.out(1, 0.5)" }}, at + 0.05);
      }};
      const say = (id, at) => tl.fromTo(id, {{ opacity: 0, filter: "blur(14px)", scale: 1.03 }},
        {{ opacity: 1, filter: "blur(0px)", scale: 1, duration: 0.16, ease: "power2.out" }}, at);
      say("#w0", {B(0) - 0.03}); landing({B(0)});
      // Each hop is one arc: across on a constant ease, up and down on its own.
      const hop = (from, to, x) => {{
        const d = to - from;
        tl.to("#dot", {{ x, duration: d, ease: "sine.inOut" }}, from);
        tl.to("#dot", {{ y: {LAND_Y - 300}, duration: d / 2, ease: "power2.out" }}, from);
        tl.to("#dot", {{ y: {LAND_Y}, duration: d / 2, ease: "power2.in" }}, from + d / 2);
      }};
      hop({B(1)}, {B(2)}, {dot_x(1)}); leave("#w0", {B(2) - 0.17}); say("#w1", {B(2) - 0.03}); landing({B(2)});
      hop({B(3)}, {B(4)}, {dot_x(2)}); leave("#w1", {B(4) - 0.17}); say("#w2", {B(4) - 0.03}); landing({B(4)});
      // "Paga" holds while the dot bounces twice on the beat, smaller each time.
      const bounce = (from, to, height) => {{
        tl.to("#dot", {{ y: {LAND_Y} - height, duration: (to - from) / 2, ease: "power2.out" }}, from);
        tl.to("#dot", {{ y: {LAND_Y}, duration: (to - from) / 2, ease: "power2.in" }}, from + (to - from) / 2);
        landing(to);
      }};
      bounce({B(4) + 0.14}, {B(5)}, 130); bounce({B(5) + 0.06}, {B(6)}, 70);
      // Then the full stop of "Paga" grows until it is the whole frame.
      tl.set("#flood", {{ opacity: 1 }}, {B(8) - 0.62});
      tl.fromTo("#flood", {{ scale: 0.0203 }}, {{ scale: 1, duration: 0.62, ease: "power3.in" }}, {B(8) - 0.62});
    """,
)

# ------------------------------------------------------- S2: the phones, on gold
s = B(8)
IP_X, IP_Y, IP_W = 900, 104, 410
U = IP_W / 417
BTN = (IP_X + U * (12 + 16 + 72), IP_Y + U * (12 + 54 + 169 + 20))
SCENES["s2-telemovel"] = scene(
    "s2-telemovel", GOLD, DEVICE_CSS +
    f"#cam {{ position: absolute; inset: 0; transform-origin: 0 0; }}"
    f"#burst {{ position: absolute; left: {960 - 1300}px; top: {540 - 1300}px; width: 2600px; height: 2600px; border-radius: 50%; background: {GOLD}; }}"
    f"#hop {{ position: absolute; left: 0; top: 0; width: 20px; height: 20px; border-radius: 50%; background: {GOLD}; }}"
    f"#ring {{ position: absolute; left: 0; top: 0; width: 26px; height: 26px; box-sizing: border-box; border-radius: 50%; border: 3px solid #fff; opacity: 0; }}",
    f"""<div id="cam" data-layout-allow-overflow>
      {device("iphone", "iphone", "assets/screens/iphone-today.webp", IP_X, IP_Y, IP_W)}
      {device("android", "android", "assets/screens/android-debts.webp", 1345, 74, 424)}
      <div id="hop"></div>
      <div id="ring"></div>
    </div>
    {words("l1", "No telemóvel", 110, 430, 112)}
    {words("l2", "que já tem", 110, 556, 112, dot=True, dot_color=INK)}
    <div id="burst" data-layout-allow-overflow></div>""",
    f"""
      gsap.set("#burst", {{ scale: 0, opacity: 1 }});
      gsap.set("#android", {{ y: 1200 }});
      // A slow push while the words hold, so the shot is never quite still.
      tl.to("#cam", {{ x: -33.5, y: -13.5, scale: 1.025, duration: {B(13) - s}, ease: "none" }}, 0);
      tl.fromTo("#iphone", {{ y: 1120 }}, {{ y: 0, duration: 0.85, ease: "expo.out" }}, 0.02);
      land(".l1-w", 0.22); land(".l2-w", 0.42);
      tl.to("#android", {{ y: 0, duration: 0.8, ease: "expo.out" }}, {B(10) - s});
      leave(".l1-w, .l2-w", {B(13) - 0.1 - s});
      // The camera puts the button at the centre of the frame, and the burst opens from there.
      {cam_js("#cam", (BTN[0], BTN[1], 3.1), B(13) - s, 0.55, "power2.inOut")}
      gsap.set("#hop", {{ x: {round(BTN[0] - 10, 1)}, y: {round(BTN[1] - 240, 1)}, opacity: 0 }});
      tl.set("#hop", {{ opacity: 1 }}, {B(14) - s});
      // The dot drops in from above the frame and lands on the button.
      tl.to("#hop", {{ y: {round(BTN[1] - 10, 1)}, duration: {round(B(15) - B(14), 3)}, ease: "power2.in" }}, {B(14) - s});
      gsap.set("#ring", {{ x: {round(BTN[0] - 13, 1)}, y: {round(BTN[1] - 13, 1)} }});
      tap({B(15) - s});
      {cam_js("#cam", (BTN[0], BTN[1], 5.2), B(15) - s - 0.05, 0.53, "power3.in")}
      tl.to("#burst", {{ scale: 1, duration: 0.42, ease: "power3.in" }}, {B(16) - s - 0.42});
    """,
)

# -------------------------------------------------- S3: a real sale, on the beat
s = B(16)
TILL_X, TILL_Y, TILL_W = 210, 218, 1500
TILL_K = TILL_W / 1440
COBRAR = (TILL_X + 1188 * TILL_K, TILL_Y + 696 * TILL_K)
states = "\n".join(
    f'<img id="till{i}" src="assets/screens/till-{i}.webp" alt="" style="opacity:{1 if i == 0 else 0}" />' for i in range(6)
)
tap_js = "\n".join(f'tl.set("#till{i + 1}", {{ opacity: 1 }}, {round(B(17 + i) - s, 3)});' for i in range(5))
# Where the dot lands: the centre of each tile it taps, in frame coordinates, then the button.
TILE_Y = round(TILL_Y + 279 * TILL_K)
TILE_X = [round(TILL_X + x * TILL_K) for x in (119, 488, 857)]
TILES = [(TILE_X[0], TILE_Y), (TILE_X[1], TILE_Y), (TILE_X[0], TILE_Y), (TILE_X[1], TILE_Y), (TILE_X[2], TILE_Y)]
HOPS = [(x - 19, y - 19) for x, y in TILES] + [(round(COBRAR[0] - 19, 1), round(COBRAR[1] - 19, 1))]
hop_js = "\n      ".join(
    f'hop({round(B(17 + i - 1) - s, 3)}, {round(B(17 + i) - s, 3)}, {HOPS[i][0]}, {HOPS[i][1]});' for i in range(1, 6)
)
SCENES["s3-balcao"] = scene(
    "s3-balcao", GOLD,
    f"#cam {{ position: absolute; inset: 0; transform-origin: 0 0; }}"
    f"#burst {{ position: absolute; left: {960 - 1300}px; top: {540 - 1300}px; width: 2600px; height: 2600px; border-radius: 50%; background: {GOLD}; }}"
    f"#hop {{ position: absolute; left: 0; top: 0; width: 38px; height: 38px; border-radius: 50%; background: {GOLD}; box-shadow: 0 6px 14px -4px rgb(92 54 0 / .55); }}"
    f"#ring {{ position: absolute; left: 0; top: 0; width: 44px; height: 44px; box-sizing: border-box; border-radius: 50%; border: 4px solid #fff; opacity: 0; }}",
    f"""<div id="cam" data-layout-allow-overflow>
      <div id="till" class="shot" style="left:{TILL_X}px;top:{TILL_Y}px;width:{TILL_W}px;height:{round(900 * TILL_K)}px" data-layout-allow-overflow>
      {states}
      </div>
      <div id="hop"></div>
      <div id="ring"></div>
    </div>
    {words("h", "Ao balcão, é tocar e cobrar", 210, 84, 96, dot=True, dot_color=INK)}
    <div id="burst" data-layout-allow-overflow></div>""",
    f"""
      gsap.set("#burst", {{ scale: 0, opacity: 1 }});
      tl.to("#cam", {{ x: -19.2, y: -12.4, scale: 1.02, duration: {round(B(22) - s - 0.1, 3)}, ease: "none" }}, 0);
      tl.fromTo("#till", {{ y: 900 }}, {{ y: 0, duration: 0.75, ease: "expo.out" }}, 0.02);
      land(".h-w", 0.1, 0.05);
      {tap_js}
      // The dot falls onto the first tile, then hops tile to tile, one item a beat.
      gsap.set("#hop", {{ x: {HOPS[0][0]}, y: 60, scaleX: 1, scaleY: 1, transformOrigin: "50% 100%" }});
      tl.to("#hop", {{ y: {HOPS[0][1]}, duration: {round(B(17) - s - 0.05, 3)}, ease: "power2.in" }}, 0.05);
      const squash = (at) => {{
        tl.to("#hop", {{ scaleX: 1.3, scaleY: 0.66, duration: 0.04, ease: "power2.out" }}, at);
        tl.to("#hop", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "elastic.out(1, 0.5)" }}, at + 0.04);
      }};
      const hop = (from, to, x, y) => {{
        const d = to - from;
        tl.to("#hop", {{ x, duration: d, ease: "sine.inOut" }}, from);
        tl.to("#hop", {{ y: Math.min(y, {TILE_Y}) - 150, duration: d / 2, ease: "power2.out" }}, from);
        tl.to("#hop", {{ y, duration: d / 2, ease: "power2.in" }}, from + d / 2);
        squash(to);
      }};
      squash({round(B(17) - s, 3)});
      {hop_js}
      gsap.set("#ring", {{ x: {round(COBRAR[0] - 22, 1)}, y: {round(COBRAR[1] - 22, 1)} }});
      tap({B(22) - s});
      leave(".h-w", {B(22) - s - 0.2});
      {cam_js("#cam", (COBRAR[0], COBRAR[1], 2.7), B(22) - s - 0.1, 0.5, "power2.inOut")}
      {cam_js("#cam", (COBRAR[0], COBRAR[1], 5.6), B(24) - s - 0.48, 0.48, "power3.in")}
      tl.to("#burst", {{ scale: 1, duration: 0.36, ease: "power3.in" }}, {B(24) - s - 0.36});
    """,
)

# ---------------------------------------- S4: the homepage's shot, the dot rising
s = B(24)
DOC_X, DOC_Y, DOC_W = 310, 296, 1300
DOC_K = DOC_W / 1440
ROW = (DOC_X + (60 + 214) * DOC_K, DOC_Y + 278 * DOC_K)
SCENES["s4-documentos"] = scene(
    "s4-documentos", WHITE,
    f"#sun {{ position: absolute; left: -60px; top: 200px; width: 2040px; height: 2040px; border-radius: 50%; background: {GOLD}; }}"
    "#cam { position: absolute; inset: 0; transform-origin: 0 0; }",
    f"""<div id="sun" data-layout-allow-overflow></div>
    <div id="cam" data-layout-allow-overflow>
      <div id="doc" class="shot" style="left:{DOC_X}px;top:{DOC_Y}px;width:{DOC_W}px;height:{round(900 * DOC_K)}px" data-layout-allow-overflow>
        <img src="assets/screens/documents.webp" alt="" />
      </div>
    </div>
    {words("h", "Cada documento, comunicado à AGT", 960, 104, 80, dot=True, extra="transform:translateX(-50%);")}""",
    f"""
      gsap.set("#sun", {{ scale: 3.2, transformOrigin: "50% 50%" }});
      tl.to("#sun", {{ scale: 1, duration: 0.72, ease: "power3.inOut" }}, 0);
      tl.to("#sun", {{ y: -26, duration: {round(B(30) - s - 0.72, 3)}, ease: "none" }}, 0.72);
      tl.to("#cam", {{ x: -28.8, y: -21.0, scale: 1.03, duration: {round(B(30) - s - 0.06, 3)}, ease: "none" }}, 0);
      tl.fromTo("#doc", {{ y: 820 }}, {{ y: 0, duration: 0.85, ease: "expo.out" }}, 0.22);
      land(".h-w", 0.5, 0.05);
      leave(".h-w", {B(30) - s - 0.16});
      {cam_js("#cam", (ROW[0], ROW[1], 3.0), B(30) - s - 0.06, 0.56, "power2.inOut")}
      tl.to("#cam, #sun", {{ x: "-=2100", duration: 0.24, ease: "power3.in" }}, {B(32) - s - 0.24});
    """,
)

# ------------------------------------------------------- S5: who owes you, on ink
s = B(32)
SCENES["s5-cobrar"] = scene(
    "s5-cobrar", INK, DEVICE_CSS,
    f"""{device("android", "android", "assets/screens/android-debts.webp", 180, 74, 424)}
    {device("iphone", "iphone", "assets/screens/iphone-today.webp", 180, 104, 410)}
    {words("a1", "Sabe quem", 760, 400, 140, color=WHITE)}
    {words("a2", "lhe deve", 760, 548, 140, color=WHITE, dot=True)}
    {words("c1", "O lembrete", 760, 400, 140, color=WHITE)}
    {words("c2", "já vem escrito", 760, 548, 140, color=WHITE, dot=True)}""",
    f"""
      gsap.set("#iphone", {{ x: 1800, y: 0 }});
      tl.fromTo("#android", {{ scale: 1 }}, {{ scale: 1.03, duration: {round(B(36) - s, 3)}, ease: "none" }}, 0);
      tl.fromTo("#iphone", {{ scale: 1 }}, {{ scale: 1.03, duration: {round(B(40) - B(36), 3)}, ease: "none", immediateRender: false }}, {round(B(36) - s, 3)});
      tl.fromTo("#android", {{ x: 1800 }}, {{ x: 0, duration: 0.8, ease: "expo.out" }}, 0);
      land(".a1-w", 0.2); land(".a2-w", 0.38);
      gsap.set(".c1-w, .c2-w", {{ opacity: 0 }});
      leave(".a1-w, .a2-w", {B(36) - s - 0.16});
      tl.to("#android", {{ x: -900, duration: 0.42, ease: "power3.in" }}, {B(36) - s - 0.34});
      tl.to("#iphone", {{ x: 0, duration: 0.72, ease: "expo.out" }}, {B(36) - s - 0.02});
      land(".c1-w", {B(36) - s + 0.12}); land(".c2-w", {B(36) - s + 0.3});
    """,
)

# ----------------------------------------------- S6: everything else, on the beat
s = B(40)
ITEMS = [
    ("Facturas", 340, WHITE, INK, GOLD), ("Recibos", 360, GOLD, INK, INK), ("Clientes", 380, INK, WHITE, GOLD),
    ("Guias", 420, WHITE, INK, GOLD), ("Stock", 420, GOLD, INK, INK), ("Orçamentos", 240, INK, WHITE, GOLD),
    ("Kwanzas", 330, WHITE, INK, GOLD), ("21 províncias", 236, GOLD, INK, INK),
]
layers = []
js = []
for i, (t, size, ground, ink, dot) in enumerate(ITEMS):
    layers.append(
        f'<div id="m{i}" class="abs" style="inset:0;background:{ground};opacity:0;display:flex;align-items:center;justify-content:center">'
        f'<div id="mw{i}" style="white-space:nowrap;line-height:1;letter-spacing:-.018em;font-size:{size}px;color:{ink}">{t}<span id="mp{i}" class="pt" style="background:{dot}"></span></div></div>'
    )
    at = round(B(40 + i) - s, 3)
    js.append(f'tl.set("#m{i}", {{ opacity: 1 }}, {at});')
    js.append(f'tl.fromTo("#mw{i}", {{ scale: 1.09, filter: "blur(8px)" }}, {{ scale: 1, filter: "blur(0px)", duration: 0.2, ease: "expo.out" }}, {at});')
    js.append(f'tl.fromTo("#mp{i}", {{ scale: 0 }}, {{ scale: 1, duration: 0.3, ease: "elastic.out(1, 0.55)" }}, {round(at + 0.09, 3)});')
    if i:
        js.append(f'tl.set("#m{i - 1}", {{ opacity: 0 }}, {at});')
SCENES["s6-tudo"] = scene("s6-tudo", WHITE, "", "\n".join(layers), "\n".join(js))

# ------------------------------------------------------------ S7: the certificate
s = B(48)
SCENES["s7-certificado"] = scene(
    "s7-certificado", INK, "",
    f"""{words("a1", "Certificado", 130, 268, 236, color=WHITE)}
    {words("a2", "pela AGT", 130, 500, 236, color=WHITE, dot=True)}
    {words("a3", "N.º FE/419/AGT/2026", 138, 770, 56, color=GREY, extra="letter-spacing:0;")}
    {words("b1", "Obrigatória a", 130, 340, 160, color=WHITE)}
    {words("b2", "1 de janeiro de 2027", 130, 516, 160, color=WHITE, dot=True)}""",
    f"""
      gsap.set(".b1-w, .b2-w", {{ opacity: 0 }});
      land(".a1-w", 0.0); land(".a2-w", 0.14); land(".a3-w", 0.42, 0.05);
      leave(".a1-w, .a2-w", {B(52) - s - 0.15});
      land(".b1-w", {B(52) - s}, 0.09); land(".b2-w", {B(52) - s + 0.2}, 0.08);
      // The full stop leaps out of the top of the frame, and the end card's dot falls back in.
      tl.to("#b2 .pt", {{ scaleX: 1.15, scaleY: 0.6, duration: 0.08, ease: "power2.out" }}, {B(56) - s - 0.32});
      tl.to("#b2 .pt", {{ x: -500, y: -720, scaleX: 1.2, scaleY: 1.9, duration: 0.24, ease: "power2.in" }}, {B(56) - s - 0.24});
    """,
)

# ------------------------------------------------------------- S8: the dot lands
s = B(56)
WM_L, WM_T, WM_W = 400, 300, 1120
K = WM_W / 524
DOT_D = 20 * K
DOT_X = WM_L + (385.01 + 1.6 - 10) * K
DOT_Y = WM_T + (70 - 3 - 10) * K
LETTERS = re.search(r'<path[^>]* d="([^"]+)"', (ROOT / "assets/facturac-ao.svg").read_text()).group(1)
SCENES["s8-fim"] = scene(
    "s8-fim", WHITE,
    f"#sun {{ position: absolute; left: -100px; top: 760px; width: 2120px; height: 2120px; border-radius: 50%; background: {GOLD}; }}"
    f"#dot {{ position: absolute; left: 0; top: 0; width: {DOT_D}px; height: {DOT_D}px; border-radius: 50%; background: {GOLD}; }}",
    f"""<div id="sun" data-layout-allow-overflow></div>
    <svg id="wm" class="abs" style="left:{WM_L}px;top:{WM_T}px" width="{WM_W}" height="{round(80.4 * K, 1)}" viewBox="-1.6 3 524 80.4" role="img" aria-label="facturac.ao"><path fill="{INK}" d="{LETTERS}"/></svg>
    <div id="dot"></div>
    {words("cta", "Abra a sua conta grátis", 960, 512, 64, extra="transform:translateX(-50%);letter-spacing:-.01em;")}""",
    f"""
      gsap.set("#sun", {{ y: 420 }});
      gsap.set("#dot", {{ x: {round(DOT_X, 1)}, y: -80, scaleX: 1, scaleY: 1, transformOrigin: "50% 100%" }});
      gsap.set("#wm", {{ clipPath: "inset(-20% 100% -20% 0%)" }});
      tl.to("#sun", {{ y: 0, duration: 1.0, ease: "expo.out" }}, 0);
      tl.to("#wm", {{ clipPath: "inset(-20% 0% -20% 0%)", duration: 0.52, ease: "power3.inOut" }}, 0.06);
      tl.to("#dot", {{ y: {round(DOT_Y, 1)}, duration: {round(B(58) - s - 0.1, 3)}, ease: "power2.in" }}, 0.1);
      tl.to("#dot", {{ scaleX: 1.34, scaleY: 0.62, duration: 0.05, ease: "power2.out" }}, {B(58) - s});
      tl.to("#dot", {{ scaleX: 1, scaleY: 1, duration: 0.5, ease: "elastic.out(1, 0.5)" }}, {B(58) - s + 0.05});
      land(".cta-w", {B(57) - s + 0.05}, 0.05);
    """,
)

# ---------------------------------------------------------------- root and sound
HOSTS = [
    ("s1-ponto", 0.0, B(8)), ("s2-telemovel", B(8), B(16)), ("s3-balcao", B(16), B(24)), ("s4-documentos", B(24), B(32)),
    ("s5-cobrar", B(32), B(40)), ("s6-tudo", B(40), B(48)), ("s7-certificado", B(48), B(56)), ("s8-fim", B(56), END),
]
DUR = {"whoosh-soft": 1.579, "whoosh-up": 1.579, "pop-soft": 0.575, "pop-confirm": 0.575, "riser-short": 2.17, "impact-soft": 2.757, "drop-thud": 0.792}
# Where each file is loudest, measured. An effect is placed by its peak, one frame after the picture.
PEAK = {"whoosh-soft": 0.51, "whoosh-up": 0.51, "pop-soft": 0.03, "pop-confirm": 0.03, "riser-short": 2.17, "impact-soft": 0.57, "drop-thud": 0.05}
FX = [
    ("drop-thud", B(0), 0.55), ("pop-soft", B(2), 0.42), ("pop-confirm", B(4), 0.46), ("pop-soft", B(5), 0.2), ("pop-soft", B(6), 0.16),
    ("whoosh-up", B(8) - 0.12, 0.3), ("whoosh-soft", B(13) + 0.28, 0.22), ("pop-soft", B(15), 0.3), ("whoosh-up", B(16) - 0.12, 0.3),
    ("pop-soft", B(17), 0.3), ("pop-confirm", B(18), 0.26), ("pop-soft", B(19), 0.3), ("pop-confirm", B(20), 0.26), ("pop-soft", B(21), 0.3), ("pop-confirm", B(22), 0.34),
    ("whoosh-up", B(24) - 0.1, 0.3), ("whoosh-soft", B(24) + 0.33, 0.24), ("whoosh-soft", B(30) + 0.22, 0.2),
    ("whoosh-up", B(32) - 0.08, 0.3), ("whoosh-up", B(36) - 0.1, 0.24),
    *[(("pop-soft", "pop-confirm")[i % 2], B(40 + i) + 0.09, 0.2 + i * 0.012) for i in range(8)],
    ("riser-short", B(48), 0.2), ("impact-soft", B(48), 0.34),
    ("whoosh-soft", B(52) + 0.05, 0.16), ("whoosh-up", B(56) - 0.08, 0.26), ("pop-confirm", B(58), 0.55),
]
FX = [(n, t - PEAK[n] + 1 / 30, v) for n, t, v in FX]


def root():
    hosts = "\n".join(
        f'      <div id="el-{n}" class="clip" data-composition-id="{n}" data-composition-src="compositions/{n}.html" data-start="{a}" data-duration="{round(b - a, 3)}" data-track-index="1" data-track-kind="graphics" data-width="1920" data-height="1080"></div>'
        for n, a, b in HOSTS
    )
    fx = "\n".join(
        f'      <audio id="fx{i}" src="assets/sfx/{n}.mp3" data-start="{round(t, 3)}" data-duration="{DUR[n]}" data-track-index="{20 + i}" data-volume="{v}"></audio>'
        for i, (n, t, v) in enumerate(FX)
    )
    return f"""<!doctype html>
<html lang="pt-AO">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=1920, height=1080" />
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js"></script>
    <style>
      * {{ margin: 0; padding: 0; box-sizing: border-box; }}
      html, body {{ width: 1920px; height: 1080px; overflow: hidden; background: #fff; }}
      #root {{ position: relative; width: 1920px; height: 1080px; overflow: hidden; background: #fff; }}
    </style>
  </head>
  <body>
    <div id="root" data-composition-id="main" data-start="0" data-duration="{END}" data-width="1920" data-height="1080" data-motion-blur="on" data-motion-blur-shutter="120">
{hosts}

      <audio id="bgm" src="assets/bgm/track-phone.mp3" data-start="0" data-duration="{END}" data-track-index="10" data-volume="0.95"></audio>
{fx}
    </div>
    <script>
      window.__timelines = window.__timelines || {{}};
      window.__timelines["main"] = gsap.timeline({{ paused: true }});
    </script>
  </body>
</html>
"""


(ROOT / "compositions").mkdir(exist_ok=True)
for name, html in SCENES.items():
    (ROOT / "compositions" / f"{name}.html").write_text(html)
(ROOT / "index.html").write_text(root())
print(f"{len(SCENES)} scenes, {len(FX)} effects")
