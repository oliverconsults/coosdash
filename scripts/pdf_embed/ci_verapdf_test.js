#!/usr/bin/env node

// CI smoke test: Embed XML into PDF and validate PDF/A-3B via veraPDF.
// Exits non-zero if:
// - embed fails
// - veraPDF not compliant
// - required building blocks are missing

const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');

function die(msg) {
  process.stderr.write(String(msg) + '\n');
  process.exit(2);
}

function mustFile(p) {
  if (!fs.existsSync(p)) die(`Missing file: ${p}`);
}

function runNode(scriptPath, inputJson, extraEnv = {}) {
  const r = childProcess.spawnSync(process.execPath, [scriptPath], {
    input: JSON.stringify(inputJson),
    encoding: 'utf8',
    maxBuffer: 50 * 1024 * 1024,
    env: { ...process.env, ...extraEnv },
  });
  if (typeof r.status !== 'number') {
    die(`Failed to run ${scriptPath} (no exit code). stderr=${r.stderr || ''}`);
  }
  let out = null;
  try { out = JSON.parse(r.stdout || ''); } catch (_) {
    die(`Non-JSON output from ${scriptPath} (exit=${r.status}). stdout=${(r.stdout || '').slice(0, 2000)}`);
  }
  return { exit: r.status, out, stdout: r.stdout || '', stderr: r.stderr || '' };
}

async function main() {
  const base = __dirname;
  const fixDir = path.join(base, 'fixtures', 'verapdf');

  const inPdf = path.join(fixDir, 'repro-in.pdf');
  const inXml = path.join(fixDir, 'repro-in.xml');
  const reqTmplPath = path.join(fixDir, 'request.json');

  mustFile(inPdf);
  mustFile(inXml);
  mustFile(reqTmplPath);

  const reqTmpl = JSON.parse(fs.readFileSync(reqTmplPath, 'utf8'));

  // Use temp dir so CI runners can run in parallel.
  const tmpDir = fs.mkdtempSync('/tmp/coos-verapdf-');
  const tmpInPdf = path.join(tmpDir, 'in.pdf');
  const tmpInXml = path.join(tmpDir, 'in.xml');
  const tmpOutPdf = path.join(tmpDir, 'out.pdf');

  fs.copyFileSync(inPdf, tmpInPdf);
  fs.copyFileSync(inXml, tmpInXml);

  const embedCli = path.join(base, 'pdf_embed_cli.js');
  const validateCli = path.join(base, 'verapdf_validate_cli.js');

  mustFile(embedCli);
  mustFile(validateCli);

  const verapdfCli = process.env.COOS_VERAPDF_CLI || '/var/www/coosdash/shared/bin/verapdf-cli';
  if (!fs.existsSync(verapdfCli)) {
    die(`veraPDF CLI not found: ${verapdfCli} (set COOS_VERAPDF_CLI or install veraPDF)`);
  }

  // Run embed + inline verify (via pdf_embed_cli)
  const req = {
    ...reqTmpl,
    in_pdf: tmpInPdf,
    xml_path: tmpInXml,
    out_pdf: tmpOutPdf,
    verify: 'verapdf',
    verapdf_flavour: '3b',
    verapdf_cli: verapdfCli,
  };

  const r1 = runNode(embedCli, req);
  if (!r1.out || r1.out.ok !== true) {
    die(`Embed/verify failed (exit=${r1.exit}). stderr=${r1.out?.stderr || r1.stderr || ''}`);
  }

  // Extra assert: markers should include embedded XML and PDFA metadata blocks
  const m = r1.out.markers || {};
  const mustMarkers = [
    '/EmbeddedFiles',
    'zugferd-invoice.xml',
    '/AF',
    '/AFRelationship',
    '/OutputIntents',
    'pdfaid:part',
    'pdfaid:conformance',
  ];
  for (const k of mustMarkers) {
    if (m[k] !== true) {
      die(`Missing marker: ${k}`);
    }
  }

  // Second pass: explicit veraPDF validate
  const r2 = runNode(validateCli, {
    pdf_path: tmpOutPdf,
    flavour: '3b',
    extract: ['embeddedFile', 'metadata', 'outputIntent'],
    verapdf_cli: verapdfCli,
  });

  if (!r2.out || r2.out.ok_run !== true) {
    die(`veraPDF did not run cleanly (exit=${r2.exit}). stderr=${r2.out?.stderr || r2.stderr || ''}`);
  }
  if (r2.out.compliant !== true) {
    die(`veraPDF not compliant (exit_code=${r2.out.exit_code}).`);
  }

  process.stdout.write('OK CI veraPDF PDF/A-3B compliant\n');
}

main().catch((e) => die(e && e.stack || e));
