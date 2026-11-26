# Nginx Manager 2.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the portable Windows Nginx + multi-PHP manager as v2.0: PowerShell scripts instead of `.bat`, and a sidebar dashboard web UI with separate pages for Sites, Groups, SSL Certificates and PHP Versions/Updates.

**Architecture:** One JSON settings file (`data/settings.json`) is the single source of truth for PHP versions, ports and paths; both the PowerShell scripts and the PHP web UI read it, and the nginx upstreams + manager vhost are *generated* from it. `start-all.ps1` self-elevates, so nginx, every `php-cgi` and therefore the web UI run as Administrator (needed for `certutil`, the hosts file and PHP installs). Long-running/self-affecting work (PHP install/update, PHP restart) runs as detached PowerShell "jobs" that the UI polls.

**Tech Stack:** PowerShell 5.1 (Windows built-in) + Pester 3.4 for script tests; plain PHP 8.3 (no Composer, no framework, PSR-4 autoloader of our own) for the UI; vanilla CSS/JS; nginx for Windows; OpenSSL from Git for Windows; `certutil`.

**Spec (agreed in chat, 2026-11-26):**
- Replace the `.bat` scripts with `.ps1`; actions that need Administrator get it (decision: option A — `start-all.ps1` elevates once, everything inherits).
- v1 was one page (form + site list). v2 = dashboard with sidebar and separate routes.
- Sites are listed grouped; groups live on their own page and new groups can be created any time.
- SSL certificate install/management on its own page.
- Clicking a site shows its details, and they are editable.
- A WordPress-style "Updates" page: shows when a newer PHP patch release exists for an installed branch, updates it in one click, and lists other available PHP versions to install.
- **No absolute paths anywhere in the project** (code, scripts, committed configs, settings). Machine-specific values come from `.env` (gitignored) with a committed `.env.example`.
- A separate cPanel-style "PHP Extensions" page: pick a PHP version, see every extension with on/off toggles, enable/disable any of them, and download + install new extensions (PECL Windows builds).

## Changes during implementation (2026-11-26)

- **v2 is a fresh install; v1 is reference only.** No migration script; nothing from v1 is copied except the runtime (`nginx.exe`, `php/`) that was already in place. Task 1 = bootstrap only.
- **Every file starts with the Semansys copyright banner** (Project / File / Date / Author / Copyright / Tel / Email / All rights reserved; `#`-prefixed in PowerShell, nginx, .env and git files), then the detailed purpose notes.
- **Several project roots:** `.env` takes `PROJECTS_DIR` plus optional `PROJECTS_DIR_<X>`; each becomes nginx variable `$projects_dir[_x]`.
- **Thread safety is per PHP version** (`threadSafety` in settings.json) - real installs mix TS and NTS.
- Absolute-path check pattern: `(?<![A-Za-z0-9])[A-Za-z]:[/\]` (plain `[A-Za-z]:` matches `https://`).
- `start-all.ps1` / `stop-all.ps1` accept `-NoElevate` for diagnostics and automated checks.

## Global Constraints

- **No absolute paths in any project file.** `{base}` (the install folder) is always computed at runtime — PowerShell: `Split-Path` from `$PSScriptRoot`; PHP: `dirname(__DIR__, N)`. Anything outside `{base}` comes from `.env`. The v1 folder is passed only as a `-Source` argument to the migration script and is never stored or modified.
- **Path rules inside nginx configs** (nginx is always started with `-p {base}/`, so relative paths resolve against `{base}` for `root`/logs and against `{base}/conf` for `include`/`ssl_certificate`):
  - certificates: `ssl_certificate ../ssl/<domain>/ssl.crt/server.crt;` `ssl_certificate_key ../ssl/<domain>/ssl.key/server.key;`
  - includes: `include sites/<file>.inc;`
  - logs: `logs/<label>-error.log` (as v1)
  - manager vhost: `root manager/public;`
  - site roots: `root $projects_dir/<path relative to PROJECTS_DIR>;` — `$projects_dir` is defined in the **generated, gitignored** `conf/generated/env.conf` (`map $host $projects_dir { default "<PROJECTS_DIR from .env>"; }`). Site roots must live under `PROJECTS_DIR`; the UI rejects others with "Move the project under PROJECTS_DIR or change PROJECTS_DIR in .env".
- **`.env` keys** (`.env.example` committed with these keys, comments and empty/default values; `.env` gitignored):
  ```
  # Folder that contains all your projects. Site roots are stored relative to it.
  PROJECTS_DIR=
  # Extra folders prepended to PATH for php-cgi (e.g. Oracle Instant Client). Separate with ';'
  PHP_EXTRA_PATH=
  # Optional: full path to openssl.exe. Empty = auto-detect (%ProgramFiles%\Git\usr\bin, then PATH)
  OPENSSL_BIN=
  # PHP build flavour used for downloads: ts or nts
  PHP_THREAD_SAFETY=ts
  NGINX_HTTP_PORT=80
  NGINX_HTTPS_PORT=443
  MANAGER_HOST=127.0.0.1
  MANAGER_PORT=8080
  ```
  Other system locations are derived from Windows environment variables, never hard-coded: hosts file = `%SystemRoot%\System32\drivers\etc\hosts`; Git's OpenSSL = `%ProgramFiles%\Git\usr\bin\openssl.exe`.
- `.env` loaders: PHP `Env::load(string $file)` / `Env::get(string $key, ?string $default = null)`; PowerShell `Import-DotEnv` in Common.psm1. Format: `KEY=value`, `#` comments, optional surrounding quotes, no variable expansion. A missing `.env` → scripts and UI stop with "Copy .env.example to .env and fill it in".
- **Site/machine data is never committed:** `conf/sites/*` (except `_template.conf.example`), `ssl/*` (except `configuration.conf`), all of `data/` (`settings.json`, `groups.json`, jobs, cache), `.env`, logs, backups, generated configs, `php/`, `nginx.exe`. A fresh clone + `.env` + `start-all.ps1` must work on a new machine: missing `data/settings.json` is created by scanning `php\*\php-cgi.exe`, missing `groups.json` is seeded from existing sites, missing `ssl/localhost` cert is generated.
- Test fixtures are sanitised: domains `*.example.test`, roots `$projects_dir/demo/...` — no real client domains or project names.
- Tests never use real machine paths: they build trees under `sys_get_temp_dir()` / `$TestDrive`.
- **Every file starts with a header comment** (who/what/why). Formats:
  - PHP: `/** File: <relative path>\n * Purpose: …\n * Responsibilities: …\n * Used by: …\n * Notes: … */`
  - PowerShell: comment-based help `<# .SYNOPSIS / .DESCRIPTION / .PARAMETER / .NOTES (Requires Admin: yes/no; Called by: …) #>`
  - Views/CSS/JS: `<?php /* View: … | Data: $vars it expects */ ?>` / `/* File: … Purpose: … */`
  - Generated nginx files: `# GENERATED by manager/bin/manager.php config:write — DO NOT EDIT`
- All files written by any tool are **UTF-8 without BOM** (PowerShell: `[IO.File]::WriteAllText($p, $t, (New-Object Text.UTF8Encoding $false))`; never `Set-Content -Encoding UTF8`). JSON readers strip a leading BOM defensively.
- PowerShell `ConvertTo-Json` always with `-Depth 10`.
- Paths inside nginx configs use forward slashes.
- PHP builds: x64, **thread-safe (`ts`)** to match the existing installs (`php8ts.dll`); value comes from `.env` `PHP_THREAD_SAFETY`.
- PHP release feed: `https://downloads.php.net/~windows/releases/releases.json`; zip download base: `https://downloads.php.net/~windows/releases/`. Feed keys look like `ts-vs16-x64`, `ts-vc15-x64`, `ts-vs17-x64` — match with `^ts-v[cs]\d+-x64$`.
- PECL extension builds: `https://downloads.php.net/~windows/pecl/releases/<ext>/<ver>/` (Apache directory listing, no checksums — HTTPS only); zip name pattern `php_<ext>-<ver>-<branch>-ts-v[cs]<n>-x64.zip`.
- Every `php.ini` write: backup to `{base}/backups/php-ini/<id>/<YYYYmmdd-His>.ini` → write → validate with `php\<id>\php.exe -c <ini> -v` (any `Warning`/`Unable to load` in output = fail) → rollback on failure.
- Port convention: `9000 + digits(id)` for `major.minor` (8.4 → 9084); fall back to next free port ≥ 9100 on collision. Upstream name: `php` + digits (`8.5.12` → `php8512`).
- Manager UI listens on `127.0.0.1:8080` only (it runs as Admin — never expose on LAN).
- Every POST carries a CSRF token; every output is escaped with `e()` (`htmlspecialchars`, ENT_QUOTES).
- Every write to an nginx config: backup → write → `nginx -t` → rollback on failure. Backups go to `{base}/backups/sites/<site-id>/<YYYYmmdd-His>.conf`.
- Shell commands from PHP go through `Shell::run(array $argv)` (no string concatenation of user input into commands).
- Frameworks supported (same as v1): `general`, `laravel` (v1 stored this as `generic` — treat as alias), `symfony`, `wordpress`, `codeigniter`.
- Test runner (PHP): `php\8.3\php.exe tests\run.php` from `{base}`. Pester: `Invoke-Pester tests\ps`.

## Review Focus

1. **Hand-edited site configs** (e.g. `hylecapl-dev.net.conf` with `include …rewrites.inc`, `deny`, comments) — structured edits must change only the targeted directive and keep every other byte; if the shape is unknown, refuse and point to the raw editor (test in Task 4).
2. **Updating the PHP version the manager itself runs on (8.3)** — the request that started it dies; the job must keep running detached and the job page must recover after reconnecting (test in Task 8 via `JobRunner` detaching + log offset polling).
3. **Invalid config saved from the raw editor** — nginx must keep running on the previous file; user sees `nginx -t` output (test in Task 4 `SiteRepository` rollback).
4. **Path traversal / bad ids in routes** (`/sites/..%2f..%2fnginx.conf`) — site ids must validate against `^[a-z0-9*.-]+$` and resolve inside `conf/sites` (test in Task 3 `Validator` + Task 5).
5. **v1 absolute paths** left in migrated configs (`<v1 folder>/ssl/...`, `<projects folder>/...`) — after migration no committed or site file may contain a drive-letter path; v2 must not silently keep serving v1 certificates (test in Task 1 `Convert-V1Paths`; Task 11 greps for `[A-Za-z]:[/\\]`).
6. **Enabling an extension whose DLL can't load** (wrong toolset, missing dependency like ImageMagick DLLs) — php.ini must be rolled back and the PHP process left running on the old ini, with PHP's warning shown (test in Task 9 `ExtensionManager`).

---

## File Structure

```
nginx-manager-2.0/
├── nginx.exe, php/, ssl/, logs/, temp/, html/, docs/   ← runtime copied from v1 (Task 1)
├── .env                           ← machine-specific values (gitignored)
├── .env.example                   ← committed template
├── conf/
│   ├── nginx.conf                 ← modified: includes generated/*.conf, manager/*.conf, sites/*.conf
│   ├── generated/upstreams.conf   ← GENERATED from settings
│   ├── generated/env.conf         ← GENERATED from .env ($projects_dir map; gitignored)
│   ├── manager/manager.conf       ← GENERATED UI vhost (127.0.0.1:8080)
│   └── sites/*.conf | *.conf.disabled
├── data/
│   ├── settings.json              ← single source of truth
│   ├── groups.json                ← groups + site→group assignments
│   ├── cache/php-releases.json    ← cached feed
│   └── jobs/<id>.json, <id>.log   ← background job state + log
├── backups/                       ← config backups, old PHP folders, v1 leftovers
├── start-all.ps1, stop-all.ps1, restart-all.ps1, reload-nginx.ps1
├── scripts/
│   ├── lib/Common.psm1            ← shared PS functions
│   ├── migrate-from-v1.ps1
│   ├── php-service.ps1            ← start/stop/restart one PHP version
│   ├── install-php.ps1            ← install / update a PHP version (job)
│   ├── install-extension.ps1      ← download + install a PECL extension (job)
│   └── install-autostart.ps1      ← scheduled task, run elevated at logon
├── manager/
│   ├── public/index.php           ← front controller (nginx root)
│   ├── public/assets/app.css, app.js
│   ├── bin/manager.php            ← CLI: config:write, groups:seed
│   ├── src/bootstrap.php          ← autoloader, error handling, helpers e(), url()
│   ├── src/Http/{Router,Request,Response,Csrf,Flash}.php
│   ├── src/Support/{Env,Paths,Settings,JsonStore,Shell,ShellResult,Validator,View}.php
│   ├── src/Nginx/{SiteConfigParser,ParsedSite,SiteConfigBuilder,SiteSpec,SiteConfigEditor,
│   │              UnsupportedConfigException,SiteRepository,Site,NginxControl,NginxConfigWriter}.php
│   ├── src/Hosts/HostsFile.php
│   ├── src/Groups/GroupRepository.php
│   ├── src/Ssl/{CertificateService,CertInfo}.php
│   ├── src/Php/{PhpReleaseFeed,PhpVersionRepository,UpdateChecker,JobRunner,
│   │            PhpIni,ExtensionManager,PeclCatalog}.php
│   ├── src/Status/ServiceStatus.php
│   ├── src/Controllers/{Dashboard,Sites,Groups,Ssl,Php,Extensions,Jobs,Nginx}Controller.php
│   └── views/layout.php, partials/{sidebar,flash,job-poller}.php,
│       dashboard.php, sites/{index,create,show}.php, groups/index.php,
│       ssl/index.php, php/index.php, extensions/index.php, jobs/show.php, errors/404.php
├── tests/
│   ├── run.php, bootstrap.php     ← tiny assertion runner
│   ├── fixtures/sites/*.conf, fixtures/releases.json, fixtures/hosts.txt
│   ├── **/*Test.php
│   └── ps/*.Tests.ps1             ← Pester
└── README.md
```

---

### Task 1: Project bootstrap + migration from v1

**Files:**
- Create: `.gitignore`, `.env.example`, `scripts/lib/Common.psm1` (migration + env helpers for now), `scripts/migrate-from-v1.ps1`, `tests/ps/Migration.Tests.ps1`

**Interfaces:**
- Produces (Common.psm1): `Get-ManagerBase() -> string` (folder above `scripts/`, computed from `$PSScriptRoot`); `Import-DotEnv([string]$Path) -> hashtable` (also sets process env vars); `Get-ManagerEnv() -> hashtable` (loads `{base}\.env`, throws the "Copy .env.example…" message if missing); `ConvertFrom-V1PhpVersions([string]$Value) -> [pscustomobject[]]{ id; port; upstream }`; `Convert-V1Paths([string]$Text, [string]$V1Base, [string]$ProjectsDir) -> string`; `Write-Utf8NoBom([string]$Path, [string]$Text)`; `Get-ManagerSettings() -> pscustomobject`; `Save-ManagerSettings($Settings)`.
- Produces `data/settings.json` — app state only, no paths, no ports from `.env`:
```json
{
  "php": {
    "managerVersion": "8.3",
    "feedUrl": "https://downloads.php.net/~windows/releases/releases.json",
    "downloadBase": "https://downloads.php.net/~windows/releases/",
    "peclBase": "https://downloads.php.net/~windows/pecl/releases/",
    "versions": [ { "id": "8.3", "port": 9083, "upstream": "php83", "version": "8.3.0" } ]
  }
}
```
- Produces `.env` (filled from v1 `config.bat`: `ORACLE_IC_DIR` → `PHP_EXTRA_PATH`; `PROJECTS_DIR` = the common parent of all v1 site roots — v1's stale `GIT_DIR` setting is ignored; thread safety detected).

- [ ] **Step 1: `git init`, write `.gitignore`** — the repo holds **code and templates only; all site/machine data is ignored** so the same repo works on any machine:
  ```gitignore
  # machine config
  .env
  # runtime binaries (installed per machine)
  /nginx.exe
  /php/
  # site data — differs per machine
  /conf/sites/*
  !/conf/sites/_template.conf.example
  !/conf/sites/.gitkeep
  /ssl/*
  !/ssl/configuration.conf
  !/ssl/.gitkeep
  /data/*
  !/data/.gitkeep
  # generated / runtime
  /conf/generated/
  /conf/manager/
  /logs/
  /temp/
  /backups/
  .idea/
  ```
  Add `.gitkeep` to `conf/sites/`, `ssl/`, `data/`. Write `.env.example` exactly as in Global Constraints.

- [ ] **Step 2: Write failing Pester tests** `tests/ps/Migration.Tests.ps1`:
```powershell
Import-Module "$PSScriptRoot\..\..\scripts\lib\Common.psm1" -Force
Describe 'ConvertFrom-V1PhpVersions' {
  It 'parses id:port pairs and derives upstream names' {
    $r = ConvertFrom-V1PhpVersions '7.2:9072 8.5.12:9086'
    $r.Count | Should Be 2
    $r[0].id | Should Be '7.2';    $r[0].port | Should Be 9072; $r[0].upstream | Should Be 'php72'
    $r[1].id | Should Be '8.5.12'; $r[1].upstream | Should Be 'php8512'
  }
}
Describe 'Convert-V1Paths' {
  # Drive letters below are TEST INPUT that simulates v1 files; nothing here is a project path.
  It 'makes certificate paths relative to conf/' {
    Convert-V1Paths 'ssl_certificate  X:/old/ssl/a.test/ssl.crt/server.crt;' 'X:/old' 'Y:/src' |
      Should Be 'ssl_certificate  ../ssl/a.test/ssl.crt/server.crt;'
  }
  It 'makes includes relative to conf/, backslashes and case handled' {
    Convert-V1Paths 'include x:\OLD\conf\sites\a.inc;' 'X:/old' 'Y:/src' | Should Be 'include sites/a.inc;'
  }
  It 'rewrites roots under PROJECTS_DIR to $projects_dir' {
    Convert-V1Paths 'root  Y:/src/backporch/nmc/public;' 'X:/old' 'Y:/src' |
      Should Be 'root  $projects_dir/backporch/nmc/public;'
  }
  It 'leaves roots outside PROJECTS_DIR unchanged (reported by the migration)' {
    Convert-V1Paths 'root  Z:/elsewhere/app;' 'X:/old' 'Y:/src' | Should Be 'root  Z:/elsewhere/app;'
  }
  It 'is idempotent' {
    Convert-V1Paths 'root  $projects_dir/a;' 'X:/old' 'Y:/src' | Should Be 'root  $projects_dir/a;'
  }
}
Describe 'Import-DotEnv' {
  It 'parses comments, quotes and empty values' {
    $p = Join-Path $TestDrive '.env'
    Write-Utf8NoBom $p "# c`nA=1`nB=`"two words`"`nC=`n"
    $e = Import-DotEnv $p
    $e.A | Should Be '1'; $e.B | Should Be 'two words'; $e.C | Should Be ''
  }
}
Describe 'Write-Utf8NoBom' {
  It 'writes without BOM' {
    $p = Join-Path $env:TEMP 'nm2-bom.txt'; Write-Utf8NoBom $p 'x'
    ([IO.File]::ReadAllBytes($p))[0] | Should Be 120
  }
}
```

- [ ] **Step 3: Run** `powershell -NoProfile -Command "Invoke-Pester tests\ps"` → FAIL (module missing).

- [ ] **Step 4: Implement the functions in `Common.psm1`.** `Convert-V1Paths`: per directive line (`ssl_certificate`, `ssl_certificate_key`, `include`, `root`), normalise the value's backslashes to `/`, then case-insensitively strip `<V1Base>/conf/` → `` (includes), `<V1Base>/` → `../` (anything else inside v1, e.g. ssl), `<ProjectsDir>/` → `$projects_dir/` (roots only). Comment lines untouched.

- [ ] **Step 5: Run Pester** → PASS.

- [ ] **Step 6: Implement `scripts/migrate-from-v1.ps1 -Source <path> [-SkipPhp] [-Force]`** (no admin needed). In order:
  0. Parameters: `-Source <v1 folder>` (required), `-ProjectsDir <folder>` (optional; default = common parent of v1 roots). Neither is stored anywhere except `PROJECTS_DIR` in `.env`.
  1. Refuse if `data/settings.json` or `.env` exists unless `-Force`.
  2. `robocopy` (`/E /NFL /NDL /NJH`): `nginx.exe`, `conf/` (exclude `site-manager.conf`, `*.bak*`), `ssl/`, `html/50x.html`, `html/index.html`, `docs/`, `contrib/`; `php/` unless `-SkipPhp`, excluding folders matching `*backup*`. Treat robocopy exit codes < 8 as success.
  3. Copy v1 `conf/sites/*.bak*` and `conf/*.bak*` to `backups/v1/` (not into `conf/`).
  4. Create empty `logs/`, `temp/`, `data/cache/`, `data/jobs/`, `backups/sites/`, `conf/generated/`, `conf/manager/`.
  5. Run `Convert-V1Paths` over `conf/sites/*.conf`, `*.inc` and `conf/nginx.conf`; print each changed file; print a **warning list** of any line still containing `[A-Za-z]:[/\\]` (roots outside PROJECTS_DIR) for the user to fix.
  6. Parse v1 `config.bat` (`PHP_VERSIONS`, `ORACLE_IC_DIR`) → settings + `.env`; detect each version with `php\<id>\php.exe -n -r "echo PHP_VERSION;"`; `PHP_THREAD_SAFETY` = `ts` if `php\<id>\php*ts.dll` exists else `nts`; `managerVersion` = `8.3` if installed else highest id.
  7. Write `.env` from `.env.example` with the values filled in; save settings with `Save-ManagerSettings`.

- [ ] **Step 7: Run it** from `{base}`: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\migrate-from-v1.ps1 -Source ..\nginx-manager` (relative argument works).
  Expected: `data\settings.json` lists 8 versions (7.2 … 8.5.12) with detected versions (e.g. 8.3 → `8.3.0`, 8.4 → `8.4.27`); `.env` has `PROJECTS_DIR` set; `git grep -nE "[A-Za-z]:[/\\\\]"` (committed files) returns nothing and `grep -rnE "[A-Za-z]:[/\\\\]" conf/sites` returns nothing.

- [ ] **Step 8: Commit** `git add -A && git commit -m "chore: bootstrap v2 and migrate runtime from v1"`

---

### Task 2: PowerShell service scripts (elevated start/stop/reload, per-PHP control, autostart)

**Files:**
- Modify: `scripts/lib/Common.psm1`
- Create: `start-all.ps1`, `stop-all.ps1`, `restart-all.ps1`, `reload-nginx.ps1`, `scripts/php-service.ps1`, `scripts/install-autostart.ps1`, `tests/ps/Common.Tests.ps1`

**Interfaces:**
- Produces (Common.psm1): `Test-IsAdmin() -> bool`; `Invoke-SelfElevate([string]$ScriptPath, [string[]]$Arguments)` (relaunch `powershell -NoProfile -ExecutionPolicy Bypass -File … ` with `-Verb RunAs`, then `exit`); `Test-PortListening([int]$Port) -> bool`; `Get-PortOwnerProcess([int]$Port) -> Process|null` (via `Get-NetTCPConnection -State Listen -LocalPort`); `Get-PhpVersionById([string]$Id) -> pscustomobject`; `Get-UpstreamName([string]$Id)`, `Get-DefaultPhpPort([string]$Id, [int[]]$UsedPorts) -> int`; `Start-PhpCgi($Version)`, `Stop-PhpCgi($Version)`; `Write-Step([string]$Message, [ValidateSet('info','ok','warn','error')]$Level)`; `Write-JobLog([string]$JobId, [string]$Message)`; `Set-JobState([string]$JobId, [ValidateSet('running','done','failed')]$State, [string]$Message)` (merges into `data/jobs/<id>.json`).
- Script CLIs: `php-service.ps1 -Action start|stop|restart -Id <id> [-JobId <id>]`; `start-all.ps1 [-NoPause] [-Open]`; `install-autostart.ps1 [-Remove]`.
- Consumes: `bin/manager.php config:write` (Task 3) — `start-all.ps1` calls it; until Task 3 lands guard with `if (Test-Path …)`.

- [ ] **Step 1: Failing Pester tests** `tests/ps/Common.Tests.ps1`:
```powershell
Describe 'Get-DefaultPhpPort' {
  It 'uses 90XX for major.minor'        { Get-DefaultPhpPort '8.4' @() | Should Be 9084 }
  It 'falls back to >=9100 on collision' { Get-DefaultPhpPort '8.4' @(9084) | Should Be 9100 }
  It 'skips used fallback ports'         { Get-DefaultPhpPort '8.4' @(9084,9100) | Should Be 9101 }
}
Describe 'Get-UpstreamName' { It 'strips dots' { Get-UpstreamName '8.5.12' | Should Be 'php8512' } }
Describe 'Set-JobState' {
  It 'creates and merges job json without BOM' {
    Set-JobState 'pester1' 'running' 'step 1'; Set-JobState 'pester1' 'done' 'ok'
    $p = Join-Path (Get-ManagerBase) 'data\jobs\pester1.json'
    (Get-Content $p -Raw | ConvertFrom-Json).state | Should Be 'done'
    ([IO.File]::ReadAllBytes($p))[0] | Should Be 123
    Remove-Item $p
  }
}
```
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** the functions above. `Start-PhpCgi`: skip if port already listening; `$env:PHP_FCGI_MAX_REQUESTS='0'`; prepend `.env` `PHP_EXTRA_PATH` entries to `$env:PATH`; `Start-Process "<base>\php\<id>\php-cgi.exe" -ArgumentList "-b 127.0.0.1:<port>" -WorkingDirectory … -WindowStyle Hidden`; wait up to 5 s for the port. `Stop-PhpCgi`: stop the owner process of that port **only if its Path is under `<base>\php\`** (never kill v1 or unrelated php-cgi). **Step 4: Run** → PASS.

- [ ] **Step 5: Implement scripts.**
  - `Initialize-ManagerSettings` (Common.psm1): when `data/settings.json` is missing, scan `php\*\php-cgi.exe` (skip folders matching `*backup*`, `*.new`), detect each version, assign ports with `Get-DefaultPhpPort`, `managerVersion` = `8.3` if present else highest; save. Pester test: a `$TestDrive` tree with fake `php\8.3\php-cgi.exe` and `php\8.4\php-cgi.exe` (version detection mocked) → two entries, ports 9083/9084.
  - `start-all.ps1`: self-elevate → require `.env` → `Initialize-ManagerSettings` if needed → ensure `ssl/localhost` cert (port v1 logic; OpenSSL lookup order: `.env` `OPENSSL_BIN`, `Join-Path $env:ProgramFiles 'Git\usr\bin\openssl.exe'`, `(Get-Command openssl).Source`) → run `config:write` → `Start-PhpCgi` for each version → `nginx.exe -t -p <base>/` (abort with output on failure) → reload if nginx running else `Start-Process nginx.exe -WorkingDirectory <base> -WindowStyle Hidden` → print a table (PHP id, port, status) and `Manager: http://127.0.0.1:8080` → `-Open` opens the browser; pause unless `-NoPause`.
  - `stop-all.ps1`: self-elevate → `nginx -s quit -p <base>/`, after 3 s `Stop-Process` remaining `nginx` whose Path is under base → `Stop-PhpCgi` for each version.
  - `restart-all.ps1`: stop-all then start-all (pass `-NoPause` through).
  - `reload-nginx.ps1`: `nginx -t` then `-s reload`; non-zero exit on failure.
  - `php-service.ps1`: does the action; when `-JobId` given, mirrors output via `Write-JobLog` and finishes with `Set-JobState done|failed`.
  - `install-autostart.ps1`: requires admin; `Register-ScheduledTask -TaskName 'NginxManager2' -Trigger (New-ScheduledTaskTrigger -AtLogOn) -Principal (New-ScheduledTaskPrincipal -UserId $env:USERNAME -RunLevel Highest) -Action (powershell … start-all.ps1 -NoPause)`; `-Remove` unregisters.

- [ ] **Step 6: Manual verification** (stop v1 first: run v1 `stop-all.bat`). Run `.\start-all.ps1` → UAC prompt once → all versions show `running`; `Test-NetConnection 127.0.0.1 -Port 9083` succeeds; `.\stop-all.ps1` → ports closed. Then start again and leave running.

- [ ] **Step 7: Commit** `feat(scripts): elevated PowerShell service scripts`

---

### Task 3: Manager web skeleton — front controller, router, layout with sidebar, generated nginx config

**Files:**
- Create: `manager/src/bootstrap.php`, `manager/src/Http/{Router,Request,Response,Csrf,Flash}.php`, `manager/src/Support/{Settings,JsonStore,Shell,ShellResult,Validator,View}.php`, `manager/src/Nginx/{NginxControl,NginxConfigWriter}.php`, `manager/src/Controllers/{DashboardController,NginxController}.php`, `manager/public/index.php`, `manager/public/assets/{app.css,app.js}`, `manager/bin/manager.php`, `manager/views/{layout.php,dashboard.php,errors/404.php,partials/sidebar.php,partials/flash.php}`, `tests/{run.php,bootstrap.php}`, `tests/Http/RouterTest.php`, `tests/Support/{ValidatorTest,JsonStoreTest}.php`, `tests/Nginx/NginxConfigWriterTest.php`
- Modify: `conf/nginx.conf` — delete the `upstream` blocks; before `include sites/*.conf;` add `include generated/*.conf;` and `include manager/*.conf;` (keeps v1's relative `../ssl/localhost/...` default cert)
- Create also: `manager/src/Support/{Env,Paths}.php`, `tests/Support/PathsTest.php`

**Interfaces:**
- `Router::add(string $method, string $pattern, callable $handler): void` — `{name}` matches `[^/]+`, params passed url-decoded as an assoc array; `Router::dispatch(string $method, string $path): Response` — unknown route → 404 response.
- `Response::html(string $body, int $status=200)`, `Response::redirect(string $to)`, `Response::json(array $data, int $status=200)`; `->send(): void`.
- `Request::fromGlobals()`; `->method`, `->path` (no query, trailing slash trimmed, `/` kept), `->post(string $key, mixed $default=null)`, `->query(…)`.
- `Csrf::token(): string`, `Csrf::verify(?string $token): void` (throws on mismatch); every POST route verified in `index.php` before dispatch.
- `Flash::add(string $type /*success|error|warn|info*/, string $message)`, `Flash::pull(): array`.
- `Env::load(string $file): void` (throws the "Copy .env.example…" message if missing), `Env::get(string $key, ?string $default = null): ?string`, `Env::fromArray(array): void` (tests).
- `Paths` — the only place paths are built: `Paths::base(): string` (`dirname(__DIR__, 3)` from `src/Support`, forward slashes), `Paths::in(string ...$parts): string` (under base), `Paths::projectsDir(): string` (from `.env`, trailing slash trimmed), `Paths::hostsFile(): string` (`getenv('SystemRoot') . '/System32/drivers/etc/hosts'`), `Paths::toProjectRelative(string $abs): ?string` (null when outside PROJECTS_DIR, case-insensitive), `Paths::resolveRoot(string $nginxRoot): string` (expands `$projects_dir`, makes prefix-relative paths absolute — for display, file checks and log reading only; never written to configs), `Paths::overrideBase(string $dir)` (tests).
- `Settings::load(): Settings` (reads `Paths::in('data/settings.json')`); `->phpVersions(): array<array{id,port,upstream,version}>`, `->phpVersion(string $id): ?array`, `->managerVersion()`, `->get(string $dotPath, $default=null)`, `->update(callable $mutator): void` (JsonStore write).
- `JsonStore::read(string $path, array $default=[]): array` (strips BOM), `JsonStore::write(string $path, array $data): void` (pretty JSON, temp file + `rename`, guarded by `flock` on `<path>.lock`).
- `Shell::run(array $argv, ?string $cwd=null, int $timeout=60): ShellResult{ int exitCode; string output }` (proc_open, stderr merged).
- `Validator::domain(string): bool` (`^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$`), `Validator::siteId(string): bool` (`^[a-z0-9*.-]+$`, no `..`), `Validator::absolutePath(string): bool` (`^[A-Za-z]:[\\/]`).
- `View::render(string $template, array $data=[], string $layout='layout'): string`; helpers in bootstrap: `e(?string): string`, `url(string $path): string`, `csrf_field(): string`, `active(string $prefix): string` (returns `active` for sidebar).
- `NginxControl::__construct(Settings, Shell)`; `->test(): ShellResult` (`nginx.exe -t -p <base>/`); `->reload(): ShellResult` (test first, reload only if ok); `->isRunning(): bool`.
- `NginxConfigWriter::upstreams(Settings): string`, `::envMap(string $projectsDir): string`, `::managerVhost(Settings): string` (listen `MANAGER_HOST:MANAGER_PORT` from `.env`), `::writeAll(Settings): void` (writes `conf/generated/upstreams.conf`, `conf/generated/env.conf`, `conf/manager/manager.conf`).
- CLI: `php manager\bin\manager.php config:write` → exit 0 and prints the three relative paths.
- Routes registered here: `GET /` (dashboard placeholder), `POST /nginx/reload`.

- [ ] **Step 1: Write `tests/bootstrap.php` + `tests/run.php`** — global `test(string $name, callable $fn)`, `assertSame($expected, $actual, string $msg='')`, `assertTrue($v, …)`, `assertContains(string $needle, string $haystack)`, `assertThrows(string $class, callable $fn)`; runner globs `tests/**/*Test.php`, prints `PASS/FAIL name`, exits 1 on any failure.

- [ ] **Step 2: Failing tests.**
```php
// tests/Http/RouterTest.php
test('router matches params and decodes them', function () {
    $r = new Router();
    $r->add('GET', '/sites/{id}', fn($req, $p) => Response::html($p['id']));
    assertSame('api.example.test', $r->dispatch('GET', '/sites/api.example.test')->body);
});
test('router returns 404 for unknown path and wrong method', function () {
    $r = new Router(); $r->add('POST', '/groups', fn() => Response::html('x'));
    assertSame(404, $r->dispatch('GET', '/groups')->status);
    assertSame(404, $r->dispatch('GET', '/nope')->status);
});
// tests/Support/ValidatorTest.php
test('domain validation', function () {
    assertTrue(Validator::domain('api.example.test'));
    assertTrue(Validator::domain('*.ncmdev.net'));
    assertTrue(!Validator::domain('localhost'));      // needs a dot
    assertTrue(!Validator::domain('a..b.com'));
    assertTrue(!Validator::domain('API.example.test'));   // lowercase only
});
test('site id rejects traversal', function () {
    assertTrue(Validator::siteId('api.example.test'));
    assertTrue(!Validator::siteId('../nginx'));
    assertTrue(!Validator::siteId('a/b'));
});
// tests/Support/JsonStoreTest.php
test('json store strips BOM and round-trips', function () {
    $p = sys_get_temp_dir() . '/nm2-store.json';
    file_put_contents($p, "\xEF\xBB\xBF{\"a\":1}");
    assertSame(['a' => 1], JsonStore::read($p));
    JsonStore::write($p, ['b' => [1, 2]]);
    assertSame(['b' => [1, 2]], JsonStore::read($p));
});
// tests/Nginx/NginxConfigWriterTest.php
test('upstreams generated from settings', function () {
    Env::fromArray(['MANAGER_HOST' => '127.0.0.1', 'MANAGER_PORT' => '8080']);
    $s = Settings::fromArray([
        'php' => ['managerVersion' => '8.3', 'versions' => [
            ['id' => '8.3', 'port' => 9083, 'upstream' => 'php83', 'version' => '8.3.0'],
            ['id' => '8.5.12', 'port' => 9086, 'upstream' => 'php8512', 'version' => '8.5.12']]]]);
    $out = NginxConfigWriter::upstreams($s);
    assertContains("upstream php8512 {\n    server 127.0.0.1:9086;\n}", $out);
    assertContains('# GENERATED', $out);
    $vh = NginxConfigWriter::managerVhost($s);
    assertContains('listen       127.0.0.1:8080;', $vh);
    assertContains('fastcgi_pass   php83;', $vh);
    assertContains('root  manager/public;', $vh);   // prefix-relative, never absolute
    assertContains("map \$host \$projects_dir {\n    default \"/tmp/projects\";\n}", NginxConfigWriter::envMap('/tmp/projects'));
});
// tests/Support/PathsTest.php
test('project-relative conversion', function () {
    Env::fromArray(['PROJECTS_DIR' => sys_get_temp_dir() . '/proj']);
    assertSame('a/public', Paths::toProjectRelative(sys_get_temp_dir() . '/proj/a/public'));
    assertSame(null, Paths::toProjectRelative(sys_get_temp_dir() . '/other/a'));
    assertSame(sys_get_temp_dir() . '/proj/a', Paths::resolveRoot('$projects_dir/a'));
});
```
(`Settings::fromArray(array)` is a named constructor for tests.)

- [ ] **Step 3: Run** `php\8.3\php.exe tests\run.php` → FAIL.

- [ ] **Step 4: Implement** the classes above. `index.php`: bootstrap → `session_start()` → `Request::fromGlobals()` → if POST `Csrf::verify` → build router via a `routes()` function in `bootstrap.php` (controllers get `Settings` + services by constructor; no container) → wrap dispatch in try/catch: `Throwable` → 500 page showing message (local tool, details are useful). Manager vhost: `try_files $uri /index.php$is_args$args;`.

- [ ] **Step 5: Layout + sidebar.** Keep v1's dark slate palette (`#0f172a` bg, `#1e293b` cards, `#3b82f6` accent). Sidebar (fixed, 240px): brand "Nginx Manager 2.0", links Dashboard `/`, Sites `/sites`, Groups `/groups`, SSL Certificates `/ssl`, PHP Versions `/php` with an updates-count badge, PHP Extensions `/extensions` (pages not built yet render a "coming soon" placeholder) (`$updateCount` passed to layout; 0 hides it), footer form "Reload Nginx" (`POST /nginx/reload`). Main area: page title, flash messages, content. Sketch:
```
┌──────────────┬─────────────────────────────────────────┐
│ Nginx Mgr 2.0│  Dashboard                              │
│              │  [flash messages]                       │
│ ▣ Dashboard  │  ┌────────┐┌────────┐┌────────┐┌───────┐│
│ ◫ Sites      │  │Sites 18││Groups 4││SSL 17  ││PHP 8  ││
│ ◧ Groups     │  └────────┘└────────┘└────────┘└───────┘│
│ 🔒 SSL       │  ...                                    │
│ ⬢ PHP    (3) │                                         │
│ ⧉ Extensions │                                         │
│ [Reload ngx] │                                         │
└──────────────┴─────────────────────────────────────────┘
```

- [ ] **Step 6: Run tests** → PASS. **Step 7: Live check:** `php\8.3\php.exe manager\bin\manager.php config:write`, update `nginx.conf` as above, `.\reload-nginx.ps1`, open `http://127.0.0.1:8080` → layout with sidebar; Reload Nginx button shows a success flash; `http://<LAN-IP>:8080` refused.

- [ ] **Step 8: Commit** `feat(manager): app skeleton, router, layout, generated nginx config`

---

### Task 4: Site config core — parse, build, structured edit, safe save

**Files:**
- Create: `manager/src/Nginx/{ParsedSite,SiteConfigParser,SiteSpec,SiteConfigBuilder,SiteConfigEditor,UnsupportedConfigException,Site,SiteRepository}.php`
- Test: `tests/Nginx/{SiteConfigParserTest,SiteConfigBuilderTest,SiteConfigEditorTest,SiteRepositoryTest}.php`, fixtures `tests/fixtures/sites/` = **sanitised** copies of the migrated configs (same structure, domains/paths renamed): `api.example.test.conf` (from `api.bpmc.net.conf`: SSL + redirect, symfony), `custom.example.test.conf` (from `hylecapl-dev.net.conf`: hand-edited, `include sites/custom.example.test.rewrites.inc`, `deny`, comments containing braces, upstream `php8512`), and one HTTP-only config produced by the builder. The test assertions below use these sanitised names. `SiteRepository` tests run against a temp `{base}` via `Paths::overrideBase()`.

**Interfaces:**
- `ParsedSite` (readonly props): `array $serverNames`, `?string $root`, `?string $upstream`, `bool $ssl`, `?string $certPath`, `?string $keyPath`, `?string $framework` (from header `Framework: x`; `generic` → `laravel`), `?string $projectPath`, `?string $errorLog`, `?string $accessLog`, `bool $hasRedirectBlock`.
- `SiteConfigParser::parse(string $conf): ParsedSite` — main block = first `server { }` that is not only `listen/server_name/return 301`. Brace matching must ignore `{`/`}` inside comments and quoted strings and in regexes like `location ~ \.php$`.
- `SiteSpec` (constructor promoted): `string $domain, array $aliases, string $root, string $phpId, string $upstream, string $framework, bool $ssl, ?string $certPath, ?string $keyPath`.
- `SiteSpec::$root` is **relative to PROJECTS_DIR** (e.g. `backporch/nmc/public`); `$certPath`/`$keyPath` are conf-relative (`../ssl/<domain>/ssl.crt/server.crt`).
- `SiteConfigBuilder::build(SiteSpec $s): string` — v1's output format (header comment lines, per-framework `try_files`, WordPress extra locations, `error_log logs/<label>-error.log`, redirect block when SSL) but with `root  $projects_dir/<root>;`, relative cert paths, and header `#  <domain> → PHP <phpId> (SSL enabled|HTTP only)` / `#  Framework: <fw> | Project: <root relative>`. Output never contains a drive letter.
- `SiteConfigEditor::apply(string $conf, array $changes): string` — keys (all optional): `root`, `upstream`, `serverNames` (string[]), `ssl` (bool) + `certPath`/`keyPath`, `phpLabel` (header text). Throws `UnsupportedConfigException` when a targeted directive is missing or ambiguous (e.g. two non-redirect server blocks with different roots).
- `Site`: `string $id` (filename without `.conf`/`.conf.disabled`), `string $file`, `bool $enabled`, `ParsedSite $parsed`.
- `SiteRepository::__construct(Settings, NginxControl)`; `->all(): Site[]` (sorted by id; skips `_template*`); `->find(string $id): ?Site` (validates id, path must resolve inside `conf/sites`); `->read(string $id): string`; `->create(SiteSpec): Site` (throws `RuntimeException` if file exists); `->save(string $id, string $content): ShellResult` (backup → write → `nginx->test()` → on failure restore previous bytes and return the failing result); `->setEnabled(string $id, bool): void` (rename `.conf` ↔ `.conf.disabled`); `->delete(string $id): void` (move to `backups/deleted/<id>-<ts>.conf`); `->backups(string $id): string[]`.

- [ ] **Step 1: Failing tests (exact assertions):**
```php
test('parses SSL symfony site', function () {
    $p = SiteConfigParser::parse(fixture('sites/api.example.test.conf'));
    assertSame(['api.example.test'], $p->serverNames);
    assertSame('$projects_dir/demo/api/public', $p->root);
    assertSame('../ssl/api.example.test/ssl.crt/server.crt', $p->certPath);
    assertSame('php83', $p->upstream);
    assertTrue($p->ssl); assertTrue($p->hasRedirectBlock);
    assertSame('symfony', $p->framework);
    assertSame('logs/api.example.test-error.log', $p->errorLog);
});
test('parses hand-edited site with include + comments containing braces', function () {
    $p = SiteConfigParser::parse(fixture('sites/custom.example.test.conf'));
    assertSame('php8512', $p->upstream);
    assertSame('$projects_dir/demo/custom', $p->root);
});
test('builder output round-trips through parser and has no drive letters', function () {
    $spec = new SiteSpec('demo.test', ['www.demo.test'], 'demo/public', '8.4', 'php84', 'laravel', false, null, null);
    $conf = SiteConfigBuilder::build($spec);
    $p = SiteConfigParser::parse($conf);
    assertSame(['demo.test', 'www.demo.test'], $p->serverNames);
    assertSame('$projects_dir/demo/public', $p->root);
    assertSame('php84', $p->upstream); assertTrue(!$p->ssl); assertSame('laravel', $p->framework);
    assertSame(0, preg_match('~[A-Za-z]:[/\\\\]~', $conf));
});
test('editor changes upstream and keeps every other byte', function () {
    $in = fixture('sites/custom.example.test.conf');
    $out = SiteConfigEditor::apply($in, ['upstream' => 'php85']);
    assertSame(str_replace('fastcgi_pass   php8512;', 'fastcgi_pass   php85;', $in), $out);
});
test('editor changes root only in main block', function () { /* api fixture: root replaced once; redirect block untouched */ });
test('editor turns SSL on for HTTP-only site', function () {
    $http = SiteConfigBuilder::build(new SiteSpec('demo.test', [], 'd', '8.3', 'php83', 'general', false, null, null));
    $out  = SiteConfigEditor::apply($http, ['ssl' => true, 'certPath' => '../ssl/demo.test/ssl.crt/server.crt', 'keyPath' => '../ssl/demo.test/ssl.key/server.key']);
    $p = SiteConfigParser::parse($out);
    assertTrue($p->ssl); assertTrue($p->hasRedirectBlock);
    assertSame('../ssl/demo.test/ssl.crt/server.crt', $p->certPath);
});
test('editor turns SSL off and removes redirect block', function () { /* api fixture → listen 80, no ssl_ lines, no return 301 block */ });
test('editor refuses ambiguous config', function () {
    assertThrows(UnsupportedConfigException::class, fn() => SiteConfigEditor::apply("server { listen 80; }", ['root' => 'x']));
});
test('repository rolls back when nginx -t fails', function () {
    // SiteRepository with a fake NginxControl whose test() returns exitCode 1, output "emerg: unexpected }"
    // save('demo.test', 'broken') → returns exitCode 1; file content equals the original; a backup file exists.
});
test('repository refuses ids outside conf/sites', function () {
    assertSame(null, $repo->find('../nginx'));
});
```
(`fixture(string $rel): string` helper lives in `tests/bootstrap.php`. `NginxControl` gets an interface-free fake by subclassing with an overridden `test()`.)

- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** Parser approach: a small tokenizer that walks the text tracking `#`-comments (to end of line), quotes, and `{ }` depth to produce top-level `server` block spans; directive lookup by regex within a block's span, ignoring commented lines. The editor works on those spans and replaces only matched directive values (`preg_replace` with a limit inside the span) so untouched text is byte-identical. **Step 4: Run** → PASS.

- [ ] **Step 5: Commit** `feat(nginx): site config parser, builder, editor, safe repository`

---

### Task 5: Sites pages — grouped list, create, details/edit, logs, hosts file

**Files:**
- Create: `manager/src/Hosts/HostsFile.php`, `manager/src/Controllers/SitesController.php`, `manager/views/sites/{index,create,show}.php`, `tests/Hosts/HostsFileTest.php`, `tests/fixtures/hosts.txt`
- Modify: `manager/src/bootstrap.php` (routes), `manager/public/assets/app.js` (list filter, tabs)

**Interfaces:**
- Consumes: `SiteRepository`, `SiteConfigBuilder/Editor`, `NginxControl` (Task 4), `GroupRepository` (Task 6 — until then pass `null` and render everything under "Ungrouped"), `CertificateService::generate()` (Task 7 — until then the SSL toggle on create is disabled with a "coming in SSL page" note).
- `HostsFile::__construct(?string $path = null)` (null → `Paths::hostsFile()`; tests pass a temp file); `->has(string $domain): bool` (any non-comment line mapping it); `->add(string $domain): void` appends `127.0.0.1\t<domain>\t# nginx-manager`; `->remove(string $domain): void` removes only lines carrying `# nginx-manager`; preserves existing line endings; throws `RuntimeException('Editing the hosts file needs Administrator — start the server with start-all.ps1')` when not writable.
- `tailFile(string $path, int $lines = 200): string` helper in bootstrap (reads backwards in 8 KB chunks; never loads whole file).
- Routes:
  - `GET /sites` — grouped list + search box
  - `GET /sites/new`, `POST /sites`
  - `GET /sites/{id}` — details (tabs: Overview, Config, Logs, Backups)
  - `POST /sites/{id}` — structured update (serverNames, root, phpId, group, ssl)
  - `POST /sites/{id}/raw` — raw config save
  - `POST /sites/{id}/toggle`, `POST /sites/{id}/delete` (options: remove hosts entry)

- [ ] **Step 1: Failing HostsFile tests**
```php
test('hosts add/has/remove only touches our marker lines', function () {
    $p = tempCopy(fixture('hosts.txt')); // contains "127.0.0.1 api.example.test" WITHOUT marker
    $h = new HostsFile($p);
    assertTrue($h->has('api.example.test'));
    $h->add('demo.test'); assertTrue($h->has('demo.test'));
    $h->remove('api.example.test'); assertTrue($h->has('api.example.test')); // not ours → kept
    $h->remove('demo.test');    assertTrue(!$h->has('demo.test'));
});
test('hosts ignores commented entries', function () { /* "# 127.0.0.1 old.test" → has('old.test') false */ });
```
- [ ] **Step 2: Run → FAIL; Step 3: implement HostsFile; Step 4: Run → PASS.**

- [ ] **Step 5: Implement controller + views.**
  - **List** — one collapsible section per group (group colour dot, name, count), rows: domain (link), PHP id, SSL badge, enabled/disabled badge, root (muted, truncated). Search box filters rows client-side. "+ New Site" button.
    ```
    Sites                                      [search…] [+ New Site]
    ● bpmc.net (7)
      api.example.test        PHP 8.3   SSL   backporch/…/public
      admin.bpmc.net      PHP 8.3   SSL   …
    ● ncmdev.net (3)
      …
    ○ Ungrouped (2)
    ```
  - **Root input** everywhere (create + details): shown as a fixed prefix label `PROJECTS_DIR /` followed by a text box for the relative part; pasting an absolute path inside PROJECTS_DIR is converted with `Paths::toProjectRelative`, one outside is rejected with the message from Global Constraints. Display uses `Paths::resolveRoot` only to show "folder exists / missing".
  - **Create** — fields: domain, aliases (space separated), root, PHP version (select from `Settings::phpVersions()`; shows `id → port`), framework, group (select + "new group…" text input), SSL toggle, "add to hosts file" (default on). Server-side: validate (domain, aliases, absolute root; warn-not-block if root folder missing), build → `create` → `nginx->test()` (delete the new file and show output on failure) → hosts → reload → redirect to `/sites/{id}` with flash.
  - **Details** — Overview tab: editable form (domains, root, PHP select, group select, SSL toggle with cert status + "Generate certificate" link to `/ssl?domain=`), read-only framework/project/log paths, "Open site" link (`https://` if SSL). Submit → `SiteConfigEditor::apply` → `save` → reload. On `UnsupportedConfigException` flash: "This config was hand-edited in a way the form can't change safely — use the Config tab." Config tab: textarea with raw file, Save (runs the same safe save; shows `nginx -t` output on failure and keeps the user's text in the textarea). Logs tab: last 200 lines of error/access log (paths resolved relative to `{base}` when not absolute). Backups tab: list of backups with "restore" (POST raw with backup content). Danger zone: disable/enable, delete (+ remove hosts entry checkbox).
  - Every successful change triggers `NginxControl::reload()`; if reload output is not ok, flash it as error.

- [ ] **Step 6: Live check** — create `demo-v2.test` (HTTP, root = any small project folder under PROJECTS_DIR; the written file shows `root  $projects_dir/…;`) → opens in browser; change PHP to 8.4 → file shows `fastcgi_pass php84;`; paste broken text in Config tab → error shown, site still served; delete it → hosts entry gone, backup exists. Browse `/sites/..%2f..%2fnginx.conf` → 404.

- [ ] **Step 7: Commit** `feat(sites): grouped list, create, editable details, logs, hosts`

---

### Task 6: Groups

**Files:**
- Create: `manager/src/Groups/GroupRepository.php`, `manager/src/Controllers/GroupsController.php`, `manager/views/groups/index.php`, `tests/Groups/GroupRepositoryTest.php`
- Modify: `manager/bin/manager.php` (add `groups:seed`), `SitesController` (group select now live), `manager/src/bootstrap.php` (routes)

**Interfaces:**
- `data/groups.json`: `{ "groups": [ {"id": "bpmc-net", "name": "bpmc.net", "color": "#3b82f6"} ], "assignments": { "api.bpmc.net": "bpmc-net" } }`
- `GroupRepository::__construct(string $path)`; `->all(): array` (ordered by name); `->find(string $id): ?array`; `->create(string $name, string $color = '#3b82f6'): array` (id = slug of name, `-2` suffix on clash; name 1–40 chars, unique case-insensitive; colour `^#[0-9a-f]{6}$`); `->update(string $id, string $name, string $color): void`; `->delete(string $id): void` (its sites become unassigned); `->assign(string $siteId, ?string $groupId): void`; `->groupOf(string $siteId): ?string`; `->forgetSite(string $siteId): void` (called by site delete); `->seedFromSites(string[] $siteIds): int` (only when file absent: one group per registrable suffix = last two labels, e.g. `api.bpmc.net` → `bpmc.net`; returns groups created).
- Routes: `GET /groups`, `POST /groups`, `POST /groups/{id}`, `POST /groups/{id}/delete`, `POST /groups/{id}/assign` (multi-select of site ids).

- [ ] **Step 1: Failing tests** — create gives slug id + rejects duplicate name (`Alpha.test` vs `alpha.test`) and bad colour; delete unassigns its sites; seed: `['api.alpha.test','www.alpha.test','one.beta.test','gamma-dev.test']` → groups `alpha.test`, `beta.test`, `gamma-dev.test`; returns 3; second seed call returns 0.
- [ ] **Step 2: Run → FAIL; Step 3: implement; Step 4: Run → PASS.**
- [ ] **Step 5: Page** — cards per group: colour, name, site count, inline rename/colour form, delete (with confirm text in-page, not `confirm()`), "Assign sites" multi-select. "New group" form at top. Ungrouped card listing unassigned sites.
- [ ] **Step 6: Run** `php\8.3\php.exe manager\bin\manager.php groups:seed`; check `/sites` shows grouped sections; create group "Clients"; move a site into it from the site details page and from the Groups page.
- [ ] **Step 7: Commit** `feat(groups): group management and grouped site list`

---

### Task 7: SSL Certificates page

**Files:**
- Create: `manager/src/Ssl/{CertInfo,CertificateService}.php`, `manager/src/Controllers/SslController.php`, `manager/views/ssl/index.php`, `tests/Ssl/CertificateServiceTest.php`
- Modify: `SitesController` (enable SSL toggle on create/details), routes

**Interfaces:**
- `CertInfo` (readonly): `string $domain`, `string $certPath`, `string $keyPath`, `?DateTimeImmutable $notAfter`, `?int $daysLeft`, `?string $serialHex`, `array $sans`, `bool $trusted`, `array $usedBy` (site ids whose `certPath` matches).
- `CertificateService::__construct(Settings, Shell)`; `->opensslPath(): ?string` (`.env` `OPENSSL_BIN` → `getenv('ProgramFiles') . '/Git/usr/bin/openssl.exe'` → `where openssl`); the certificate files are always addressed via `Paths::in('ssl', $domain, …)` and written into configs as `../ssl/<domain>/…`; `->list(array $sites): CertInfo[]` (folders under `ssl/` with `ssl.crt/server.crt`, parsed with `openssl_x509_parse`); `->generate(string $domain, array $extraSans = []): array` (log lines `[level, message]`; SANs = domain, `*.domain`, localhost, extras; template = `ssl/configuration.conf`; 3650 days; deletes old cert and untrusts old serial first — v1 behaviour); `->trust(string $domain): ShellResult` (`certutil -addstore Root <crt>`); `->untrust(string $domain): ShellResult` (`certutil -delstore Root <serialHex>`); `->isTrusted(string $serialHex): bool` (`certutil -store Root <serialHex>` exit 0; memoised per request); `->delete(string $domain): void` (refuses when `usedBy` non-empty or domain is `localhost`).
- `CertificateService::buildOpensslConfig(string $template, string $domain, array $sans): string` — pure, tested.
- Routes: `GET /ssl` (optional `?domain=` prefills form), `POST /ssl/generate` (fields: domain, extra SANs, trust (default on), apply-to-site select), `POST /ssl/{domain}/trust`, `POST /ssl/{domain}/untrust`, `POST /ssl/{domain}/delete`.

- [ ] **Step 1: Failing tests** — `buildOpensslConfig` sets `commonName_default = demo.test` and appends `DNS.1 = demo.test`, `DNS.2 = *.demo.test`, `DNS.3 = localhost`, `DNS.4 = extra.test`; `generate` with a fake Shell writing files → returns success log and calls `certutil -addstore` only when asked; `delete` throws when cert used by a site.
- [ ] **Step 2: Run → FAIL; Step 3: implement; Step 4: Run → PASS.**
- [ ] **Step 5: Page** — table: domain, expiry (red < 30 days, amber < 90), trusted badge, used-by sites (links), actions (Regenerate, Trust/Untrust, Delete). Generate form above. "Apply to site" uses `SiteConfigEditor::apply(['ssl'=>true,…])` → safe save → reload. Show the generation log in a collapsible panel after POST.
- [ ] **Step 6: Live check** — generate for `demo-v2.test`, apply to that site → `https://demo-v2.test` loads with no browser warning (after browser restart); Untrust → badge flips; certmgr.msc confirms.
- [ ] **Step 7: Commit** `feat(ssl): certificate management page`

---

### Task 8: PHP Versions & Updates page + background jobs + installer script

**Files:**
- Create: `manager/src/Php/{PhpReleaseFeed,PhpVersionRepository,UpdateChecker,JobRunner}.php`, `manager/src/Controllers/{PhpController,JobsController}.php`, `manager/views/php/index.php`, `manager/views/jobs/show.php`, `manager/views/partials/job-poller.php`, `scripts/install-php.ps1`, `tests/Php/{PhpReleaseFeedTest,UpdateCheckerTest,JobRunnerTest}.php`, `tests/fixtures/releases.json` (trimmed copy of the real feed: 7.4, 8.3, 8.4, 8.6)
- Modify: `manager/views/partials/sidebar.php` (badge), routes

**Interfaces:**
- `PhpReleaseFeed::__construct(string $url, string $cachePath, int $ttl = 21600, ?callable $fetch = null)`; `->releases(string $threadSafety, bool $refresh = false): array<string, array{branch:string, version:string, zipPath:string, sha256:string, buildKey:string}>` keyed by branch; on network failure returns stale cache + sets `->lastError()`; `->cachedOnly(string $threadSafety): array` (never touches network — for dashboard/sidebar); `->checkedAt(): ?DateTimeImmutable`.
- `PhpVersionRepository::__construct(Settings, Shell)`; `->installed(): array<array{id, branch, port, upstream, version, path, running:bool, isManager:bool}>` (`branch` = `^\d+\.\d+` of id; `running` via `fsockopen('127.0.0.1', port, …, 0.2)`).
- `UpdateChecker::compare(array $installed, array $releases): array{updates: list<array{id, current, latest, release}>, current: list<…>, eol: list<…>, available: list<release>}` — `eol` when branch not in feed; `available` = feed branches with no installed id equal to the branch.
- `JobRunner::__construct(string $jobsDir, string $baseDir)`; `->start(string $script, array $args, string $title): string` (writes `<id>.json` `{id,title,state:'queued',createdAt}`, launches `cmd /c start "" /B powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File scripts\<script> -JobId <id> -<k> <v>…` via `proc_open` with NUL handles, returns immediately); `->status(string $id): ?array`; `->log(string $id, int $offset): array{text:string, offset:int, state:string}`; `->recent(int $n = 10): array`. Job id = `date('Ymd-His') . '-' . bin2hex(random_bytes(3))`; validated `^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$` on read.
- `install-php.ps1 -JobId <id> -Mode install|update -Id <id> -Version <x.y.z> -ZipPath <feed path> -Sha256 <hex>`:
  1. Download `downloadBase + ZipPath` to `temp\php-<version>.zip` (`[Net.ServicePointManager]::SecurityProtocol = Tls12`; `Invoke-WebRequest -UseBasicParsing`); compare `Get-FileHash -Algorithm SHA256` (case-insensitive) — mismatch → failed.
  2. `Expand-Archive` to `php\<id>.new`; run `php\<id>.new\php.exe -n -v` — non-zero → fail with "Install the latest Microsoft Visual C++ Redistributable (x64)".
  3. **update:** copy old `php.ini` into new; copy every `ext\*.dll` present in old but absent in new (keeps oci8/xdebug etc.) and log each; `Stop-PhpCgi`; rename `php\<id>` → `backups\php\<id>-<oldVersion>-<ts>`; rename `.new` → `<id>`; `Start-PhpCgi`; if port not listening in 10 s → restore backup folder, start it, mark failed.
     **install:** rename `.new` → `<id>`; create `php.ini` from `php.ini-development` with `extension_dir = "ext"` and enable `curl, fileinfo, gd, intl, mbstring, mysqli, openssl, pdo_mysql, pdo_sqlite, sodium, zip`; add `{id, port: Get-DefaultPhpPort, upstream, version}` to settings; run `config:write`; `Start-PhpCgi`; `nginx -t` + reload.
  4. Update `version` in settings; `Set-JobState done`. Any exception → log + `failed`; always delete the zip and leftover `.new`.
- Routes: `GET /php`, `POST /php/refresh`, `POST /php/{id}/update`, `POST /php/install` (field `branch`), `POST /php/{id}/start|stop|restart`, `GET /jobs/{id}`, `GET /jobs/{id}/log?offset=N` (JSON).

- [ ] **Step 1: Failing tests**
```php
test('feed picks ts x64 build regardless of vc/vs toolset', function () {
    $f = new PhpReleaseFeed('x', tempPath(), 0, fn() => fixture('releases.json'));
    $r = $f->releases('ts');
    assertSame('8.3.37', $r['8.3']['version']);
    assertSame('php-8.3.37-Win32-vs16-x64.zip', $r['8.3']['zipPath']);
    assertSame('ts-vc15-x64', $r['7.4']['buildKey']);
});
test('feed falls back to stale cache when fetch throws', function () { /* first call caches, second fetch throws → same data, lastError set */ });
test('update checker classifies installed versions', function () {
    $installed = [['id'=>'7.2','version'=>'7.2.34'],['id'=>'8.3','version'=>'8.3.0'],
                  ['id'=>'8.4','version'=>'8.4.28'],['id'=>'8.5.12','version'=>'8.5.12']];
    $res = UpdateChecker::compare(withBranches($installed), $releasesFromFixture);
    assertSame(['8.3'], array_column($res['updates'], 'id'));   // 8.3.0 → 8.3.37
    assertSame(['8.4'], array_column($res['current'], 'id'));
    assertSame(['7.2','8.5.12'], array_column($res['eol'], 'id')); // fixture has no 7.2 / 8.5 branch
    assertSame(['7.4','8.6'], array_column($res['available'], 'branch')); // fixture branches minus installed
});
test('job runner rejects malformed ids', function () { assertSame(null, $runner->status('../../settings')); });
test('job log returns incremental text from offset', function () { /* write log "abc", log(id,0)→"abc",3; append "d"; log(id,3)→"d",4 */ });
```
(The fixture deliberately contains only branches 7.4, 8.3, 8.4, 8.6 so the EOL and "available" cases are pinned.)

- [ ] **Step 2: Run → FAIL; Step 3: implement PHP classes; Step 4: Run → PASS.**

- [ ] **Step 5: Implement `install-php.ps1`** per the steps above, using Common.psm1 helpers. Smoke-test from a console: `.\scripts\install-php.ps1 -JobId manual-1 -Mode install -Id 8.6 -Version 8.6.0 -ZipPath php-8.6.0-Win32-vs18-x64.zip -Sha256 <from feed>` → `php\8.6` exists, port 9086 is already used by 8.5.12 so settings get 9100, upstream `php86` in `conf/generated/upstreams.conf`.

- [ ] **Step 6: Page** (WordPress-style):
```
PHP Versions                                   Last checked 10 min ago [Check now]
┌ Updates available (1) ─────────────────────────────────────────────┐
│ PHP 8.3   8.3.0 → 8.3.37   (used by 9 sites · runs this manager)   [Update] │
└────────────────────────────────────────────────────────────────────┘
Installed
  8.4   8.4.28  ● running :9084   6 sites   [Restart][Stop]
  7.2   7.2.34  ● running :9072   1 site    end of life — no updates
Available to install
  8.6   8.6.0   [Install]      7.4  7.4.33  [Install]
Recent jobs …
```
  - Update/Install/Start/Stop/Restart all go through `JobRunner` and redirect to `/jobs/{id}`; Stop is disabled for the manager's own version (Restart allowed).
  - Sites-per-version count comes from `SiteRepository::all()` upstreams.
  - Job page: polls `/jobs/{id}/log` every 1.5 s, appends text, shows state; on fetch failure shows "Manager restarting… reconnecting" and keeps retrying for 60 s (covers updating the manager's own PHP).
  - Sidebar badge = `count(updates)` from `cachedOnly()` (no network on every page).

- [ ] **Step 7: Live check** — Update PHP 8.4 (8.4.27 → 8.4.28) from the page: job log streams, `php\8.4\php.exe -v` shows 8.4.28, `backups\php\8.4-8.4.27-*` exists, oci8 dll copied, sites on php84 still load. Then update 8.3 (the manager's own PHP): page reconnects and shows `done`.

- [ ] **Step 8: Commit** `feat(php): versions & updates page with background installer`

---

### Task 9: PHP Extensions page (cPanel-style)

**Files:**
- Create: `manager/src/Php/{PhpIni,ExtensionManager,PeclCatalog}.php`, `manager/src/Controllers/ExtensionsController.php`, `manager/views/extensions/index.php`, `scripts/install-extension.ps1`, `tests/Php/{PhpIniTest,ExtensionManagerTest,PeclCatalogTest}.php`, fixtures `tests/fixtures/php.ini` (trimmed real 8.3 ini incl. commented `;extension=` lines and a `zend_extension`), `tests/fixtures/pecl/{redis-index.html,redis-6.2.0.html}` (saved directory listings)
- Modify: routes; `install-php.ps1` update mode already copies extra `ext\*.dll` (Task 8) — also re-apply the old ini's `extension=` lines, which copying `php.ini` already does.

**Interfaces:**
- `PhpIni::__construct(string $text)`; `PhpIni::fromFile(string $path)`; `->extensions(): array<string, array{enabled:bool, zend:bool}>` — recognises `extension=redis`, `extension=php_redis.dll`, `extension="redis"`, `zend_extension=opcache`, `zend_extension=xdebug` with or without leading `;`, case-insensitive; key = normalised name (`redis`, `opcache`); `->setEnabled(string $name, bool $on, bool $zend): void` — uncomment/comment the existing line if one exists, otherwise append under a `; ---- Extensions managed by Nginx Manager ----` section at the end; never duplicates; `->text(): string` (unchanged bytes for untouched lines).
- `ExtensionManager::__construct(Settings, Shell, JobRunner)`; `->list(string $phpId): list<array{name, file, available:bool, enabled:bool, zend:bool, loaded:bool, bundled:bool}>` — union of `php\<id>\ext\php_*.dll` and ini entries; `loaded` from `php.exe -c <ini> -m` (one call per page view); `bundled` = DLL present in the official zip (stored once in `data/cache/bundled-ext-<id>.json` when the version is installed/updated; anything else is "installed from PECL"); zend list: `opcache`, `xdebug` plus anything already in a `zend_extension=` line. `->setEnabled(string $phpId, array<string,bool> $changes): array{ok:bool, output:string, jobId:?string}` — apply all toggles to a `PhpIni`, backup → write → validate (`php.exe -c <ini> -v`), on failure restore and return PHP's output; on success start `php-service.ps1 -Action restart -Id <id>` job and return its id.
- `PeclCatalog::__construct(string $cacheDir, int $ttl = 86400, ?callable $fetch = null)`; `->versions(string $ext): string[]` (newest first, stable only unless `$includePre`); `->findBuild(string $ext, string $branch, string $threadSafety): ?array{ext, version, zip, url}` — walks versions newest → oldest (max 15) and returns the first with a matching zip; `->popular(): string[]` = `['apcu','igbinary','imagick','mailparse','memcache','mongodb','oci8','pdo_sqlsrv','redis','sqlsrv','ssh2','xdebug','yaml']`. Extension name input validated `^[a-z0-9_]{2,40}$`.
- `install-extension.ps1 -JobId <id> -Id <phpId> -Ext <name> -Url <zip url> [-Enable]`: download to `temp\`; extract to `temp\ext-<jobId>\`; copy `php_*.dll` → `php\<id>\ext\` (backup an existing DLL of the same name to `backups\ext\<id>\`), copy other `*.dll` dependencies → `php\<id>\` (log each; never overwrite an existing DLL there — log a warning instead); if `-Enable`, call `php manager\bin\manager.php ext:enable <id> <name>` (same validate/rollback path as the UI); restart that PHP with `Stop-PhpCgi` + `Start-PhpCgi` from Common.psm1; `Set-JobState`.
- CLI addition: `manager.php ext:enable <phpId> <ext>` → exit 0/1 with PHP's output.
- Routes: `GET /extensions?php=<id>` (defaults to manager version), `POST /extensions/{phpId}` (form of checkboxes → `setEnabled` with only the changed ones), `GET /extensions/{phpId}/search?ext=<name>` (JSON: `findBuild` result or `{found:false}`), `POST /extensions/{phpId}/install` (fields `ext`, `enable`).

- [ ] **Step 1: Failing tests**
```php
test('ini detects enabled, commented and zend extensions', function () {
    $ini = new PhpIni("extension=curl\n;extension=gd\nextension=php_oci8_19.dll\nzend_extension=opcache\n;zend_extension=xdebug\n");
    $x = $ini->extensions();
    assertTrue($x['curl']['enabled']);  assertTrue(!$x['gd']['enabled']);
    assertTrue($x['oci8_19']['enabled']); assertTrue($x['opcache']['zend']);
    assertTrue(!$x['xdebug']['enabled']);
});
test('enabling a commented extension uncomments in place', function () {
    $ini = new PhpIni("a=1\n;extension=gd\nb=2\n"); $ini->setEnabled('gd', true, false);
    assertSame("a=1\nextension=gd\nb=2\n", $ini->text());
});
test('enabling an unknown extension appends once under managed section', function () {
    $ini = new PhpIni("a=1\n"); $ini->setEnabled('redis', true, false); $ini->setEnabled('redis', true, false);
    assertSame(1, substr_count($ini->text(), 'extension=redis'));
    assertContains('; ---- Extensions managed by Nginx Manager ----', $ini->text());
});
test('disabling comments the line and keeps the rest byte-identical', function () {
    $src = fixture('php.ini'); $ini = new PhpIni($src); $ini->setEnabled('curl', false, false);
    assertSame(str_replace("\nextension=curl", "\n;extension=curl", $src), $ini->text());
});
test('manager rolls back ini when php reports a load warning', function () {
    // fake Shell: `php -v` output "PHP Warning:  PHP Startup: Unable to load dynamic library 'imagick'"
    // setEnabled('8.3', ['imagick'=>true]) → ok false, output contains 'Unable to load', ini file bytes unchanged, no job started
});
test('pecl catalog finds newest build for branch, skipping versions without one', function () {
    // fixture listing: redis 6.4.0RC1, 6.3.0 (no 8.3 build), 6.2.0 (has php_redis-6.2.0-8.3-ts-vs16-x64.zip)
    $b = $catalog->findBuild('redis', '8.3', 'ts');
    assertSame('6.2.0', $b['version']);
    assertSame('https://downloads.php.net/~windows/pecl/releases/redis/6.2.0/php_redis-6.2.0-8.3-ts-vs16-x64.zip', $b['url']);
});
test('pecl catalog returns null for EOL branch with no build', function () { assertSame(null, $catalog->findBuild('redis', '7.2', 'ts')); });
```
- [ ] **Step 2: Run → FAIL; Step 3: implement PHP classes; Step 4: Run → PASS.**
- [ ] **Step 5: Implement `install-extension.ps1`** as specified.
- [ ] **Step 6: Page** —
```
PHP Extensions                PHP version: [ 8.3 (8.3.37) ▾ ]      [Save changes]
[filter…]                              Show: (•) All ( ) Enabled ( ) Disabled
┌──────────────────────────────────────────────────────────────────────┐
│ [x] curl        [x] mbstring     [ ] gd          [x] openssl         │
│ [ ] intl        [x] pdo_mysql    [x] oci8_19     [ ] sodium          │
│ [x] opcache ᶻ   [ ] redis  PECL  …                                    │
└──────────────────────────────────────────────────────────────────────┘
 ⚠ enabled but not loaded: oci8_19 (shown red, with PHP's warning on hover)
Install new extension
  [ redis ▾ / type a name ]  [Find build]  → redis 6.2.0 for PHP 8.3 (ts, vs16)  [x] enable after install  [Install]
```
  - Checkbox grid, 4 columns, alphabetical; `ᶻ` badge for zend extensions, `PECL` badge for non-bundled; red outline when `enabled && !loaded`.
  - Save sends only changed boxes; on success redirect to the restart job page (`/jobs/{id}`), which returns to `/extensions?php=<id>` when done; on validation failure stay on the page with PHP's output and the user's checkbox choices preserved.
  - "Find build" calls the JSON search route and shows result or "No Windows build of <ext> for PHP <branch>".
  - Install → `install-extension.ps1` job → job page.
  - Note shown under the grid: "Extensions that ship extra DLLs (e.g. imagick) may also need their vendor runtime on PATH."
- [ ] **Step 7: Live check** — on PHP 8.4: enable `intl` → job restarts PHP 8.4 → `php\8.4\php.exe -m` lists intl; install `apcu` with enable → listed + loaded; enable a fake broken ini entry by dropping an invalid `php_bogus.dll` into ext and toggling it → error shown, php.ini unchanged, PHP 8.4 still running.
- [ ] **Step 8: Commit** `feat(extensions): cPanel-style extension manager with PECL installs`

---

### Task 10: Dashboard

**Files:**
- Create: `manager/src/Status/ServiceStatus.php`, `tests/Status/ServiceStatusTest.php`
- Modify: `manager/src/Controllers/DashboardController.php`, `manager/views/dashboard.php`

**Interfaces:**
- `ServiceStatus::__construct(Settings, NginxControl, PhpVersionRepository)`; `->snapshot(): array{nginx: bool, php: list<array{id, port, running}>}`.
- Dashboard data: counts (sites enabled/disabled, groups, certs, PHP versions), service status card (nginx + each PHP with green/red dot and Start-all hint), "Updates available" card (cached only, link to `/php`), "Certificates expiring within 30 days" list, "Sites missing a hosts entry" list, last 20 lines of `logs/error.log`, recent jobs.

- [ ] **Step 1: Failing test** — `snapshot()` with a fake repository (one port open via a temporary `stream_socket_server('tcp://127.0.0.1:0')`, one closed) reports `running` true/false correctly.
- [ ] **Step 2: Run → FAIL; Step 3: implement + view; Step 4: Run → PASS.**
- [ ] **Step 5: Live check** — stop PHP 7.2 from `/php` → dashboard shows it red; start → green. Page loads < 1 s with network unplugged (no feed fetch).
- [ ] **Step 6: Commit** `feat(dashboard): service status, updates, expiring certs`

---

### Task 11: Docs, cutover, final verification

**Files:**
- Create: `README.md`
- Modify: none else expected

- [ ] **Step 1: README** — what it is; folder layout; first-time setup (`Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`, `copy .env.example .env` and what each key means, run `migrate-from-v1.ps1 -Source <v1 folder>`, `start-all.ps1`, optional `install-autostart.ps1`); moving to another machine/drive = copy the folder + edit `.env` only; why it runs as Administrator and the `127.0.0.1` binding; how settings/upstreams are generated (never hand-edit `conf/generated`); backups location; troubleshooting (port in use → v1 still running; VC++ redistributable; `nginx -t` errors).
- [ ] **Step 2: Full test run** — `php\8.3\php.exe tests\run.php` and `Invoke-Pester tests\ps` → all PASS (paste counts).
- [ ] **Step 2b: No-absolute-path check** — `git grep -nE "[A-Za-z]:[/\\\\]" -- . ":!tests/ps/Migration.Tests.ps1" ":!docs"` returns nothing; same grep over `conf/sites/` returns nothing. Portability smoke: copy `.env`, stop services, rename the install folder, `start-all.ps1` → everything still works.
- [ ] **Step 3: Cutover checklist** — v1 `stop-all.bat`; v2 `start-all.ps1`; open every migrated site once (script: loop `conf/sites/*.conf` server names, `Invoke-WebRequest -SkipCertificateCheck` not available on 5.1 → use `curl.exe -sk -o NUL -w "%{http_code}"`) and list non-2xx/3xx; `http://127.0.0.1:8080` all pages render.
- [ ] **Step 4: Commit** `docs: README and cutover notes`
