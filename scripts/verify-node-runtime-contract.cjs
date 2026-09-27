'use strict';

const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');

const repositoryRoot = resolve(__dirname, '..');
const expected = Object.freeze({ node: '22.23.2', npm: '10.9.8', version: '1.7.2' });
const declarationsOnly = process.argv.includes('--declarations-only');

function read(path) {
  return readFileSync(resolve(repositoryRoot, path), 'utf8');
}

function readJson(path) {
  return JSON.parse(read(path));
}

function assertEqual(actual, wanted, label) {
  if (actual !== wanted) {
    throw new Error(`${label}: expected ${wanted}, received ${actual}`);
  }
}

const packageJson = readJson('frontend/package.json');
const packageLock = readJson('frontend/package-lock.json');
const lockedRoot = packageLock.packages?.[''];

assertEqual(packageJson.version, expected.version, 'frontend/package.json version');
assertEqual(lockedRoot?.version, expected.version, 'frontend/package-lock.json version');
assertEqual(packageJson.engines?.node, expected.node, 'frontend/package.json engines.node');
assertEqual(packageJson.engines?.npm, expected.npm, 'frontend/package.json engines.npm');
assertEqual(packageJson.packageManager, `npm@${expected.npm}`, 'frontend/package.json packageManager');
assertEqual(lockedRoot?.engines?.node, expected.node, 'frontend/package-lock.json engines.node');
assertEqual(lockedRoot?.engines?.npm, expected.npm, 'frontend/package-lock.json engines.npm');
assertEqual(read('.nvmrc').trim(), expected.node, '.nvmrc');

const workflow = read('.github/workflows/accessibility.yml');
const pins = [...workflow.matchAll(/node-version:\s*['"]?([^'"\s]+)/g)].map((match) => match[1]);
assertEqual(pins.length, 1, 'accessibility workflow Node declaration count');
assertEqual(pins[0], expected.node, 'accessibility workflow node-version');
assertEqual(workflow.match(/npm --version/g)?.length ?? 0, 1, 'workflow npm assertion count');

if (!declarationsOnly) {
  assertEqual(process.versions.node, expected.node, 'active Node runtime');
  const npmVersion = execFileSync('npm', ['--version'], { encoding: 'utf8' }).trim();
  assertEqual(npmVersion, expected.npm, 'active npm runtime');
}

console.log(
  declarationsOnly
    ? `Runtime declarations are pinned to Node ${expected.node} and npm ${expected.npm}.`
    : `Runtime contract verified on Node ${expected.node} and npm ${expected.npm}.`,
);
