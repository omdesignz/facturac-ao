#!/usr/bin/env python3
"""Lays a film's ElevenLabs read over its render and exports it. Run from the project root.

    python3 tools/mix_voice.py o-ponto ponto-a

The read is one continuous take and stays whole: build_films.py cuts the picture to it,
so nothing here moves a word. The bed (the render's music and effects) is brought to
BED_LUFS and ridden down under the voice on a drawn envelope that bridges short gaps,
so the music moves in phrases instead of pumping per word.

Reads   films/<film>/renders/raw.mp4 and assets/vo/<take>.mp3
Writes  renders/<film>-master.mp4 and renders/social/<film>-lancamento-{instagram,whatsapp}.mp4
"""

import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TOOLS = ROOT.parent / "tools"
VOICE_AT = 0.35  # build_films.VOICE_AT
VOICE_LUFS, BED_LUFS = -16.0, -22.0
DUCK_DB, DUCK_ATTACK, DUCK_RELEASE, DUCK_BRIDGE = -5.0, 0.22, 0.6, 1.2


def stderr(args):
    return subprocess.run(args, capture_output=True, text=True, check=True).stderr


def loudness(path):
    tail = stderr(["ffmpeg", "-hide_banner", "-nostats", "-i", str(path), "-af", "ebur128", "-f", "null", "-"]).split("Summary:")[-1]
    return float(re.search(r"I:\s+(-?[\d.]+) LUFS", tail).group(1))


def spoken(path):
    """The read's spoken passages, joined across gaps too short to bring the music back."""
    out = stderr(["ffmpeg", "-hide_banner", "-nostats", "-i", str(path), "-af", "silencedetect=noise=-40dB:d=0.12", "-f", "null", "-"])
    starts = [float(x) for x in re.findall(r"silence_start: (-?[\d.]+)", out)]
    ends = [float(x) for x in re.findall(r"silence_end: ([\d.]+)", out)]
    spans, cursor = [], 0.0
    for start, end in zip(starts, ends):
        if start > cursor + 0.05:
            if spans and cursor - spans[-1][1] < DUCK_BRIDGE:
                spans[-1] = (spans[-1][0], start)
            else:
                spans.append((cursor, start))
        cursor = end
    return [(a + VOICE_AT, b + VOICE_AT) for a, b in spans]


def envelope(spans):
    depth = 1 - 10 ** (DUCK_DB / 20)
    terms = [f"clip((t-{a - DUCK_ATTACK:.3f})/{DUCK_ATTACK},0,1)*clip(({b + DUCK_RELEASE:.3f}-t)/{DUCK_RELEASE},0,1)" for a, b in spans]
    peak = terms[0]
    for term in terms[1:]:
        peak = f"max({peak},{term})"
    return f"1-{depth:.4f}*{peak}"


def main(film, take):
    raw = ROOT / "films" / film / "renders" / "raw.mp4"
    read = ROOT / "assets" / "vo" / f"{take}.mp3"
    mixed = ROOT / "renders" / f"{film}-mixed.mp4"
    master = ROOT / "renders" / f"{film}-master.mp4"
    voice_gain = VOICE_LUFS - loudness(read)
    bed_gain = BED_LUFS - loudness(raw)
    delay = int(VOICE_AT * 1000)
    graph = (
        f"[1:a]aresample=48000,highpass=f=80,acompressor=threshold=-27dB:ratio=3.5:attack=6:release=140:makeup=3,"
        f"volume={voice_gain:.2f}dB,adelay={delay}:all=1[voice];"
        f"[0:a]aresample=48000,volume={bed_gain:.2f}dB,asetnsamples=n=256,volume='{envelope(spoken(read))}':eval=frame[bed];"
        "[bed][voice]amix=inputs=2:normalize=0:duration=first[a]"
    )
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", str(raw), "-i", str(read), "-filter_complex", graph,
                    "-map", "0:v", "-map", "[a]", "-c:v", "copy", "-c:a", "pcm_s24le", str(mixed.with_suffix(".mov"))], check=True)
    subprocess.run([str(TOOLS / "master_audio.sh"), str(mixed.with_suffix(".mov")), str(master)], check=True)
    mixed.with_suffix(".mov").unlink()
    subprocess.run([str(TOOLS / "export_social.sh"), str(master), str(ROOT / "renders" / "social"), f"{film}-lancamento"], check=True)


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
