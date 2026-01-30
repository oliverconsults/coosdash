const fs = require('fs');
const { PDFDocument, PDFName, PDFDict, PDFArray } = require('pdf-lib');

function asDict(pdf, o) {
  if (!o) return null;
  try {
    return (o instanceof PDFDict) ? o : pdf.context.lookup(o, PDFDict);
  } catch (_) {
    return null;
  }
}

function fontEmbeddedFromFontDescriptor(pdf, fdRefOrDict) {
  const fdd = asDict(pdf, fdRefOrDict);
  if (!fdd) return { embedded: null, fontFileKey: null };

  for (const k of ['FontFile', 'FontFile2', 'FontFile3']) {
    const key = PDFName.of(k);
    if (fdd.has(key)) return { embedded: true, fontFileKey: k };
  }
  return { embedded: false, fontFileKey: null };
}

function fontEmbeddedBestEffort(pdf, fontDict) {
  if (!fontDict) return { embedded: null, fontFileKey: null, hasFontDescriptor: false };

  // Normal case: FontDescriptor on the font dict itself
  const fd = fontDict.get(PDFName.of('FontDescriptor'));
  if (fd) {
    const r = fontEmbeddedFromFontDescriptor(pdf, fd);
    return { ...r, hasFontDescriptor: true };
  }

  // Type0 composite fonts: FontDescriptor lives on the descendant CIDFont
  const subtype = fontDict.get(PDFName.of('Subtype'));
  if (subtype && subtype.toString() === '/Type0') {
    const desc = fontDict.get(PDFName.of('DescendantFonts'));
    let arr = null;
    try { arr = desc ? pdf.context.lookup(desc, PDFArray) : null; } catch (_) { arr = null; }

    if (arr) {
      for (let i = 0; i < arr.size(); i++) {
        const child = asDict(pdf, arr.get(i));
        if (!child) continue;
        const childFd = child.get(PDFName.of('FontDescriptor'));
        if (!childFd) continue;
        const r = fontEmbeddedFromFontDescriptor(pdf, childFd);
        if (r.embedded !== null) {
          return { ...r, hasFontDescriptor: true };
        }
      }
    }

    // If we got here: subtype is Type0, but we couldn't resolve the descendant descriptor.
    return { embedded: null, fontFileKey: null, hasFontDescriptor: false };
  }

  // For Type1 standard fonts there is no FontDescriptor and that's fine.
  return { embedded: null, fontFileKey: null, hasFontDescriptor: false };
}

async function main(){
  const p = process.argv[2];
  if(!p){
    console.error('Usage: node check_fonts.js <pdf>');
    process.exit(1);
  }
  const bytes = fs.readFileSync(p);
  const pdf = await PDFDocument.load(bytes, { updateMetadata:false });

  const allFonts = [];

  // Iterate all indirect objects and collect Font dictionaries.
  for (const [ref, obj] of pdf.context.enumerateIndirectObjects()) {
    if (!(obj instanceof PDFDict)) continue;
    const type = obj.get(PDFName.of('Type'));
    const subtype = obj.get(PDFName.of('Subtype'));

    // Font dictionaries have /Type /Font
    if (type && type.toString() === '/Font') {
      const baseFont = obj.get(PDFName.of('BaseFont'));
      const emb = fontEmbeddedBestEffort(pdf, obj);

      allFonts.push({
        ref: ref.toString(),
        subtype: subtype ? subtype.toString() : null,
        baseFont: baseFont ? baseFont.toString() : null,
        hasFontDescriptor: emb.hasFontDescriptor,
        embedded: emb.embedded,
        fontFileKey: emb.fontFileKey,
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
