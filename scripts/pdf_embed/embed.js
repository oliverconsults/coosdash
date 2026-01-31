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

  // Optional (off by default): best-effort mapping of standard Helvetica fonts to an embedded TTF.
  // NOTE: This is risky because Type1 Helvetica uses single-byte WinAnsi encoding, while embedded
  // TrueType fonts are typically Type0/CID fonts (Identity-H). Swapping the resource without
  // rewriting the content stream can cause invalid glyph mapping + width mismatches in veraPDF.
  const mapHelvetica = process.env.COOS_PDF_MAP_HELVETICA === '1';

  let noto = null;
  if (mapHelvetica) try {
    const ttfPath = path.join(__dirname, 'assets', 'NotoSans-Regular.ttf');
    if (fs.existsSync(ttfPath)) {
      const ttfBytes = fs.readFileSync(ttfPath);
      // IMPORTANT: veraPDF flagged width mismatches when we subsetted the font.
      // Using the full font program keeps Widths/W entries consistent with the embedded program.
      noto = await pdfDoc.embedFont(ttfBytes, { subset: false });

      // Draw a tiny marker to ensure the embedded font is referenced even if mapping finds nothing.
      const page0 = pdfDoc.getPages()[0];
      if (page0) page0.drawText(' ', { x: 1, y: 1, size: 1, font: noto });

      // Map strategy: on each page, replace any Font resource whose BaseFont starts with Helvetica
      // (Type1 standard font) with our embedded Noto font.
      const helvNames = new Set([
        'Helvetica',
        'Helvetica-Bold',
        'Helvetica-Oblique',
        'Helvetica-BoldOblique',
      ]);

      const asDict = (o) => {
        if (!o) return null;
        try {
          return (o instanceof PDFDict) ? o : pdfDoc.context.lookup(o, PDFDict);
        } catch (_) {
          return null;
        }
      };

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

          // Only touch Type1 standard Helvetica fonts.
          if (
            subtype && subtype.toString() === '/Type1' &&
            helvNames.has(baseFontName)
          ) {
            fontDict.set(k, noto.ref);
          }
        }
      }
    } else {
      console.warn('WARN: missing TTF asset (skip font embed+map):', ttfPath);
    }
  } catch (e) {
    console.warn('WARN: failed to embed/map TTF font (best-effort):', e?.message || e);
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
   <zf:ConformanceLevel>BASIC</zf:ConformanceLevel>
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
    'ConformanceLevel',
  ].map(m => ({ m, ok: s.includes(m) }));

  console.log('OK wrote', outPdf);
  for (const x of markers) console.log(x.ok ? 'HAS' : 'MISS', x.m);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
