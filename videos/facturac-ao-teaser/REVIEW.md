# Review: "O ponto"

Checked from the rendered MP4, not only snapshots: 1920 x 1080, 60 fps, 30.0 s, 10.2 MB. `hyperframes check` passes.

**Sound:** -14.7 LUFS, peak -1.7 dBFS, measured. Not heard: the mix still needs a person's ears.

**Fixed after review**
- The logo path was parsed wrongly, so the end card had no wordmark.
- The first frame was nearly empty: the dot now starts large and falls.
- The gold burst opened away from its button, because the camera moves the button to the centre. It now opens from the centre.
- "Cada documento, comunicado à AGT." left too soon, and the certificate line was too long to read.

**Known and accepted**
- `check` warns that "Notas de crédito" holds under its reading floor: one word group a beat is the montage's point.
- It also warns about the phones' status bars being under the type floor: they are part of the device, not copy.
- In the till shot, the sale list has scrolled, so the first line's name sits just out of view: that is the real screen with four lines.

**Rebuilding:** `python3 tools/build.py`, then `npx hyperframes render --fps 60 -q delivery -o renders/o-ponto-1080p60.mp4`. Screens in `assets/screens` are the real app with a local demo company.
