#!/usr/bin/env python3
"""
Writes the scenes and the main composition of "Cobrar", the facturac.ao promo.

Every number that matters for how this survives a messaging app lives here:
type is large (nothing under 44px on a 1080px frame), the ground is one flat
colour (no grid, no gradient, no grain: those are what a low-bitrate encoder
turns into blocks), and everything the viewer must read sits inside the safe
zone (y 250 to 1500) that WhatsApp status and Reels leave clear.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FRAMES = ROOT / "compositions" / "frames"
FRAMES.mkdir(parents=True, exist_ok=True)

INK = "#171716"
GOLD = "#f9b233"
PAPER = "#ffffff"
MUTED = "#57534e"

# Scene id, start, duration. The last scene ends the film.
SCENES = [
    ("01-quem", 0.0, 3.0),
    ("02-lembrete", 3.0, 5.6),
    ("03-enviar", 8.6, 4.3),
    ("04-factura", 12.9, 4.8),
    ("05-certificada", 17.7, 4.5),
    ("06-prazo", 22.2, 3.8),
    ("07-comece", 26.0, 4.0),
]
TOTAL = 30.0

DUR = {"drop-thud": 0.792, "impact-soft": 2.758, "pop-confirm": 0.576, "pop-soft": 0.576, "whoosh-soft": 1.58, "whoosh-up": 1.58}

# Source image geometry: the full-page phone capture is 1185px wide at 3x and the
# screen is 664px wide, so one source pixel is SCALE screen pixels.
IMG_W = 672.5
SCALE = IMG_W / 1185.0


def y_for(src_y: float) -> float:
    return round(-src_y * SCALE, 1)


def page(scene_id: str, css: str, body: str, js: str, ground: str = PAPER) -> str:
    p = f"f{scene_id.split('-')[0]}"
    return f"""<template>
  <style>
    @font-face {{ font-family: "Hanken Grotesk"; font-weight: 600; font-style: normal; font-display: block;
      src: url("assets/fonts/HankenGrotesk-600.woff2") format("woff2"); }}
    @font-face {{ font-family: "Hanken Grotesk"; font-weight: 700; font-style: normal; font-display: block;
      src: url("assets/fonts/HankenGrotesk-700.woff2") format("woff2"); }}
    #root {{ position: absolute; inset: 0; width: 1080px; height: 1920px; overflow: hidden;
      font-family: "Hanken Grotesk", sans-serif; color: {INK}; background: {ground}; }}
    .{p}-h {{ position: absolute; left: 80px; right: 80px; font-weight: 700; letter-spacing: -0.035em; }}
    .dot {{ display: inline-block; width: 0.17em; height: 0.17em; border-radius: 50%; background: {GOLD}; margin-left: 0.04em; }}
    .hl {{ background: {GOLD}; padding: 0 0.16em 0.04em; border-radius: 0.18em; box-decoration-break: clone; -webkit-box-decoration-break: clone; }}
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


PHONE_CSS = """
    .phone { position: absolute; left: 190px; top: 560px; width: 700px; height: 1500px; border-radius: 96px; background: #171716; }
    .island { position: absolute; left: 270px; top: 40px; width: 160px; height: 42px; border-radius: 21px; background: #171716; z-index: 3; }
    .screen { position: absolute; left: 18px; top: 18px; width: 664px; height: 1464px; border-radius: 78px; overflow: hidden; background: #fff; }
    .screen img { position: absolute; left: 0; top: 0; width: %spx; height: auto; display: block; }
""" % IMG_W


def phone(img_id: str, src: str, y0: float) -> str:
    return f"""    <div class="phone" id="{img_id}-phone" data-layout-allow-overflow>
      <div class="island"></div>
      <div class="screen"><img id="{img_id}" src="{src}" data-layout-allow-overflow /></div>
    </div>"""


def build() -> None:
    # 1 ─ the question ---------------------------------------------------------
    css = f"""
    .f01-h {{ font-size: 200px; line-height: 1.1; height: 220px; }}
    .f01-pill {{ position: absolute; left: 80px; height: 118px; padding: 0 44px; border-radius: 59px; background: {INK}; color: #fff;
      display: flex; align-items: center; gap: 26px; font-weight: 600; font-size: 52px; white-space: nowrap; }}
    .f01-pill b {{ color: {GOLD}; font-weight: 700; }}
    .f01-mark {{ position: absolute; left: 80px; top: 96px; width: 330px; }}
"""
    body = f"""    <img class="f01-mark" id="f01-mark" src="assets/facturac-ao.svg" />
    <div class="f01-h" id="f01-a" style="top: 330px">Quem lhe</div>
    <div class="f01-h" id="f01-b" style="top: 575px">deve</div>
    <div class="f01-h" id="f01-c" style="top: 820px">dinheiro?</div>
    <div class="f01-pill" id="f01-p1" style="top: 1180px">Mavinga &amp; Filhos <b>378 000 Kz</b></div>
    <div class="f01-pill" id="f01-p2" style="top: 1320px">Coop. Kizomba <b>131 100 Kz</b></div>"""
    js = """
      tl.set({}, {}, 3.0);
      tl.fromTo("#f01-mark", { opacity: 0, y: -20 }, { opacity: 1, y: 0, duration: 0.4, ease: "power3.out" }, 0.1);
      ["#f01-a", "#f01-b", "#f01-c"].forEach((s, i) => {
        tl.fromTo(s, { y: 120, opacity: 0 }, { y: 0, opacity: 1, duration: 0.5, ease: "back.out(2)" }, 0.25 + i * 0.22);
      });
      tl.fromTo("#f01-p1", { x: -160, opacity: 0 }, { x: 0, opacity: 1, duration: 0.5, ease: "back.out(1.6)" }, 1.5);
      tl.fromTo("#f01-p2", { x: -160, opacity: 0 }, { x: 0, opacity: 1, duration: 0.5, ease: "back.out(1.6)" }, 1.85);
"""
    (FRAMES / "01-quem.html").write_text(page("01-quem", css, body, js))

    # 2 ─ the reminder is already written --------------------------------------
    a, b, c = y_for(330), y_for(1300), y_for(3150)
    css = PHONE_CSS + f"""
    .f02-h {{ font-size: 116px; line-height: 1.0; top: 250px; }}
    .f02-note {{ position: absolute; right: 70px; top: 508px; height: 96px; padding: 0 38px; border-radius: 48px; background: {GOLD};
      display: flex; align-items: center; font-weight: 700; font-size: 46px; color: {INK}; white-space: nowrap; z-index: 5; }}
"""
    body = f"""    <div class="f02-h f01-h" id="f02-h">O lembrete<br/>já está <span class="hl">escrito</span><span class="dot"></span></div>
    <div class="f02-note" id="f02-note">Nada é enviado até confirmar</div>
{phone("f02-img", "assets/ui/cobrar-phone-full.png", a)}"""
    js = f"""
      tl.set({{}}, {{}}, 5.6);
      tl.set("#f02-img", {{ y: {a} }}, 0);
      tl.fromTo("#f02-h", {{ y: 70, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.5, ease: "power3.out" }}, 0.05);
      tl.fromTo("#f02-img-phone", {{ y: 220, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.6, ease: "power3.out" }}, 0.15);
      tl.to("#f02-img", {{ y: {b}, duration: 0.9, ease: "power3.inOut" }}, 1.6);
      tl.to("#f02-img", {{ y: {c}, duration: 1.1, ease: "power3.inOut" }}, 3.0);
      tl.fromTo("#f02-note", {{ scale: 0.6, opacity: 0, transformOrigin: "100% 50%" }}, {{ scale: 1, opacity: 1, duration: 0.4, ease: "back.out(2)" }}, 4.3);
"""
    (FRAMES / "02-lembrete.html").write_text(page("02-lembrete", css, body, js))

    # 3 ─ review and send ------------------------------------------------------
    d = y_for(5480)
    css = PHONE_CSS + f"""
    .f03-h {{ font-size: 140px; line-height: 1.0; top: 250px; }}
    .f03-tap {{ position: absolute; left: 480px; top: 1251px; width: 120px; height: 120px; border-radius: 60px; border: 10px solid {INK}; z-index: 6; opacity: 0; }}
    .f03-done {{ position: absolute; left: 150px; top: 760px; width: 780px; height: 360px; border-radius: 70px; background: {INK}; z-index: 7;
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 20px; opacity: 0; }}
    .f03-done span {{ font-weight: 700; font-size: 110px; color: #fff; letter-spacing: -0.03em; }}
    .f03-done i {{ display: grid; place-items: center; width: 120px; height: 120px; border-radius: 60px; background: {GOLD}; }}
"""
    body = f"""    <div class="f03-h f01-h" id="f03-h">Reveja.<br/>Envie<span class="dot"></span></div>
{phone("f03-img", "assets/ui/cobrar-phone-full.png", y_for(3150))}
    <div class="f03-tap" id="f03-tap"></div>
    <div class="f03-done" id="f03-done"><i><svg width="68" height="68" viewBox="0 0 24 24" fill="none" stroke="{INK}" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></i><span>Enviado.</span></div>"""
    js = f"""
      tl.set({{}}, {{}}, 4.3);
      tl.set("#f03-img", {{ y: {y_for(3150)} }}, 0);
      tl.fromTo("#f03-h", {{ y: 60, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.45, ease: "power3.out" }}, 0.05);
      tl.to("#f03-img", {{ y: {d}, duration: 1.1, ease: "power3.inOut" }}, 0.5);
      tl.fromTo("#f03-tap", {{ scale: 1.7, opacity: 0 }}, {{ scale: 1, opacity: 1, duration: 0.3, ease: "power2.out" }}, 2.1);
      tl.to("#f03-tap", {{ scale: 0.82, duration: 0.1, ease: "power2.in" }}, 2.45);
      tl.to("#f03-tap", {{ opacity: 0, duration: 0.2 }}, 2.6);
      tl.fromTo("#f03-done", {{ scale: 0.7, opacity: 0, transformOrigin: "50% 50%" }}, {{ scale: 1, opacity: 1, duration: 0.45, ease: "back.out(2.2)" }}, 2.7);
"""
    (FRAMES / "03-enviar.html").write_text(page("03-enviar", css, body, js))

    # 4 ─ invoicing ------------------------------------------------------------
    css = f"""
    .f04-h {{ font-size: 124px; line-height: 1.14; top: 250px; }}
    .f04-card {{ position: absolute; left: 80px; top: 640px; width: 920px; height: 700px; border-radius: 64px; background: #f5f5f7; }}
    .f04-k {{ position: absolute; left: 56px; top: 50px; font-weight: 600; font-size: 40px; letter-spacing: 0.08em; text-transform: uppercase; color: {MUTED}; }}
    .f04-no {{ position: absolute; left: 56px; top: 108px; font-weight: 700; font-size: 58px; letter-spacing: -0.01em; }}
    .f04-cl {{ position: absolute; left: 56px; top: 190px; font-weight: 600; font-size: 52px; color: {MUTED}; }}
    .f04-tot {{ position: absolute; left: 56px; top: 270px; font-weight: 700; font-size: 132px; letter-spacing: -0.04em; }}
    .f04-tot small {{ font-size: 64px; font-weight: 600; color: {MUTED}; letter-spacing: 0; margin-left: 14px; }}
    .f04-rail {{ position: absolute; left: 90px; width: 740px; top: 520px; height: 14px; border-radius: 7px; background: #d6d3d1; }}
    .f04-fill {{ position: absolute; left: 0; top: 0; height: 14px; border-radius: 7px; background: {INK}; width: 0; }}
    .f04-node {{ position: absolute; top: 507px; width: 40px; height: 40px; border-radius: 20px; background: #d6d3d1; }}
    .f04-lab {{ position: absolute; top: 580px; font-weight: 600; font-size: 44px; color: {MUTED}; width: 200px; text-align: center; }}
    .f04-ok {{ position: absolute; left: 80px; top: 1400px; height: 112px; padding: 0 48px; border-radius: 56px; background: #bef264; color: #1a2e05;
      display: flex; align-items: center; gap: 22px; font-weight: 700; font-size: 54px; white-space: nowrap; }}
"""
    xs = [140, 385, 630, 820]  # node centres inside the card
    labs = ["Rascunho", "Emitida", "Validada", "Paga"]
    nodes = "\n".join(f'      <div class="f04-node" id="f04-n{i}" style="left: {x - 20}px"></div>' for i, x in enumerate(xs))
    labels = "\n".join(f'      <div class="f04-lab" id="f04-l{i}" style="left: {x - 100}px">{t}</div>' for i, (x, t) in enumerate(zip(xs, labs)))
    body = f"""    <div class="f04-h f01-h" id="f04-h">E para facturar?<br/><span class="hl">Em segundos</span><span class="dot"></span></div>
    <div class="f04-card" id="f04-card">
      <div class="f04-k">Factura-recibo</div>
      <div class="f04-no">FR LOJA2026/00184</div>
      <div class="f04-cl">Mercearia Kilamba</div>
      <div class="f04-tot">139 080,00<small>Kz</small></div>
      <div class="f04-rail"><div class="f04-fill" id="f04-fill"></div></div>
{nodes}
{labels}
    </div>
    <div class="f04-ok" id="f04-ok"><svg width="58" height="58" viewBox="0 0 24 24" fill="none" stroke="#1a2e05" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>Assinada e comunicada à AGT</div>"""
    js = f"""
      tl.set({{}}, {{}}, 4.8);
      tl.fromTo("#f04-h", {{ y: 70, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.5, ease: "power3.out" }}, 0.05);
      tl.fromTo("#f04-card", {{ y: 180, opacity: 0 }}, {{ y: 0, opacity: 1, duration: 0.6, ease: "power3.out" }}, 0.45);
      const xs = {xs};
      const railLeft = 90;
      xs.slice(0, 3).forEach((x, i) => {{
        const at = 1.3 + i * 0.5;
        if (i > 0) tl.to("#f04-fill", {{ width: x - railLeft, duration: 0.4, ease: "power2.inOut" }}, at - 0.4);
        tl.to("#f04-n" + i, {{ backgroundColor: i === 2 ? "{GOLD}" : "{INK}", scale: i === 2 ? 1.5 : 1, duration: 0.25, ease: "back.out(2)" }}, at);
        tl.to("#f04-l" + i, {{ color: "{INK}", duration: 0.25 }}, at);
      }});
      tl.fromTo("#f04-ok", {{ scale: 0.8, opacity: 0, transformOrigin: "0% 50%" }}, {{ scale: 1, opacity: 1, duration: 0.45, ease: "back.out(2)" }}, 3.0);
"""
    (FRAMES / "04-factura.html").write_text(page("04-factura", css, body, js))

    # 5 ─ certified ------------------------------------------------------------
    css = f"""
    .f05-h {{ font-size: 176px; line-height: 0.96; top: 640px; }}
    .f05-badge {{ position: absolute; left: 80px; top: 350px; width: 220px; height: 220px; border-radius: 110px; background: {GOLD}; display: grid; place-items: center; }}
    .f05-line {{ position: absolute; left: 80px; right: 80px; font-weight: 600; font-size: 60px; line-height: 1.15; color: {INK}; }}
    .f05-num {{ font-family: "Hanken Grotesk", sans-serif; font-weight: 700; letter-spacing: -0.01em; }}
"""
    body = f"""    <div class="f05-badge" id="f05-badge"><svg width="132" height="132" viewBox="0 0 24 24" fill="none" stroke="{INK}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.5l7.5 2.8v6.2c0 4.6-3.1 8.3-7.5 10-4.4-1.7-7.5-5.4-7.5-10V5.3z"/><path d="M8.5 12.2l2.5 2.5 4.6-4.9"/></svg></div>
    <div class="f05-h f01-h" id="f05-h">Certificada<br/>pela AGT<span class="dot"></span></div>
    <div class="f05-line" id="f05-l1" style="top: 1090px">Certificado n.º <span class="f05-num">FE/419/AGT/2026</span></div>
    <div class="f05-line" id="f05-l2" style="top: 1180px; color: {MUTED}">28 de outubro de 2026</div>"""
    js = """
      tl.set({}, {}, 4.5);
      tl.fromTo("#f05-badge", { scale: 0.3, rotation: -25, opacity: 0, transformOrigin: "50% 50%" }, { scale: 1, rotation: 0, opacity: 1, duration: 0.6, ease: "back.out(2.2)" }, 0.1);
      tl.fromTo("#f05-h", { y: 80, opacity: 0 }, { y: 0, opacity: 1, duration: 0.55, ease: "power3.out" }, 0.45);
      tl.fromTo("#f05-l1", { y: 40, opacity: 0 }, { y: 0, opacity: 1, duration: 0.45, ease: "power3.out" }, 1.3);
      tl.fromTo("#f05-l2", { y: 40, opacity: 0 }, { y: 0, opacity: 1, duration: 0.45, ease: "power3.out" }, 1.6);
"""
    (FRAMES / "05-certificada.html").write_text(page("05-certificada", css, body, js))

    # 6 ─ the deadline ---------------------------------------------------------
    css = f"""
    .f06-h {{ font-size: 200px; line-height: 0.96; top: 500px; color: #fff; }}
    .f06-rule {{ position: absolute; left: 80px; top: 1100px; width: 220px; height: 12px; border-radius: 6px; background: {GOLD}; }}
    .f06-sub {{ position: absolute; left: 80px; right: 80px; top: 1170px; font-weight: 600; font-size: 62px; line-height: 1.2; color: #e7e5e4; }}
"""
    body = f"""    <div class="f06-h f01-h" id="f06-h">1 de janeiro<br/>de 2027<span class="dot"></span></div>
    <div class="f06-rule" id="f06-rule"></div>
    <div class="f06-sub" id="f06-sub">Todas as empresas emitem factura electrónica.</div>"""
    js = """
      tl.set({}, {}, 3.8);
      tl.fromTo("#f06-h", { y: 90, opacity: 0 }, { y: 0, opacity: 1, duration: 0.55, ease: "power3.out" }, 0.1);
      tl.fromTo("#f06-rule", { scaleX: 0, opacity: 1, transformOrigin: "0% 50%" }, { scaleX: 1, opacity: 1, duration: 0.5, ease: "power3.out" }, 0.9);
      tl.fromTo("#f06-sub", { y: 40, opacity: 0 }, { y: 0, opacity: 1, duration: 0.5, ease: "power3.out" }, 1.2);
"""
    (FRAMES / "06-prazo.html").write_text(page("06-prazo", css, body, js, ground=INK))

    # 7 ─ start ----------------------------------------------------------------
    css = f"""
    .f07-mark {{ position: absolute; left: 130px; top: 470px; width: 820px; }}
    .f07-h {{ font-size: 124px; line-height: 1.05; top: 770px; }}
    .f07-cta {{ position: absolute; left: 80px; top: 1090px; height: 150px; padding: 0 70px; border-radius: 75px; background: {GOLD}; color: {INK};
      display: flex; align-items: center; font-weight: 700; font-size: 66px; letter-spacing: -0.02em; white-space: nowrap; }}
    .f07-foot {{ position: absolute; left: 80px; right: 80px; top: 1300px; font-weight: 600; font-size: 52px; color: {MUTED}; }}
"""
    body = f"""    <img class="f07-mark" id="f07-mark" src="assets/facturac-ao.svg" />
    <div class="f07-h f01-h" id="f07-h">Abra a sua conta<br/>grátis<span class="dot"></span></div>
    <div class="f07-cta" id="f07-cta">facturac.ao</div>
    <div class="f07-foot" id="f07-foot">Sem cartão. Sem contrato.</div>"""
    js = """
      tl.set({}, {}, 4.0);
      tl.fromTo("#f07-mark", { scale: 0.88, opacity: 0, transformOrigin: "50% 50%" }, { scale: 1, opacity: 1, duration: 0.6, ease: "back.out(1.6)" }, 0.1);
      tl.fromTo("#f07-h", { y: 70, opacity: 0 }, { y: 0, opacity: 1, duration: 0.5, ease: "power3.out" }, 0.55);
      tl.fromTo("#f07-cta", { scale: 0.8, opacity: 0, transformOrigin: "0% 50%" }, { scale: 1, opacity: 1, duration: 0.45, ease: "back.out(2)" }, 1.1);
      tl.fromTo("#f07-foot", { y: 30, opacity: 0 }, { y: 0, opacity: 1, duration: 0.4, ease: "power3.out" }, 1.5);
"""
    (FRAMES / "07-comece.html").write_text(page("07-comece", css, body, js))

    # main composition ---------------------------------------------------------
    scenes = "\n".join(
        f"""      <div id="el-{sid}" class="scene" data-composition-id="{sid}" data-composition-src="compositions/frames/{sid}.html"
        data-start="{start}" data-duration="{dur}" data-track-index="1"></div>"""
        for sid, start, dur in SCENES
    )
    sfx = [
        ("whoosh-soft", 0.3, 0.36), ("pop-confirm", 1.55, 0.5), ("pop-confirm", 1.9, 0.5),
        ("whoosh-up", 2.85, 0.34), ("whoosh-soft", 4.6, 0.3), ("whoosh-soft", 6.0, 0.3), ("pop-soft", 7.3, 0.4),
        ("whoosh-up", 8.45, 0.34), ("whoosh-soft", 9.1, 0.3), ("pop-confirm", 10.75, 0.5), ("drop-thud", 11.3, 0.3),
        ("whoosh-up", 12.75, 0.34), ("whoosh-soft", 13.4, 0.3), ("pop-confirm", 14.8, 0.5),
        ("whoosh-up", 17.55, 0.34), ("pop-confirm", 17.85, 0.5), ("drop-thud", 18.3, 0.3), ("pop-soft", 19.0, 0.36),
        ("whoosh-up", 22.05, 0.34), ("impact-soft", 22.4, 0.3), ("pop-soft", 23.1, 0.36),
        ("whoosh-up", 25.85, 0.34), ("pop-confirm", 27.1, 0.5), ("impact-soft", 26.6, 0.28),
    ]
    sfx_tags = "\n".join(
        f"""      <audio id="el-sfx-{i}" src="assets/sfx/{name}.mp3" data-start="{t}" data-duration="{ DUR[name] }" data-track-index="{20 + i}" data-volume="{vol}"></audio>"""
        for i, (name, t, vol) in enumerate(sfx)
    )
    index = f"""<!doctype html>
<html lang="pt-AO">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=1080, height=1920" />
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js" integrity="sha384-sG0Hv1tP1lZCk9KQmrIbY/XNwi+OY84GQqhMscbnsoBFqAz8KNCil1kvfL3Hbbk2" crossorigin="anonymous"></script>
    <style>
      * {{ margin: 0; padding: 0; box-sizing: border-box; }}
      html, body {{ width: 1080px; height: 1920px; overflow: hidden; background: #000; }}
      #root {{ position: relative; width: 1080px; height: 1920px; overflow: hidden; background: {PAPER}; }}
      .scene {{ position: absolute; inset: 0; width: 100%; height: 100%; }}
    </style>
  </head>
  <body>
    <div id="root" data-composition-id="main" data-start="0" data-duration="{TOTAL}" data-width="1080" data-height="1920">
{scenes}

      <audio id="el-bgm" src="assets/bgm/track.mp3" data-start="0" data-duration="{TOTAL}" data-track-index="11" data-volume="0.85"></audio>
{sfx_tags}
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
    print("scenes:", len(SCENES), "total:", TOTAL)
