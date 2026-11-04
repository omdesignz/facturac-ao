#!/usr/bin/env python3
"""Writes index.html and compositions/ for "Quem me deve?". Run from the project root.

The money comes first: what is owed, who owes it, the reminder already written, nothing
sent until confirmed. Real screens from a local demo company; a tall capture scrolls
inside the phone under the app's own header and tab bar.
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



SCROLL_CSS = """
    .stack { height: calc(777 * var(--u)); overflow: hidden; background: #fff; }
    .scroll { position: absolute; z-index: 1; left: 0; top: 0; width: 100%; }
    .scroll img { display: block; width: 100%; height: auto; }
    .scroll img + img { position: absolute; left: 0; top: 0; }
    .bar-top { position: absolute; z-index: 3; left: 0; right: 0; top: 0; height: calc(64 * var(--u)); overflow: hidden; }
    .bar-top img { position: absolute; left: 0; top: 0; }
    .bar-white { position: absolute; z-index: 3; left: 0; right: 0; bottom: 0; height: calc(65 * var(--u)); background: #fff; }
    .bar-bottom { position: absolute; z-index: 4; left: 0; right: 0; bottom: 0; height: calc(92 * var(--u)); overflow: hidden; mix-blend-mode: multiply; }
    .bar-bottom img { position: absolute; left: 0; bottom: 0; }
"""
BARS = '<div class="bar-top"><img src="assets/screens/q-nav.webp" alt="" /></div><div class="bar-white"></div><div class="bar-bottom"><img src="assets/screens/q-nav.webp" alt="" /></div>'
ODO_CSS = """
    .odo { position: absolute; left: 0; width: 1080px; display: flex; justify-content: center; line-height: 1.1; letter-spacing: 0; }
    .cell { display: block; width: .556em; height: 1.1em; overflow: hidden; text-align: center; }
    .strip { display: block; }
    .gap { display: block; width: .24em; }
"""
DOT_CSS = lambda size: f"#hop {{ position: absolute; left: 0; top: 0; width: {size}px; height: {size}px; border-radius: 50%; background: {GOLD}; box-shadow: 0 6px 14px -4px rgb(92 54 0 / .55); }}" + f"#ring {{ position: absolute; left: 0; top: 0; width: {size + 6}px; height: {size + 6}px; box-sizing: border-box; border-radius: 50%; border: 3px solid #fff; opacity: 0; }}"


def odometer(el_id, value, top, size, color):
    """Rolling digits. Each digit is a 0-9 strip moved by transform, so any frame can be sought."""
    cells, i = [], 0
    for ch in value:
        if ch == " ":
            cells.append('<span class="gap"></span>')
        else:
            digits = "<br>".join("0123456789")
            cells.append(f'<span class="cell"><span id="{el_id}-d{i}" class="strip">{digits}</span></span>')
            i += 1
    return f'<div id="{el_id}" class="odo" style="top:{top}px;font-size:{size}px;color:{color}">{"".join(cells)}</div>'


def digits_of(value):
    return [int(c) for c in value if c != " "]


def group(total):
    return f"{total:,}".replace(",", " ")


SCENES = {}

# ------------------------------------------------------------ S1: the money
TOTAL = 5_529_000
S_TOP, S_SIZE = 960, 84
# "Kz por receber." measured in Century Gothic Bold at 84 px: width, then the dot's slot.
DOT = 16
DX = round((W - 598.0) / 2 + 582.1, 1)
DY = S_TOP + 59
start = digits_of(group(TOTAL))
roll = "\n      ".join(f'gsap.set("#n-d{i}", {{ yPercent: 0 }}); tl.to("#n-d{i}", {{ yPercent: {-10 * d}, duration: {round(B(3) - B(0) - 0.06 * (6 - i), 3)}, ease: "power3.out" }}, {B(0)});' for i, d in enumerate(start))
SCENES["s1-dinheiro"] = scene(
    "s1-dinheiro", INK, ODO_CSS +
    f"#dot {{ position: absolute; left: 0; top: 0; width: {DOT}px; height: {DOT}px; border-radius: 50%; background: {GOLD}; }}"
    f"#flood {{ position: absolute; left: {DX - 1500 + DOT / 2}px; top: {DY - 1500 + DOT / 2}px; width: 3000px; height: 3000px; border-radius: 50%; background: {GOLD}; }}",
    f"""{odometer("n", group(TOTAL), 700, 210, WHITE)}
    {words("sub", "Kz por receber", S_TOP, S_SIZE, color=WHITE)}
    <div id="flood" data-layout-allow-overflow></div>
    <div id="dot"></div>""",
    f"""
      gsap.set("#dot", {{ x: {CX - DOT / 2}, y: 620, scaleX: 16, scaleY: 16, transformOrigin: "50% 100%" }});
      gsap.set("#flood", {{ scale: 0, opacity: 0 }});
      gsap.set("#n, .sub-w", {{ opacity: 0 }});
      // The dot falls onto the first beat and becomes the full stop. The number rolls up to what is owed.
      tl.to("#dot", {{ x: {DX}, y: {DY}, scaleX: 1, scaleY: 1, duration: {B(0)}, ease: "power2.in" }}, 0);
      const landing = (at) => {{
        tl.to("#dot", {{ scaleX: 1.34, scaleY: 0.62, duration: 0.05, ease: "power2.out" }}, at);
        tl.to("#dot", {{ scaleX: 1, scaleY: 1, duration: 0.42, ease: "elastic.out(1, 0.5)" }}, at + 0.05);
      }};
      landing({B(0)});
      tl.to("#n", {{ opacity: 1, duration: 0.1, ease: "power1.out" }}, {B(0) - 0.03});
      {roll}
      land(".sub-w", {B(0)}, 0.05);
      const bounce = (from, to, height) => {{
        tl.to("#dot", {{ y: {DY} - height, duration: (to - from) / 2, ease: "power2.out" }}, from);
        tl.to("#dot", {{ y: {DY}, duration: (to - from) / 2, ease: "power2.in" }}, from + (to - from) / 2);
        landing(to);
      }};
      bounce({B(4) + 0.14}, {B(5)}, 90); bounce({B(5) + 0.06}, {B(6)}, 48);
      tl.set("#flood", {{ opacity: 1 }}, {B(8) - 0.62});
      tl.fromTo("#flood", {{ scale: {round(DOT / 3000, 4)} }}, {{ scale: 1, duration: 0.62, ease: "power3.in" }}, {B(8) - 0.62});
    """,
)

# ---------------------------------------- S2: who owes, oldest first, on gold
s = B(8)
LIST_SCROLL = 260
TICK = on_iphone(46, 472 - LIST_SCROLL)
ROW = on_iphone(196.5, 265)
screen = f'<div id="sc" class="scroll">{img("q-tall-0")}{img("q-tall-1", "l1", hidden=True)}</div>{BARS}'
kx, ky, kk = round(540 * -0.03, 1), round(1100 * -0.03, 1), 1.03
SCENES["s2-lista"] = scene(
    "s2-lista", GOLD, DEVICE_CSS + SCROLL_CSS + DOT_CSS(30),
    f"""<div id="cam" class="cam" data-layout-allow-overflow>
      {iphone("iphone", screen)}
      <div id="hop"></div>
      <div id="ring"></div>
    </div>
    {words("t1", "Quem me deve?", 250, 112)}
    {words("l1t", "O mais antigo", 192, 104)}
    {words("l2t", "vem primeiro", 310, 104, dot=True, dot_color=INK)}
    <div id="burst" class="burst" data-layout-allow-overflow></div>""",
    f"""
      gsap.set("#burst", {{ scale: 0, opacity: 1 }});
      gsap.set(".l1t-w, .l2t-w", {{ opacity: 0 }});
      tl.to("#cam", {{ x: {kx}, y: {ky}, scale: {kk}, duration: {round(B(16) - s, 3)}, ease: "none" }}, 0);
      tl.fromTo("#iphone", {{ y: 1500 }}, {{ y: 0, duration: 0.85, ease: "expo.out" }}, 0.02);
      land(".t1-w", 0.18, 0.06);
      leave(".t1-w", {round(B(12) - s - 0.16, 3)});
      land(".l1t-w", {round(B(12) - s + 0.02, 3)}); land(".l2t-w", {round(B(12) - s + 0.18, 3)});
      tl.to("#sc", {{ y: {round(-LIST_SCROLL * U, 1)}, duration: 0.9, ease: "power3.inOut" }}, {round(B(12) - s - 0.05, 3)});
      leave(".l1t-w, .l2t-w", {round(B(16) - s - 0.12, 3)});
      {cam_js(ROW[0], ROW[1], 1.72, B(16) - s, 0.55, "power2.inOut")}
      // The dot drops onto the first customer's box and ticks it.
      gsap.set("#hop", {{ x: {round(TICK[0] - 15, 1)}, y: {round(TICK[1] - 330, 1)}, opacity: 0 }});
      gsap.set("#ring", {{ x: {round(TICK[0] - 18, 1)}, y: {round(TICK[1] - 18, 1)}, borderColor: "{INK}" }});
      tl.set("#hop", {{ opacity: 1 }}, {round(B(17) - s, 3)});
      tl.to("#hop", {{ y: {round(TICK[1] - 15, 1)}, duration: {round(B(18) - B(17), 3)}, ease: "power2.in" }}, {round(B(17) - s, 3)});
      tap({round(B(18) - s, 3)});
      tl.set("#l1", {{ opacity: 1 }}, {round(B(18) - s + 0.03, 3)});
      {cam_js(ROW[0], ROW[1], 3.8, B(24) - s - 0.48, 0.48, "power3.in")}
      tl.to("#burst", {{ scale: 1, duration: 0.42, ease: "power3.in" }}, {round(B(24) - s - 0.42, 3)});
    """,
)

# ---------------------------------------------- S3: the reminder is already written
s = B(24)
PANEL_SCROLL = 130
panel = lambda el: f'<div id="{el}" class="scroll">{img("q-tall-panel")}</div>{BARS}'
SCENES["s3-lembrete"] = scene(
    "s3-lembrete", WHITE, DEVICE_CSS + SCROLL_CSS +
    f"#sun {{ position: absolute; left: -360px; top: 640px; width: 1800px; height: 1800px; border-radius: 50%; background: {GOLD}; }}",
    f"""<div id="sun" data-layout-allow-overflow></div>
    <div id="cam" class="cam" data-layout-allow-overflow>
      {iphone("iphone", panel("sc"))}
    </div>
    {words("l1", "O lembrete", 192, 104)}
    {words("l2", "já vem escrito", 310, 104, dot=True)}""",
    f"""
      gsap.set("#sun", {{ scale: 3.4, transformOrigin: "50% 50%" }});
      tl.to("#sun", {{ scale: 1, duration: 0.72, ease: "power3.inOut" }}, 0);
      tl.to("#sun", {{ y: -30, duration: {round(B(32) - s - 0.96, 3)}, ease: "none" }}, 0.72);
      tl.to("#cam", {{ x: {kx}, y: {ky}, scale: {kk}, duration: {round(B(32) - s - 0.24, 3)}, ease: "none" }}, 0);
      tl.fromTo("#iphone", {{ y: 1500 }}, {{ y: 0, duration: 0.85, ease: "expo.out" }}, 0.22);
      land(".l1-w", 0.46); land(".l2-w", 0.62);
      tl.to("#sc", {{ y: {round(-PANEL_SCROLL * U, 1)}, duration: 2.1, ease: "sine.inOut" }}, {round(B(26) - s, 3)});
      leave(".l1-w, .l2-w", {round(B(32) - s - 0.3, 3)});
      tl.to("#cam, #sun", {{ x: "-=1700", duration: 0.24, ease: "power3.in" }}, {round(B(32) - s - 0.24, 3)});
    """,
)

# ---------------------------------------------- S4: nothing goes until you confirm
s = B(32)
SEND = on_iphone(196.5, 702 - PANEL_SCROLL)
sent_html = f'<div class="scroll" style="transform:translateY({round(-PANEL_SCROLL * U, 1)}px)">{img("q-tall-panel")}</div>{BARS}{img("q-sent", "sent", hidden=True)}'
SCENES["s4-enviar"] = scene(
    "s4-enviar", INK, DEVICE_CSS + SCROLL_CSS + DOT_CSS(34) + "#sent { position: absolute; z-index: 5; left: 0; top: 0; display: block; width: 100%; height: auto; }",
    f"""<div id="cam" class="cam" data-layout-allow-overflow>
      {iphone("iphone", sent_html)}
      <div id="hop"></div>
      <div id="ring"></div>
    </div>
    {words("a1", "Nada é enviado", 192, 104, color=WHITE)}
    {words("a2", "até confirmar", 310, 104, color=WHITE, dot=True)}
    {words("c1", "Lembrete", 192, 104, color=WHITE)}
    {words("c2", "enviado", 310, 104, color=WHITE, dot=True)}""",
    f"""
      gsap.set(".c1-w, .c2-w", {{ opacity: 0 }});
      tl.to("#cam", {{ x: {kx}, y: {ky}, scale: {kk}, duration: {round(B(40) - s, 3)}, ease: "none" }}, 0);
      tl.fromTo("#iphone", {{ x: 1200 }}, {{ x: 0, duration: 0.8, ease: "expo.out" }}, 0);
      land(".a1-w", 0.2); land(".a2-w", 0.38);
      gsap.set("#hop", {{ x: {round(SEND[0] - 17, 1)}, y: {round(SEND[1] - 700, 1)}, opacity: 0 }});
      gsap.set("#ring", {{ x: {round(SEND[0] - 20, 1)}, y: {round(SEND[1] - 20, 1)} }});
      tl.set("#hop", {{ opacity: 1 }}, {round(B(35) - s, 3)});
      tl.to("#hop", {{ y: {round(SEND[1] - 17, 1)}, duration: {round(B(36) - B(35), 3)}, ease: "power2.in" }}, {round(B(35) - s, 3)});
      tap({round(B(36) - s, 3)});
      tl.set("#sent", {{ opacity: 1 }}, {round(B(36) - s + 0.2, 3)});
      leave(".a1-w, .a2-w", {round(B(36) - s + 0.02, 3)});
      land(".c1-w", {round(B(36) - s + 0.2, 3)}); land(".c2-w", {round(B(36) - s + 0.34, 3)});
    """,
)

# ---------------------------------------------- S5: customer by customer, it falls
s = B(40)
PAID = [("Oficina Rangel Auto", 61_560), ("Restaurante Ilha Azul", 159_600), ("Transportes Lubango Express", 144_780), ("Papelaria Cazenga", 200_640),
        ("Construções Kwanza Sul", 90_060), ("Pescas do Namibe", 68_400), ("Gráfica Sambizanga", 34_200), ("Peixaria Mussulo", 108_300)]
GROUNDS = [(WHITE, INK, GOLD), (GOLD, INK, INK), (INK, WHITE, GOLD)]
layers, js, total = [], [], TOTAL
for i, (name, amount) in enumerate(PAID):
    ground, ink, dot = GROUNDS[i % 3]
    before, total = total, total - amount
    at = round(B(40 + i) - s, 3)
    layers.append(
        f'<div id="m{i}" class="abs" style="inset:0;background:{ground};opacity:0">'
        f'<div class="t" style="top:610px;font-size:56px;color:{ink}">Por receber</div>'
        + odometer(f"o{i}", group(before), 690, 210, ink)
        + f'<div id="nm{i}" class="t" style="top:970px;font-size:52px;color:{ink}">{name} pagou<span id="mp{i}" class="pt" style="background:{dot}"></span></div></div>'
    )
    js.append(f'tl.set("#m{i}", {{ opacity: 1 }}, {at});')
    if i:
        js.append(f'tl.set("#m{i - 1}", {{ opacity: 0 }}, {at});')
    for j, (a, b) in enumerate(zip(digits_of(group(before)), digits_of(group(total)))):
        js.append(f'gsap.set("#o{i}-d{j}", {{ yPercent: {-10 * a} }});')
        if a != b:
            js.append(f'tl.to("#o{i}-d{j}", {{ yPercent: {-10 * b}, duration: 0.34, ease: "expo.out" }}, {round(at + 0.02, 3)});')
    js.append(f'tl.fromTo("#mp{i}", {{ scale: 0 }}, {{ scale: 1, duration: 0.3, ease: "elastic.out(1, 0.55)" }}, {round(at + 0.09, 3)});')
SCENES["s5-numeros"] = scene("s5-numeros", WHITE, ODO_CSS, "\n".join(layers), "\n      ".join(js))

# ------------------------------------------------------------ S6: the point
s = B(48)
SCENES["s6-seu"] = scene(
    "s6-seu", INK, "",
    f"""{words("a1", "Este dinheiro", 780, 130, color=WHITE)}
    {words("a2", "já era seu", 930, 130, color=WHITE, dot=True)}
    {words("b1", "Só faltava", 780, 130, color=WHITE)}
    {words("b2", "lembrar", 930, 130, color=WHITE, dot=True)}""",
    f"""
      gsap.set(".b1-w, .b2-w", {{ opacity: 0 }});
      land(".a1-w", 0.0); land(".a2-w", 0.14);
      leave(".a1-w, .a2-w", {round(B(52) - s - 0.15, 3)});
      land(".b1-w", {round(B(52) - s, 3)}, 0.09); land(".b2-w", {round(B(52) - s + 0.2, 3)}, 0.08);
      // The full stop leaps out of the top of the frame, and the end card's dot falls back in.
      tl.to("#b2 .pt", {{ scaleX: 1.15, scaleY: 0.6, duration: 0.08, ease: "power2.out" }}, {round(B(56) - s - 0.32, 3)});
      tl.to("#b2 .pt", {{ x: -45, y: -1120, scaleX: 1.2, scaleY: 1.9, duration: 0.24, ease: "power2.in" }}, {round(B(56) - s - 0.24, 3)});
    """,
)
# ------------------------------------------------------------- S7: the dot lands
s = B(56)
WM_W = 920
WM_L, WM_T = (W - WM_W) / 2, 700
K = WM_W / 524
DOT_D = 20 * K
DOT_X = WM_L + (385.01 + 1.6 - 10) * K
DOT_Y = WM_T + (70 - 3 - 10) * K
LETTERS = re.search(r'<path[^>]* d="([^"]+)"', (ROOT / "assets/facturac-ao.svg").read_text()).group(1)
SCENES["s7-fim"] = scene(
    "s7-fim", WHITE,
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
    ("s1-dinheiro", 0.0, B(8)), ("s2-lista", B(8), B(24)), ("s3-lembrete", B(24), B(32)), ("s4-enviar", B(32), B(40)),
    ("s5-numeros", B(40), B(48)), ("s6-seu", B(48), B(56)), ("s7-fim", B(56), END),
]
DUR = {"whoosh-soft": 1.579, "whoosh-up": 1.579, "pop-soft": 0.575, "pop-confirm": 0.575, "riser-short": 2.17, "impact-soft": 2.757, "drop-thud": 0.792}
# Where each file is loudest, measured. An effect is placed by its peak, one frame after the picture.
PEAK = {"whoosh-soft": 0.51, "whoosh-up": 0.51, "pop-soft": 0.03, "pop-confirm": 0.03, "riser-short": 2.17, "impact-soft": 0.57, "drop-thud": 0.05}
FX = [
    ("drop-thud", B(0), 0.55), ("pop-soft", B(1), 0.24), ("pop-soft", B(2), 0.28), ("pop-confirm", B(3), 0.42), ("pop-soft", B(5), 0.2), ("pop-soft", B(6), 0.16),
    ("whoosh-up", B(8) - 0.12, 0.3), ("whoosh-soft", B(8) + 0.3, 0.2), ("whoosh-soft", B(12) + 0.4, 0.2), ("whoosh-soft", B(16) + 0.28, 0.22), ("pop-confirm", B(18), 0.42),
    ("whoosh-up", B(24) - 0.1, 0.3), ("whoosh-soft", B(24) + 0.33, 0.24),
    ("whoosh-up", B(32) - 0.08, 0.3), ("pop-confirm", B(36), 0.46),
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
