const fs = require('fs');
const path = require('path');
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
  // Ensure: Catalog /AF [ <FilespecRef> ]
  try {
    const names = pdfDoc.catalog.get(PDFName.of('Names'));
    const namesDict = names ? pdfDoc.context.lookup(names, PDFDict) : null;
    const embeddedFiles = namesDict ? namesDict.get(PDFName.of('EmbeddedFiles')) : null;
    const efDict = embeddedFiles ? pdfDoc.context.lookup(embeddedFiles, PDFDict) : null;
    const efNames = efDict ? efDict.get(PDFName.of('Names')) : null;
    const efArr = efNames ? pdfDoc.context.lookup(efNames, PDFArray) : null;

    // Name tree: /Names [ (name1) filespecRef1 (name2) filespecRef2 ... ]
    const filespecRef = efArr && efArr.size() >= 2 ? efArr.get(1) : null;
    if (filespecRef) {
      const afArr = PDFArray.withContext(pdfDoc.context);
      afArr.push(filespecRef);
      pdfDoc.catalog.set(PDFName.of('AF'), afArr);
    } else {
      console.warn('WARN: could not locate embedded Filespec ref for Catalog /AF');
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

  const outBytes = await pdfDoc.save({ useObjectStreams: false });
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
