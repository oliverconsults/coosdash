# PDF Font Check (COOS)

Purpose: quick inspection helper to see what fonts are present/embedded in a PDF.

## Install

```bash
cd /home/deploy/projects/coos/scripts/pdf_font_check
npm ci
```

## Run

```bash
node check_fonts.js /path/to/file.pdf
```

Output: JSON to stdout with
- `fonts`: de-duplicated list of font dicts + embedded flag
- `pageFonts`: which fonts each page references
- `helveticaOnPages`: convenience list
