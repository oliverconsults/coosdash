const fs = require('fs');
const { PDFDocument, PDFName, PDFDict } = require('pdf-lib');

async function main(){
  const p = process.argv[2];
  if(!p){
    console.error('Usage: node check_fonts.js <pdf>');
    process.exit(1);
  }
  const bytes = fs.readFileSync(p);
  const pdf = await PDFDocument.load(bytes, { updateMetadata:false });

  const allFonts = [];

  // Iterate all indirect objects and collect FontDescriptor dictionaries.
  for (const [ref, obj] of pdf.context.enumerateIndirectObjects()) {
    if (!(obj instanceof PDFDict)) continue;
    const type = obj.get(PDFName.of('Type'));
    const subtype = obj.get(PDFName.of('Subtype'));

    // Font dictionaries have /Type /Font
    if (type && type.toString() === '/Font') {
      const baseFont = obj.get(PDFName.of('BaseFont'));
      const fd = obj.get(PDFName.of('FontDescriptor'));
      let embedded = null;
      let fontFileKey = null;
      if (fd) {
        const fdd = pdf.context.lookup(fd, PDFDict);
        for (const k of ['FontFile', 'FontFile2', 'FontFile3']) {
          const key = PDFName.of(k);
          if (fdd.has(key)) { embedded = true; fontFileKey = k; break; }
        }
        if (embedded === null) embedded = false;
      }
      allFonts.push({
        ref: ref.toString(),
        subtype: subtype ? subtype.toString() : null,
        baseFont: baseFont ? baseFont.toString() : null,
        hasFontDescriptor: !!fd,
        embedded,
        fontFileKey,
      });
    }
  }

  // Page-level usage: what fonts are referenced in each page's /Resources /Font dict?
  const pageFonts = [];
  const pages = pdf.getPages();
  for (let i=0;i<pages.length;i++) {
    const page = pages[i];
    const res = page.node.get(PDFName.of('Resources'));
    const resDict = res ? pdf.context.lookup(res, PDFDict) : null;
    const fontObj = resDict ? resDict.get(PDFName.of('Font')) : null;
    const fontDict = fontObj ? pdf.context.lookup(fontObj, PDFDict) : null;
    const fonts = [];
    if (fontDict) {
      for (const k of fontDict.keys()) {
        const v = fontDict.get(k);
        const fd = v ? pdf.context.lookup(v, PDFDict) : null;
        if (!fd) continue;
        const subtype = fd.get(PDFName.of('Subtype'));
        const baseFont = fd.get(PDFName.of('BaseFont'));
        fonts.push({
          name: k.toString(),
          ref: v.toString(),
          subtype: subtype ? subtype.toString() : null,
          baseFont: baseFont ? baseFont.toString() : null,
        });
      }
    }
    fonts.sort((a,b)=>(a.name||'').localeCompare(b.name||''));
    pageFonts.push({ page: i+1, fonts });
  }

  // Deduplicate global font list by baseFont+embedded+subtype
  const key = (r) => [r.baseFont,r.subtype,r.embedded,r.fontFileKey].join('|');
  const seen = new Set();
  const uniq = [];
  for (const r of allFonts) { const k = key(r); if (seen.has(k)) continue; seen.add(k); uniq.push(r); }

  uniq.sort((a,b)=> (a.baseFont||'').localeCompare(b.baseFont||''));

  const helveticaOnPages = pageFonts.flatMap(pf => pf.fonts
    .filter(f => (f.baseFont||'').startsWith('/Helvetica'))
    .map(f => ({ page: pf.page, ...f }))
  );

  console.log(JSON.stringify({
    file: p,
    fonts: uniq,
    pageFonts,
    helveticaOnPages,
  }, null, 2));
}
main().catch(e=>{ console.error(e); process.exit(2);});
