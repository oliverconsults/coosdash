#!/usr/bin/env node

// JSON-CLI Wrapper um embed.js (pdf-lib) für COOS Worker.
// Ziel: stabile, maschinenlesbare Ausgabe (JSON auf stdout), während embed.js weiterhin
// seine menschenlesbaren Marker loggen darf.
//
// Input:
// - entweder als 1. Argument ein Pfad zu einer JSON-Datei
// - oder JSON auf stdin
//
// Beispiel:
//   echo '{"in_pdf":"/tmp/in.pdf","xml_path":"/tmp/in.xml","out_pdf":"/tmp/out.pdf","lang":"de"}' | node pdf_embed_cli.js
//
// Output (stdout):
//   { ok, exit_code, out_pdf, out_pdf_bytes, stdout, stderr, markers }

const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');

function readAllStdin() {
  return new Promise((resolve, reject) => {
    let data = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (c) => { data += c; });
    process.stdin.on('end', () => resolve(data));
    process.stdin.on('error', reject);
  });
}

function parseMarkersFromEmbedStdout(stdout) {
  // embed.js loggt pro Marker eine Zeile: "HAS <marker>" oder "MISS <marker>"
  const markers = {};
  for (const line of String(stdout || '').split(/\r?\n/)) {
    const m = line.match(/^(HAS|MISS)\s+(.+)$/);
    if (!m) continue;
    markers[m[2]] = (m[1] === 'HAS');
  }
  return markers;
}

async function main() {
  let raw = null;

  const argPath = process.argv[2];
  if (argPath) {
    raw = fs.readFileSync(argPath, 'utf8');
  } else {
    raw = await readAllStdin();
  }

  let req = null;
  try {
    req = JSON.parse(raw);
  } catch (e) {
    const out = { ok: false, exit_code: 2, reason: 'JSON konnte nicht geparst werden', error: String(e && e.message || e) };
    process.stdout.write(JSON.stringify(out, null, 2) + '\n');
    process.exit(2);
  }

  const inPdf = req.in_pdf || req.inPdf || req.input_pdf;
  const xmlPath = req.xml_path || req.xmlPath || req.xml;
  const outPdf = req.out_pdf || req.outPdf || req.output_pdf;

  if (!inPdf || !xmlPath || !outPdf) {
    const out = {
      ok: false,
      exit_code: 2,
      reason: 'Pflichtfelder fehlen (in_pdf, xml_path, out_pdf)',
      got: { in_pdf: inPdf || null, xml_path: xmlPath || null, out_pdf: outPdf || null },
    };
    process.stdout.write(JSON.stringify(out, null, 2) + '\n');
    process.exit(2);
  }

  const env = { ...process.env };

  // Convenience-Felder (werden in ENV gemappt)
  const lang = req.lang || req.COOS_LANG;
  if (lang) env.COOS_LANG = String(lang);

  const producer = req.producer || req.COOS_PDF_PRODUCER;
  if (producer) env.COOS_PDF_PRODUCER = String(producer);

  const creatorTool = req.creator_tool || req.creatorTool || req.COOS_PDF_CREATOR_TOOL;
  if (creatorTool) env.COOS_PDF_CREATOR_TOOL = String(creatorTool);

  const mapHelvetica = (req.map_helvetica === true || req.mapHelvetica === true || req.COOS_PDF_MAP_HELVETICA === '1');
  if (mapHelvetica) env.COOS_PDF_MAP_HELVETICA = '1';

  // Zusätzlich: direkte env-Overrides aus JSON erlauben
  if (req.env && typeof req.env === 'object') {
    for (const [k, v] of Object.entries(req.env)) {
      if (!k) continue;
      env[String(k)] = (v === null || v === undefined) ? '' : String(v);
    }
  }

  const embedPath = path.join(__dirname, 'embed.js');

  const r = childProcess.spawnSync(process.execPath, [embedPath, inPdf, xmlPath, outPdf], {
    env,
    encoding: 'utf8',
    maxBuffer: 50 * 1024 * 1024,
  });

  const ok = (r.status === 0);

  let outBytes = null;
  try {
    const st = fs.statSync(outPdf);
    outBytes = st.size;
  } catch (_) {
    outBytes = null;
  }

  const out = {
    ok: ok && outBytes !== null,
    exit_code: (typeof r.status === 'number') ? r.status : null,
    out_pdf: outPdf,
    out_pdf_bytes: outBytes,
    stdout: r.stdout || '',
    stderr: r.stderr || '',
    markers: parseMarkersFromEmbedStdout(r.stdout || ''),
  };

  process.stdout.write(JSON.stringify(out, null, 2) + '\n');
  process.exit(ok ? 0 : (typeof r.status === 'number' ? r.status : 1));
}

main().catch((e) => {
  const out = { ok: false, exit_code: 2, reason: 'Unerwarteter Fehler im Wrapper', error: String(e && e.message || e) };
  process.stdout.write(JSON.stringify(out, null, 2) + '\n');
  process.exit(2);
});
