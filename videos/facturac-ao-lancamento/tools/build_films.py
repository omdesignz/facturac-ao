#!/usr/bin/env python3
"""Writes the launch cuts of "Tudo acaba em .ao", "Mudar" and "O Endereço". Run from the project root.

Each film is its own small HyperFrames project under films/<film>/, its assets
folder a link to this project's, rendered from inside that folder. They share the pilot's look (SPEC.md) and its pieces
(tools/build_ponto.py): the phone lives in the root and travels between scenes,
the scenes carry the type, and everything on a phone is a real capture.

The two app films use Apple's iPhone 17 frame. "O Endereço" shows the website in a
browser, so it uses a frame drawn here instead: Apple's licence covers mock-ups of
iOS-only software, and the website is not that. Its address bar is drawn too; the
page under it is the real homepage (assets/capture/site-top.png).
"""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "tools"))
import build_ponto as P  # noqa: E402
import type_outlines as T  # noqa: E402

W, H, INK, INK_SOFT, GOLD = P.W, P.H, P.INK, P.INK_SOFT, P.GOLD
RED = "#a80f3a"
r = P.r
SFX_LEN = {
    "bounce-pop": 0.8, "chime-resolve": 1.441, "drop-thud": 0.792, "error-buzz": 1.247, "impact-soft": 2.0,
    "keyboard-typing": 1.1, "pop-confirm": 0.575, "pop-soft": 0.575, "stamp-thud": 0.792, "success-chime": 2.4,
    "tick-soft": 0.6, "whoosh-soft": 1.579, "whoosh-up": 1.579,
}
PONTO_A_X = P.GLYPHS[8]["x"]  # where "a" starts in "facturação", brand units


# ------------------------------------------------------------ shared pieces

def beats(first, bpm, n=60):
    step = 60 / bpm
    return [first + i * step for i in range(n)]


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
    dot_x = left + (glyphs[i_a]["x"] + (P.DOT_CX - PONTO_A_X) + P.GROUP_SHIFT) * k
    return {
        "html": f'<div id="{prefix}-box" style="position:absolute;left:{left:.2f}px;top:{top:.2f}px">{html}</div>',
        "prefix": prefix, "k": k, "count": len(glyphs), "i_c": i_c, "i_a": i_a, "i_o": i_o,
        "left": left, "top": top, "width": width * k,
        "land": (dot_x, top + (T.ASCENT - P.DOT_R) * k),
        "o_top": (left + (glyphs[i_o]["x"] + P.AO_SHIFT + P.GROUP_SHIFT + 32) * k, top + (P.X_HEIGHT_TOP - P.DOT_R) * k),
        "dot_d": 2 * P.DOT_R * k,
    }


def ao_motion(js, w, t_til, t_ced, t_dot, dot_id, enter_from=(1240, 160), fly=1.0):
    """Til flies, cedilha drops, the letters part and the dot bounces in: the brand's move."""
    p, k, d = w["prefix"], w["k"], w["dot_d"]
    til, ced = f'#{p}-g{w["i_a"]}-tilde', f'#{p}-g{w["i_c"]}-cedilla'
    for mark in (til, ced):
        js.append(f'tl.to("{mark} path", {{ fill: "{GOLD}", duration: 0.18, ease: "power1.out" }}, {r(t_til - 0.4)});')
    js.append(f'tl.to("{til}", {{ y: -22, rotation: -14, scale: 1.12, duration: 0.18, ease: "power2.out", transformOrigin: "50% 60%" }}, {r(t_til - 0.18)});')
    js.append(f'tl.to("{til}", {{ x: {r(560 * fly)}, y: {r(-700 * fly)}, rotation: 420, scale: 0.55, duration: 0.55, ease: "power2.in" }}, {r(t_til)});')
    js.append(f'tl.to("#{p}-g{w["i_a"]}", {{ y: -14, duration: 0.1, ease: "power2.out" }}, {r(t_til + 0.04)});')
    js.append(f'tl.to("#{p}-g{w["i_a"]}", {{ y: 0, duration: 0.4, ease: "bounce.out" }}, {r(t_til + 0.14)});')
    js.append(f'tl.to("{ced}", {{ rotation: -12, duration: 0.09, ease: "power1.inOut", transformOrigin: "40% 0%" }}, {r(t_ced - 0.2)});')
    js.append(f'tl.to("{ced}", {{ rotation: 10, duration: 0.1, ease: "power1.inOut" }}, {r(t_ced - 0.11)});')
    js.append(f'tl.to("{ced}", {{ y: {r(1700 * fly)}, rotation: 38, opacity: 0, duration: 0.75, ease: "power2.in" }}, {r(t_ced)});')
    js.append(f'tl.to("#{p}-g{w["i_c"]}", {{ y: -18, duration: 0.11, ease: "power2.out" }}, {r(t_ced + 0.05)});')
    js.append(f'tl.to("#{p}-g{w["i_c"]}", {{ y: 0, duration: 0.4, ease: "bounce.out" }}, {r(t_ced + 0.16)});')
    for i in range(w["count"]):
        shift = P.AO_SHIFT + P.GROUP_SHIFT if i in (w["i_a"], w["i_o"]) else P.GROUP_SHIFT
        js.append(f'tl.to("#{p}-g{i}", {{ x: {r(shift * k, 2)}, duration: 0.38, ease: "power3.inOut" }}, {r(t_dot - 0.55)});')
    hops = [(enter_from, t_dot - 0.48), (w["o_top"], t_dot - 0.24), (w["land"], t_dot)]
    js.append(f'tl.set("#{dot_id}", {{ x: {r(enter_from[0] - d / 2, 2)}, y: {r(enter_from[1] - d / 2, 2)}, opacity: 1 }}, {r(t_dot - 0.5)});')
    for ((x0, y0), t0), ((x1, y1), t1), apex in zip(hops, hops[1:], [140 * k / 1.5, 180 * k / 1.5]):
        dur = t1 - t0
        js.append(f'tl.to("#{dot_id}", {{ x: {r(x1 - d / 2, 2)}, duration: {r(dur)}, ease: "none" }}, {r(t0)});')
        js.append(f'tl.to("#{dot_id}", {{ y: {r(min(y0, y1) - apex - d / 2, 2)}, duration: {r(dur / 2)}, ease: "power2.out" }}, {r(t0)});')
        js.append(f'tl.to("#{dot_id}", {{ y: {r(y1 - d / 2, 2)}, duration: {r(dur / 2)}, ease: "power2.in" }}, {r(t0 + dur / 2)});')
    js.append(f'tl.to("#{dot_id}", {{ scaleX: 1.45, scaleY: 0.62, duration: 0.05, ease: "power2.out", transformOrigin: "50% 100%" }}, {r(t_dot)});')
    js.append(f'tl.to("#{dot_id}", {{ scaleX: 1, scaleY: 1, duration: 0.24, ease: "back.out(3)" }}, {r(t_dot + 0.05)});')


def dot_css(dot_id, d):
    return f"#{dot_id} {{ position: absolute; left: 0; top: 0; width: {d:.2f}px; height: {d:.2f}px; border-radius: 50%; background: {GOLD}; opacity: 0; }}"


def outline_line(text, px, prefix, top, color=INK):
    """A centred line of outline type, one box per word so the words can arrive in turn."""
    words = text.split(" ")
    _, units = T.layout(text)
    px = min(px, 980 / (units / T.EM))  # never wider than the frame's safe area
    space = (T.advance(" ") + T.TRACKING) * px / T.EM
    parts = []
    for i, w in enumerate(words):
        h, _, _ = T.word_html(w, px, f"{prefix}-w{i}", color=color)
        parts.append(f'<div id="{prefix}-w{i}" style="position:relative">{h}</div>')
    css = f"#{prefix} {{ position: absolute; left: 0; top: {top}px; width: {W}px; display: flex; justify-content: center; gap: {space:.2f}px; }}"
    return f'<div id="{prefix}">{"".join(parts)}</div>', css, len(words)


def line_in(js, prefix, count, t, stagger=0.07):
    for i in range(count):
        js.append(f'tl.fromTo("#{prefix}-w{i}", {{ opacity: 0, y: 34 }}, {{ opacity: 1, y: 0, duration: 0.34, ease: "power3.out" }}, {r(t + i * stagger)});')


def caption(fid, i, text, top):
    css = f""".{fid}-cap {{ position: absolute; left: 60px; right: 60px; text-align: center;
      font: 600 52px/1.18 "Hanken Grotesk", sans-serif; color: {INK_SOFT}; opacity: 0; }}
    #{fid}-cap{i} {{ top: {top}px; }}"""
    return f'<div id="{fid}-cap{i}" class="{fid}-cap">{text}</div>', css


def cap_in_out(js, fid, i, t_in, t_out):
    js.append(f'tl.fromTo("#{fid}-cap{i}", {{ opacity: 0, y: 18 }}, {{ opacity: 1, y: 0, duration: 0.3, ease: "power3.out" }}, {r(t_in)});')
    if t_out is not None:
        js.append(f'tl.to("#{fid}-cap{i}", {{ opacity: 0, y: -14, duration: 0.18, ease: "power2.in" }}, {r(t_out - 0.18)});')


def end_scene(cid, start, dur, land):
    """"Disponível." with the dot as its full stop, the wordmark, and the call to action."""
    fid = "frame-" + cid
    px = 172
    k = px / T.EM
    word, w1, _ = T.word_html("Disponível", px, f"{fid}-word")
    d = 2 * P.DOT_R * k
    left = (W - (w1 + d * 0.9)) / 2
    top = 860 - T.ASCENT * k
    mark_w = 470
    css = f"""
    #{fid}-word {{ position: absolute; left: {left:.2f}px; top: {top:.2f}px; }}
    #{fid}-dot {{ position: absolute; left: {left + w1:.2f}px; top: {top + (T.ASCENT - 2 * P.DOT_R) * k:.2f}px; width: {d:.2f}px; height: {d:.2f}px; border-radius: 50%; background: {GOLD}; }}
    #{fid}-mark {{ position: absolute; left: {(W - mark_w) / 2:.2f}px; top: 990px; width: {mark_w}px; height: {mark_w * 80.4 / 524:.2f}px; }}
    #{fid}-cta {{ position: absolute; left: 50%; top: 1170px; transform: translateX(-50%); padding: 30px 58px; border-radius: 999px;
      background: {GOLD}; color: {INK}; font: 600 44px/1 "Hanken Grotesk", sans-serif; white-space: nowrap;
      box-shadow: 0 14px 30px rgba(249,178,51,0.32); }}
    """
    body = (
        f'      <div id="{fid}-word">{word}</div>\n      <div id="{fid}-dot"></div>\n'
        f'      <img id="{fid}-mark" src="assets/facturac-ao.svg" alt="facturac.ao">\n'
        f'      <div id="{fid}-cta-wrap"><div id="{fid}-cta">Abra a sua conta grátis</div></div>'
    )
    t = land - start
    js = [
        f'tl.fromTo("#{fid}-word", {{ opacity: 0, y: 46 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }}, {r(t - 0.5)});',
        f'tl.fromTo("#{fid}-dot", {{ y: -900 }}, {{ y: 0, duration: 0.36, ease: "power2.in", immediateRender: true }}, {r(t - 0.36)});',
        f'tl.to("#{fid}-dot", {{ scaleX: 1.5, scaleY: 0.6, duration: 0.05, transformOrigin: "50% 100%" }}, {r(t)});',
        f'tl.to("#{fid}-dot", {{ scaleX: 1, scaleY: 1, duration: 0.3, ease: "back.out(3)" }}, {r(t + 0.05)});',
        f'tl.fromTo("#{fid}-mark", {{ clipPath: "inset(0 100% 0 0)" }}, {{ clipPath: "inset(0 0% 0 0)", duration: 0.55, ease: "power3.out" }}, {r(t + 0.45)});',
        f'tl.fromTo("#{fid}-cta-wrap", {{ opacity: 0, y: 30, scale: 0.94 }}, {{ opacity: 1, y: 0, scale: 1, duration: 0.42, ease: "back.out(1.8)", transformOrigin: "50% 1200px" }}, {r(t + 1.0)});',
    ]
    return cid, start, dur, P.scene_doc(cid, dur, css, body, "      " + "\n      ".join(js))


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


def root(filename, end, screens, moves, scenes, music, sfx, drawn=False, overlay=""):
    hosts = "\n".join(
        f'      <div id="el-{cid}" class="scene" data-composition-id="{cid}" data-composition-src="compositions/{cid}.html" '
        f'data-start="{r(start)}" data-duration="{r(dur)}" data-track-index="{2 + i}" data-track-kind="graphics"></div>'
        for i, (cid, start, dur, _) in enumerate(scenes)
    )
    shots = []
    for s in screens:
        attrs = f'id="{s["id"]}" class="clip" src="{s["src"]}" data-start="{r(s["start"])}" data-duration="{r(s["dur"])}" data-track-index="{s.get("track", 0)}"'
        if s["src"].endswith(".mp4"):
            shots.append(f'<video {attrs} muted playsinline data-media-start="{r(s["from"])}"></video>')
        else:
            shots.append(f'<img {attrs} alt="">')
    audio = "\n".join(
        f'      <audio id="sfx-{i}" src="assets/sfx/{name}.mp3" data-start="{r(at)}" data-duration="{SFX_LEN[name]}" data-volume="{vol}" data-track-index="{10 + i % 6}"></audio>'
        for i, (name, at, vol) in enumerate(sfx)
    )
    js = [f'tl.set("#phone", {{ {P.gsap_state(moves[0][1])}, transformOrigin: "0 0" }}, 0);']
    for t, state, dur, ease in moves[1:]:
        js.append(f'tl.to("#phone", {{ {P.gsap_state(state)}, duration: {dur}, ease: "{ease}" }}, {r(t)});')
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
      * {{ margin: 0; padding: 0; box-sizing: border-box; }}
      html, body {{ width: {W}px; height: {H}px; overflow: hidden; background: #fff; }}
      #root {{ position: relative; width: {W}px; height: {H}px; overflow: hidden; background: #ffffff; }}
      .scene {{ position: absolute; inset: 0; width: 100%; height: 100%; }}
      #phone {{ position: absolute; left: 0; top: 0; width: {P.FRAME_W}px; height: {P.FRAME_H}px;
        filter: drop-shadow(0 46px 70px rgba(0,0,0,0.20)) drop-shadow(0 10px 18px rgba(0,0,0,0.14)); }}
      #screen {{ position: absolute; left: {P.SCREEN_X}px; top: {P.SCREEN_Y}px; width: {P.SCREEN_W}px; height: {P.SCREEN_H}px;
        overflow: hidden; border-radius: 150px; background: #fff; }}
      #screen > img, #screen > video {{ position: absolute; left: 0; top: 0; width: {P.SCREEN_W}px; height: {P.SCREEN_H}px; display: block; }}
      #bezel {{ position: absolute; inset: 0; width: {P.FRAME_W}px; height: {P.FRAME_H}px; }}{frame_css}
    </style>
  </head>
  <body>
    <div id="root" data-composition-id="main" data-start="0" data-duration="{end}" data-width="{W}" data-height="{H}">
      <div id="phone" data-layout-allow-overflow>{frame_html if drawn else ""}
        <div id="screen">
          {chr(10).join("          " + s for s in shots).lstrip()}{overlay}
        </div>{frame_html if not drawn else island}
      </div>
{hosts}
      <audio id="music" src="assets/bgm/{music}" data-start="0" data-duration="{end}" data-volume="0.9" data-fade-out="1.2" data-track-index="9"></audio>
{audio}
    </div>
    <script>
      window.__timelines = window.__timelines || {{}};
      const tl = gsap.timeline({{ paused: true }});
      tl.set({{}}, {{}}, {end});
      {chr(10).join("      " + line for line in js).lstrip()}
      window.__timelines["main"] = tl;
    </script>
  </body>
</html>
"""


def write(filename, scenes, doc):
    """Each film is its own small project under films/, sharing this project's assets."""
    film = ROOT / "films" / filename.removesuffix(".html")
    (film / "compositions").mkdir(parents=True, exist_ok=True)
    for name in ("hyperframes.json", ".gitignore"):
        (film / name).write_text((ROOT / name).read_text())
    (film / "meta.json").write_text('{"id": "%s", "name": "facturac.ao %s"}\n' % (film.name, film.name))
    if not (film / "assets").exists():
        (film / "assets").symlink_to("../../assets")
    for cid, _, _, scene in scenes:
        (film / "compositions" / f"{cid}.html").write_text(scene)
    (film / "index.html").write_text(doc)
    print("wrote", film.relative_to(ROOT), "with", ", ".join(cid for cid, *_ in scenes))


# Phone positions shared by the films (frame top-left and scale).
LOW = P.placed(0.5, y=640)
CENTRE = P.placed(0.62, y=(H - P.FRAME_H * 0.62) / 2)
UNDER_TITLE = P.placed(0.54, y=390)
GONE = {**LOW, "y": H + 160}


# ------------------------------------------------------ Tudo acaba em .ao

def tudo_em_ao():
    BT = beats(0.485, 126)
    B = lambda n: BT[n]  # noqa: E731
    END = 21.0
    chapters = [  # (cid, word, caption, start, end)
        ("tudo-1-atencao", "atenção", "A factura que venceu avisa-o.", 0.0, B(12)),
        ("tudo-2-migracao", "migração", "Traga o que já tem: clientes e artigos.", B(12), B(22)),
        ("tudo-3-comunicacao", "comunicação", "Do número à AGT, sozinha.", B(22), B(32)),
    ]
    scenes = []
    for n, (cid, text, cap, start, stop) in enumerate(chapters):
        fid = "frame-" + cid
        dur = stop - start
        w = ao_word(text, 132, f"{fid}-word", baseline=300)
        cap_html, cap_css = caption(fid, 0, cap, 410)
        dot = f"{fid}-dot"
        css = cap_css + "\n    " + dot_css(dot, w["dot_d"])
        body = f'      {w["html"]}\n      {cap_html}\n      <div id="{dot}"></div>'
        js = []
        local = lambda n_: B(n_) - start  # noqa: E731
        first = 0 if n == 0 else int(round((start - 0.485) / (60 / 126)))
        if n > 0:
            js.append(f'tl.fromTo("#{w["prefix"]}-box", {{ opacity: 0, y: 60 }}, {{ opacity: 1, y: 0, duration: 0.34, ease: "power3.out" }}, 0.02);')
        ao_motion(js, w, local(first + 1), local(first + 2), local(first + 4), dot)
        cap_in_out(js, fid, 0, local(first + 4) + 0.1, None)
        js.append(f'tl.to("#{w["prefix"]}-box, #{dot}, #{fid}-cap0", {{ y: "-=120", opacity: 0, duration: 0.24, ease: "power2.in" }}, {r(dur - 0.26)});')
        scenes.append((cid, start, dur, P.scene_doc(cid, dur, css, body, "      " + "\n      ".join(js))))

    # The close: the line the original film ended on, then the end card.
    cid, start, dur = "tudo-4-fim", B(32), 2.2
    fid = "frame-" + cid
    line1, css1, n1 = outline_line("No fim, tudo", 104, f"{fid}-l1", 700)
    line2, css2, n2 = outline_line("acaba em .ao", 104, f"{fid}-l2", 840)
    js = []
    line_in(js, f"{fid}-l1", n1, 0.05)
    line_in(js, f"{fid}-l2", n2, 0.25)
    js.append(f'tl.to("#{fid}-l1, #{fid}-l2", {{ opacity: 0, y: -40, duration: 0.24, ease: "power2.in" }}, {r(dur - 0.26)});')
    scenes.append((cid, start, dur, P.scene_doc(cid, dur, css1 + "\n    " + css2, f"      {line1}\n      {line2}", "      " + "\n      ".join(js))))
    scenes.append(end_scene("tudo-5-disponivel", B(32) + 2.2, END - (B(32) + 2.2), B(38)))

    # Screens: the overdue notice arrives and opens its invoice; the customers and
    # the catalogue that came over; the invoice issued now and its AGT record.
    n_start = 1.6 - 2.6  # notification lands (2.75 s in the clip) on beat 3
    screens = [
        {"id": "shot-notice", "src": "assets/capture/notification-60.mp4", "start": 0, "dur": B(12) + 0.2, "from": 2.6 - (B(3) - 0.0) + 0.0},
        {"id": "shot-clientes", "src": "assets/capture/clientes.png", "start": B(12), "dur": B(17) - B(12) + 0.1},
        {"id": "shot-artigos", "src": "assets/capture/artigos.png", "start": B(17), "dur": B(22) - B(17) + 0.1},
        {"id": "shot-emitida", "src": "assets/capture/issue-60.mp4", "start": B(22), "dur": B(27) - B(22) + 0.1, "from": 100.4},
        {"id": "shot-validada", "src": "assets/capture/validada.png", "start": B(27), "dur": END - B(27)},
    ]
    del n_start
    # The notice clip: the banner lands at 2.75 s and is tapped at 4.25 s; start it so
    # the tap falls on beat 6 and the record is up by beat 10.
    screens[0]["from"] = r(4.25 - B(6), 3)
    moves = [
        (0, LOW, 0, ""),
        (B(9) - 0.2, P.focus(0.74, P.SCREEN_X + 603, P.SCREEN_Y + 600, cy=1180), 0.6, "power3.inOut"),
        (B(12) - 0.3, LOW, 0.5, "power3.inOut"),
        (B(27) - 0.2, P.placed(0.62, y=500), 0.6, "power3.inOut"),
        (B(32) - 0.4, GONE, 0.5, "power2.in"),
    ]
    sfx = []
    for n in (0, 10, 20):
        sfx += [("whoosh-up", B(n + 1) - 0.05, 0.2), ("drop-thud", B(n + 2) + 0.5, 0.16), ("pop-confirm", B(n + 4), 0.2)]
    sfx += [("pop-soft", B(3), 0.24), ("pop-soft", B(6), 0.26), ("stamp-thud", B(27), 0.22), ("impact-soft", B(38), 0.26)]
    scenes.sort(key=lambda s: s[1])
    write("tudo-em-ao.html", scenes, root("tudo-em-ao.html", END, screens, moves, scenes, "tudo-em-ao.mp3", sfx))


# ------------------------------------------------------------------ Mudar

def mudar():
    END = 19.5
    scenes = []

    # 1. The fear, said plainly.
    cid, start, dur = "mudar-1-medo", 0.0, 3.3
    fid = "frame-" + cid
    pre_html, pre_css = caption(fid, 0, "Já paga por um programa?", 640)
    l1, c1, n1 = outline_line("Mudar", 170, f"{fid}-l1", 760)
    l2, c2, n2 = outline_line("mete medo.", 170, f"{fid}-l2", 950)
    js = []
    js.append(f'tl.set("#{fid}-cap0", {{ opacity: 1 }}, 0);')
    js.append(f'tl.to("#{fid}-l2", {{ x: -10, duration: 0.05, repeat: 5, yoyo: true, ease: "none" }}, 2.0);')
    js.append(f'tl.to("#{fid}-cap0, #{fid}-l1, #{fid}-l2", {{ opacity: 0, y: -60, duration: 0.26, ease: "power2.in" }}, {r(dur - 0.28)});')
    scenes.append((cid, start, dur, P.scene_doc(cid, dur, f"{pre_css}\n    {c1}\n    {c2}", f"      {pre_html}\n      {l1}\n      {l2}", "      " + "\n      ".join(js))))

    # 2-4. What actually happens, each with its line above the phone.
    lines = [
        ("mudar-2-dados", "Não devia.", "Clientes e artigos entram do Excel.", 3.3, 7.1),
        ("mudar-3-numeracao", "A numeração continua.", "Sem saltos: a seguir à última.", 7.1, 12.0),
        ("mudar-4-agt", "A parte chata?", "Assinada e comunicada à AGT, sozinha.", 12.0, 15.4),
    ]
    for cid, head, cap, start, stop in lines:
        fid = "frame-" + cid
        dur = stop - start
        line, lcss, n = outline_line(head, 92, f"{fid}-h", 150)
        cap_html, cap_css = caption(fid, 0, cap, 280)
        js = []
        line_in(js, f"{fid}-h", n, 0.1)
        cap_in_out(js, fid, 0, 0.45, None)
        js.append(f'tl.to("#{fid}-h, #{fid}-cap0", {{ opacity: 0, y: -30, duration: 0.22, ease: "power2.in" }}, {r(dur - 0.24)});')
        scenes.append((cid, start, dur, P.scene_doc(cid, dur, f"{lcss}\n    {cap_css}", f"      {line}\n      {cap_html}", "      " + "\n      ".join(js))))
    scenes.append(end_scene("mudar-5-disponivel", 15.4, END - 15.4, 16.2))

    screens = [
        {"id": "shot-clientes", "src": "assets/capture/clientes.png", "start": 0, "dur": 5.3},
        {"id": "shot-artigos", "src": "assets/capture/artigos.png", "start": 5.2, "dur": 2.0},
        {"id": "shot-quantidades", "src": "assets/capture/issue-60.mp4", "start": 7.1, "dur": 2.4, "from": 36.6},
        {"id": "shot-rever", "src": "assets/capture/issue-60.mp4", "start": 9.4, "dur": 2.7, "from": 99.2},
        {"id": "shot-validada", "src": "assets/capture/validada.png", "start": 12.0, "dur": END - 12.0},
    ]
    moves = [
        (0, GONE, 0, ""),
        (3.35, UNDER_TITLE, 0.7, "power3.out"),
        (10.9, P.placed(0.62, y=440), 0.6, "power3.inOut"),
        (12.0, UNDER_TITLE, 0.5, "power3.inOut"),
        (13.1, P.placed(0.62, y=440), 0.6, "power3.inOut"),
        (15.1, GONE, 0.45, "power2.in"),
    ]
    sfx = [
        ("pop-soft", 0.05, 0.14), ("drop-thud", 1.2, 0.2), ("whoosh-up", 3.3, 0.18), ("tick-soft", 5.2, 0.18),
        ("tick-soft", 7.6, 0.14), ("tick-soft", 8.1, 0.14), ("tick-soft", 8.6, 0.14),
        ("pop-confirm", 10.55, 0.24), ("stamp-thud", 12.2, 0.24), ("chime-resolve", 13.4, 0.2), ("impact-soft", 16.2, 0.26),
    ]
    write("mudar.html", scenes, root("mudar.html", END, screens, moves, scenes, "mudar.mp3", sfx))


# ------------------------------------------------------------- O Endereço

def endereco():
    BT = beats(0.565, 110)
    B = lambda n: BT[n]  # noqa: E731
    END = 15.5
    scale = 0.62
    phone = P.placed(scale, y=560)

    # The drawn address bar sits inside the screen, in frame pixels; its word is
    # outline type so the til and the cedilha can leave it like everywhere else.
    bar_x, bar_y, bar_w, bar_h = 96, 250, 1014, 150
    bar_px = 96
    bar_word = ao_word("facturação", bar_px, "bar-word", left=150, baseline=bar_y + bar_h / 2 + 30)
    bar_dot = "bar-dot"
    typing = [B(1) + i * 0.11 for i in range(bar_word["count"])]
    refused = typing[-1] + 0.45
    t_til, t_ced, t_dot, t_go = B(6), B(8), B(11), B(13)
    overlay = f"""
          <div id="page" class="clip" data-start="0" data-duration="{END}" data-track-index="2"></div>
          <img id="site" class="clip" src="assets/capture/site-top.png" alt="" data-start="{r(t_go)}" data-duration="{r(END - t_go)}" data-track-index="3">
          <div id="bar"></div>
          {bar_word["html"]}
          <div id="{bar_dot}"></div>
          <div id="bar-error">Endereço inválido</div>"""
    shots_css = f"""
      #page {{ position: absolute; inset: 0; background: #f2f2f4; }}
      #screen > #site {{ position: absolute; left: 0; top: 440px; width: {P.SCREEN_W}px; height: {P.SCREEN_H}px; }}
      #bar {{ position: absolute; left: {bar_x}px; top: {bar_y}px; width: {bar_w}px; height: {bar_h}px; border-radius: 75px; background: #ffffff;
        box-shadow: 0 6px 24px rgba(0,0,0,0.08), inset 0 0 0 3px #e4e4e7; }}
      #bar-word-box {{ position: absolute; }}
      #bar-error {{ position: absolute; left: 0; right: 0; top: 470px; text-align: center; font: 600 54px/1 "Hanken Grotesk", sans-serif; color: {RED}; opacity: 0; }}
      {dot_css(bar_dot, bar_word["dot_d"])}
    """
    js_overlay = []
    for i, t in enumerate(typing):
        js_overlay.append(f'tl.fromTo("#bar-word-g{i}", {{ opacity: 0 }}, {{ opacity: 1, duration: 0.01 }}, {r(t)});')
    js_overlay.append(f'tl.to("#bar", {{ boxShadow: "0 6px 24px rgba(168,15,58,0.18), inset 0 0 0 5px {RED}", duration: 0.12 }}, {r(refused)});')
    js_overlay.append(f'tl.to("#bar, #bar-word-box", {{ x: 18, duration: 0.05, repeat: 7, yoyo: true, ease: "none" }}, {r(refused)});')
    js_overlay.append(f'tl.fromTo("#bar-error", {{ opacity: 0, y: 12 }}, {{ opacity: 1, y: 0, duration: 0.2 }}, {r(refused + 0.05)});')
    js_overlay.append(f'tl.to("#bar-error", {{ opacity: 0, duration: 0.2 }}, {r(t_til - 0.3)});')
    js_overlay.append(f'tl.to("#bar", {{ boxShadow: "0 6px 24px rgba(0,0,0,0.08), inset 0 0 0 3px #e4e4e7", duration: 0.25 }}, {r(t_til - 0.3)});')
    ao_motion(js_overlay, bar_word, t_til, t_ced, t_dot, bar_dot, enter_from=(1180, 120), fly=0.6)
    js_overlay.append(f'tl.to("#bar", {{ boxShadow: "0 6px 24px rgba(249,178,51,0.30), inset 0 0 0 5px {GOLD}", duration: 0.12, yoyo: true, repeat: 1 }}, {r(t_go - 0.15)});')
    js_overlay.append(f'tl.fromTo("#site", {{ opacity: 0, y: 60 }}, {{ opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }}, {r(t_go)});')

    scenes = []
    cid, start, dur = "endereco-1-onde", 0.0, B(19) + 0.1
    fid = "frame-" + cid
    l1, c1, n1 = outline_line("Onde fica a sua", 84, f"{fid}-l1", 160)
    l2, c2, n2 = outline_line("facturação?", 84, f"{fid}-l2", 270)
    caps = [("Tire o til.", t_til - 0.3, t_ced - 0.2), ("Tire a cedilha.", t_ced - 0.2, t_dot - 0.6), ("Ponha um ponto.", t_dot - 0.6, t_go + 0.4), ("É só isso.", t_go + 0.4, None)]
    cap_html, cap_css, js = [], [], []
    for i, (text, t_in, t_out) in enumerate(caps):
        h, c = caption(fid, i, text, 230)
        cap_html.append(h)
        cap_css.append(c)
        cap_in_out(js, fid, i, t_in, t_out)
    js.append(f'tl.to("#{fid}-l1, #{fid}-l2", {{ opacity: 0, y: -40, duration: 0.24, ease: "power2.in" }}, {r(t_til - 0.55)});')
    js.append(f'tl.to("#{fid}-cap3", {{ opacity: 0, y: -14, duration: 0.18, ease: "power2.in" }}, {r(dur - 0.25)});')
    scenes.append((cid, start, dur, P.scene_doc(cid, dur, "\n    ".join([c1, c2] + cap_css), "      " + "\n      ".join([l1, l2] + cap_html), "      " + "\n      ".join(js))))
    scenes.append(end_scene("endereco-2-disponivel", B(19) + 0.1, END - (B(19) + 0.1), B(21)))

    moves = [
        (0, phone, 0, ""),
        (0.55, P.focus(0.87, P.SCREEN_X + 603, P.SCREEN_Y + bar_y + bar_h / 2, cy=900), 0.6, "power3.inOut"),
        (t_go + 0.25, P.placed(0.75, y=330), 0.7, "power3.inOut"),
        (B(19) - 0.2, GONE, 0.45, "power2.in"),
    ]
    sfx = [("keyboard-typing", typing[0], 0.22), ("error-buzz", refused, 0.18), ("whoosh-up", t_til - 0.05, 0.2),
           ("drop-thud", t_ced + 0.5, 0.16), ("pop-confirm", t_dot, 0.22), ("success-chime", t_go, 0.16), ("impact-soft", B(21), 0.26)]
    doc = root("endereco.html", END, [], moves, scenes, "endereco.mp3", sfx, drawn=True, overlay=overlay)
    doc = doc.replace("</style>", shots_css + "    </style>", 1)
    doc = doc.replace('      window.__timelines["main"] = tl;', "      " + "\n      ".join(js_overlay) + '\n      window.__timelines["main"] = tl;')
    write("endereco.html", scenes, doc)


if __name__ == "__main__":
    tudo_em_ao()
    mudar()
    endereco()
