<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : CertificateService.php
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
 * File: manager/src/Ssl/CertificateService.php
 * Purpose: Self-signed certificates for local HTTPS sites (stored in ssl/<domain>/,
 *          machine data - never committed) and the Windows trust store.
 * Responsibilities:
 *   - list(sites): every ssl/<domain>/ssl.crt/server.crt with CN, SANs, expiry, trusted?,
 *     and which sites use it ($sites = site id => certificate path from its config)
 *   - generate(domain, extraSans, trust): OpenSSL with SANs (domain, *.domain, localhost,
 *     extras) from the ssl/configuration.conf template, 10 years; an existing certificate
 *     for the domain is untrusted and replaced. Returns a log of [level, message].
 *   - trust / untrust: certutil -addstore / -delstore Root (needs Administrator)
 *   - delete: refused while a site uses it, and for the "localhost" default certificate
 * Used by: SslController, SitesController (HTTPS toggle)
 */

declare(strict_types=1);

namespace NginxManager\Ssl;

use InvalidArgumentException;
use NginxManager\Nginx\SiteRepository;
use NginxManager\Support\Env;
use NginxManager\Support\Paths;
use NginxManager\Support\Platform;
use NginxManager\Support\Shell;
use NginxManager\Support\Validator;
use RuntimeException;

final class CertificateService
{
    private const DAYS = 3650;

    /** @var array<string, bool> serial => trusted (one certutil call per serial per request) */
    private array $trustCache = [];

    /** @var callable(string, string): bool file move (injectable so tests can make it fail) */
    private $move;

    public function __construct(private readonly Shell $shell, private readonly ?string $openssl = null, ?callable $move = null)
    {
        $this->move = $move ?? static fn(string $from, string $to): bool => @rename($from, $to);
    }

    /**
     * Puts the new key + certificate in place as a PAIR: the live files are moved aside first
     * and put back if either move fails, so nginx never gets a key that does not match.
     */
    private function swapPair(string $newKey, string $key, string $newCrt, string $crt): bool
    {
        $move = $this->move;
        $aside = [];
        $ok = true;
        foreach ([$key, $crt] as $live) {
            if (is_file($live)) {
                @unlink($live . '.old');
                if (!$move($live, $live . '.old')) {
                    $ok = false;
                    break;
                }
                $aside[] = $live;
            }
        }
        $ok = $ok && $move($newKey, $key) && $move($newCrt, $crt);
        if (!$ok) {
            foreach ([$key, $crt] as $live) {
                if (is_file($live . '.old')) {
                    @unlink($live);
                    @rename($live . '.old', $live);
                }
            }
            @unlink($newKey);
            @unlink($newCrt);
            return false;
        }
        foreach ($aside as $live) {
            @unlink($live . '.old');
        }
        return true;
    }

    // -- paths -----------------------------------------------------------------------

    public static function folderFor(string $domain): string
    {
        return SiteRepository::idFor($domain);   // "*.x.test" -> "wildcard.x.test"
    }

    public static function certPath(string $folder): string
    {
        return Paths::in('ssl', $folder, 'ssl.crt', 'server.crt');
    }

    public static function keyPath(string $folder): string
    {
        return Paths::in('ssl', $folder, 'ssl.key', 'server.key');
    }

    /** Paths as written into nginx site configs (relative to conf/). */
    public static function configPaths(string $folder): array
    {
        return ["../ssl/$folder/ssl.crt/server.crt", "../ssl/$folder/ssl.key/server.key"];
    }

    public function opensslPath(): ?string
    {
        if ($this->openssl !== null) {
            return $this->openssl === '' ? null : $this->openssl;   // '' = none on this machine (tests)
        }
        // OPENSSL_BIN from Settings, then the usual places (Git for Windows / Homebrew / system), then PATH.
        foreach (array_merge([Env::get('OPENSSL_BIN')], Platform::opensslCandidates()) as $c) {
            if ($c && is_file($c)) {
                return str_replace('\\', '/', $c);
            }
        }
        $where = trim((string) strtok($this->shell->run(Platform::whichCommand('openssl'), null, 5)->output, "\r\n"));
        return $where !== '' && is_file($where) ? str_replace('\\', '/', $where) : null;
    }

    // -- listing ---------------------------------------------------------------------------

    /**
     * @param array<string, string> $sites site id => certificate path written in its config
     * @return list<CertInfo>
     */
    public function list(array $sites): array
    {
        $out = [];
        foreach (glob(Paths::in('ssl') . '/*/ssl.crt/server.crt') ?: [] as $file) {
            $folder = basename(dirname($file, 2));
            $x = @openssl_x509_parse((string) file_get_contents($file)) ?: [];
            $sans = [];
            foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $part) {
                if (preg_match('/^\s*DNS:(.+)$/', $part, $m)) {
                    $sans[] = trim($m[1]);
                }
            }
            $notAfter = isset($x['validTo_time_t']) ? (int) $x['validTo_time_t'] : null;
            $serial = isset($x['serialNumberHex']) ? strtoupper((string) $x['serialNumberHex']) : null;
            $usedBy = [];
            foreach ($sites as $siteId => $certPath) {
                if (str_contains((string) $certPath, "/ssl/$folder/")) {
                    $usedBy[] = $siteId;
                }
            }
            $out[] = new CertInfo(
                domain: $folder,
                commonName: (string) ($x['subject']['CN'] ?? $folder),
                sans: $sans,
                notAfter: $notAfter,
                daysLeft: $notAfter === null ? null : (int) floor(($notAfter - time()) / 86400),
                serialHex: $serial,
                trusted: $serial !== null && $this->isTrusted($serial, $file, (string) ($x['subject']['CN'] ?? $folder)),
                usedBy: $usedBy,
            );
        }
        usort($out, static fn(CertInfo $a, CertInfo $b) => strcmp($a->domain, $b->domain));
        return $out;
    }

    public function exists(string $folder): bool
    {
        return is_file(self::certPath($folder)) && is_file(self::keyPath($folder));
    }

    // -- create / trust / delete ---------------------------------------------------------------

    /**
     * @param list<string> $extraSans
     * @return list<array{0:string, 1:string}> log lines [level, message]
     */
    public function generate(string $domain, array $extraSans, bool $trust): array
    {
        $domain = strtolower(trim($domain));
        if (!Validator::domain($domain) && $domain !== 'localhost') {
            throw new InvalidArgumentException("\"$domain\" is not a valid domain.");
        }
        foreach ($extraSans as $san) {
            if (!Validator::domain($san) && $san !== 'localhost') {
                throw new InvalidArgumentException("\"$san\" is not a valid extra domain.");
            }
        }
        // openssl.exe when available; otherwise PHP's own OpenSSL (a new PC without Git for Windows).
        $openssl = $this->opensslPath();
        if ($openssl === null && !function_exists('openssl_pkey_new')) {
            throw new RuntimeException('OpenSSL was not found - install Git for Windows, set its path on the Settings page, or enable PHP\'s openssl extension.');
        }

        $log = [];
        $folder = self::folderFor($domain);
        $crt = self::certPath($folder);
        $key = self::keyPath($folder);
        // OpenSSL writes next to the live files; they are swapped in only when it succeeded,
        // so a failed renewal leaves the working certificate (and its trust) untouched.
        $newCrt = $crt . '.new';
        $newKey = $key . '.new';
        @unlink($newCrt);
        @unlink($newKey);
        foreach ([dirname($crt), dirname($key)] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        $template = Paths::in('ssl', 'configuration.conf');
        if (!is_file($template)) {
            throw new RuntimeException('The OpenSSL template ssl/configuration.conf is missing.');
        }
        $conf = self::buildOpensslConfig((string) file_get_contents($template), $domain, $extraSans);
        $tmp = Paths::in('temp', 'openssl-' . bin2hex(random_bytes(4)) . '.cnf');
        if (!is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0777, true);
        }
        file_put_contents($tmp, $conf);
        try {
            $r = $openssl !== null
                ? $this->shell->run([
                    $openssl, 'req', '-config', $tmp, '-new', '-sha256', '-newkey', 'rsa:2048', '-nodes',
                    '-keyout', $newKey, '-x509', '-days', (string) self::validityDays(),
                    '-subj', '/C=IN/ST=TN/L=Chennai/O=LocalDev/CN=' . $domain, '-out', $newCrt,
                ], Paths::base(), 60)
                : self::generateWithPhp($tmp, $domain, $newKey, $newCrt);
        } finally {
            @unlink($tmp);
        }
        if (!$r->ok() || !is_file($newCrt) || !is_file($newKey)) {
            @unlink($newCrt);
            @unlink($newKey);
            $log[] = ['error', 'OpenSSL could not create the certificate'
                . (is_file($crt) ? ' - the existing certificate was kept' : '') . ': ' . trim($r->output)];
            return $log;
        }

        $oldPem = is_file($crt) ? (string) file_get_contents($crt) : null;
        $old = $oldPem !== null ? (@openssl_x509_parse($oldPem) ?: []) : null;
        if (!$this->swapPair($newKey, $key, $newCrt, $crt)) {
            $log[] = ['error', 'Could not put the new certificate in place (a file is in use) - the existing certificate was kept.'];
            return $log;
        }
        if ($old !== null) {
            // Only now that the new pair is in place: the old certificate leaves the trust store.
            if (!empty($old['serialNumberHex'])) {
                $this->trustRemove((string) $old['serialNumberHex'], (string) $oldPem);
                $log[] = ['info', 'Removed the previous certificate from the ' . self::storeName() . '.'];
            }
            $log[] = ['info', 'Replaced the previous certificate.'];
        }
        $this->trustCache = [];
        $log[] = ['success', "Certificate created for $domain (valid " . (self::validityDays() >= 730 ? intdiv(self::validityDays(), 365) . ' years' : self::validityDays() . ' days') . ').'];

        if ($trust) {
            $log[] = $this->trustFolder($folder);
        }
        $log[] = ['success', 'Done.'];
        return $log;
    }

    /**
     * The extra domains of a certificate - its SANs without the ones every certificate gets
     * (domain, *.domain, localhost) - so Renew re-creates it with the same names.
     *
     * @param list<string> $sans
     * @return list<string>
     */
    public static function extraSans(string $domain, array $sans): array
    {
        $defaults = [strtolower($domain), '*.' . strtolower($domain), 'localhost'];
        return array_values(array_filter(array_map('strtolower', $sans),
            static fn(string $s) => !in_array($s, $defaults, true)));
    }

    /** @return array{0:string, 1:string} */
    public function trustFolder(string $folder): array
    {
        $cert = self::certPath($folder);
        $r = Platform::isWindows()
            ? $this->shell->run(['certutil', '-addstore', 'Root', $cert], null, 20)
            // Login keychain: macOS shows its own password dialog to confirm.
            : $this->shell->run(['security', 'add-trusted-cert', '-r', 'trustRoot', '-k', self::loginKeychain(), $cert], null, 120);
        if ($r->ok() || stripos($r->output, 'already in store') !== false) {
            $this->trustCache = [];
            return ['success', 'Trusted by ' . Platform::label() . ' - browsers accept it after a restart.'];
        }
        return ['warn', Platform::isWindows()
            ? 'Windows did not trust the certificate (the server must run as Administrator): ' . trim($r->output)
            : 'macOS did not trust the certificate (was the password dialog cancelled?): ' . trim($r->output)];
    }

    /** @return array{0:string, 1:string} */
    public function untrustFolder(string $folder): array
    {
        $pem = (string) @file_get_contents(self::certPath($folder));
        $x = @openssl_x509_parse($pem) ?: [];
        if (empty($x['serialNumberHex'])) {
            return ['error', 'Certificate not found.'];
        }
        $r = $this->trustRemove((string) $x['serialNumberHex'], $pem);
        return $r->ok() ? ['success', 'Removed from the ' . self::storeName() . '.']
                        : ['warn', 'Could not remove it from the ' . self::storeName() . ': ' . trim($r->output)];
    }

    /** @param array<string, string> $sites site id => certificate path */
    public function delete(string $folder, array $sites): void
    {
        if (!Validator::siteId($folder) || !is_file(self::certPath($folder))) {
            throw new RuntimeException('Certificate not found.');
        }
        if ($folder === 'localhost') {
            throw new RuntimeException('The localhost certificate is the server default and cannot be deleted.');
        }
        $users = array_keys(array_filter($sites, static fn($p) => str_contains((string) $p, "/ssl/$folder/")));
        if ($users) {
            throw new RuntimeException('This certificate is used by ' . implode(', ', $users) . ' - switch those sites to HTTP first.');
        }
        $this->untrustFolder($folder);
        foreach ([self::certPath($folder), self::keyPath($folder)] as $f) {
            @unlink($f);
        }
        @rmdir(dirname(self::certPath($folder)));
        @rmdir(dirname(self::keyPath($folder)));
        @rmdir(Paths::in('ssl', $folder));
    }

    // -- helpers ----------------------------------------------------------------------------------

    /** OpenSSL config from the template with this domain as CN and the SAN list appended. */
    public static function buildOpensslConfig(string $template, string $domain, array $extraSans): string
    {
        $conf = (string) preg_replace('/commonName_default\s*=\s*.+/', 'commonName_default          = ' . $domain, $template);
        // Server certificates need EKU serverAuth (macOS and Chrome reject them otherwise);
        // added to the certificate section of templates that do not have it yet.
        if (!preg_match('/^\s*extendedKeyUsage\s*=/m', $conf)) {
            $conf = (string) preg_replace('/^(\[\s*x509_ext\s*\]\s*\n)/m', "\$1extendedKeyUsage       = serverAuth\n", $conf, 1);
        }
        $names = array_values(array_unique(array_merge([$domain, '*.' . ltrim($domain, '*.'), 'localhost'], $extraSans)));
        if (str_starts_with($domain, '*.')) {
            $names = array_values(array_unique(array_merge([$domain, substr($domain, 2), 'localhost'], $extraSans)));
        }
        $conf = rtrim($conf) . "\n";
        foreach ($names as $i => $name) {
            $conf .= 'DNS.' . ($i + 1) . " = $name\n";
        }
        return $conf;
    }

    // -- trust store: Windows Root store (certutil) / macOS login keychain (security) -------------

    private function isTrusted(string $serialHex, string $certPath, string $name = ''): bool
    {
        // macOS: the SSL policy for the certificate's name, so "trusted" means a browser
        // accepts it (validity limit and serverAuth included), not only that the root is known.
        return $this->trustCache[$serialHex] ??= Platform::isWindows()
            ? $this->shell->run(['certutil', '-store', 'Root', $serialHex], null, 20)->ok()
            : $this->shell->run(['security', 'verify-cert', '-q', '-L', '-p', 'ssl', '-s', $name, '-c', $certPath], null, 20)->ok();
    }

    /**
     * The same certificate as "openssl req -x509" with the same config file, made with PHP's
     * openssl extension - for machines without openssl.exe (no Git for Windows). The key is
     * created on this machine; nothing is shipped.
     */
    private static function generateWithPhp(string $cnf, string $domain, string $keyFile, string $crtFile): \NginxManager\Support\ShellResult
    {
        $opts = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $errors = static function (): string {
            $out = [];
            while (($e = openssl_error_string()) !== false) {
                $out[] = $e;
            }
            return implode("\n", $out);
        };
        $key = openssl_pkey_new($opts);
        if ($key === false) {
            return new \NginxManager\Support\ShellResult(1, 'openssl_pkey_new: ' . $errors());
        }
        $dn = ['countryName' => 'IN', 'stateOrProvinceName' => 'TN', 'localityName' => 'Chennai',
               'organizationName' => 'LocalDev', 'commonName' => $domain];
        $csr = openssl_csr_new($dn, $key, $opts + ['req_extensions' => 'req_ext']);
        $cert = $csr === false ? false
            : openssl_csr_sign($csr, null, $key, self::validityDays(), $opts + ['x509_extensions' => 'x509_ext'], random_int(1, PHP_INT_MAX));
        if ($cert === false || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem, null, $opts)) {
            return new \NginxManager\Support\ShellResult(1, 'PHP OpenSSL: ' . $errors());
        }
        file_put_contents($keyFile, $keyPem);
        file_put_contents($crtFile, $certPem);
        return new \NginxManager\Support\ShellResult(0, 'created with PHP OpenSSL');
    }

    /** macOS refuses TLS server certificates valid longer than 825 days; Windows does not. */
    private static function validityDays(): int
    {
        return Platform::isWindows() ? self::DAYS : 825;
    }

    private function trustRemove(string $serialHex, string $pem): \NginxManager\Support\ShellResult
    {
        $this->trustCache = [];
        if (Platform::isWindows()) {
            return $this->shell->run(['certutil', '-delstore', 'Root', strtoupper($serialHex)], null, 20);
        }
        $sha1 = strtoupper((string) openssl_x509_fingerprint($pem, 'sha1'));
        // -t: also remove the trust setting added with add-trusted-cert
        return $this->shell->run(['security', 'delete-certificate', '-t', '-Z', $sha1, self::loginKeychain()], null, 60);
    }

    private static function loginKeychain(): string
    {
        // HOME may be missing when started by launchd: fall back to the user's account record.
        $home = (string) getenv('HOME');
        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        }
        return rtrim($home, '/') . '/Library/Keychains/login.keychain-db';
    }

    private static function storeName(): string
    {
        return Platform::isWindows() ? 'Windows trust store' : 'macOS keychain';
    }
}
