'use strict';
const { spawnSync } = require('node:child_process');
const { existsSync, readdirSync } = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const php = process.env.PHOTOBOOTH_TEST_PHP || (process.platform === 'win32' && existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php');
function run(command, args) {
  const result = spawnSync(command, args, { cwd: root, stdio: 'inherit', windowsHide: true });
  if (result.error) { console.error(result.error.message); process.exit(1); }
  if (result.status !== 0) process.exit(result.status || 1);
}
for (const name of readdirSync(path.join(root, 'resources/js')).filter((name) => name.endsWith('.js'))) {
  run(process.execPath, ['--check', path.join('resources/js', name)]);
}
console.log('JavaScript syntax checks passed');
if (process.argv.includes('--syntax-only')) process.exit(0);
for (const test of ['run-tests.php', 'test-api-logic.php', 'test-filter-effects.php', 'test-photo-receipt.php', 'test-receipt-deployment.php', 'test-frame-previews.php', 'test-scroll-interaction.php']) {
  run(php, [path.join('scripts', test)]);
}
run(process.execPath, ['scripts/test-filter-session.cjs']);
run(process.execPath, ['scripts/test-gallery-polling.cjs']);
