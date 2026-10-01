#!/usr/bin/env python3
"""Reapplies the audio mix to index.html.

`assemble-index.mjs` rebuilds index.html from STORYBOARD.md, which resets every
audio element to the storyboard's defaults. Those defaults were written for a
film with no narration: the bed sits at 0.5 and the cues sit under it. Once a
voice is in the film that balance is wrong — the bed ends up only ~4 LU below
the narration and fights it for the same space.

The levels below are derived from each source's measured loudness so that,
against narration normalised to -14.6 LUFS, the bed rides ~14 LU under it and
every cue punctuates without competing. The stamp is the one hit allowed up
near the voice, because the seal press is the moment the film is built around.

Run this after any `assemble-index.mjs`, then re-render.
"""

import re
import sys
from pathlib import Path

VOLUMES = {
    "el-bgm": "0.16",
    "el-sfx-0": "0.21",
    "el-sfx-1": "0.21",
    "el-sfx-2": "0.25",
    "el-sfx-3": "0.21",
    "el-sfx-4": "1",
    "el-sfx-5": "0.62",
    "el-sfx-6": "0.33",
}

# Slot lengths. The two tick assets are 7.8s runs of hits spaced ~0.8s apart —
# far too slow to follow a 0.2s stagger — so each is cut to its first hit and
# used as a single accent. The rest are one-shots declared at their real length.
DURATIONS = {
    "el-sfx-0": "0.5",
    "el-sfx-1": "0.5",
    "el-sfx-2": "1.285",
    "el-sfx-3": "0.5",
    "el-sfx-4": "0.79",
    "el-sfx-5": "0.72",
    "el-sfx-6": "1.44",
}

# Frame starts: 0 · 5.328 · 8.988 · 17.230 · 23.795 · ends 30.000. Every cue is
# placed against the picture it punctuates, not against the storyboard's
# original guesses — the frames were rebuilt and every beat moved.
STARTS = {
    "el-sfx-0": "2.89",    # the pile shifts under "E mudar?"
    "el-sfx-2": "3.95",    # swell into MEDO
    "el-sfx-1": "8.148",   # CONSIGO lands
    "el-sfx-3": "11.838",  # the series starts counting
    "el-sfx-4": "21.780",  # the seal presses
    "el-sfx-5": "22.930",  # "Comunicada à AGT"
    "el-sfx-6": "28.200",  # resolves through the final held beat
}


def main() -> int:
    path = Path(__file__).parent / "index.html"
    html = path.read_text()

    for clip_id, volume in VOLUMES.items():
        match = re.search(r'id="%s".*?</audio>' % clip_id, html, re.S)
        if match is None:
            print(f"  ! {clip_id} not found in index.html", file=sys.stderr)
            return 1
        block = match.group(0)
        updated = re.sub(r'data-volume="[^"]*"', f'data-volume="{volume}"', block)
        if clip_id in DURATIONS:
            updated = re.sub(
                r'data-duration="[^"]*"', f'data-duration="{DURATIONS[clip_id]}"', updated
            )
        if clip_id in STARTS:
            updated = re.sub(r'data-start="[^"]*"', f'data-start="{STARTS[clip_id]}"', updated)
        html = html.replace(block, updated)

    path.write_text(html)
    print(f"  mix applied to {path.name}: {len(VOLUMES)} cues")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
