#!/usr/bin/env python3
"""Writes index.html and compositions/ for the vertical cut of "O ponto". Run from the project root.

Recomposed for a phone, not cropped: one phone at a time, shown whole, with the app's
real mobile screens. Every word sits between y 190 and y 1200, clear of the bars that
WhatsApp and Instagram draw over the frame. Large flat colour and big type on purpose:
it is what survives their re-encoding.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
INK, GOLD, WHITE = "#171716", "#f9b233", "#ffffff"
GREY = "#a1a1aa"
CLOCK = "09:46"
W, H = 1080, 1920
CX, CY = W / 2, H / 2

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

# The phones: one size all film, whole in the frame, under a two-line title.
IP_W = 640
IP_X, IP_Y = (W - IP_W) / 2, 470
U = IP_W / 417
AN_W = 616
AN_X, AN_Y = (W - AN_W) / 2, 462


def on_iphone(x, y):
    """A point on the app's screen (in points) as a frame coordinate."""
    return (IP_X + U * (12 + x), IP_Y + U * (12 + 54 + y))


BASE_CSS = f"""
    @font-face {{ font-family: "CG"; font-weight: 700; font-display: block;
      src: local("Century Gothic Bold"), local("CenturyGothic-Bold"); }}
    @font-face {{ font-family: "Hanken Grotesk"; font-weight: 600; font-display: block;
      src: url("assets/fonts/HankenGrotesk-600.woff2") format("woff2"); }}
    #root {{ position: absolute; inset: 0; width: {W}px; height: {H}px; overflow: hidden;
      font-family: "CG", sans-serif; font-weight: 700; font-variant-ligatures: none; color: {INK}; }}
    .abs {{ position: absolute; }}
    .t {{ position: absolute; left: 0; width: {W}px; text-align: center; white-space: nowrap; line-height: 1; letter-spacing: -0.018em; }}
    .w {{ display: inline-block; }}
    .pt {{ display: inline-block; width: .19em; height: .19em; margin-left: .065em; border-radius: 50%; background: {GOLD}; }}
    .cam {{ position: absolute; inset: 0; transform-origin: 0 0; }}
    .burst {{ position: absolute; left: {CX - 1250}px; top: {CY - 1250}px; width: 2500px; height: 2500px; border-radius: 50%; background: {GOLD}; }}
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
    .stack { position: relative; }
    .stack img { display: block; width: 100%; height: auto; }
    .stack img + img { position: absolute; left: 0; top: 0; }
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


def device(el_id, kind, screen_html, left, top, width):
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
        f'<div class="stack">{screen_html}</div><div class="gesture"><span></span></div>'
        "</div></div></div></div>"
    )


def img(name, el_id="", hidden=False):
    return f'<img {f"""id="{el_id}" """ if el_id else ""}src="assets/screens/{name}.webp" alt=""{" style=\"opacity:0\"" if hidden else ""} />'


def iphone(el_id, screen_html):
    return device(el_id, "iphone", screen_html, IP_X, IP_Y, IP_W)


def android(el_id, screen_html):
    return device(el_id, "android", screen_html, AN_X, AN_Y, AN_W)


def scene(scene_id, ground, css, body, js):
    return f"""<template>
  <style>{BASE_CSS}
    #root {{ background: {ground}; }}
{css}
  </style>
  <div id="root" data-composition-id="{scene_id}" data-width="{W}" data-height="{H}">
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


def words(el_id, text, top, size, color=INK, dot=False, dot_color=GOLD, extra=""):
    spans = " ".join(f'<span class="w {el_id}-w">{w}</span>' for w in text.split(" "))
    point = f'<span class="w {el_id}-w"><span class="pt" style="background:{dot_color}"></span></span>' if dot else ""
    return f'<div class="t" id="{el_id}" style="top:{top}px;font-size:{size}px;color:{color};{extra}">{spans}{point}</div>'


def cam_js(focus_x, focus_y, scale, at, duration, ease):
    x, y = round(CX - scale * focus_x, 1), round(CY - scale * focus_y, 1)
    return f'tl.to("#cam", {{ x: {x}, y: {y}, scale: {scale}, duration: {duration}, ease: "{ease}" }}, {round(at, 3)});'


SCENES = {}

# ------------------------------------------------------------ S1: the dot arrives
# Widths and slots measured in Century Gothic Bold at 190 px.
WORDS = [("Emitida", 700.9, 664.8), ("Validada", 872.5, 836.4), ("Paga", 517.6, 481.5)]
TOP, SLOT_Y, DOT = 790, 133, 36
def dot_x(i):
    return round((W - WORDS[i][1]) / 2 + WORDS[i][2], 1)
LAND_Y = TOP + SLOT_Y
word_html = "\n".join(
    f'<div id="w{i}" class="abs" style="left:{(W - w) / 2}px;top:{TOP}px;white-space:nowrap;line-height:1;letter-spacing:-.018em;font-size:190px;opacity:0">{t}<span class="pt" style="opacity:0"></span></div>'
    for i, (t, w, _) in enumerate(WORDS)
)
SCENES["s1-ponto"] = scene(
    "s1-ponto", WHITE,
    f"#dot {{ position: absolute; left: 0; top: 0; width: {DOT}px; height: {DOT}px; border-radius: 50%; background: {GOLD}; }}"
    f"#flood {{ position: absolute; left: {dot_x(2) - 1500 + DOT / 2}px; top: {LAND_Y - 1500 + DOT / 2}px; width: 3000px; height: 3000px; border-radius: 50%; background: {GOLD}; }}",
    word_html + '\n<div id="flood" data-layout-allow-overflow></div>\n<div id="dot"></div>',
    f"""
      gsap.set("#dot", {{ x: {CX - DOT / 2}, y: 760, scaleX: 8, scaleY: 8, transformOrigin: "50% 100%" }});
      gsap.set("#flood", {{ scale: 0, opacity: 0 }});
      tl.to("#dot", {{ x: {dot_x(0)}, y: {LAND_Y}, scaleX: 1, scaleY: 1, duration: {B(0)}, ease: "power2.in" }}, 0);
      const landing = (at) => {{
        tl.to("#dot", {{ scaleX: 1.34, scaleY: 0.62, duration: 0.05, ease: "power2.out" }}, at);
        tl.to("#dot", {{ scaleX: 1, scaleY: 1, duration: 0.42, ease: "elastic.out(1, 0.5)" }}, at + 0.05);
      }};
      const say = (id, at) => tl.fromTo(id, {{ opacity: 0, filter: "blur(14px)", scale: 1.03 }},
        {{ opacity: 1, filter: "blur(0px)", scale: 1, duration: 0.16, ease: "power2.out" }}, at);
      say("#w0", {B(0) - 0.03}); landing({B(0)});
      const hop = (from, to, x) => {{
        const d = to - from;
        tl.to("#dot", {{ x, duration: d, ease: "sine.inOut" }}, from);
        tl.to("#dot", {{ y: {LAND_Y - 260}, duration: d / 2, ease: "power2.out" }}, from);
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
      bounce({B(4) + 0.14}, {B(5)}, 90); bounce({B(5) + 0.06}, {B(6)}, 48);
      tl.set("#flood", {{ opacity: 1 }}, {B(8) - 0.62});
      tl.fromTo("#flood", {{ scale: 0.012 }}, {{ scale: 1, duration: 0.62, ease: "power3.in" }}, {B(8) - 0.62});
    """,
)

# -------------------------------------------- S2: Android, then iPhone, on gold
s = B(8)
BTN = on_iphone(88, 189)
SCENES["s2-telemovel"] = scene(
    "s2-telemovel", GOLD, DEVICE_CSS + f"#hop {{ position: absolute; left: 0; top: 0; width: 18px; height: 18px; border-radius: 50%; background: {GOLD}; }}"
    f"#ring {{ position: absolute; left: 0; top: 0; width: 24px; height: 24px; box-sizing: border-box; border-radius: 50%; border: 3px solid #fff; opacity: 0; }}",
    f"""<div id="cam" class="cam" data-layout-allow-overflow>
      {android("android", img("m-debts"))}
      {iphone("iphone", img("m-today"))}
      <div id="hop"></div>
      <div id="ring"></div>
    </div>
    {words("l1", "No telemóvel", 192, 104)}
    {words("l2", "que já tem", 310, 104, dot=True, dot_color=INK)}
    <div id="burst" class="burst" data-layout-allow-overflow></div>""",
    f"""
      gsap.set("#burst", {{ scale: 0, opacity: 1 }});
      gsap.set("#iphone", {{ x: 1100 }});
      // A slow push while the words hold, so the shot is never quite still.
      tl.to("#cam", {{ x: -16.2, y: -33.0, scale: 1.03, duration: {B(13) - s}, ease: "none" }}, 0);
      tl.fromTo("#android", {{ y: 1500 }}, {{ y: 0, duration: 0.85, ease: "expo.out" }}, 0.02);
      land(".l1-w", 0.18); land(".l2-w", 0.36);
      // The Android whips away and the iPhone takes its place, same title, same line.
      tl.to("#android", {{ x: -1100, duration: 0.3, ease: "power3.in" }}, {B(11) - s - 0.28});
      tl.to("#iphone", {{ x: 0, duration: 0.6, ease: "expo.out" }}, {B(11) - s - 0.04});
      leave(".l1-w, .l2-w", {B(13) - 0.1 - s});
      {cam_js(BTN[0], BTN[1], 2.4, B(13) - s, 0.55, "power2.inOut")}
      gsap.set("#hop", {{ x: {round(BTN[0] - 9, 1)}, y: {round(BTN[1] - 460, 1)}, opacity: 0 }});
      tl.set("#hop", {{ opacity: 1 }}, {B(14) - s});
      tl.to("#hop", {{ y: {round(BTN[1] - 9, 1)}, duration: {round(B(15) - B(14), 3)}, ease: "power2.in" }}, {B(14) - s});
      gsap.set("#ring", {{ x: {round(BTN[0] - 12, 1)}, y: {round(BTN[1] - 12, 1)} }});
      tap({B(15) - s});
      {cam_js(BTN[0], BTN[1], 4.2, B(15) - s - 0.05, 0.53, "power3.in")}
      tl.to("#burst", {{ scale: 1, duration: 0.42, ease: "power3.in" }}, {B(16) - s - 0.42});
    """,
)

# ---------------------------------------- S3: the dot taps a real sale in, on gold
s = B(16)
# Tile centres read from the real screen: Açúcar, Cabo, Açúcar, Cabo, Café, then Ver venda.
TAPS = [(104, 345), (289, 485), (104, 345), (289, 485), (104, 618)]
SIZE = 30
HOPS = [(round(on_iphone(*p)[0] - SIZE / 2, 1), round(on_iphone(*p)[1] - SIZE / 2, 1)) for p in TAPS]
VER = on_iphone(326, 745)
HOPS.append((round(VER[0] - SIZE / 2, 1), round(VER[1] - SIZE / 2, 1)))
states = img("m-till-0") + "".join(img(f"m-till-{i}", f"till{i}", hidden=True) for i in range(1, 6))
tap_js = "\n      ".join(f'tl.set("#till{i + 1}", {{ opacity: 1 }}, {round(B(17 + i) - s, 3)});' for i in range(5))
hop_js = "\n      ".join(f"hop({round(B(16 + i) - s, 3)}, {round(B(17 + i) - s, 3)}, {HOPS[i][0]}, {HOPS[i][1]});" for i in range(1, 6))
SCENES["s3-balcao"] = scene(
    "s3-balcao", GOLD, DEVICE_CSS + f"#hop {{ position: absolute; left: 0; top: 0; width: {SIZE}px; height: {SIZE}px; border-radius: 50%; background: {GOLD}; box-shadow: 0 6px 14px -4px rgb(92 54 0 / .55); }}"
    f"#ring {{ position: absolute; left: 0; top: 0; width: 36px; height: 36px; box-sizing: border-box; border-radius: 50%; border: 3px solid #fff; opacity: 0; }}",
    f"""<div id="cam" class="cam" data-layout-allow-overflow>
      {iphone("iphone", states)}
      <div id="hop"></div>
      <div id="ring"></div>
    </div>
    {words("l1", "Ao balcão, é", 192, 104)}
    {words("l2", "tocar e cobrar", 310, 104, dot=True, dot_color=INK)}
    <div id="burst" class="burst" data-layout-allow-overflow></div>""",
    f"""
      gsap.set("#burst", {{ scale: 0, opacity: 1 }});
      tl.to("#cam", {{ x: -13.5, y: -27.5, scale: 1.025, duration: {round(B(22) - s - 0.1, 3)}, ease: "none" }}, 0);
      tl.fromTo("#iphone", {{ y: 1500 }}, {{ y: 0, duration: 0.72, ease: "expo.out" }}, 0.02);
      land(".l1-w", 0.08); land(".l2-w", 0.24);
      {tap_js}
      gsap.set("#hop", {{ x: {HOPS[0][0]}, y: 500, scaleX: 1, scaleY: 1, transformOrigin: "50% 100%" }});
      tl.to("#hop", {{ y: {HOPS[0][1]}, duration: {round(B(17) - s - 0.05, 3)}, ease: "power2.in" }}, 0.05);
      const squash = (at) => {{
        tl.to("#hop", {{ scaleX: 1.3, scaleY: 0.66, duration: 0.04, ease: "power2.out" }}, at);
        tl.to("#hop", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "elastic.out(1, 0.5)" }}, at + 0.04);
      }};
      const hop = (from, to, x, y) => {{
        const d = to - from;
        tl.to("#hop", {{ x, duration: d, ease: "sine.inOut" }}, from);
        tl.to("#hop", {{ y: Math.min(y, {HOPS[0][1]}) - 110, duration: d / 2, ease: "power2.out" }}, from);
        tl.to("#hop", {{ y, duration: d / 2, ease: "power2.in" }}, from + d / 2);
        squash(to);
      }};
      squash({round(B(17) - s, 3)});
      {hop_js}
      gsap.set("#ring", {{ x: {round(VER[0] - 18, 1)}, y: {round(VER[1] - 18, 1)} }});
      tap({B(22) - s});
      leave(".l1-w, .l2-w", {B(22) - s - 0.2});
      {cam_js(VER[0], VER[1], 2.5, B(22) - s - 0.1, 0.5, "power2.inOut")}
      {cam_js(VER[0], VER[1], 5.2, B(24) - s - 0.48, 0.48, "power3.in")}
      tl.to("#burst", {{ scale: 1, duration: 0.36, ease: "power3.in" }}, {B(24) - s - 0.36});
    """,
)

# ---------------------------------------- S4: the dot rises behind Documents
s = B(24)
ROW = on_iphone(210, 526)
SCENES["s4-documentos"] = scene(
    "s4-documentos", WHITE, DEVICE_CSS +
    f"#sun {{ position: absolute; left: -360px; top: 640px; width: 1800px; height: 1800px; border-radius: 50%; background: {GOLD}; }}",
    f"""<div id="sun" data-layout-allow-overflow></div>
    <div id="cam" class="cam" data-layout-allow-overflow>
      {iphone("iphone", img("m-documents"))}
    </div>
    {words("l1", "Cada documento,", 192, 92)}
    {words("l2", "comunicado à AGT", 302, 92, dot=True)}""",
    f"""
      gsap.set("#sun", {{ scale: 3.4, transformOrigin: "50% 50%" }});
      tl.to("#sun", {{ scale: 1, duration: 0.72, ease: "power3.inOut" }}, 0);
      tl.to("#sun", {{ y: -30, duration: {round(B(30) - s - 0.72, 3)}, ease: "none" }}, 0.72);
      tl.to("#cam", {{ x: -16.2, y: -33.0, scale: 1.03, duration: {round(B(30) - s - 0.06, 3)}, ease: "none" }}, 0);
      tl.fromTo("#iphone", {{ y: 1500 }}, {{ y: 0, duration: 0.85, ease: "expo.out" }}, 0.22);
      land(".l1-w", 0.46); land(".l2-w", 0.62);
      leave(".l1-w, .l2-w", {B(30) - s - 0.16});
      {cam_js(ROW[0], ROW[1], 2.1, B(30) - s - 0.06, 0.56, "power2.inOut")}
      tl.to("#cam, #sun", {{ x: "-=1700", duration: 0.24, ease: "power3.in" }}, {B(32) - s - 0.24});
    """,
)

# ------------------------------------------------------- S5: who owes you, on ink
s = B(32)
SCENES["s5-cobrar"] = scene(
    "s5-cobrar", INK, DEVICE_CSS,
    f"""{android("android", img("m-debts"))}
    {iphone("iphone", img("m-today"))}
    {words("a1", "Sabe quem", 192, 104, color=WHITE)}
    {words("a2", "lhe deve", 310, 104, color=WHITE, dot=True)}
    {words("c1", "O lembrete", 192, 104, color=WHITE)}
    {words("c2", "já vem escrito", 310, 104, color=WHITE, dot=True)}""",
    f"""
      gsap.set("#iphone", {{ x: 1200 }});
      tl.fromTo("#android", {{ scale: 1 }}, {{ scale: 1.025, duration: {round(B(36) - s, 3)}, ease: "none" }}, 0);
      tl.fromTo("#iphone", {{ scale: 1 }}, {{ scale: 1.025, duration: {round(B(40) - B(36), 3)}, ease: "none", immediateRender: false }}, {round(B(36) - s, 3)});
      gsap.set(".c1-w, .c2-w", {{ opacity: 0 }});
      tl.fromTo("#android", {{ x: 1200 }}, {{ x: 0, duration: 0.8, ease: "expo.out" }}, 0);
      land(".a1-w", 0.2); land(".a2-w", 0.38);
      leave(".a1-w, .a2-w", {B(36) - s - 0.16});
      tl.to("#android", {{ x: -1200, duration: 0.4, ease: "power3.in" }}, {B(36) - s - 0.34});
      tl.to("#iphone", {{ x: 0, duration: 0.72, ease: "expo.out" }}, {B(36) - s - 0.02});
      land(".c1-w", {B(36) - s + 0.12}); land(".c2-w", {B(36) - s + 0.3});
    """,
)

# ----------------------------------------------- S6: everything else, on the beat
s = B(40)
ITEMS = [
    (["Facturas"], 210, WHITE, INK, GOLD), (["Recibos"], 224, GOLD, INK, INK), (["Clientes"], 222, INK, WHITE, GOLD),
    (["Guias"], 300, WHITE, INK, GOLD), (["Stock"], 310, GOLD, INK, INK), (["Orçamentos"], 145, INK, WHITE, GOLD),
    (["Kwanzas"], 200, WHITE, INK, GOLD), (["21", "províncias"], 160, GOLD, INK, INK),
]
layers, js = [], []
for i, (lines, size, ground, ink, dot) in enumerate(ITEMS):
    text = "<br>".join(lines)
    layers.append(
        f'<div id="m{i}" class="abs" style="inset:0;background:{ground};opacity:0;display:flex;align-items:center;justify-content:center;text-align:center">'
        f'<div id="mw{i}" style="white-space:nowrap;line-height:1.04;letter-spacing:-.018em;font-size:{size}px;color:{ink}">{text}<span id="mp{i}" class="pt" style="background:{dot}"></span></div></div>'
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
    f"""{words("a1", "Certificado", 700, 160, color=WHITE)}
    {words("a2", "pela AGT", 876, 160, color=WHITE, dot=True)}
    {words("a3", "N.º FE/419/AGT/2026", 1128, 52, color=GREY, extra="letter-spacing:0;")}
    {words("b1", "Obrigatória a", 680, 120, color=WHITE)}
    {words("b2", "1 de janeiro", 824, 120, color=WHITE)}
    {words("b3", "de 2027", 968, 120, color=WHITE, dot=True)}""",
    f"""
      gsap.set(".b1-w, .b2-w, .b3-w", {{ opacity: 0 }});
      land(".a1-w", 0.0); land(".a2-w", 0.14); land(".a3-w", 0.42, 0.05);
      leave(".a1-w, .a2-w", {B(52) - s - 0.15});
      land(".b1-w", {B(52) - s}, 0.09); land(".b2-w", {B(52) - s + 0.18}, 0.08); land(".b3-w", {B(52) - s + 0.34}, 0.08);
      // The full stop leaps out of the top of the frame, and the end card's dot falls back in.
      tl.to("#b3 .pt", {{ scaleX: 1.15, scaleY: 0.6, duration: 0.08, ease: "power2.out" }}, {B(56) - s - 0.32});
      tl.to("#b3 .pt", {{ x: -29, y: -1120, scaleX: 1.2, scaleY: 1.9, duration: 0.24, ease: "power2.in" }}, {B(56) - s - 0.24});
    """,
)

# ------------------------------------------------------------- S8: the dot lands
s = B(56)
WM_W = 920
WM_L, WM_T = (W - WM_W) / 2, 700
K = WM_W / 524
DOT_D = 20 * K
DOT_X = WM_L + (385.01 + 1.6 - 10) * K
DOT_Y = WM_T + (70 - 3 - 10) * K
LETTERS = re.search(r'<path[^>]* d="([^"]+)"', (ROOT / "assets/facturac-ao.svg").read_text()).group(1)
SCENES["s8-fim"] = scene(
    "s8-fim", WHITE,
    f"#sun {{ position: absolute; left: -360px; top: 1240px; width: 1800px; height: 1800px; border-radius: 50%; background: {GOLD}; }}"
    f"#dot {{ position: absolute; left: 0; top: 0; width: {DOT_D}px; height: {DOT_D}px; border-radius: 50%; background: {GOLD}; }}",
    f"""<div id="sun" data-layout-allow-overflow></div>
    <svg id="wm" class="abs" style="left:{WM_L}px;top:{WM_T}px" width="{WM_W}" height="{round(80.4 * K, 1)}" viewBox="-1.6 3 524 80.4" role="img" aria-label="facturac.ao"><path fill="{INK}" d="{LETTERS}"/></svg>
    <div id="dot"></div>
    {words("cta", "Abra a sua conta grátis", 880, 68, extra="letter-spacing:-.01em;")}""",
    f"""
      gsap.set("#sun", {{ y: 700 }});
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
    ("whoosh-up", B(8) - 0.12, 0.3), ("whoosh-soft", B(11) - 0.12, 0.22), ("whoosh-soft", B(13) + 0.28, 0.22), ("pop-soft", B(15), 0.3), ("whoosh-up", B(16) - 0.12, 0.3),
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
        f'      <div id="el-{n}" class="clip" data-composition-id="{n}" data-composition-src="compositions/{n}.html" data-start="{a}" data-duration="{round(b - a, 3)}" data-track-index="1" data-track-kind="graphics" data-width="{W}" data-height="{H}"></div>'
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
    <meta name="viewport" content="width={W}, height={H}" />
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js"></script>
    <style>
      * {{ margin: 0; padding: 0; box-sizing: border-box; }}
      html, body {{ width: {W}px; height: {H}px; overflow: hidden; background: #fff; }}
      #root {{ position: relative; width: {W}px; height: {H}px; overflow: hidden; background: #fff; }}
    </style>
  </head>
  <body>
    <div id="root" data-composition-id="main" data-start="0" data-duration="{END}" data-width="{W}" data-height="{H}" data-motion-blur="on" data-motion-blur-shutter="120">
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
