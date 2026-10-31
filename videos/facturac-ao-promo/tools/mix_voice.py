#!/usr/bin/env python3
"""
Places an ElevenLabs read on the picture and ducks the music under it.

    python3 tools/mix_voice.py a        # uses assets/vo/read-a.mp3

The voice is ONE continuous take, cut only at its own pauses (found with
silencedetect) and re-spaced so each phrase lands on the picture's cue. The
music follows an envelope drawn from those same phrases: full level where nobody
speaks, down about 9 dB while someone does, with soft ramps.
"""
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# where each of the ten phrases lands on the film (s), and what it says
CUES = [
    (0.40, "Tire o til."),
    (0.95, "Tire a cedilha."),
    (1.55, "Ponha um ponto."),
    (4.45, "A partir de dia um de Janeiro, é assim que se fatura em Angola."),
    (8.20, "E quem lhe deve dinheiro?"),
    (10.05, "O lembrete já vem escrito."),
    (11.95, "Leia, mude o que quiser, e carregue em enviar."),
    (17.95, "Tudo isto num software certificado pela AGT."),
    (21.60, "Chegou a altura de mudar."),
    (25.40, "Abra a sua conta grátis, em faturac ponto à ó."),
]
BASE, DUCKED = 0.62, 0.20


def run(*cmd, **kw):
    return subprocess.run(cmd, check=True, **kw)


def segments(path, min_pause=0.30):
    out = subprocess.run(["ffmpeg", "-hide_banner", "-i", str(path), "-af", "silencedetect=noise=-38dB:d=0.28", "-f", "null", "-"], capture_output=True, text=True).stderr
    dur = float(subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(path)], capture_output=True, text=True).stdout)
    starts = [float(x) for x in re.findall(r"silence_start: ([\d.]+)", out)]
    ends = [float(x) for x in re.findall(r"silence_end: ([\d.]+)", out)]
    pauses = [(s, e) for s, e in zip(starts, ends) if e - s >= min_pause]
    cuts, t = [], 0.0
    for s, e in pauses:
        cuts.append((t, s))
        t = e
    cuts.append((t, dur))
    return cuts


def main(take):
    read = ROOT / f"assets/vo/read-{take}.mp3"
    segs = segments(read)
    if len(segs) != len(CUES):
        sys.exit(f"take {take}: found {len(segs)} phrases, expected {len(CUES)}: {segs}")
    parts, labels, windows = [], [], []
    for i, ((a, b), (at, _)) in enumerate(zip(segs, CUES)):
        a, b = max(0.0, a - 0.02), b + 0.04
        parts.append(f"[0:a]atrim={a:.3f}:{b:.3f},asetpts=PTS-STARTPTS,afade=t=in:d=0.01,afade=t=out:st={b-a-0.01:.3f}:d=0.01,adelay={int(at*1000)}|{int(at*1000)}[v{i}]")
        labels.append(f"[v{i}]")
        windows.append((at - 0.1, at + (b - a) + 0.15))
    graph = ";".join(parts) + ";" + "".join(labels) + f"amix=inputs={len(CUES)}:normalize=0,apad=whole_dur=30,atrim=0:30[out]"
    run("ffmpeg", "-v", "error", "-y", "-i", str(read), "-filter_complex", graph, "-map", "[out]", "-ar", "48000", "-ac", "2", str(ROOT / f"assets/vo/vo-{take}.wav"))
    merged = []
    for w in windows:
        if merged and w[0] - merged[-1][1] < 1.0:
            merged[-1] = (merged[-1][0], w[1])
        else:
            merged.append(w)
    terms = [f"clip(min((t-{a:.3f})/0.18,({b:.3f}-t)/0.30),0,1)" for a, b in merged]
    env = terms[0]
    for t in terms[1:]:
        env = f"max({env},{t})"
    expr = f"{BASE}*(1-{1-DUCKED/BASE:.4f}*({env}))"
    run("ffmpeg", "-v", "error", "-y", "-i", str(ROOT / "assets/bgm/track.mp3"), "-af", f"volume='{expr}':eval=frame", "-t", "30", "-ar", "48000", str(ROOT / f"assets/bgm/track-ducked-{take}.wav"))
    print(f"take {take}: phrases", [(round(a, 2), round(b, 2)) for a, b in segs], "duck windows", [(round(a, 1), round(b, 1)) for a, b in merged])


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "a")
