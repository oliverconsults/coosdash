#!/usr/bin/env node

// JSON-CLI Wrapper um veraPDF für COOS Worker.
// Ziel: stabile, maschinenlesbare Ausgabe (JSON auf stdout), ohne dass veraPDF Exit-Code=1
// ("FAIL" / nicht compliant) als Crash gewertet wird.
//
// Input:
// - entweder als 1. Argument ein Pfad zu einer JSON-Datei
// - oder JSON auf stdin
//
// Pflichtfelder:
// - pdf_path
//
// Optional:
// - flavour (Default: 3b)
// - extract (Array oder CSV-String; z.B. ["embeddedFile","metadata","outputIntent"])
// - verapdf_cli (Default: /var/www/coosdash/shared/bin/verapdf-cli)
//
// Output (stdout):
//   { ok_run, exit_code, pdf_path, report_json, report_parse_ok, compliant, stdout, stderr }

const fs = require('fs');
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

function normalizeExtract(v) {
  if (!v) return [];
  if (Array.isArray(v)) return v.map(String).filter(Boolean);
  if (typeof v === 'string') {
    return v.split(',').map(s => s.trim()).filter(Boolean);
  }
  return [];
}

async function main() {
  let raw = null;
  const argPath = process.argv[2];
  if (argPath) raw = fs.readFileSync(argPath, 'utf8');
  else raw = await readAllStdin();

  let req = null;
  try {
    req = JSON.parse(raw);
  } catch (e) {
    const out = { ok_run: false, exit_code: 2, reason: 'JSON konnte nicht geparst werden', error: String(e && e.message || e) };
    process.stdout.write(JSON.stringify(out, null, 2) + '\n');
    process.exit(2);
  }

  const pdfPath = req.pdf_path || req.pdfPath || req.pdf;
  if (!pdfPath) {
    const out = { ok_run: false, exit_code: 2, reason: 'Pflichtfeld fehlt (pdf_path)', got: { pdf_path: pdfPath || null } };
    process.stdout.write(JSON.stringify(out, null, 2) + '\n');
    process.exit(2);
  }

  const cli = String(req.verapdf_cli || '/var/www/coosdash/shared/bin/verapdf-cli');
  const flavour = String(req.flavour || '3b');
  const extract = normalizeExtract(req.extract);

  const args = ['--format', 'json', '--flavour', flavour];
  if (extract.length) {
    args.push('--extract', extract.join(','));
  }
  args.push(pdfPath);

  const r = childProcess.spawnSync(cli, args, {
    encoding: 'utf8',
    maxBuffer: 50 * 1024 * 1024,
  });

  const exitCode = (typeof r.status === 'number') ? r.status : null;

  // veraPDF:
  // - 0 => compliant
  // - 1 => nicht compliant (normaler Fall)
  // - >=2 => echte Fehler / Crash
  const okRun = (exitCode === 0 || exitCode === 1);

  let report = null;
  let reportParseOk = false;
  let compliant = null;

  if (typeof r.stdout === 'string' && r.stdout.trim() !== '') {
    try {
      report = JSON.parse(r.stdout);
      reportParseOk = true;

      // Heuristik: veraPDF JSON enthält typischerweise ein isCompliant Feld.
      // Wir machen es robust, ohne uns auf exakte Struktur festzunageln.
      function findIsCompliant(obj, depth = 0) {
        if (!obj || depth > 6) return null;
        if (typeof obj === 'object') {
          if (Object.prototype.hasOwnProperty.call(obj, 'isCompliant')) {
            const v = obj.isCompliant;
            if (typeof v === 'boolean') return v;
          }
          for (const k of Object.keys(obj)) {
            const v = findIsCompliant(obj[k], depth + 1);
            if (typeof v === 'boolean') return v;
          }
        }
        return null;
      }

      compliant = findIsCompliant(report);
    } catch (_) {
      report = null;
      reportParseOk = false;
    }
  }

  const out = {
    ok_run: okRun,
    exit_code: exitCode,
    pdf_path: pdfPath,
    flavour,
    extract,
    report_parse_ok: reportParseOk,
    compliant,
    report_json: report,
    stdout: r.stdout || '',
    stderr: r.stderr || '',
  };

  process.stdout.write(JSON.stringify(out, null, 2) + '\n');
  process.exit(okRun ? 0 : (exitCode === null ? 2 : exitCode));
}

main().catch((e) => {
  const out = { ok_run: false, exit_code: 2, reason: 'Unerwarteter Fehler im Wrapper', error: String(e && e.message || e) };
  process.stdout.write(JSON.stringify(out, null, 2) + '\n');
  process.exit(2);
});
