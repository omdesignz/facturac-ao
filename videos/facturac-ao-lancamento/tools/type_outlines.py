"""Century Gothic Bold as SVG outlines, letter by letter, for the launch films.

The brand wordmark is Century Gothic Bold converted to outlines at 100 units
per em with -5 units of tracking; this reproduces the same geometry per glyph
so a word can be animated letter by letter and still land on the logo exactly.
Composite glyphs are split into their parts: "ç" is c + cedilla and "ã" is
a + tilde, which is what lets the til and the cedilha leave their letters.

No font file is shipped: only path data ends up in the compositions.
"""

import html
import os

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

FONT = os.path.expanduser("~/Library/Fonts/ufonts.com_century-gothic-bold.ttf")
EM = 100.0  # brand units per em
TRACKING = -5.0  # brand tracking, in units per em
ASCENT = 100.0  # path y origin: top of the em box sits at y=0, baseline at y=ASCENT

_font = TTFont(FONT)
_glyphs = _font.getGlyphSet()
_cmap = _font.getBestCmap()
_hmtx = _font["hmtx"]
_glyf = _font["glyf"]
_scale = EM / _font["head"].unitsPerEm


def _path(glyph_name, dx_units=0.0, dy_units=0.0):
    pen = SVGPathPen(_glyphs)
    # Font units, y up -> brand units, y down, baseline at ASCENT.
    t = TransformPen(pen, (_scale, 0, 0, -_scale, dx_units * _scale, ASCENT - dy_units * _scale))
    _glyphs[glyph_name].draw(t)
    return pen.getCommands()


def glyph_parts(ch):
    """[(part_name, d)] for one character: the base, then any marks."""
    name = _cmap[ord(ch)]
    g = _glyf[name]
    if g.isComposite():
        parts = []
        for i, comp in enumerate(g.components):
            role = "base" if i == 0 else ("tilde" if "tilde" in comp.glyphName else "cedilla" if "cedilla" in comp.glyphName else comp.glyphName)
            parts.append((role, _path(comp.glyphName, comp.x, comp.y)))
        return parts
    return [("base", _path(name))]


def advance(ch):
    return _hmtx[_cmap[ord(ch)]][0] * _scale


def layout(text):
    """Glyphs with x positions in brand units (tracking applied between letters)."""
    x = 0.0
    out = []
    for i, ch in enumerate(text):
        if ch == " ":
            x += advance(" ") + TRACKING
            continue
        out.append({"ch": ch, "x": x, "adv": advance(ch), "parts": glyph_parts(ch)})
        x += advance(ch) + TRACKING
    width = x - TRACKING if text else 0.0
    return out, width


def word_html(text, px, prefix, color="#171716", cls="", extra_style=""):
    """A word as absolutely positioned per-glyph <svg>s inside one relative box.

    The box is width x (1.0em) tall, its top at the em top, baseline at
    0.80 em... in brand units the baseline is at ASCENT=100 of a 125-unit box.
    Returns (html, width_px, glyph_ids).
    """
    glyphs, width = layout(text)
    k = px / EM
    box_h = 125.0  # 100 above baseline, 25 below for descenders and cedilla
    parts_html = []
    ids = []
    for i, g in enumerate(glyphs):
        gid = f"{prefix}-g{i}"
        ids.append(gid)
        inner = []
        for role, d in g["parts"]:
            pid = f"{gid}-{role}"
            inner.append(
                f'<svg id="{pid}" class="{prefix}-part {prefix}-{role}" viewBox="0 0 {g["adv"]:.2f} {box_h}" '
                f'style="position:absolute;left:0;top:0;width:{g["adv"] * k:.2f}px;height:{box_h * k:.2f}px;overflow:visible" '
                f'aria-hidden="true"><path d="{d}" fill="{color}"/></svg>'
            )
        parts_html.append(
            f'<div id="{gid}" class="{prefix}-glyph" data-ch="{html.escape(g["ch"])}" '
            f'style="position:absolute;left:{g["x"] * k:.2f}px;top:0;width:{g["adv"] * k:.2f}px;height:{box_h * k:.2f}px">'
            + "".join(inner)
            + "</div>"
        )
    w = width * k
    h = box_h * k
    return (
        f'<div class="{prefix} {cls}" aria-label="{html.escape(text)}" '
        f'style="position:relative;width:{w:.2f}px;height:{h:.2f}px;{extra_style}">' + "".join(parts_html) + "</div>",
        w,
        ids,
    )


if __name__ == "__main__":
    g, w = layout("facturac")
    print("facturac width", round(w, 2))
    g2, w2 = layout("facturacao")
    for x in g2:
        print(x["ch"], round(x["x"], 2), round(x["adv"], 2), [p for p, _ in x["parts"]])
    print("ç parts", [p for p, _ in glyph_parts("ç")], "ã parts", [p for p, _ in glyph_parts("ã")])
