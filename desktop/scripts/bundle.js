/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : bundle.js
 *  Date    : 26/11/2026
 *  Author  : Semansys Development Team
 *
 *  Copyright (c) 2026
 *  Tel: +91 97863 37914
 *  Email: dev.digiboost.muthu@gmail.com
 *  All rights reserved, see the LICENSE file for more information.
 *
 ******************************************************************************/

/**
 * File: desktop/scripts/bundle.js
 * Purpose: Collects what a NEW machine needs into desktop/bundle/nginx-manager/, which the
 *          installer ships (electron-builder extraResources). Run: npm run bundle
 *          (dist:win / dist:mac run it first).
 * Contents:
 *   - the project's code and configs: ONLY files tracked by git (so .env, data/, your sites
 *     in conf/sites/, certificates in ssl/, logs and backups can never be included);
 *     desktop/, docs/, tests/ and CI files are left out
 *   - Windows: nginx.exe from nginx.org (pinned SHA-256) and PHP 8.4 (thread safe, x64) from
 *     php.net and checked against php.net's SHA-256 - never the local php/ folder (it has
 *     your php.ini and extensions); php.ini is created fresh from php.ini-development
 * Safety check: the build FAILS if the bundle contains .env, data files, site configs,
 *   certificates/keys, logs/backups, or any value from your .env (e.g. project folders).
 * Options (environment): NM_BUNDLE_PHP=8.4 (branch), NM_BUNDLE_TARGET=win32|darwin.
 */

'use strict';

const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const https = require('https');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = path.join(__dirname, '..', 'bundle', 'nginx-manager');
const CACHE = path.join(__dirname, '..', '.cache');
const TARGET = process.env.NM_BUNDLE_TARGET || process.platform;
const PHP_BRANCH = process.env.NM_BUNDLE_PHP || '8.4';

const EXCLUDE_DIRS = ['desktop/', 'docs/', 'tests/', '.github/', '.githooks/'];
const EXCLUDE_FILES = ['.gitignore', '.gitattributes'];

function log(msg) {
    console.log('[bundle] ' + msg);
}

function trackedFiles() {
    const out = execFileSync('git', ['ls-files', '-z'], { cwd: ROOT, encoding: 'utf8' });
    return out.split('\0').filter(Boolean)
        .filter((f) => !EXCLUDE_DIRS.some((d) => f.startsWith(d)) && !EXCLUDE_FILES.includes(f));
}

function copy(rel, from = ROOT) {
    const target = path.join(OUT, ...rel.split('/'));
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.copyFileSync(path.join(from, ...rel.split('/')), target);
}

function get(url) {
    return new Promise((resolve, reject) => {
        https.get(url, { headers: { 'User-Agent': 'nginx-manager-build' } }, (res) => {
            if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
                res.resume();
                get(new URL(res.headers.location, url).toString()).then(resolve, reject);
                return;
            }
            if (res.statusCode !== 200) { res.resume(); reject(new Error(`HTTP ${res.statusCode} for ${url}`)); return; }
            const chunks = [];
            res.on('data', (c) => chunks.push(c));
            res.on('end', () => resolve(Buffer.concat(chunks)));
        }).on('error', reject);
    });
}

/** Official nginx for Windows from nginx.org, checked against the pinned SHA-256. */
const NGINX = { version: '1.26.3', sha256: '39ca13277b361910f9e463a7e958e11566f7ede8a6f0df08a21b659ca92f3662' };

async function bundleNginx() {
    fs.mkdirSync(CACHE, { recursive: true });
    const zipFile = path.join(CACHE, `nginx-${NGINX.version}.zip`);
    if (!fs.existsSync(zipFile)) {
        log(`downloading nginx ${NGINX.version} from nginx.org`);
        fs.writeFileSync(zipFile, await get(`https://nginx.org/download/nginx-${NGINX.version}.zip`));
    }
    const sha = crypto.createHash('sha256').update(fs.readFileSync(zipFile)).digest('hex');
    if (sha !== NGINX.sha256) {
        fs.unlinkSync(zipFile);
        throw new Error(`SHA-256 mismatch for nginx-${NGINX.version}.zip - download removed`);
    }
    const tmp = path.join(CACHE, `nginx-${NGINX.version}`);
    fs.rmSync(tmp, { recursive: true, force: true });
    execFileSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command',
        `Expand-Archive -LiteralPath '${zipFile.replace(/'/g, "''")}' -DestinationPath '${tmp.replace(/'/g, "''")}' -Force`]);
    fs.copyFileSync(path.join(tmp, `nginx-${NGINX.version}`, 'nginx.exe'), path.join(OUT, 'nginx.exe'));
    fs.rmSync(tmp, { recursive: true, force: true });
    log(`nginx ${NGINX.version} - SHA-256 verified (nginx.exe only; conf/ and html/ come from the project)`);
}

async function bundlePhp() {
    const releases = JSON.parse((await get('https://windows.php.net/downloads/releases/releases.json')).toString('utf8'));
    const branch = releases[PHP_BRANCH];
    if (!branch) throw new Error(`php.net lists no PHP ${PHP_BRANCH}`);
    const key = Object.keys(branch).find((k) => /^ts-vs\d+-x64$/.test(k));
    const zip = key && branch[key].zip;
    if (!zip || !zip.path || !zip.sha256) throw new Error(`No thread-safe x64 build of PHP ${PHP_BRANCH} with a checksum`);

    fs.mkdirSync(CACHE, { recursive: true });
    const zipFile = path.join(CACHE, path.basename(zip.path));
    if (!fs.existsSync(zipFile)) {
        log(`downloading ${zip.path} from php.net`);
        fs.writeFileSync(zipFile, await get('https://windows.php.net/downloads/releases/' + zip.path));
    }
    const sha = crypto.createHash('sha256').update(fs.readFileSync(zipFile)).digest('hex');
    if (sha.toLowerCase() !== String(zip.sha256).toLowerCase()) {
        fs.unlinkSync(zipFile);
        throw new Error(`SHA-256 mismatch for ${zip.path} - download removed`);
    }
    log(`PHP ${branch.version} - SHA-256 verified`);

    const phpDir = path.join(OUT, 'php', PHP_BRANCH);
    fs.mkdirSync(phpDir, { recursive: true });
    execFileSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command',
        `Expand-Archive -LiteralPath '${zipFile.replace(/'/g, "''")}' -DestinationPath '${phpDir.replace(/'/g, "''")}' -Force`]);

    // Fresh php.ini with the same defaults install-php.ps1 uses (Enable-PhpIniDefaults).
    const psm1 = path.join(ROOT, 'scripts', 'lib', 'Common.psm1');
    execFileSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command',
        `Import-Module '${psm1.replace(/'/g, "''")}'; ` +
        `$t = [IO.File]::ReadAllText('${path.join(phpDir, 'php.ini-development').replace(/'/g, "''")}'); ` +
        `$ini = Enable-PhpIniDefaults $t @('curl','fileinfo','gd','gd2','intl','mbstring','mysqli','openssl','pdo_mysql','pdo_sqlite','sodium','zip'); ` +
        `Write-Utf8NoBom '${path.join(phpDir, 'php.ini').replace(/'/g, "''")}' $ini`]);
    log(`php/${PHP_BRANCH}/php.ini created from php.ini-development`);
}

/** Fails when anything private could have slipped into the bundle. */
function safetyCheck() {
    const files = listAll(OUT);
    const forbidden = files.filter((rel) =>
        rel === '.env'
        || (/^data\//.test(rel) && rel !== 'data/.gitkeep')
        || (/^conf\/sites\//.test(rel) && !['conf/sites/.gitkeep', 'conf/sites/_template.conf.example'].includes(rel))
        || /^conf\/(generated|manager)\//.test(rel)
        || (/^ssl\//.test(rel) && !['ssl/.gitkeep', 'ssl/configuration.conf'].includes(rel))
        || /^(logs|backups|temp)\//.test(rel)
        || /\.(key|pem|crt|p12|pfx)$/i.test(rel));
    if (forbidden.length) throw new Error('Private files in the bundle:\n  ' + forbidden.join('\n  '));

    // No value of the machine's .env (project folders, paths) may appear in a text file.
    const envFile = path.join(ROOT, '.env');
    const secrets = fs.existsSync(envFile)
        ? fs.readFileSync(envFile, 'utf8').split(/\r?\n/)
            .map((l) => (l.match(/^\s*[A-Z0-9_]+\s*=\s*(.*?)\s*$/) || [])[1])
            .map((v) => (v || '').replace(/^(['"])(.*)\1$/, '$2'))
            // file system paths only (D:/projects, /Users/me, \\server\share) - not e.g. "Asia/Kolkata"
            .filter((v) => v.length >= 4 && /^([A-Za-z]:[\\/]|\/|\\\\)/.test(v))
        : [];
    for (const rel of files) {
        if (/^php\//.test(rel) || /\.(exe|dll|phar|png|ico|gz|zip)$/i.test(rel)) continue;
        const text = fs.readFileSync(path.join(OUT, ...rel.split('/')), 'utf8');
        for (const s of secrets) {
            if (text.includes(s) || text.includes(s.replace(/\//g, '\\'))) {
                throw new Error(`A value from your .env appears in ${rel} - not bundled`);
            }
        }
    }
    log(`safety check passed: ${files.length} files, no private data`);
}

function listAll(dir, prefix = '') {
    const out = [];
    for (const e of fs.readdirSync(path.join(dir, prefix), { withFileTypes: true })) {
        const rel = prefix ? `${prefix}/${e.name}` : e.name;
        if (e.isDirectory()) out.push(...listAll(dir, rel)); else out.push(rel);
    }
    return out;
}

(async () => {
    if (process.env.NM_BUNDLE_CHECK_ONLY === '1') {   // re-check an existing bundle (used to test the check)
        safetyCheck();
        return;
    }
    fs.rmSync(path.join(__dirname, '..', 'bundle'), { recursive: true, force: true });
    const files = trackedFiles();
    for (const rel of files) copy(rel);
    log(`${files.length} project files (tracked by git only)`);
    if (TARGET === 'win32') {
        await bundleNginx();
        await bundlePhp();
    } else {
        log('macOS: nginx and PHP come from Homebrew - not bundled');
    }
    safetyCheck();
})().catch((e) => {
    console.error('[bundle] FAILED: ' + e.message);
    process.exit(1);
});
