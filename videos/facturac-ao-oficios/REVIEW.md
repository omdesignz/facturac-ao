# Review: "Por ofício"

Four vertical cuts, 1080 x 1920, 30 fps, 11.0 s each: `oficina`, `farmacia`, `restaurante`, `loja`. Each opens on the trade's name, asks its own question, taps that trade's items into the real till and rises to the real charge screen. Checked from the rendered files; `hyperframes check` passes.

Files are in `renders/`: `<trade>-whatsapp.mp4` (720 x 1280, 4 MB) and `<trade>-instagram.mp4` (1080 x 1920, 4 MB).

**Sound:** -14.7 LUFS, true peak -2.2 dB, no sample over full scale. Not heard: it needs a person's ears.

**Real and illustrated:** the till and charge screens are the real app, with local demo companies for each trade. The film stops at the charge screen; no sale was issued, so no fiscal documents exist for these captures.

**To confirm:** the farmácia's demo prices carry 14% IVA, as every demo item does. A pharmacist may notice if their medicines are taxed differently.

**Known and accepted:** `check` warns about the phone's status bar under the type floor (part of the device). Century Gothic Bold must be installed to re-render; see the licence note in `../facturac-ao-teaser/REVIEW.md`.

**Rebuilding, for each trade:** `python3 tools/build.py oficina`, `npx hyperframes render --fps 30 -q delivery --crf 12 -o renders/oficina-raw.mp4`, `bash ../tools/master_audio.sh renders/oficina-raw.mp4 renders/oficina-master.mp4`, then `bash ../tools/export_social.sh renders/oficina-master.mp4 renders oficina`. `index.html` holds whichever trade was built last.
