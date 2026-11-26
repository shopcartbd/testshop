# Bundled fonts

## Noto Sans Bengali (local static files)

- Source: Google Fonts / Noto Project, `https://fonts.google.com/noto/specimen/Noto+Sans+Bengali`
- Files: `assets/fonts/noto-sans-bengali-400.ttf`, `-600.ttf`, `-700.ttf`
- Build: static TTF files with the complete glyph set and OpenType GSUB/GPOS shaping tables; hinting data was stripped to reduce size. All Bengali conjuncts, signs, digits and the Taka sign remain available.
- License: SIL Open Font License 1.1. Full text: `assets/fonts/OFL.txt`.
- Purpose: render Bangladesh division/district/thana names and Bengali delivery-zone labels on devices without a local Bengali font. No external font request is made at runtime.
