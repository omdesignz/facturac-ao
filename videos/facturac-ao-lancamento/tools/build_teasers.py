#!/usr/bin/env python3
"""The older teasers, recreated with the voice leading. Run from the project root.

"Quem me deve", the four "Por ofício" cuts and "O ponto" (vertical status cut and
landscape teaser) used to show drawn phones with web screens and no voice. Same method
as build_films.py, whose parts this reuses: each film is one continuous ElevenLabs read
(assets/vo) and every cut, move and highlight below sits on a word of it (`c(t)` is a
second of the read). tools/mix_voice.py lays the read on and exports.

Everything on a phone is the real iPhone app against the demo company (tools/demo, with
tools/demo/trade.php for each trade's shelf and tools/demo/agt_replay.php for the AGT's
answer): captures in assets/capture-teasers.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import build_films as F  # noqa: E402
from build_films import FRAME_H, Scene, c, end_scene, placed, root, write  # noqa: E402

CAP = "assets/capture-teasers"
MUSIC = "track-phone.mp3"

# Places on the real screens the voice names (x, y, width, height in screen pixels).
SPOT_PANEL = (54, 455, 1098, 535)
SPOT_FIGURE = (40, 455, 740, 150)
SPOT_ROWS = (30, 1240, 1150, 340)
SPOT_SEND = (40, 2270, 1126, 270)
SPOT_TOTAL = (40, 2210, 1126, 120)
SPOT_VALIDATED = (102, 720, 460, 92)
SPOT_TIMELINE = (40, 1120, 1126, 710)
SPOT_EM_DIA = (30, 1055, 1150, 140)
SPOT_RG_ROW = (30, 810, 1150, 140)
SPOT_FT_ROW = (30, 965, 1150, 140)

REST = placed(0.56, y=330)
BIG = placed(0.72, y=310)
PULL = placed(0.62, y=250)
GONE = {**REST, "y": F.H + 160}
IN = "power3.inOut"


def cuts(sequence, end):
    """[(file, film time, fade, seek into a clip)] to the root's screens; each holds to the next."""
    screens = []
    for i, shot in enumerate(sequence):
        src, start = shot[0], shot[1]
        following = sequence[i + 1][1] if i + 1 < len(sequence) else end
        path = src if src.startswith("assets/") else f"{CAP}/{src}"
        entry = {"id": f"shot-{i}", "src": path, "start": start, "dur": min(following - start + 0.2, end - start), "from": shot[3] if len(shot) > 3 else 0}
        if len(shot) > 2 and shot[2]:
            entry["fade"] = True
        screens.append(entry)
    return screens


# ------------------------------------------------------------------ Quem me deve

def quem_deve():
    """Read: assets/vo/quemdeve-a.mp3."""
    end = c(20.86) + 1.5

    s1 = Scene("deve-1-quanto", 0, c(5.84))
    s1.head("Quem me deve<b>?</b>", 92, 86, None)
    s1.cap("Aqui vê quanto.", 214, c(1.86), c(3.04), px=46)
    s1.cap("O mais antigo primeiro.", 214, c(3.1), c(5.84), px=46)

    s2 = Scene("deve-2-lembrete", c(5.84), c(14.72))
    s2.cap("O lembrete já vem escrito.", 150, c(6.0), c(9.12), px=52)
    s2.cap("Nada é enviado até confirmar.", 150, c(9.18), c(11.38), px=52)
    s2.cap("O cliente paga.", 150, c(11.44), c(12.62), px=52)
    s2.cap("Regista.", 150, c(12.68), c(13.46), px=52)
    s2.cap("E o número desce.", 150, c(13.52), c(14.72), px=52)

    s3 = Scene("deve-3-seu", c(14.76), c(18.06))
    s3.head("Este dinheiro", 112, 690, c(14.8))
    s3.head("já era seu<b>.</b>", 112, 815, c(15.52))
    s3.cap("Só faltava lembrar.", 975, c(16.66), px=56)

    s4 = end_scene("deve-4-disponivel", c(18.06), end, c(18.38), c(18.9), c(19.42), c(20.0))

    screens = cuts([
        ("hoje.png", 0),
        ("cobrar-antes.png", c(1.76), True),
        ("q-lembrar.mp4", c(5.9), True),
        ("q-scroll.mp4", c(7.0)),
        ("lembrete-fim.png", c(9.45)),
        ("q-enviar.mp4", c(10.98)),
        ("q-recibo.mp4", c(12.6)),
        ("q-desce.mp4", c(13.44)),
        ("cobrar-depois.png", c(14.8)),
    ], end)
    moves = [
        (0, REST, 0, ""),
        (c(1.62), BIG, 0.5, IN),
        (c(5.72), PULL, 0.5, IN),
        (c(13.3), BIG, 0.4, IN),
        (c(14.52), GONE, 0.42, "power2.in"),
    ]
    spots = [
        (SPOT_PANEL, 0.2, c(1.5)),
        (SPOT_FIGURE, c(1.86), c(3.0)),
        (SPOT_ROWS, c(3.1), c(5.6)),
        (SPOT_SEND, c(9.36), c(10.86)),
        (SPOT_FIGURE, c(14.0), c(14.5)),
    ]
    sfx = [("pop-soft", c(6.0), 0.12), ("pop-soft", c(10.98), 0.14), ("chime-resolve", c(12.86), 0.09), ("tick-soft", c(13.66), 0.12), ("impact-soft", c(18.9), 0.16)]
    scenes = [s1, s2, s3, s4]
    write("quem-deve", scenes, root(end, screens, moves, scenes, MUSIC, sfx, spots=spots))


# --------------------------------------------------------------------- Por ofício

# Each trade's read, in seconds of the take: the question, the line after it, the three
# articles, the total, "emitida", "enviada", "sozinha", "já", "disponível", the name, its end.
TRADES = {
    "oficina": {
        "take": "oficina-a", "word": "Oficina", "ask": "Mudou o óleo?", "line": "Antes de entregar o carro.",
        "t": dict(line=1.26, i1=3.92, i2=4.6, i3=5.26, total=6.26, emit=8.14, env=8.92, solo=10.7, ja=11.62, disp=11.78, name=12.82, end=14.36),
    },
    "farmacia": {
        "take": "farmacia-b", "word": "Farmácia", "ask": "Aviou a receita?", "line": "Com o cliente ao balcão.",
        "t": dict(line=1.42, i1=3.9, i2=4.96, i3=6.04, total=7.02, emit=8.98, env=9.78, solo=11.48, ja=12.44, disp=12.58, name=13.6, end=15.02),
    },
    "restaurante": {
        "take": "restaurante-a", "word": "Restaurante", "ask": "Pediram a conta?", "line": "Antes de a mesa se levantar.",
        "t": dict(line=1.32, i1=4.18, i2=5.08, i3=5.76, total=6.7, emit=8.78, env=9.58, solo=11.5, ja=12.52, disp=12.68, name=13.7, end=14.96),
    },
    "loja": {
        "take": "loja-a", "word": "Loja", "ask": "Cliente à espera?", "line": "Não o faça esperar mais.",
        "t": dict(line=1.38, i1=3.14, i2=4.0, i3=4.86, total=5.58, emit=7.42, env=8.18, solo=10.04, ja=11.0, disp=11.16, name=12.14, end=13.62),
    },
}
EMIT_CLIP = {"oficina": (1.8, 0.5), "farmacia": (2.0, 0.6), "restaurante": (1.9, 0.4), "loja": (1.9, 0.4)}  # length, when the press lands


def oficio(film):
    """One trade: its catalogue, its three articles said one by one, issued, validated."""
    trade = TRADES[film]
    t = trade["t"]
    end = c(t["end"]) + 1.5

    s1 = Scene(f"{film}-1-pergunta", 0, c(t["line"] - 0.3))
    s1.head(f'{trade["word"]}<b>.</b>', 92, 86, None)
    s1.cap(trade["ask"], 214, c(0.02), px=46)

    s2 = Scene(f"{film}-2-factura", c(t["line"] - 0.3), c(t["ja"] - 0.4))
    s2.cap(trade["line"], 170, c(t["line"]), c(t["i1"] - 0.3), px=48)
    s2.cap("O total já vem com IVA.", 170, c(t["total"]), c(t["emit"] - 0.14), px=48)
    s2.cap("Emitida e enviada à AGT.", 170, c(t["emit"]), c(t["solo"] - 0.08), px=48)
    s2.cap("Sozinha.", 170, c(t["solo"]), px=48)

    s3 = end_scene(f"{film}-3-disponivel", c(t["ja"] - 0.4), end, c(t["disp"]), c(t["disp"] + 0.5), c(t["name"]), c(t["name"] + 0.5))

    emit_len, press = EMIT_CLIP[film]
    emit_at = c(t["emit"]) - press
    screens = cuts([
        (f"{film}-artigos.png", 0),
        (f"{film}-0.png", c(t["line"] - 0.1), True),
        (f"{film}-1.png", c(t["i1"] - 0.08), True),
        (f"{film}-2.png", c(t["i2"] - 0.08), True),
        (f"{film}-linhas.png", c(t["i3"] - 0.08), True),
        (f"{film}-emitir.mp4", emit_at, True),
        (f"{film}-validada.png", min(emit_at + emit_len - 0.1, c(t["env"] + 0.5)), True),
    ], end)
    moves = [
        (0, REST, 0, ""),
        (c(t["line"] - 0.34), BIG, 0.5, IN),
        (c(t["total"] - 0.3), PULL, 0.42, IN),
        (c(t["ja"] - 0.7), GONE, 0.42, "power2.in"),
    ]
    spots = [
        (SPOT_TOTAL, c(t["total"] + 0.14), c(t["emit"] - 0.5)),
        (SPOT_VALIDATED, c(t["solo"] - 0.4), c(t["ja"] - 0.76)),
    ]
    sfx = [("tick-soft", c(t["i1"]), 0.12), ("tick-soft", c(t["i2"]), 0.12), ("tick-soft", c(t["i3"]), 0.12),
           ("pop-confirm", c(t["emit"]) + 0.3, 0.12), ("chime-resolve", c(t["solo"] - 0.4), 0.09), ("impact-soft", c(t["disp"] + 0.5), 0.16)]
    scenes = [s1, s2, s3]
    write(film, scenes, root(end, screens, moves, scenes, MUSIC, sfx, spots=spots))


# ------------------------------------------------------- O ponto, two shapes

LEFT, COLUMN = 110, 900


class Side(Scene):
    """A scene whose type sits in a column beside the phone (the landscape teaser)."""

    def head(self, html, px, top, t_in, t_out=None, color=F.INK, stagger=0.045):
        super().head(html, px, top, t_in, t_out, color, stagger)
        self.css[-1] = self.css[-1].replace("left: 54px; right: 54px;", f"left: {LEFT}px; width: {COLUMN}px;").replace("justify-content: center", "justify-content: flex-start")

    def cap(self, text, top, t_in, t_out=None, px=44):
        super().cap(text, top, t_in, t_out, px)
        self.css[-1] = self.css[-1].replace("left: 54px; right: 54px;", f"left: {LEFT}px; width: {COLUMN}px;").replace("text-align: center", "text-align: left")


def ponto(film, wide):
    """Read: assets/vo/status-a.mp3. The same read and the same cuts in both shapes."""
    end = c(33.36) + 1.5
    if wide:
        F.W, F.H = 1920, 1080
        scale = 0.36
        side = placed(scale, x=1160, y=(F.H - FRAME_H * scale) / 2)
        rest = big = pull = side
        gone = {**side, "y": F.H + 160}
        S = Side
        word_px, word_top, line_px, line_top = 132, 410, 72, 440
        title = dict(px=112, cap=44)
        tops = dict(cert=340, number=620, must=330, law=420, date=560)
    else:
        rest, big, pull, gone = REST, BIG, PULL, GONE
        S = Scene
        word_px, word_top, line_px, line_top = 112, 110, 52, 150
        title = dict(px=112, cap=48)
        tops = dict(cert=720, number=1000, must=660, law=750, date=880)

    def say(scene, text, t_in, t_out=None):
        """One spoken line: quiet above the phone, larger beside it."""
        if wide:
            scene.head(text, line_px, line_top, t_in, t_out)
        else:
            scene.cap(text, line_top, t_in, t_out, px=line_px)

    s1 = S("ponto-1-estados", 0, c(2.86))
    s1.head("Emitida<b>.</b>", word_px, word_top, None, c(1.04))
    s1.head("Validada<b>.</b>", word_px, word_top, c(1.08), c(2.06))
    s1.head("Paga<b>.</b>", word_px, word_top, c(2.1))

    s2 = S("ponto-2-telemovel", c(2.86), c(5.9))
    say(s2, "A sua facturação,", c(2.94), c(4.2))
    say(s2, "no telemóvel que já tem.", c(4.24))

    s3 = S("ponto-3-emitir", c(5.9), c(9.4))
    say(s3, "Escolhe o cliente.", c(5.98), c(7.2))
    say(s3, "Junta os artigos.", c(7.26), c(8.44))
    say(s3, "E emite.", c(8.48))

    s4 = S("ponto-4-agt", c(9.4), c(12.5))
    say(s4, "Cada documento segue para a AGT.", c(9.48), c(11.54))
    say(s4, "Sozinho.", c(11.58))

    s5 = S("ponto-5-cobrar", c(12.5), c(15.44))
    say(s5, "Sabe quem lhe deve.", c(12.56), c(13.54))
    say(s5, "O lembrete já vem escrito.", c(13.58))

    s6 = S("ponto-6-recebeu", c(15.44), c(18.3))
    say(s6, "Recebeu?", c(15.5), c(16.32))
    say(s6, "Regista, e fica em dia.", c(16.36))

    s7 = S("ponto-7-tudo", c(18.3), c(22.76))
    say(s7, "Facturas.", c(18.36), c(19.04))
    say(s7, "Recibos.", c(19.08), c(19.7))
    say(s7, "Clientes.", c(19.74), c(20.42))
    say(s7, "Artigos.", c(20.46), c(21.36))
    say(s7, "Tudo no mesmo sítio.", c(21.4))

    s8 = Scene("ponto-8-certificado", c(22.86), c(25.36))
    s8.head("Certificado", title["px"], tops["cert"], c(23.5))
    s8.head("pela AGT<b>.</b>", title["px"], tops["cert"] + title["px"] + 14, c(24.2))
    s8.cap("N.º FE/419/AGT/2026", tops["number"], c(24.5), px=title["cap"])

    s9 = Scene("ponto-9-obrigatoria", c(25.36), c(30.64))
    s9.cap("A factura electrónica", tops["must"], c(25.44), px=title["cap"])
    s9.head("é obrigatória", title["px"], tops["law"], c(26.7))
    s9.head("a 1 de janeiro de 2027<b>.</b>", title["px"] - 28, tops["date"], c(28.04))

    s10 = end_scene("ponto-10-disponivel", c(30.64), end, c(30.94), c(31.44), c(31.9), c(32.3))
    if wide:
        s10.css[0] = s10.css[0].replace("top: 700px", "top: 230px").replace("top: 930px", "top: 460px").replace("top: 1100px", "top: 640px")

    screens = cuts([
        ("factura-emitida.png", 0),
        ("doc-validada.png", c(1.0), True),
        ("doc-paga.png", c(2.02), True),
        ("assets/capture/home.png", c(2.86), True),
        ("assets/capture/launch-60.mp4", c(3.4), False, 2.5),
        ("hoje.png", c(5.3), True),
        ("s-picker.png", c(5.9), True),
        ("factura-linhas.png", c(7.2), True),
        ("s-emitir.mp4", c(8.3), True),
        ("doc-validada.png", c(9.4), True),
        ("cobrar-antes.png", c(12.5), True),
        ("lembrete.png", c(13.54), True),
        ("pagamento-teclado.png", c(15.44), True),
        ("q-recibo.mp4", c(16.16), True),
        ("registos-clientes.png", c(17.06), True),
        ("registos-documentos.png", c(18.3), True),
        ("registos-clientes.png", c(19.7), True),
        ("registos-artigos.png", c(20.42), True),
    ], end)
    moves = [
        (0, rest, 0, ""),
        (c(5.7), pull, 0.42, IN),
        (c(12.32), big, 0.42, IN),
        (c(15.3), pull, 0.42, IN),
        (c(22.42), gone, 0.42, "power2.in"),
    ]
    if wide:
        moves = [moves[0], moves[-1]]
    spots = [
        (SPOT_VALIDATED, c(1.26), c(1.92)),
        (SPOT_TIMELINE, c(9.7), c(12.3)),
        (SPOT_FIGURE, c(12.66), c(13.4)),
        (SPOT_EM_DIA, c(17.2), c(18.16)),
        (SPOT_FT_ROW, c(18.42), c(18.98)),
        (SPOT_RG_ROW, c(19.14), c(19.62)),
    ]
    sfx = [("pop-confirm", c(0.1), 0.1), ("chime-resolve", c(1.26), 0.08), ("pop-soft", c(3.7), 0.12), ("pop-confirm", c(8.7), 0.12),
           ("chime-resolve", c(16.5), 0.08), ("tick-soft", c(18.36), 0.1), ("tick-soft", c(19.08), 0.1), ("tick-soft", c(19.74), 0.1), ("tick-soft", c(20.46), 0.1),
           ("impact-soft", c(31.44), 0.16)]
    scenes = [s1, s2, s3, s4, s5, s6, s7, s8, s9, s10]
    write(film, scenes, root(end, screens, moves, scenes, "track-phone-long.mp3", sfx, spots=spots))
    F.W, F.H = 1080, 1920


if __name__ == "__main__":
    quem_deve()
    for name in TRADES:
        oficio(name)
    ponto("status", wide=False)
    ponto("teaser", wide=True)
