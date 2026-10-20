'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

if (process.platform !== 'win32') {
  console.log('Windows setup checks skipped on this platform');
  process.exit(0);
}

const root = path.resolve(__dirname, '..');
const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'photobooth setup '));
const project = path.join(fixture, 'xampp', 'htdocs', 'booth with spaces');
const assets = [
  'normalize.css/normalize.css', 'font-awesome/css/font-awesome.css',
  'material-icons/iconfont/material-icons.css', 'material-icons/css/material-icons.css',
  'whatwg-fetch/dist/fetch.umd.js', 'jquery/dist/jquery.min.js',
  '@andreasremdt/simple-translator/dist/umd/translator.min.js',
  'driver.js/dist/driver.css', 'driver.js/dist/driver.js.iife.js',
  'selectize/dist/css/selectize.css', 'selectize/dist/js/standalone/selectize.min.js',
  'waypoints/lib/jquery.waypoints.min.js', 'marvinj/marvinj/release/marvinj-1.0.js',
  'github-markdown-css/github-markdown.css'
];
let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; }
function write(relative, content) {
  const target = path.join(project, relative);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, content);
}
function run(checkOnly = true, npmVersion = '10.9.2') {
  return spawnSync(process.env.ComSpec, ['/d', '/c', 'setup.bat', ...(checkOnly ? ['-CheckOnly'] : [])], {
    cwd: project,
    env: { ...process.env, PATH: `${path.join(process.env.SystemRoot, 'System32')};${process.env.SystemRoot}`, PHOTOBOOTH_TEST_NPM_VERSION: npmVersion },
    encoding: 'utf8', windowsHide: true, timeout: 30000
  });
}
try {
  write('scripts/setup.ps1', fs.readFileSync(path.join(root, 'scripts/setup.ps1')));
  write('setup.bat', fs.readFileSync(path.join(root, 'setup.bat')));
  write('package.json', JSON.stringify({ engines: { node: '>=20.19.0', npm: '>=9.6.0' } }));
  write('data/tmp/nodejs/npm.cmd', '@echo off\r\nif "%~1"=="--version" (\r\nnode -p "process.env.PHOTOBOOTH_TEST_NPM_VERSION"\r\nexit /b\r\n)\r\nexit /b 73\r\n');
  const fixtureNode = path.join(project, 'data/tmp/nodejs/node.exe');
  try { fs.linkSync(process.execPath, fixtureNode); }
  catch (error) {
    if (!['EXDEV', 'EPERM', 'EACCES'].includes(error.code)) throw error;
    fs.copyFileSync(process.execPath, fixtureNode);
  }
  for (const asset of assets) write(`node_modules/${asset}`, 'fixture');
  const apache = path.join(fixture, 'xampp', 'apache', 'conf');
  fs.mkdirSync(apache, { recursive: true });
  fs.writeFileSync(path.join(apache, 'httpd.conf'), 'Listen 8081\n');

  const ready = run();
  check(ready.status === 0, `Setup can check a portable Node installation outside PATH in a path with spaces: ${ready.stdout}\n${ready.stderr}`);
  check(ready.stdout.includes('http://localhost:8081/booth%20with%20spaces/'), 'Setup reports the configured Apache port and installation folder');

  const driverCss = path.join(project, 'node_modules/driver.js/dist/driver.css');
  fs.unlinkSync(driverCss);
  const missingDriver = run();
  check(missingDriver.status !== 0 && (missingDriver.stdout + missingDriver.stderr).includes('driver.css'), 'A missing guide stylesheet prevents a successful setup check');
  fs.writeFileSync(driverCss, 'fixture');

  const oldNpm = run(true, '9.5.0');
  check(oldNpm.status !== 0 && (oldNpm.stdout + oldNpm.stderr).includes('9.6.0'), 'An unsupported npm version is rejected before installation');
  const badNpm = run(true, 'invalid');
  check(badNpm.status !== 0, 'An unreadable npm version cannot report success');

  const failedInstall = run(false);
  check(failedInstall.status === 73, `The batch entrypoint preserves npm installation failure codes: ${failedInstall.stdout}\n${failedInstall.stderr}`);
  console.log(`${checks} Windows setup checks passed`);
} finally {
  fs.rmSync(fixture, { recursive: true, force: true });
}
