const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const fontkit = require('@pdf-lib/fontkit');
const {
  PDFDocument,
  AFRelationship,
  PDFName,
  PDFArray,
  PDFDict,
  PDFString,
  PDFHexString,
  PDFNumber,
} = require('pdf-lib');

function ensureTrailerId(pdfDoc, { rotateSecond = true } = {}) {
  try {
    const ctx = pdfDoc.context;

    const existing = ctx.trailerInfo?.ID;
    let firstHex = null;

    // existing can be a PDFArray, or an indirect ref, depending on parser.
    try {
      const arr = (existing instanceof PDFArray)
        ? existing
        : (existing ? ctx.lookup(existing, PDFArray) : null);
      if (arr) {
        const a = arr.asArray();
        const first = a[0];
        // Accept <...> hex or (...) literal.
        const s = first?.toString?.() || '';
        const m = s.match(/^[<(]([0-9A-Fa-f]+)[>)]$/);
        if (m) firstHex = m[1].toLowerCase();
      }
    } catch (_) {
      // ignore
    }

    if (!firstHex) firstHex = crypto.randomBytes(16).toString('hex');
    const secondHex = rotateSecond ? crypto.randomBytes(16).toString('hex') : firstHex;

    const idArr = PDFArray.withContext(ctx);
    idArr.push(PDFHexString.of(firstHex));
    idArr.push(PDFHexString.of(secondHex));
    ctx.trailerInfo.ID = idArr;
  } catch (_) {
    // best-effort
  }
}

async function main() {
  const inPdf = process.argv[2];
  const xmlPath = process.argv[3];
  const outPdf = process.argv[4];
  if (!inPdf || !xmlPath || !outPdf) {
    console.error('Usage: node embed.js <in.pdf> <file.xml> <out.pdf>');
    process.exit(1);
  }

  const pdfBytes = fs.readFileSync(inPdf);
  const xmlBytes = fs.readFileSync(xmlPath);

  const pdfDoc = await PDFDocument.load(pdfBytes, { updateMetadata: false });

  // Trailer /ID: some validators/tools expect it to be present.
  // Strategy:
  // - keep existing first entry if present
  // - always rotate second entry for a modified output
  ensureTrailerId(pdfDoc, { rotateSecond: true });

  // Enable TTF/OTF font embedding via fontkit.
  pdfDoc.registerFontkit(fontkit);

  // Optional (off by default): "safe" remap of standard Type1 Helvetica fonts to an embedded
  // TrueType font with WinAnsiEncoding.
  //
  // Motivation (veraPDF / PDF/A-3):
  // - Standard 14 fonts (Helvetica etc.) are NOT embedded. PDF/A typically requires embedding.
  // - pdf-lib's embedFont() for TTF creates a Type0/CID font (Identity-H). If you only swap the
  //   Font resource ref, existing single-byte strings (WinAnsi) no longer map 1:1 -> veraPDF fails.
  //
  // "Safe" strategy:
  // - create our own Simple TrueType font dictionary (/Subtype /TrueType)
  // - /Encoding /WinAnsiEncoding
  // - /FirstChar.. /LastChar + /Widths computed from the embedded TTF program
  // - optional /ToUnicode CMap for common bytes
  // - only replace Helvetica Type1 resources that (a) are Type1 Helvetica and (b) use WinAnsi
  //   (or have no explicit encoding)
  const mapHelvetica = process.env.COOS_PDF_MAP_HELVETICA === '1';

  const WIN_ANSI_0x80_0x9F = {
    0x80: 0x20AC,
    0x82: 0x201A,
    0x83: 0x0192,
    0x84: 0x201E,
    0x85: 0x2026,
    0x86: 0x2020,
    0x87: 0x2021,
    0x88: 0x02C6,
    0x89: 0x2030,
    0x8A: 0x0160,
    0x8B: 0x2039,
    0x8C: 0x0152,
    0x8E: 0x017D,
    0x91: 0x2018,
    0x92: 0x2019,
    0x93: 0x201C,
    0x94: 0x201D,
    0x95: 0x2022,
    0x96: 0x2013,
    0x97: 0x2014,
    0x98: 0x02DC,
    0x99: 0x2122,
    0x9A: 0x0161,
    0x9B: 0x203A,
    0x9C: 0x0153,
    0x9E: 0x017E,
    0x9F: 0x0178,
  };

  const winAnsiCodeToUnicode = (code) => {
    if (code >= 0x20 && code <= 0x7E) return code;
    if (code >= 0xA0 && code <= 0xFF) return code;
    if (WIN_ANSI_0x80_0x9F[code]) return WIN_ANSI_0x80_0x9F[code];
    return null;
  };

  const asDict = (o) => {
    if (!o) return null;
    try {
      return (o instanceof PDFDict) ? o : pdfDoc.context.lookup(o, PDFDict);
    } catch (_) {
      return null;
    }
  };

  const createToUnicodeCMap = (mappings) => {
    // mappings: [{ srcCode: <byte>, unicode: <codepoint> }]
    const hex4 = (n) => n.toString(16).padStart(4, '0').toUpperCase();
    const hex2 = (n) => n.toString(16).padStart(2, '0').toUpperCase();

    const lines = [];
    lines.push('/CIDInit /ProcSet findresource begin');
    lines.push('12 dict begin');
    lines.push('begincmap');
    lines.push('/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def');
    lines.push('/CMapName /Adobe-Identity-UCS def');
    lines.push('/CMapType 2 def');
    lines.push('1 begincodespacerange');
    lines.push('<00><FF>');
    lines.push('endcodespacerange');

    // PDF expects chunks.
    const CHUNK = 100;
    for (let i = 0; i < mappings.length; i += CHUNK) {
      const chunk = mappings.slice(i, i + CHUNK);
      lines.push(`${chunk.length} beginbfchar`);
      for (const m of chunk) {
        lines.push(`<${hex2(m.srcCode)}><${hex4(m.unicode)}>`);
      }
      lines.push('endbfchar');
    }

    lines.push('endcmap');
    lines.push('CMapName currentdict /CMap defineresource pop');
    lines.push('end');
    lines.push('end');
    lines.push('%%EOF');

    return Buffer.from(lines.join('\n'), 'ascii');
  };

  const embedWinAnsiTrueTypeFont = (ttfBytes, { fontName = 'CoosHelveticaRemap' } = {}) => {
    const ttFont = fontkit.create(ttfBytes);
    const scale = 1000 / (ttFont.unitsPerEm || 1000);

    const firstChar = 32;
    const lastChar = 255;

    const widths = PDFArray.withContext(pdfDoc.context);
    const toUnicode = [];

    for (let code = firstChar; code <= lastChar; code++) {
      const uni = winAnsiCodeToUnicode(code);
      let w = 0;
      if (uni != null) {
        try {
          const g = ttFont.glyphForCodePoint(uni);
          // glyphForCodePoint always returns a glyph object; missing glyph can still have 0 width.
          w = Math.round((g.advanceWidth || 0) * scale);
          toUnicode.push({ srcCode: code, unicode: uni });
        } catch (_) {
          w = 0;
        }
      }
      widths.push(PDFNumber.of(w));
    }

    const bbox = ttFont.bbox || { minX: 0, minY: 0, maxX: 0, maxY: 0 };
    const fontBBox = PDFArray.withContext(pdfDoc.context);
    fontBBox.push(PDFNumber.of(Math.round((bbox.minX || 0) * scale)));
    fontBBox.push(PDFNumber.of(Math.round((bbox.minY || 0) * scale)));
    fontBBox.push(PDFNumber.of(Math.round((bbox.maxX || 0) * scale)));
    fontBBox.push(PDFNumber.of(Math.round((bbox.maxY || 0) * scale)));

    const fontFile2 = pdfDoc.context.stream(ttfBytes);
    const fontFile2Ref = pdfDoc.context.register(fontFile2);

    const italicAngle = Math.round(ttFont.italicAngle || 0);
    const ascent = Math.round((ttFont.ascent || 0) * scale);
    const descent = Math.round((ttFont.descent || 0) * scale);
    const capHeight = Math.round(((ttFont.capHeight ?? ttFont.ascent) || 0) * scale);

    // Flags: 32 = Nonsymbolic, 64 = Italic
    let flags = 32;
    if (italicAngle !== 0) flags |= 64;

    // Very rough StemV value (PDF/A validators are typically lenient here).
    const stemV = PDFNumber.of(80);

    const fontDescriptor = pdfDoc.context.obj({
      Type: 'FontDescriptor',
      FontName: PDFName.of(fontName),
      Flags: PDFNumber.of(flags),
      FontBBox: fontBBox,
      ItalicAngle: PDFNumber.of(italicAngle),
      Ascent: PDFNumber.of(ascent),
      Descent: PDFNumber.of(descent),
      CapHeight: PDFNumber.of(capHeight),
      StemV: stemV,
      FontFile2: fontFile2Ref,
    });
    const fontDescriptorRef = pdfDoc.context.register(fontDescriptor);

    const fontDict = pdfDoc.context.obj({
      Type: 'Font',
      Subtype: 'TrueType',
      BaseFont: PDFName.of(fontName),
      Encoding: PDFName.of('WinAnsiEncoding'),
      FirstChar: PDFNumber.of(firstChar),
      LastChar: PDFNumber.of(lastChar),
      Widths: widths,
      FontDescriptor: fontDescriptorRef,
    });

    // Optional ToUnicode
    try {
      const cmapBytes = createToUnicodeCMap(toUnicode);
      const cmapStream = pdfDoc.context.stream(cmapBytes);
      const cmapRef = pdfDoc.context.register(cmapStream);
      fontDict.set(PDFName.of('ToUnicode'), cmapRef);
    } catch (_) {
      // best-effort
    }

    return pdfDoc.context.register(fontDict);
  };

  if (mapHelvetica) try {
    const ttfPath = path.join(__dirname, 'assets', 'NotoSans-Regular.ttf');
    if (!fs.existsSync(ttfPath)) {
      console.warn('WARN: missing TTF asset (skip Helvetica remap):', ttfPath);
    } else {
      const ttfBytes = fs.readFileSync(ttfPath);
      const safeTtfRef = embedWinAnsiTrueTypeFont(ttfBytes, { fontName: 'CoosHelveticaRemap' });

      // Map strategy: on each page, replace any Font resource whose BaseFont is Helvetica
      // (Type1 standard font) with our WinAnsi TrueType font.
      const helvNames = new Set([
        'Helvetica',
        'Helvetica-Bold',
        'Helvetica-Oblique',
        'Helvetica-BoldOblique',
      ]);

      let skippedNonWinAnsi = 0;

      for (const page of pdfDoc.getPages()) {
        const resObj = page.node.get(PDFName.of('Resources'));
        const resDict = asDict(resObj);
        if (!resDict) continue;

        const fontObj = resDict.get(PDFName.of('Font'));
        const fontDict = asDict(fontObj);
        if (!fontDict) continue;

        for (const k of fontDict.keys()) {
          const fr = fontDict.get(k);
          const fd = asDict(fr);
          if (!fd) continue;

          const subtype = fd.get(PDFName.of('Subtype'));
          const baseFont = fd.get(PDFName.of('BaseFont'));
          const baseFontName = baseFont ? baseFont.toString().replace(/^\//, '') : '';

          if (!(subtype && subtype.toString() === '/Type1' && helvNames.has(baseFontName))) continue;

          // Only remap if Encoding is WinAnsi (or absent). If it uses Differences or a different base
          // encoding, swapping would be unsafe (content strings would decode differently).
          const enc = fd.get(PDFName.of('Encoding'));
          let encodingOk = false;
          if (!enc) {
            encodingOk = true;
          } else if (enc.toString && enc.toString() === '/WinAnsiEncoding') {
            encodingOk = true;
          } else {
            const encDict = asDict(enc);
            if (encDict) {
              const baseEnc = encDict.get(PDFName.of('BaseEncoding'));
              const diffs = encDict.get(PDFName.of('Differences'));
              if (baseEnc && baseEnc.toString && baseEnc.toString() === '/WinAnsiEncoding' && !diffs) {
                encodingOk = true;
              }
            }
          }

          if (!encodingOk) {
            skippedNonWinAnsi++;
            continue;
          }

          fontDict.set(k, safeTtfRef);
        }
      }

      if (skippedNonWinAnsi > 0) {
        console.warn(`WARN: Helvetica remap: ${skippedNonWinAnsi} Font-Refs übersprungen (Encoding != WinAnsi oder mit Differences).`);
      }
    }
  } catch (e) {
    console.warn('WARN: failed to embed/remap Helvetica (safe, best-effort):', e?.message || e);
  }

  // --- PDF/A-ish baseline: add OutputIntent with ICC profile (required for PDF/A).
  // NOTE: This does NOT magically make an arbitrary PDF fully PDF/A-3 compliant.
  // It just adds the OutputIntent/ICC building block we need.
  const iccPath = path.join(__dirname, 'assets', 'sRGB.icc');
  if (fs.existsSync(iccPath)) {
    const iccBytes = fs.readFileSync(iccPath);
    const iccStream = pdfDoc.context.stream(iccBytes, {
      N: PDFNumber.of(3), // RGB
    });
    const iccRef = pdfDoc.context.register(iccStream);

    const outIntentDict = pdfDoc.context.obj({
      Type: 'OutputIntent',
      S: 'GTS_PDFA1',
      OutputConditionIdentifier: PDFString.of('sRGB IEC61966-2.1'),
      Info: PDFString.of('sRGB IEC61966-2.1'),
      DestOutputProfile: iccRef,
    });
    const outIntentRef = pdfDoc.context.register(outIntentDict);

    const outIntents = PDFArray.withContext(pdfDoc.context);
    outIntents.push(outIntentRef);
    pdfDoc.catalog.set(PDFName.of('OutputIntents'), outIntents);
  } else {
    console.warn('WARN: missing ICC profile (skip OutputIntent):', iccPath);
  }

  const lang = (process.env.COOS_LANG || 'de').toLowerCase();

  // pdf-lib supports embedded files via attach().
  // For ZUGFeRD/XRechnung, typical filename is zugferd-invoice.xml and mime application/xml.
  const embeddedXmlName = 'zugferd-invoice.xml';
  const embeddedXmlDescription = (lang === 'de')
    ? 'ZUGFeRD/XRechnung XML'
    : 'ZUGFeRD / XRechnung invoice XML';

  await pdfDoc.attach(xmlBytes, embeddedXmlName, {
    mimeType: 'application/xml',
    description: embeddedXmlDescription,
    // For PDF/A-3 + ZUGFeRD/Factur-X the embedded XML is typically an "associated file".
    // Common choice: /AFRelationship /Alternative
    afRelationship: AFRelationship.Alternative,
    creationDate: new Date(),
    modificationDate: new Date(),
  });

  // Some consumers (and validators) only look at the Catalog-level /AF array
  // for "Associated Files". pdf-lib's attach() reliably adds the EmbeddedFiles
  // name tree entry and the Filespec + /AFRelationship, but the Catalog /AF
  // is not always present.
  //
  // Ensure:
  // - Catalog /AF exists
  // - it CONTAINS our newly attached XML Filespec
  // - we do NOT drop pre-existing /AF entries
  // - we dedupe by filename for our XML (embed.js can be run multiple times)
  //
  // Robust approach: scan all indirect objects for Filespec dicts, because
  // the /Names tree shape differs across pdf-lib versions.
  try {
    const isPdfName = (v, s) => v && v.toString && v.toString() === `/${s}`;

    const decodePdfString = (v) => {
      try {
        if (v instanceof PDFString || v instanceof PDFHexString) return v.decodeText?.();
      } catch (_) {}
      return null;
    };

    // Try the /Names -> /EmbeddedFiles name tree first. This is the most direct mapping
    // from filename -> Filespec and avoids relying on /Type or /EF heuristics.
    const findFilespecRefInEmbeddedFilesNameTree = (wantedFilename) => {
      const lookupDict = (doc, o) => {
        if (!o) return null;
        try {
          return (o instanceof PDFDict) ? o : doc.context.lookup(o, PDFDict);
        } catch (_) {
          return null;
        }
      };

      const walkNameTree = (doc, nodeDict) => {
        if (!nodeDict) return null;

        const namesArr = nodeDict.get(PDFName.of('Names'));
        const kidsArr = nodeDict.get(PDFName.of('Kids'));

        // Case A: leaf with /Names [ (k1) v1 (k2) v2 ... ]
        let names = null;
        try {
          names = (namesArr instanceof PDFArray) ? namesArr : (namesArr ? doc.context.lookup(namesArr, PDFArray) : null);
        } catch (_) {
          names = null;
        }

        if (names) {
          const flat = names.asArray();
          for (let i = 0; i + 1 < flat.length; i += 2) {
            const k = flat[i];
            const v = flat[i + 1];
            const keyStr = decodePdfString(k);
            if (keyStr === wantedFilename) return v;
          }
        }

        // Case B: internal node with /Kids
        let kids = null;
        try {
          kids = (kidsArr instanceof PDFArray) ? kidsArr : (kidsArr ? doc.context.lookup(kidsArr, PDFArray) : null);
        } catch (_) {
          kids = null;
        }

        if (kids) {
          for (const kid of kids.asArray()) {
            const kidDict = lookupDict(doc, kid);
            const found = walkNameTree(doc, kidDict);
            if (found) return found;
          }
        }

        return null;
      };

      try {
        const namesRoot = lookupDict(pdfDoc, pdfDoc.catalog.get(PDFName.of('Names')));
        const embeddedFiles = namesRoot ? lookupDict(pdfDoc, namesRoot.get(PDFName.of('EmbeddedFiles'))) : null;
        if (!embeddedFiles) return null;

        const value = walkNameTree(pdfDoc, embeddedFiles);
        // We strongly prefer an indirect ref (stable for /AF). If pdf-lib gives a direct dict,
        // register it so we can reference it.
        if (value && value instanceof PDFDict) return pdfDoc.context.register(value);
        return value || null;
      } catch (_) {
        return null;
      }
    };

    // 1) Find all Filespec refs (filename -> preferred ref).
    // If the same filename appears multiple times, prefer the LAST one we see (usually newest).
    const filenameByRefStr = new Map();
    let xmlFilespecRef = findFilespecRefInEmbeddedFilesNameTree(embeddedXmlName) || null;

    for (const [ref, obj] of pdfDoc.context.enumerateIndirectObjects()) {
      if (!(obj instanceof PDFDict)) continue;

      const type = obj.get(PDFName.of('Type'));
      const ef = obj.get(PDFName.of('EF'));
      // Some PDFs omit /Type /Filespec; the reliable indicator is a Filespec-like dict with /EF.
      if (type && !isPdfName(type, 'Filespec')) continue;
      if (!ef) continue;

      const fStr = decodePdfString(obj.get(PDFName.of('F')));
      const ufStr = decodePdfString(obj.get(PDFName.of('UF')));
      const filename = ufStr || fStr;
      if (!filename) continue;

      filenameByRefStr.set(ref.toString(), filename);

      // Only overwrite if we didn't already find it via the EmbeddedFiles name tree.
      if (!xmlFilespecRef && filename === embeddedXmlName) xmlFilespecRef = ref;
    }

    // 2) Read existing Catalog /AF (if any) and keep all entries except duplicates of our XML.
    const existingAF = pdfDoc.catalog.get(PDFName.of('AF'));
    let existingRefs = [];
    try {
      const arr = (existingAF instanceof PDFArray)
        ? existingAF
        : (existingAF ? pdfDoc.context.lookup(existingAF, PDFArray) : null);
      if (arr) existingRefs = arr.asArray();
    } catch (_) {
      existingRefs = [];
    }

    const outRefs = [];
    const seenRefStr = new Set();

    const pushRef = (r) => {
      if (!r) return;
      const k = r.toString();
      if (seenRefStr.has(k)) return;
      seenRefStr.add(k);
      outRefs.push(r);
    };

    for (const r of existingRefs) {
      // Keep only refs (skip nulls / direct dicts).
      if (!r || !r.toString) continue;

      const filename = filenameByRefStr.get(r.toString()) || null;
      if (filename === embeddedXmlName) continue; // we'll add the preferred XML Filespec once

      pushRef(r);
    }

    if (xmlFilespecRef) {
      pushRef(xmlFilespecRef);
    } else {
      // In some pdf-lib versions the Filespec objects are only materialized on save();
      // we'll fix /AF in a second pass after the first save().
      console.warn('INFO: could not locate embedded Filespec ref for Catalog /AF (will fix in second pass)');
    }

    if (outRefs.length) {
      const afArr = PDFArray.withContext(pdfDoc.context);
      for (const r of outRefs) afArr.push(r);
      pdfDoc.catalog.set(PDFName.of('AF'), afArr);
    }
  } catch (e) {
    console.warn('WARN: failed to set Catalog /AF (best-effort):', e?.message || e);
  }

  // --- XMP metadata (PDF/A-3 + ZUGFeRD/Factur-X-ish)
  // Many validators expect a /Metadata stream (Subtype /XML) with at least pdfaid fields.
  // We keep this minimal and deterministic; it does not guarantee full PDF/A compliance.
  //
  // IMPORTANT (veraPDF 6.7.3-6/7): keep Info-Dict + XMP consistent for Creator/Producer.
  const producer = process.env.COOS_PDF_PRODUCER || 'COOS (pdf-lib prototype)';
  const creatorTool = process.env.COOS_PDF_CREATOR_TOOL || producer;

  // Keep Document Info in sync with XMP.
  try { pdfDoc.setProducer(producer); } catch (_) {}
  try { pdfDoc.setCreator(creatorTool); } catch (_) {}

  const nowIso = new Date().toISOString();

  // Best-effort: infer Factur-X/ZUGFeRD profile from the XML.
  // (This is intentionally heuristic; we mainly want deterministic XMP values.)
  const xmlText = (() => {
    try { return Buffer.from(xmlBytes).toString('utf8'); } catch (_) { return ''; }
  })();

  const inferConformanceLevel = (s) => {
    const t = String(s || '').toLowerCase();

    // IMPORTANT:
    // We currently write XMP under the ZUGFeRD 1p0 namespace
    //   urn:zugferd:pdfa:CrossIndustryDocument:invoice:1p0#
    // and Mustang validates zf:ConformanceLevel against the ZUGFeRD 1.x value set.
    // Valid values there are typically: BASIC | COMFORT | EXTENDED.
    // (Values like EN16931 or BASIC-WL belong to ZUGFeRD 2.x / Factur-X profiles,
    //  but are NOT valid in this 1p0 XMP context.)

    // Heuristics based on Guideline/Profile identifiers inside the XML.
    if (t.includes(':extended')) return 'EXTENDED';

    // Treat EN16931 / COMFORT-ish as COMFORT for ZUGFeRD 1p0 XMP.
    if (t.includes(':en16931')) return 'COMFORT';
    if (t.includes(':comfort')) return 'COMFORT';

    // BASIC-WL / MINIMUM / BASIC -> BASIC (best-effort)
    if (t.includes(':basicwl') || t.includes(':basic-wl')) return 'BASIC';
    if (t.includes(':minimum')) return 'BASIC';
    if (t.includes(':basic')) return 'BASIC';

    // Fallback
    return 'BASIC';
  };

  const xmpDocumentType = 'INVOICE';
  const xmpConformanceLevel = inferConformanceLevel(xmlText);

  const xmp = `<?xpacket begin="\uFEFF" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
  <rdf:Description rdf:about=""
    xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:xmp="http://ns.adobe.com/xap/1.0/"
    xmlns:pdf="http://ns.adobe.com/pdf/1.3/"
    xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/"
    xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#"
    xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#"
    xmlns:zf="urn:zugferd:pdfa:CrossIndustryDocument:invoice:1p0#">
   <pdfaid:part>3</pdfaid:part>
   <pdfaid:conformance>B</pdfaid:conformance>
   <xmp:CreateDate>${nowIso}</xmp:CreateDate>
   <xmp:ModifyDate>${nowIso}</xmp:ModifyDate>
   <xmp:CreatorTool>${creatorTool}</xmp:CreatorTool>
   <dc:title><rdf:Alt>
     <rdf:li xml:lang="x-default">${lang === 'de' ? 'E-Rechnung' : 'E-Invoice'}</rdf:li>
     <rdf:li xml:lang="de-DE">E-Rechnung</rdf:li>
     <rdf:li xml:lang="en-US">E-Invoice</rdf:li>
   </rdf:Alt></dc:title>
   <pdf:Producer>${producer}</pdf:Producer>

   <!-- PDF/A Extension Schema for custom ZUGFeRD (zf:*) properties -->
   <pdfaExtension:schemas>
    <rdf:Bag>
     <rdf:li rdf:parseType="Resource">
      <pdfaSchema:schema>ZUGFeRD PDFA Extension Schema</pdfaSchema:schema>
      <pdfaSchema:namespaceURI>urn:zugferd:pdfa:CrossIndustryDocument:invoice:1p0#</pdfaSchema:namespaceURI>
      <pdfaSchema:prefix>zf</pdfaSchema:prefix>
      <pdfaSchema:property>
       <rdf:Seq>
        <rdf:li rdf:parseType="Resource">
         <pdfaProperty:name>DocumentFileName</pdfaProperty:name>
         <pdfaProperty:valueType>Text</pdfaProperty:valueType>
         <pdfaProperty:category>external</pdfaProperty:category>
         <pdfaProperty:description>Embedded ZUGFeRD XML invoice filename</pdfaProperty:description>
        </rdf:li>
        <rdf:li rdf:parseType="Resource">
         <pdfaProperty:name>Version</pdfaProperty:name>
         <pdfaProperty:valueType>Text</pdfaProperty:valueType>
         <pdfaProperty:category>external</pdfaProperty:category>
         <pdfaProperty:description>ZUGFeRD version</pdfaProperty:description>
        </rdf:li>
        <rdf:li rdf:parseType="Resource">
         <pdfaProperty:name>DocumentType</pdfaProperty:name>
         <pdfaProperty:valueType>Text</pdfaProperty:valueType>
         <pdfaProperty:category>external</pdfaProperty:category>
         <pdfaProperty:description>ZUGFeRD document type (e.g. INVOICE)</pdfaProperty:description>
        </rdf:li>
        <rdf:li rdf:parseType="Resource">
         <pdfaProperty:name>ConformanceLevel</pdfaProperty:name>
         <pdfaProperty:valueType>Text</pdfaProperty:valueType>
         <pdfaProperty:category>external</pdfaProperty:category>
         <pdfaProperty:description>ZUGFeRD conformance level</pdfaProperty:description>
        </rdf:li>
       </rdf:Seq>
      </pdfaSchema:property>
     </rdf:li>
    </rdf:Bag>
   </pdfaExtension:schemas>

   <!-- ZUGFeRD / Factur-X hints (best-effort) -->
   <zf:DocumentFileName>${embeddedXmlName}</zf:DocumentFileName>
   <zf:Version>1.0</zf:Version>
   <zf:DocumentType>${xmpDocumentType}</zf:DocumentType>
   <zf:ConformanceLevel>${xmpConformanceLevel}</zf:ConformanceLevel>
  </rdf:Description>
 </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>`;

  const metaStream = pdfDoc.context.stream(Buffer.from(xmp, 'utf8'), {
    Type: PDFName.of('Metadata'),
    Subtype: PDFName.of('XML'),
  });
  const metaRef = pdfDoc.context.register(metaStream);
  pdfDoc.catalog.set(PDFName.of('Metadata'), metaRef);

  // pdf-lib's attach() sometimes only materializes the EmbeddedFiles/Filespec objects
  // fully on save(). That makes "find the Filespec ref and merge Catalog /AF" tricky
  // in a single in-memory pass.
  //
  // Two-pass strategy:
  // 1) save once to materialize objects
  // 2) reload + fix Catalog /AF merge+dedupe robustly
  // 3) save final output
  const pass1 = await pdfDoc.save({ useObjectStreams: false });

  const pdfDoc2 = await PDFDocument.load(pass1, { updateMetadata: false });
  ensureTrailerId(pdfDoc2, { rotateSecond: true });

  try {
    const isPdfName = (v, s) => v && v.toString && v.toString() === `/${s}`;

    const decodePdfString = (v) => {
      try {
        if (v instanceof PDFString || v instanceof PDFHexString) return v.decodeText?.();
      } catch (_) {}
      return null;
    };

    const findFilespecRefInEmbeddedFilesNameTree = (wantedFilename) => {
      const lookupDict = (doc, o) => {
        if (!o) return null;
        try {
          return (o instanceof PDFDict) ? o : doc.context.lookup(o, PDFDict);
        } catch (_) {
          return null;
        }
      };

      const walkNameTree = (doc, nodeDict) => {
        if (!nodeDict) return null;

        const namesArr = nodeDict.get(PDFName.of('Names'));
        const kidsArr = nodeDict.get(PDFName.of('Kids'));

        let names = null;
        try {
          names = (namesArr instanceof PDFArray) ? namesArr : (namesArr ? doc.context.lookup(namesArr, PDFArray) : null);
        } catch (_) {
          names = null;
        }

        if (names) {
          const flat = names.asArray();
          for (let i = 0; i + 1 < flat.length; i += 2) {
            const k = flat[i];
            const v = flat[i + 1];
            const keyStr = decodePdfString(k);
            if (keyStr === wantedFilename) return v;
          }
        }

        let kids = null;
        try {
          kids = (kidsArr instanceof PDFArray) ? kidsArr : (kidsArr ? doc.context.lookup(kidsArr, PDFArray) : null);
        } catch (_) {
          kids = null;
        }

        if (kids) {
          for (const kid of kids.asArray()) {
            const kidDict = lookupDict(doc, kid);
            const found = walkNameTree(doc, kidDict);
            if (found) return found;
          }
        }

        return null;
      };

      try {
        const namesRoot = lookupDict(pdfDoc2, pdfDoc2.catalog.get(PDFName.of('Names')));
        const embeddedFiles = namesRoot ? lookupDict(pdfDoc2, namesRoot.get(PDFName.of('EmbeddedFiles'))) : null;
        if (!embeddedFiles) return null;

        const value = walkNameTree(pdfDoc2, embeddedFiles);
        if (value && value instanceof PDFDict) return pdfDoc2.context.register(value);
        return value || null;
      } catch (_) {
        return null;
      }
    };

    let xmlFilespecRef = findFilespecRefInEmbeddedFilesNameTree(embeddedXmlName) || null;
    const filenameByRefStr = new Map();

    for (const [ref, obj] of pdfDoc2.context.enumerateIndirectObjects()) {
      if (!(obj instanceof PDFDict)) continue;

      const type = obj.get(PDFName.of('Type'));
      const ef = obj.get(PDFName.of('EF'));
      if (type && !isPdfName(type, 'Filespec')) continue;
      if (!ef) continue;

      const fStr = decodePdfString(obj.get(PDFName.of('F')));
      const ufStr = decodePdfString(obj.get(PDFName.of('UF')));
      const filename = ufStr || fStr;
      if (!filename) continue;

      filenameByRefStr.set(ref.toString(), filename);
      if (!xmlFilespecRef && filename === embeddedXmlName) xmlFilespecRef = ref;
    }

    const existingAF = pdfDoc2.catalog.get(PDFName.of('AF'));
    let existingRefs = [];
    try {
      const arr = (existingAF instanceof PDFArray)
        ? existingAF
        : (existingAF ? pdfDoc2.context.lookup(existingAF, PDFArray) : null);
      if (arr) existingRefs = arr.asArray();
    } catch (_) {
      existingRefs = [];
    }

    const outRefs = [];
    const seenRefStr = new Set();

    const pushRef = (r) => {
      if (!r || !r.toString) return;
      const k = r.toString();
      if (seenRefStr.has(k)) return;
      seenRefStr.add(k);
      outRefs.push(r);
    };

    for (const r of existingRefs) {
      if (!r || !r.toString) continue;
      const filename = filenameByRefStr.get(r.toString()) || null;
      if (filename === embeddedXmlName) continue;
      pushRef(r);
    }

    if (xmlFilespecRef) pushRef(xmlFilespecRef);

    if (outRefs.length) {
      const afArr = PDFArray.withContext(pdfDoc2.context);
      for (const r of outRefs) afArr.push(r);
      pdfDoc2.catalog.set(PDFName.of('AF'), afArr);
    }
  } catch (e) {
    console.warn('WARN: second-pass /AF merge failed (best-effort):', e?.message || e);
  }

  const outBytes = await pdfDoc2.save({ useObjectStreams: false });
  fs.writeFileSync(outPdf, outBytes);

  const s = Buffer.from(outBytes).toString('latin1');
  const markers = [
    '/EmbeddedFiles',
    embeddedXmlName,
    '/Filespec',
    '/AF',
    '/AFRelationship',
    '/Alternative',
    '/OutputIntents',
    '/OutputIntent',
    '/GTS_PDFA1',
    '/DestOutputProfile',
    // XMP
    '/Metadata',
    'pdfaid:part',
    'pdfaid:conformance',
    'DocumentFileName',
    'DocumentType',
    'ConformanceLevel',
  ].map(m => ({ m, ok: s.includes(m) }));

  console.log('OK wrote', outPdf);
  for (const x of markers) console.log(x.ok ? 'HAS' : 'MISS', x.m);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
