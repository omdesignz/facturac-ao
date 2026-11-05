#!/usr/bin/env python3
"""Writes the four launch films under films/<film>/. Run from the project root.

The voice comes first. Each film is one continuous ElevenLabs read (assets/vo), and
every cut, camera move and landing below is placed on a word of that read: `c(t)` is
a second of the read, in film time. tools/mix_voice.py lays the read over the render.

The phone lives in the root and travels between scenes; the scenes carry the type.
Everything on a phone is a real capture of the iPhone app against the Distribuidora
Ngola demo company (tools/demo). The app films use Apple's iPhone 17 frame. "O Endereço"
shows the website, so its frame and address bar are drawn here instead: Apple's licence
covers mock-ups of iOS-only software, and the website is not that.

The look follows the app: Inter, one gold accent, hairlines, nothing that bounces but
the dot. Only the word that becomes the name stays in the brand's outlines, because it
has to turn into the logo.
"""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "tools"))
import type_outlines as T  # noqa: E402

W, H = 1080, 1920
INK, SOFT, GOLD, RED = "#171716", "#6b6b70", "#f9b233", "#a80f3a"
SFX_LEN = {
    "chime-resolve": 1.441, "drop-thud": 0.792, "error-buzz": 1.247, "impact-soft": 2.0, "keyboard-typing": 1.1,
    "pop-confirm": 0.575, "pop-soft": 0.575, "stamp-thud": 0.792, "tick-soft": 0.6, "whoosh-soft": 1.579, "whoosh-up": 1.579,
}

VOICE_AT = 0.35  # where the read starts in every film


def c(t):
    return VOICE_AT + t


def r(v, n=3):
    return round(v, n)


# ----------------------------------------------------------------- the phone

FRAME_W, FRAME_H = 1350, 2760
SCREEN_X, SCREEN_Y, SCREEN_W, SCREEN_H = 72, 69, 1206, 2622
ICON = (SCREEN_X + 864, SCREEN_Y + 2412)  # the app's dock icon, in frame pixels


def placed(scale, x=None, y=None):
    """Top-left of the frame for a scale, centred horizontally unless told otherwise."""
    if x is None:
        x = (W - FRAME_W * scale) / 2
    return {"x": r(x, 2), "y": r(y, 2), "scale": scale}


def focus(scale, px, py, cx=W / 2, cy=H / 2):
    """Puts the frame point (px, py) at canvas (cx, cy) at this scale."""
    return {"x": r(cx - px * scale, 2), "y": r(cy - py * scale, 2), "scale": scale}


def gsap_state(s):
    return f'x: {s["x"]}, y: {s["y"]}, scale: {s["scale"]}'


CENTRE = placed(0.62, y=(H - FRAME_H * 0.62) / 2)
UNDER_TITLE = placed(0.56, y=430)
UNDER_WORD = placed(0.56, y=560)
GONE = {**UNDER_TITLE, "y": H + 160}

# Places on the real screens the voice names (x, y, width, height in screen pixels).
SPOT_FIGURE = (61, 495, 1084, 240)
SPOT_OVERDUE = (61, 752, 1084, 118)
SPOT_DECISION = (61, 1407, 1084, 504)
SPOT_NUMBERS = (190, 790, 380, 820)
SPOT_VALIDATED = (112, 716, 450, 96)


# -------------------------------------------------------------- the type

FONT_FACES = """
    @font-face { font-family: "Inter"; font-weight: 500; font-style: normal; font-display: block;
      src: url("assets/fonts/Inter-Medium.woff2") format("woff2"); }
    @font-face { font-family: "Inter"; font-weight: 600; font-style: normal; font-display: block;
      src: url("assets/fonts/Inter-SemiBold.woff2") format("woff2"); }
    @font-face { font-family: "Inter Display"; font-weight: 600; font-style: normal; font-display: block;
      src: url("assets/fonts/InterDisplay-SemiBold.woff2") format("woff2"); }
"""


class Scene:
    """One sub-composition. Times given to its methods are film seconds."""

    def __init__(self, cid, start, stop):
        self.cid, self.start, self.stop = cid, start, stop
        self.dur = stop - start
        self.fid = "frame-" + cid
        self.css, self.body, self.js = [], [], []
        self.count = 0

    def at(self, t):
        return r(t - self.start)

    def _id(self, kind):
        self.count += 1
        return f"{self.fid}-{kind}{self.count}"

    def head(self, html, px, top, t_in, t_out=None, color=INK, stagger=0.045):
        """A line of display type; its words arrive in turn and leave together. With no
        t_in it is already there on the first frame, which is the thumbnail."""
        lid = self._id("l")
        words = html.split(" ")
        spans = "".join(f'<span id="{lid}-w{i}" class="{lid}-w">{w}</span>' for i, w in enumerate(words))
        self.css.append(
            f"#{lid} {{ position: absolute; left: 54px; right: 54px; top: {top}px; display: flex; justify-content: center; flex-wrap: wrap;"
            f' gap: 0 {px * 0.24:.1f}px; font: 600 {px}px/1.08 "Inter Display", sans-serif; letter-spacing: -0.032em; color: {color}; }}'
            f"\n    .{lid}-w {{ display: inline-block; opacity: 0; }}\n    #{lid} b {{ font-weight: inherit; color: {GOLD}; }}"
        )
        self.body.append(f'<div id="{lid}">{spans}</div>')
        for i in range(len(words)):
            if t_in is None:
                self.js.append(f'tl.set("#{lid}-w{i}", {{ opacity: 1 }}, 0);')
                continue
            self.js.append(f'tl.fromTo("#{lid}-w{i}", {{ opacity: 0, y: 22 }}, {{ opacity: 1, y: 0, duration: 0.3, ease: "power3.out" }}, {self.at(t_in + i * stagger)});')
        self._out(f"#{lid}", t_out)

    def cap(self, text, top, t_in, t_out=None, px=44):
        """One line said by the voice, in the quiet colour."""
        lid = self._id("c")
        self.css.append(
            f'#{lid} {{ position: absolute; left: 54px; right: 54px; top: {top}px; text-align: center; opacity: 0;'
            f' font: 500 {px}px/1.2 "Inter", sans-serif; letter-spacing: -0.014em; color: {SOFT}; }}'
        )
        self.body.append(f'<div id="{lid}">{text}</div>')
        if t_in is None:
            self.js.append(f'tl.set("#{lid}", {{ opacity: 1 }}, 0);')
        else:
            self.js.append(f'tl.fromTo("#{lid}", {{ opacity: 0, y: 12 }}, {{ opacity: 1, y: 0, duration: 0.24, ease: "power3.out" }}, {self.at(t_in)});')
        self._out(f"#{lid}", t_out)

    def _out(self, selector, t_out):
        t = self.stop - 0.16 if t_out is None else t_out - 0.16
        self.js.append(f'tl.to("{selector}", {{ opacity: 0, y: -10, duration: 0.16, ease: "power2.in" }}, {self.at(t)});')

    def doc(self):
        css = "\n    ".join(self.css)
        body = "\n      ".join(self.body)
        js = "\n      ".join(self.js)
        return f"""<template>
  <style>
{FONT_FACES}
    #root {{ position: absolute; inset: 0; width: {W}px; height: {H}px; overflow: hidden;
      font-family: "Inter", sans-serif; color: {INK}; }}
    {css}
  </style>
  <div id="root" data-composition-id="{self.cid}" data-width="{W}" data-height="{H}">
    <div id="{self.fid}-stage" class="clip" data-start="0" data-duration="{r(self.dur)}" data-layout-allow-overflow>
      {body}
    </div>
  </div>
  <script>
    (function () {{
      const tl = gsap.timeline({{ paused: true }});
      tl.set({{}}, {{}}, {r(self.dur)});
      {js}
      window.__timelines["{self.cid}"] = tl;
    }})();
  </script>
</template>
"""


# ------------------------------------------- the word that becomes the name

_, _PONTO_W = T.layout("facturação")
PONTO_A_X = T.layout("facturação")[0][8]["x"]  # where "a" starts in "facturação", brand units
AO_SHIFT = 29.47  # brand units: where "ao" sits in the logo vs. the unbroken word
GROUP_SHIFT = -AO_SHIFT / 2
DOT_CX, DOT_R, X_HEIGHT_TOP = 385.01, 10.0, 45.51


def ao_word(text, px, prefix, left=None, baseline=0.0):
    """A word ending in "ção" laid out so it can become "...c.ao" exactly as the logo does."""
    glyphs, width = T.layout(text)
    if left is None:
        px = min(px, 960 / (width / T.EM))
    k = px / T.EM
    i_c = next(i for i, g in enumerate(glyphs) if g["ch"] == "ç")
    i_a, i_o = i_c + 1, i_c + 2
    if left is None:
        left = (W - width * k) / 2
    top = baseline - T.ASCENT * k
    html, _, _ = T.word_html(text, px, prefix)
    dot_x = left + (glyphs[i_a]["x"] + (DOT_CX - PONTO_A_X) + GROUP_SHIFT) * k
    return {
        "html": f'<div id="{prefix}-box" style="position:absolute;left:{left:.2f}px;top:{top:.2f}px">{html}</div>',
        "prefix": prefix, "k": k, "count": len(glyphs), "i_c": i_c, "i_a": i_a, "i_o": i_o,
        "land": (dot_x, top + (T.ASCENT - DOT_R) * k),
        "o_top": (left + (glyphs[i_o]["x"] + AO_SHIFT + GROUP_SHIFT + 32) * k, top + (X_HEIGHT_TOP - DOT_R) * k),
        "dot_d": 2 * DOT_R * k,
    }


def ao_motion(js, w, t_til, t_ced, t_dot, dot_id, enter_from=(1240, 160), fly=1.0):
    """The til flies, the cedilha drops, the letters part and the dot lands: the brand's move."""
    p, k, d = w["prefix"], w["k"], w["dot_d"]
    til, ced = f'#{p}-g{w["i_a"]}-tilde', f'#{p}-g{w["i_c"]}-cedilla'
    for mark in (til, ced):
        js.append(f'tl.to("{mark} path", {{ fill: "{GOLD}", duration: 0.16, ease: "power1.out" }}, {r(min(t_til, t_ced) - 0.3)});')
    js.append(f'tl.to("{til}", {{ x: {r(420 * fly)}, y: {r(-560 * fly)}, rotation: 200, scale: 0.6, opacity: 0, duration: 0.5, ease: "power2.in", transformOrigin: "50% 60%" }}, {r(t_til)});')
    js.append(f'tl.to("{ced}", {{ y: {r(1100 * fly)}, rotation: 24, opacity: 0, duration: 0.6, ease: "power2.in", transformOrigin: "40% 0%" }}, {r(t_ced)});')
    for i in range(w["count"]):
        shift = AO_SHIFT + GROUP_SHIFT if i in (w["i_a"], w["i_o"]) else GROUP_SHIFT
        js.append(f'tl.to("#{p}-g{i}", {{ x: {r(shift * k, 2)}, duration: 0.34, ease: "power3.inOut" }}, {r(t_dot - 0.5)});')
    hops = [(enter_from, t_dot - 0.46), (w["o_top"], t_dot - 0.22), (w["land"], t_dot)]
    js.append(f'tl.set("#{dot_id}", {{ x: {r(enter_from[0] - d / 2, 2)}, y: {r(enter_from[1] - d / 2, 2)}, opacity: 1 }}, {r(t_dot - 0.48)});')
    for ((x0, y0), t0), ((x1, y1), t1), apex in zip(hops, hops[1:], [100 * k / 1.5, 130 * k / 1.5]):
        dur = t1 - t0
        js.append(f'tl.to("#{dot_id}", {{ x: {r(x1 - d / 2, 2)}, duration: {r(dur)}, ease: "none" }}, {r(t0)});')
        js.append(f'tl.to("#{dot_id}", {{ y: {r(min(y0, y1) - apex - d / 2, 2)}, duration: {r(dur / 2)}, ease: "power2.out" }}, {r(t0)});')
        js.append(f'tl.to("#{dot_id}", {{ y: {r(y1 - d / 2, 2)}, duration: {r(dur / 2)}, ease: "power2.in" }}, {r(t0 + dur / 2)});')
    js.append(f'tl.to("#{dot_id}", {{ scaleX: 1.25, scaleY: 0.78, duration: 0.05, ease: "power2.out", transformOrigin: "50% 100%" }}, {r(t_dot)});')
    js.append(f'tl.to("#{dot_id}", {{ scaleX: 1, scaleY: 1, duration: 0.2, ease: "power2.out" }}, {r(t_dot + 0.05)});')


def dot_css(dot_id, d):
    return f"#{dot_id} {{ position: absolute; left: 0; top: 0; width: {d:.2f}px; height: {d:.2f}px; border-radius: 50%; background: {GOLD}; opacity: 0; }}"


def add_word(scene, text, px, baseline, t_til, t_ced, t_dot, t_in=None):
    """Puts a "-ção" word in a scene and runs the brand's move on it."""
    prefix = f"{scene.fid}-word"
    w = ao_word(text, px, prefix, baseline=baseline)
    dot = f"{scene.fid}-dot"
    scene.css.append(dot_css(dot, w["dot_d"]))
    scene.body.append(w["html"])
    scene.body.append(f'<div id="{dot}"></div>')
    if t_in is not None:
        scene.js.append(f'tl.fromTo("#{prefix}-box", {{ opacity: 0, y: 26 }}, {{ opacity: 1, y: 0, duration: 0.3, ease: "power3.out" }}, {scene.at(t_in)});')
    ao_motion(scene.js, w, scene.at(t_til), scene.at(t_ced), scene.at(t_dot), dot)
    return w, dot


def end_scene(cid, start, stop, t_word, t_land, t_mark, t_cta):
    """"Disponível." with the dot as its full stop, the wordmark, and the app's own gold button."""
    s = Scene(cid, start, stop)
    f = s.fid
    px, dot, mark_w = 156, 30, 440
    s.css.append(f"""#{f}-line {{ position: absolute; left: 0; right: 0; top: 700px; display: flex; justify-content: center; align-items: baseline;
      font: 600 {px}px/1 "Inter Display", sans-serif; letter-spacing: -0.04em; color: {INK}; }}
    #{f}-word {{ display: inline-block; }}
    #{f}-dot {{ display: inline-block; width: {dot}px; height: {dot}px; margin-left: 10px; border-radius: 50%; background: {GOLD}; }}
    #{f}-mark {{ position: absolute; left: {(W - mark_w) / 2:.2f}px; top: 930px; width: {mark_w}px; height: {mark_w * 80.4 / 524:.2f}px; }}
    #{f}-cta {{ position: absolute; left: 50%; top: 1100px; width: 640px; margin-left: -320px; height: 112px; border-radius: 26px;
      display: flex; align-items: center; justify-content: center; background: {GOLD}; color: {INK}; font: 600 42px/1 "Inter", sans-serif; letter-spacing: -0.012em; }}""")
    s.body.append(f'<div id="{f}-line"><span id="{f}-word">Disponível</span><span id="{f}-dot"></span></div>')
    s.body.append(f'<img id="{f}-mark" src="assets/facturac-ao.svg" alt="facturac.ao">')
    s.body.append(f'<div id="{f}-cta">Abra a sua conta grátis</div>')
    s.js += [
        f'tl.fromTo("#{f}-word", {{ opacity: 0, y: 24 }}, {{ opacity: 1, y: 0, duration: 0.34, ease: "power3.out" }}, {s.at(t_word)});',
        f'tl.fromTo("#{f}-dot", {{ y: -760, opacity: 1 }}, {{ y: 0, opacity: 1, duration: 0.34, ease: "power2.in", immediateRender: true }}, {s.at(t_land - 0.34)});',
        f'tl.to("#{f}-dot", {{ scaleX: 1.25, scaleY: 0.78, duration: 0.05, transformOrigin: "50% 100%" }}, {s.at(t_land)});',
        f'tl.to("#{f}-dot", {{ scaleX: 1, scaleY: 1, duration: 0.22, ease: "power2.out" }}, {s.at(t_land + 0.05)});',
        f'tl.fromTo("#{f}-mark", {{ opacity: 0, y: 14 }}, {{ opacity: 1, y: 0, duration: 0.36, ease: "power3.out" }}, {s.at(t_mark)});',
        f'tl.fromTo("#{f}-cta", {{ opacity: 0, y: 18 }}, {{ opacity: 1, y: 0, duration: 0.36, ease: "power3.out" }}, {s.at(t_cta)});',
    ]
    return s


# ------------------------------------------------------------------- root

DRAWN_FRAME = """
      #frame-drawn { position: absolute; inset: 0; border-radius: 228px; background: #1c1c1e;
        box-shadow: inset 0 0 0 5px #5a5a5f, inset 0 0 0 9px #2a2a2c, inset 0 0 0 64px #050505; }
      #frame-drawn::before { content: ""; position: absolute; left: 4px; right: 4px; top: 4px; bottom: 4px; border-radius: 224px;
        border: 2px solid rgba(255,255,255,0.10); }
      .frame-key { position: absolute; width: 10px; border-radius: 4px; background: #2c2c2e; box-shadow: inset 1px 0 0 rgba(255,255,255,0.12); }
      #frame-island { position: absolute; left: 487px; top: 104px; width: 376px; height: 110px; border-radius: 55px; background: #000; }
"""
DRAWN_FRAME_HTML = """
        <div id="frame-drawn"></div>
        <div class="frame-key" style="left:-8px;top:520px;height:120px"></div>
        <div class="frame-key" style="left:-8px;top:700px;height:190px"></div>
        <div class="frame-key" style="left:-8px;top:930px;height:190px"></div>
        <div class="frame-key" style="right:-8px;top:760px;height:300px"></div>"""


def root(end, screens, moves, scenes, music, sfx, spots=(), drawn=False, overlay="", extra_css="", extra_js=()):
    hosts = "\n".join(
        f'      <div id="el-{s.cid}" class="scene" data-composition-id="{s.cid}" data-composition-src="compositions/{s.cid}.html" '
        f'data-start="{r(s.start)}" data-duration="{r(s.dur)}" data-track-index="{2 + i}" data-track-kind="graphics"></div>'
        for i, s in enumerate(scenes)
    )
    shots, js = [], []
    for i, s in enumerate(screens):
        attrs = f'id="{s["id"]}" class="clip" src="{s["src"]}" data-start="{r(s["start"])}" data-duration="{r(s["dur"])}" data-track-index="{i % 2}"'
        if s["src"].endswith(".mp4"):
            shots.append(f'<video {attrs} muted playsinline data-media-start="{r(s["from"])}"></video>')
        else:
            shots.append(f'<img {attrs} alt="">')
        if s.get("fade"):
            js.append(f'tl.fromTo("#{s["id"]}", {{ opacity: 0 }}, {{ opacity: 1, duration: 0.2, ease: "power1.inOut" }}, {r(s["start"])});')
    spot_html, spot_css = [], []
    for i, (box, t_in, t_out) in enumerate(spots):
        x, y, w, h = box
        spot_css.append(f"#spot-{i} {{ left: {x}px; top: {y}px; width: {w}px; height: {h}px; }}")
        spot_html.append(f'<div id="spot-{i}" class="spot"></div>')
        js.append(f'tl.fromTo("#spot-{i}", {{ opacity: 0 }}, {{ opacity: 1, duration: 0.26, ease: "power2.out" }}, {r(t_in)});')
        js.append(f'tl.to("#spot-{i}", {{ opacity: 0, duration: 0.2, ease: "power2.in" }}, {r(t_out)});')
    audio = "\n".join(
        f'      <audio id="sfx-{i}" src="assets/sfx/{name}.mp3" data-start="{r(at)}" data-duration="{SFX_LEN[name]}" data-volume="{vol}" data-track-index="{10 + i % 6}"></audio>'
        for i, (name, at, vol) in enumerate(sfx)
    )
    js.insert(0, f'tl.set("#phone", {{ {gsap_state(moves[0][1])}, transformOrigin: "0 0" }}, 0);')
    for t, state, dur, ease in moves[1:]:
        js.append(f'tl.to("#phone", {{ {gsap_state(state)}, duration: {dur}, ease: "{ease}" }}, {r(t)});')
    js += list(extra_js)
    frame_css = DRAWN_FRAME if drawn else ""
    frame_html = DRAWN_FRAME_HTML if drawn else '\n        <img id="bezel" src="assets/apple/iphone-17-black.png" alt="">'
    island = '\n        <div id="frame-island"></div>' if drawn else ""
    return f"""<!doctype html>
<html lang="pt">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width={W}, height={H}" />
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js" integrity="sha384-sG0Hv1tP1lZCk9KQmrIbY/XNwi+OY84GQqhMscbnsoBFqAz8KNCil1kvfL3Hbbk2" crossorigin="anonymous"></script>
    <style>
      {FONT_FACES}
      * {{ margin: 0; padding: 0; box-sizing: border-box; }}
      html, body {{ width: {W}px; height: {H}px; overflow: hidden; background: #fff; }}
      #root {{ position: relative; width: {W}px; height: {H}px; overflow: hidden; background: #ffffff; }}
      .scene {{ position: absolute; inset: 0; width: 100%; height: 100%; }}
      #phone {{ position: absolute; left: 0; top: 0; width: {FRAME_W}px; height: {FRAME_H}px;
        filter: drop-shadow(0 34px 60px rgba(0,0,0,0.14)) drop-shadow(0 6px 14px rgba(0,0,0,0.08)); }}
      #screen {{ position: absolute; left: {SCREEN_X}px; top: {SCREEN_Y}px; width: {SCREEN_W}px; height: {SCREEN_H}px;
        overflow: hidden; border-radius: 150px; background: #fff; }}
      #screen > img, #screen > video {{ position: absolute; left: 0; top: 0; width: {SCREEN_W}px; height: {SCREEN_H}px; display: block; }}
      .spot {{ position: absolute; border-radius: 34px; box-shadow: 0 0 0 4000px rgba(255,255,255,0.74); opacity: 0; }}
      {" ".join(spot_css)}
      #bezel {{ position: absolute; inset: 0; width: {FRAME_W}px; height: {FRAME_H}px; }}{frame_css}{extra_css}
    </style>
  </head>
  <body>
    <div id="root" data-composition-id="main" data-start="0" data-duration="{r(end)}" data-width="{W}" data-height="{H}">
      <div id="phone" data-layout-allow-overflow>{frame_html if drawn else ""}
        <div id="screen">
          {chr(10).join("          " + s for s in shots).lstrip()}{overlay}
          {"".join(spot_html)}
        </div>{frame_html if not drawn else island}
      </div>
{hosts}
      <audio id="music" src="assets/bgm/{music}" data-start="0" data-duration="{r(end)}" data-volume="0.9" data-fade-out="1.2" data-track-index="9"></audio>
{audio}
    </div>
    <script>
      window.__timelines = window.__timelines || {{}};
      const tl = gsap.timeline({{ paused: true }});
      tl.set({{}}, {{}}, {r(end)});
      {chr(10).join("      " + line for line in js).lstrip()}
      window.__timelines["main"] = tl;
    </script>
  </body>
</html>
"""


def write(film, scenes, doc):
    """Each film is its own small project under films/, sharing this project's assets."""
    folder = ROOT / "films" / film
    (folder / "compositions").mkdir(parents=True, exist_ok=True)
    for old in (folder / "compositions").glob("*.html"):
        old.unlink()
    for name in ("hyperframes.json", ".gitignore"):
        (folder / name).write_text((ROOT / name).read_text())
    (folder / "meta.json").write_text('{"id": "%s", "name": "facturac.ao %s"}\n' % (film, film))
    if not (folder / "assets").exists():
        (folder / "assets").symlink_to("../../assets")
    for s in scenes:
        (folder / "compositions" / f"{s.cid}.html").write_text(s.doc())
    (folder / "index.html").write_text(doc)
    print("wrote", folder.relative_to(ROOT), "with", ", ".join(s.cid for s in scenes))


# ------------------------------------------------------------------ O Ponto

def ponto():
    """Read: assets/vo/ponto-a.mp3."""
    end = c(15.84) + 1.8
    tap = c(6.98)
    press_in_clip, hoje_in_clip = 2.7, 4.75
    word = placed(0.5, y=600)
    icon_x, icon_y = word["x"] + ICON[0] * 0.5, word["y"] + ICON[1] * 0.5

    s1 = Scene("ponto-1-nome", 0, tap + 0.4)
    w, dot = add_word(s1, "facturação", 150, 330, c(0.42), c(1.46), c(2.74))
    s1.cap("Tire o til.", 418, c(0.06), c(1.12))
    s1.cap("Tire a cedilha.", 418, c(1.16), c(2.3))
    s1.cap("Ponha um ponto.", 418, c(2.36), c(3.5))
    s1.cap("É este o nome.", 418, c(3.6), c(6.3))
    f, d = s1.fid, w["dot_d"]
    land_x, land_y = w["land"]
    s1.css.append(f"""#{f}-tap {{ position: absolute; left: 0; top: 0; width: {d:.2f}px; height: {d:.2f}px; border-radius: 50%; background: {GOLD}; opacity: 0; }}
    #{f}-ring {{ position: absolute; left: {icon_x - 60:.2f}px; top: {icon_y - 60:.2f}px; width: 120px; height: 120px; border-radius: 50%; border: 5px solid {GOLD}; opacity: 0; }}""")
    s1.body.append(f'<div id="{f}-ring"></div>')
    s1.body.append(f'<div id="{f}-tap"></div>')
    t0 = tap - 0.52
    s1.js += [
        f'tl.set("#{f}-tap", {{ x: {r(land_x - d / 2, 2)}, y: {r(land_y - d / 2, 2)}, opacity: 1 }}, {s1.at(t0)});',
        f'tl.to("#{f}-tap", {{ x: {r(icon_x - d / 2, 2)}, duration: 0.52, ease: "power1.inOut" }}, {s1.at(t0)});',
        f'tl.to("#{f}-tap", {{ y: {r(land_y - 150 - d / 2, 2)}, duration: 0.17, ease: "power2.out" }}, {s1.at(t0)});',
        f'tl.to("#{f}-tap", {{ y: {r(icon_y - d / 2, 2)}, duration: 0.35, ease: "power2.in" }}, {s1.at(t0 + 0.17)});',
        f'tl.to("#{f}-tap", {{ scale: 0, duration: 0.1, ease: "power2.in" }}, {s1.at(tap)});',
        f'tl.fromTo("#{f}-ring", {{ opacity: 0.9, scale: 0.4 }}, {{ opacity: 0, scale: 1.6, duration: 0.42, ease: "power2.out" }}, {s1.at(tap)});',
        f'tl.to("#{w["prefix"]}-box, #{dot}", {{ y: "-=70", opacity: 0, duration: 0.26, ease: "power2.in" }}, {s1.at(tap + 0.04)});',
    ]

    s2 = Scene("ponto-2-hoje", c(9.1), c(13.1))
    s2.cap("O que tem a receber.", 176, c(9.18), c(10.26))
    s2.cap("Quem está em atraso.", 176, c(10.3), c(11.46))
    s2.cap("O que precisa de si hoje.", 176, c(11.5), c(13.1))

    s3 = end_scene("ponto-3-disponivel", c(13.1), end, c(13.3), c(13.98), c(14.14), c(14.42))

    hoje = placed(0.74, y=280)
    screens = [
        {"id": "shot-home", "src": "assets/capture/home.png", "start": 0, "dur": tap - 0.1},
        {"id": "shot-launch", "src": "assets/capture/launch-60.mp4", "start": tap - 0.2, "dur": hoje_in_clip - press_in_clip + 0.4, "from": press_in_clip - 0.2},
        {"id": "shot-hoje", "src": "assets/capture/hoje.png", "start": tap + hoje_in_clip - press_in_clip, "dur": end - (tap + hoje_in_clip - press_in_clip)},
    ]
    moves = [
        (0, word, 0, ""),
        (tap + 0.06, CENTRE, 0.6, "power3.inOut"),
        (c(8.76), hoje, 0.55, "power3.inOut"),
        (c(12.9), GONE, 0.42, "power2.in"),
    ]
    spots = [(SPOT_FIGURE, c(9.18), c(10.2)), (SPOT_OVERDUE, c(10.3), c(11.4)), (SPOT_DECISION, c(11.5), c(12.86))]
    sfx = [("pop-confirm", c(2.74), 0.14), ("pop-soft", tap, 0.2), ("whoosh-soft", tap + 0.1, 0.1), ("impact-soft", c(13.98), 0.16)]
    scenes = [s1, s2, s3]
    write("o-ponto", scenes, root(end, screens, moves, scenes, "track.mp3", sfx, spots=spots))


# ------------------------------------------------------ Tudo acaba em .ao

def tudo():
    """Read: assets/vo/tudo-c.mp3."""
    end = c(19.12) + 1.5

    s1 = Scene("tudo-1-atencao", 0, c(3.9))
    add_word(s1, "atenção", 132, 300, c(0.62), c(1.0), c(1.86))
    s1.cap("A factura venceu.", 398, c(0.94), c(2.2))
    s1.cap("O telefone avisa-o.", 398, c(2.24), c(3.9))

    s2 = Scene("tudo-2-migracao", c(3.9), c(7.72))
    add_word(s2, "migração", 132, 300, c(4.7), c(5.0), c(5.76), t_in=c(3.96))
    s2.cap("Os seus clientes e artigos vêm consigo.", 398, c(5.1))

    s3 = Scene("tudo-3-comunicacao", c(7.72), c(13.16))
    add_word(s3, "comunicação", 132, 300, c(8.56), c(8.86), c(9.6), t_in=c(7.78))
    s3.cap("Emitida, assinada e enviada à AGT, sozinha.", 398, c(8.96))

    s4 = Scene("tudo-4-fim", c(13.2), c(15.95))
    s4.head("No fim, tudo", 112, 730, c(13.32))
    s4.head('acaba em <b>.</b>ao', 112, 860, c(14.32))

    s5 = end_scene("tudo-5-disponivel", c(15.95), end, c(16.0), c(16.5), c(16.1), c(17.68))

    press, done = 5.85, 6.5
    t_press = c(8.96) - (done - press)
    screens = [
        {"id": "shot-notice", "src": "assets/capture/notify-60.mp4", "start": 0, "dur": c(1.06) + 0.45, "from": 3.6 - c(1.06)},
        {"id": "shot-banner", "src": "assets/capture/banner.png", "start": c(1.06) + 0.4, "dur": c(2.9) - c(1.06) - 0.35},
        {"id": "shot-open", "src": "assets/capture/notify-60.mp4", "start": c(2.9), "dur": c(3.95) - c(2.9) + 0.2, "from": 4.9},
        {"id": "shot-clientes", "src": "assets/capture/clientes.png", "start": c(3.95), "dur": c(5.96) - c(3.95) + 0.2, "fade": True},
        {"id": "shot-artigos", "src": "assets/capture/artigos.png", "start": c(5.96), "dur": c(7.78) - c(5.96) + 0.2, "fade": True},
        {"id": "shot-emit", "src": "assets/capture/emit-60.mp4", "start": c(7.78), "dur": c(10.62) - c(7.78) + 0.2, "from": press - (t_press - c(7.78)), "fade": True},
        {"id": "shot-validada", "src": "assets/capture/validada.png", "start": c(10.62), "dur": end - c(10.62), "fade": True},
    ]
    moves = [
        (0, UNDER_WORD, 0, ""),
        (c(12.96), GONE, 0.42, "power2.in"),
    ]
    spots = [(SPOT_VALIDATED, c(11.42), c(12.9))]
    sfx = [("pop-soft", c(1.06), 0.14), ("pop-soft", c(2.9), 0.16), ("pop-confirm", c(1.86), 0.12), ("pop-confirm", c(5.76), 0.12),
           ("pop-confirm", c(9.6), 0.12), ("chime-resolve", c(11.42), 0.1), ("impact-soft", c(16.5), 0.16)]
    scenes = [s1, s2, s3, s4, s5]
    write("tudo-em-ao", scenes, root(end, screens, moves, scenes, "tudo-em-ao.mp3", sfx, spots=spots))


# ------------------------------------------------------------------ Mudar

def mudar():
    """Read: assets/vo/mudar-a.mp3."""
    end = c(18.64) + 1.5

    s1 = Scene("mudar-1-medo", 0, c(3.12))
    s1.cap("Já paga por um programa?", 640, None)
    s1.head("Mudar", 164, 730, None)
    s1.head("mete medo?", 164, 910, None)

    s2 = Scene("mudar-2-dados", c(3.12), c(6.9))
    s2.head("Não devia.", 92, 150, c(3.22))
    s2.cap("Clientes e artigos entram do Excel.", 268, c(4.34))

    s3 = Scene("mudar-3-numeracao", c(6.9), c(9.38))
    s3.head("A numeração continua.", 80, 150, c(6.96))
    s3.cap("Sem saltos.", 268, c(8.32))

    s4 = Scene("mudar-4-agt", c(9.38), c(13.5))
    s4.head("A parte chata?", 92, 150, c(9.42))
    s4.cap("Assinar e comunicar à AGT: faz-se sozinha.", 268, c(10.46), px=40)

    s5 = Scene("mudar-5-isto", c(13.7), c(15.42))
    s5.head("Mudar é só isto.", 112, 860, c(13.8))

    s6 = end_scene("mudar-6-disponivel", c(15.42), end, c(15.46), c(15.86), c(17.1), c(15.54))

    press, done = 5.85, 6.5
    t_press = c(10.98) - (done - press)
    screens = [
        {"id": "shot-clientes", "src": "assets/capture/clientes.png", "start": 0, "dur": c(5.12) + 0.2},
        {"id": "shot-artigos", "src": "assets/capture/artigos.png", "start": c(5.12), "dur": c(6.96) - c(5.12) + 0.2, "fade": True},
        {"id": "shot-registos", "src": "assets/capture/registos.png", "start": c(6.96), "dur": c(9.5) - c(6.96) + 0.2, "fade": True},
        {"id": "shot-emit", "src": "assets/capture/emit-60.mp4", "start": c(9.5), "dur": c(11.74) - c(9.5) + 0.2, "from": press - (t_press - c(9.5)), "fade": True},
        {"id": "shot-validada", "src": "assets/capture/validada.png", "start": c(11.74), "dur": end - c(11.74), "fade": True},
    ]
    moves = [
        (0, GONE, 0, ""),
        (c(3.24), UNDER_TITLE, 0.55, "power3.out"),
        (c(13.42), GONE, 0.4, "power2.in"),
    ]
    spots = [(SPOT_NUMBERS, c(7.5), c(9.26)), (SPOT_VALIDATED, c(12.2), c(13.36))]
    sfx = [("tick-soft", c(5.12), 0.1), ("pop-confirm", c(10.98), 0.12), ("chime-resolve", c(12.2), 0.1), ("impact-soft", c(15.86), 0.16)]
    scenes = [s1, s2, s3, s4, s5, s6]
    write("mudar", scenes, root(end, screens, moves, scenes, "mudar.mp3", sfx, spots=spots))


# ------------------------------------------------------------- O Endereço

def endereco():
    """Read: assets/vo/endereco-a.mp3. The website, so a drawn frame and a drawn address bar."""
    end = c(12.8) + 1.8
    scale = 0.62
    phone = placed(scale, y=560)
    bar_x, bar_y, bar_w, bar_h = 96, 250, 1014, 150
    word = ao_word("facturação", 96, "bar-word", left=150, baseline=bar_y + bar_h / 2 + 30)
    dot = "bar-dot"
    typing = [c(1.92) + i * 0.085 for i in range(word["count"])]
    refused = c(3.72)
    t_til, t_ced, t_dot, t_go = c(5.32), c(6.3), c(7.48), c(9.22)
    overlay = f"""
          <div id="page" class="clip" data-start="0" data-duration="{r(end)}" data-track-index="2"></div>
          <img id="site" class="clip" src="assets/capture/site-top.png" alt="" data-start="{r(t_go)}" data-duration="{r(end - t_go)}" data-track-index="3">
          <div id="bar"></div>
          {word["html"]}
          <div id="{dot}"></div>
          <div id="bar-error">Endereço inválido</div>"""
    css = f"""
      #page {{ position: absolute; inset: 0; background: #f6f6f7; }}
      #screen > #site {{ position: absolute; left: 0; top: 440px; width: {SCREEN_W}px; height: {SCREEN_H}px; }}
      #bar {{ position: absolute; left: {bar_x}px; top: {bar_y}px; width: {bar_w}px; height: {bar_h}px; border-radius: 40px; background: #ffffff;
        box-shadow: inset 0 0 0 3px #e6e6e8; }}
      #bar-word-box {{ position: absolute; }}
      #bar-error {{ position: absolute; left: 0; right: 0; top: 470px; text-align: center; font: 500 54px/1 "Inter", sans-serif; letter-spacing: -0.012em; color: {RED}; opacity: 0; }}
      {dot_css(dot, word["dot_d"])}
    """
    js = [f'tl.fromTo("#bar-word-g{i}", {{ opacity: 0 }}, {{ opacity: 1, duration: 0.01 }}, {r(t)});' for i, t in enumerate(typing)]
    js += [
        f'tl.to("#bar", {{ boxShadow: "inset 0 0 0 5px {RED}", duration: 0.12 }}, {r(refused)});',
        f'tl.to("#bar, #bar-word-box", {{ x: 10, duration: 0.05, repeat: 5, yoyo: true, ease: "none" }}, {r(refused)});',
        f'tl.fromTo("#bar-error", {{ opacity: 0, y: 10 }}, {{ opacity: 1, y: 0, duration: 0.2 }}, {r(refused + 0.05)});',
        f'tl.to("#bar-error", {{ opacity: 0, duration: 0.2 }}, {r(c(4.9))});',
        f'tl.to("#bar", {{ boxShadow: "inset 0 0 0 3px #e6e6e8", duration: 0.25 }}, {r(c(4.9))});',
    ]
    ao_motion(js, word, t_til, t_ced, t_dot, dot, enter_from=(1180, 120), fly=0.6)
    js += [
        f'tl.to("#bar", {{ boxShadow: "inset 0 0 0 5px {GOLD}", duration: 0.12, yoyo: true, repeat: 1 }}, {r(t_go - 0.15)});',
        f'tl.fromTo("#site", {{ opacity: 0, y: 40 }}, {{ opacity: 1, y: 0, duration: 0.36, ease: "power3.out" }}, {r(t_go)});',
    ]

    s1 = Scene("endereco-1-onde", 0, c(10.9))
    s1.head("Onde fica a sua", 84, 150, None, c(4.9))
    s1.head("facturação?", 84, 250, None, c(4.9))
    s1.cap("Tire o til.", 230, c(5.02), c(6.0))
    s1.cap("Tire a cedilha.", 230, c(6.02), c(7.1))
    s1.cap("Ponha um ponto.", 230, c(7.12), c(8.2))
    s1.cap("É só isso.", 230, c(8.24), c(9.2))
    s2 = end_scene("endereco-2-disponivel", c(10.9), end, c(10.94), c(11.14), c(10.98), c(11.2))

    moves = [
        (0, phone, 0, ""),
        (c(1.5), focus(0.87, SCREEN_X + 603, SCREEN_Y + bar_y + bar_h / 2, cy=900), 0.55, "power3.inOut"),
        (t_go + 0.2, placed(0.75, y=330), 0.6, "power3.inOut"),
        (c(10.68), GONE, 0.42, "power2.in"),
    ]
    sfx = [("keyboard-typing", typing[0], 0.14), ("error-buzz", refused, 0.1), ("pop-confirm", t_dot, 0.14), ("impact-soft", c(11.14), 0.16)]
    scenes = [s1, s2]
    write("endereco", scenes, root(end, [], moves, scenes, "endereco.mp3", sfx, drawn=True, overlay=overlay, extra_css=css, extra_js=js))


if __name__ == "__main__":
    ponto()
    tudo()
    mudar()
    endereco()
