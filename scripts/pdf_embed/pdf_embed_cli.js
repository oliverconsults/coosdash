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
//   { ok, exit_code, out_pdf, out_pdf_bytes, stdout, stderr, markers, verify }

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

function normalizeVerify(v) {
  if (!v) return null;
  if (v === true) return 'verapdf';
  const s = String(v).trim().toLowerCase();
  if (s === '1' || s === 'true' || s === 'verapdf') return 'verapdf';
  return null;
}

function safeJsonParse(s) {
  try { return JSON.parse(s); } catch (_) { return null; }
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
    const out = { ok: false, exit_code: 2, reason: 'JSON konnte nicht geparst werden', error: String((e && e.message) || e) };
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

  // Helvetica-Mapping ist grundsätzlich UNSAFE (siehe embed.js). Wir setzen das ENV nur,
  // wenn der Call explizit bestätigt, dass er dieses Risiko akzeptiert.
  const mapHelveticaRequested = (req.map_helvetica === true || req.mapHelvetica === true || req.COOS_PDF_MAP_HELVETICA === '1');
  const mapHelveticaUnsafeOk = (req.map_helvetica_unsafe_ok === true || req.mapHelveticaUnsafeOk === true || req.COOS_PDF_MAP_HELVETICA_UNSAFE_OK === '1');
  if (mapHelveticaRequested && mapHelveticaUnsafeOk) {
    env.COOS_PDF_MAP_HELVETICA = '1';
    env.COOS_PDF_MAP_HELVETICA_UNSAFE_OK = '1';
  } else if (mapHelveticaRequested && !mapHelveticaUnsafeOk) {
    // bewusst nur Warnung: wir wollen das Embed nicht hart failen, aber den Wunsch dokumentieren.
    // (php/worker kann stdout/stderr loggen)
    // eslint-disable-next-line no-console
    console.warn('WARN: map_helvetica angefragt, aber UNSAFE-OK fehlt -> Mapping wird übersprungen.');
  }

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

  const embedOk = (r.status === 0);

  let outBytes = null;
  try {
    const st = fs.statSync(outPdf);
    outBytes = st.size;
  } catch (_) {
    outBytes = null;
  }

  const markers = parseMarkersFromEmbedStdout(r.stdout || '');

  // Optional: veraPDF-Verification nach erfolgreichem Embed
  const verifyMode = normalizeVerify(req.verify || req.verify_mode || req.verification);
  let verify = null;
  let finalExitCode = (typeof r.status === 'number') ? r.status : null;

  if (embedOk && outBytes !== null && verifyMode === 'verapdf') {
    const validateCliPath = path.join(__dirname, 'verapdf_validate_cli.js');

    // Defaults (wenn PHP nichts setzt): PDF/A-3B + ein paar Extrakte, die bei Debug helfen.
    const validateReq = {
      pdf_path: outPdf,
      flavour: req.verapdf_flavour || req.flavour || '3b',
      extract: req.verapdf_extract || req.extract || ['embeddedFile', 'metadata', 'outputIntent'],
      verapdf_cli: req.verapdf_cli,
    };

    const vr = childProcess.spawnSync(process.execPath, [validateCliPath], {
      input: JSON.stringify(validateReq),
      encoding: 'utf8',
      maxBuffer: 50 * 1024 * 1024,
    });

    const vJson = safeJsonParse(vr.stdout || '');
    verify = {
      mode: 'verapdf',
      ok_run: Boolean(vJson && vJson.ok_run),
      compliant: (vJson && typeof vJson.compliant === 'boolean') ? vJson.compliant : null,
      exit_code: (vJson && typeof vJson.exit_code === 'number') ? vJson.exit_code : null,
      report_parse_ok: Boolean(vJson && vJson.report_parse_ok),
      // stdout/stderr sind hier nur für Debugging gedacht; PHP kann das bei Bedarf loggen.
      stdout: (vJson && typeof vJson.stdout === 'string') ? vJson.stdout : (vr.stdout || ''),
      stderr: (vJson && typeof vJson.stderr === 'string') ? vJson.stderr : (vr.stderr || ''),
    };

    // Wenn veraPDF nicht sauber lief oder nicht compliant ist: harter Fail (Exit-Code 5 = VERIFY_FAILED).
    const vOkRun = (vJson && vJson.ok_run === true);
    const vCompliant = (vJson && vJson.compliant === true);

    if (!vOkRun || !vCompliant) {
      finalExitCode = 5;
    }
  }

  const ok = (embedOk && outBytes !== null && (verifyMode ? (verify && verify.ok_run && verify.compliant) : true));

  const out = {
    ok,
    exit_code: finalExitCode,
    out_pdf: outPdf,
    out_pdf_bytes: outBytes,
    stdout: r.stdout || '',
    stderr: r.stderr || '',
    markers,
    verify,
  };

  process.stdout.write(JSON.stringify(out, null, 2) + '\n');

  if (ok) process.exit(0);
  if (finalExitCode === 5) process.exit(5);
  process.exit(embedOk ? 1 : (typeof r.status === 'number' ? r.status : 1));
}

main().catch((e) => {
  const out = { ok: false, exit_code: 2, reason: 'Unerwarteter Fehler im Wrapper', error: String((e && e.message) || e) };
  process.stdout.write(JSON.stringify(out, null, 2) + '\n');
  process.exit(2);
});
