<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : CertificateServiceTest.php
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
 * File: tests/Ssl/CertificateServiceTest.php
 * Purpose: Local SSL certificates: OpenSSL config with SANs, generation (old certificate
 *          untrusted and replaced), Windows trust store calls, listing with expiry and
 *          which sites use a certificate, and safe deletion.
 * Notes: OpenSSL and certutil are faked (CertShell); the listing test uses a real
 *        certificate fixture (tests/fixtures/ssl/fixture.crt, public part only).
 */

declare(strict_types=1);

use NginxManager\Ssl\CertificateService;
use NginxManager\Support\Paths;
use NginxManager\Support\Shell;
use NginxManager\Support\ShellResult;

class CertShell extends Shell
{
    /** @var list<list<string>> */
    public array $calls = [];
    public bool $trusted = false;

    public function run(array $argv, ?string $cwd = null, int $timeout = 60): ShellResult
    {
        $this->calls[] = $argv;
        if (str_ends_with($argv[0], 'openssl.exe')) {
            // Fake "openssl req": write the key and certificate where asked.
            $key = $argv[array_search('-keyout', $argv, true) + 1];
            $crt = $argv[array_search('-out', $argv, true) + 1];
            file_put_contents($key, 'KEY');
            file_put_contents($crt, fixture('ssl/fixture.crt'));
            return new ShellResult(0, '');
        }
        if ($argv[0] === 'certutil') {
            if ($argv[1] === '-store') {
                return new ShellResult($this->trusted ? 0 : 1, '');
            }
            return new ShellResult(0, $argv[1] === '-addstore' ? 'Certificate "x" added to store.' : 'Deleting Certificate 0');
        }
        return new ShellResult(1, 'unexpected command');
    }
}

function certService(): array
{
    $base = tempDir();
    Paths::overrideBase($base);
    mkdir("$base/ssl", 0777, true);
    mkdir("$base/temp", 0777, true);
    file_put_contents("$base/ssl/configuration.conf", "[ subject ]\ncommonName_default          = localhost\n\n[ alternate_names ]\n");
    $shell = new CertShell();
    return [$base, $shell, new CertificateService($shell, 'Q:/fake/openssl.exe')];
}

test('openssl config gets the domain as CN and DNS entries for domain, wildcard, localhost and extras', function () {
    $conf = CertificateService::buildOpensslConfig("commonName_default = localhost\n[ alternate_names ]\n", 'demo.test', ['api.demo.test']);
    assertContains('commonName_default          = demo.test', $conf);
    assertContains("DNS.1 = demo.test\nDNS.2 = *.demo.test\nDNS.3 = localhost\nDNS.4 = api.demo.test\n", $conf);
});

test('generate writes key + certificate under ssl/<domain> and trusts it when asked', function () {
    [$base, $shell, $svc] = certService();
    $log = $svc->generate('demo.example.test', [], true);
    assertTrue(is_file("$base/ssl/demo.example.test/ssl.crt/server.crt"));
    assertTrue(is_file("$base/ssl/demo.example.test/ssl.key/server.key"));
    $cmds = array_map(fn($c) => $c[0] . ' ' . ($c[1] ?? ''), $shell->calls);
    assertTrue(in_array('certutil -addstore', $cmds, true));
    assertSame('success', end($log)[0]);
    assertTrue(!glob("$base/temp/*.cnf"), 'temporary OpenSSL config removed');
});

test('regenerating untrusts the old certificate once the new one exists', function () {
    [, $shell, $svc] = certService();
    $svc->generate('demo.example.test', [], false);
    $shell->calls = [];
    $svc->generate('demo.example.test', [], false);
    assertSame(['certutil', '-delstore', 'Root', '4BB6DA7FEE28358F7709FD0D92ED5F3923948016'], $shell->calls[1]);   // after openssl (calls[0])
});

test('wildcard domains are stored under wildcard.<domain>', function () {
    [$base, , $svc] = certService();
    $svc->generate('*.example.test', [], false);
    assertTrue(is_file("$base/ssl/wildcard.example.test/ssl.crt/server.crt"));
});

test('list reads expiry, SANs, trust and the sites using each certificate', function () {
    [$base, $shell, $svc] = certService();
    mkdir("$base/ssl/fixture.example.test/ssl.crt", 0777, true);
    copy(__DIR__ . '/../fixtures/ssl/fixture.crt', "$base/ssl/fixture.example.test/ssl.crt/server.crt");
    $shell->trusted = true;
    $certs = $svc->list(['api.example.test' => '../ssl/fixture.example.test/ssl.crt/server.crt']);
    assertSame(1, count($certs));
    $c = $certs[0];
    assertSame('fixture.example.test', $c->domain);
    assertSame(['fixture.example.test', '*.fixture.example.test'], $c->sans);
    assertTrue($c->daysLeft > 3000);
    assertTrue($c->trusted);
    assertSame(['api.example.test'], $c->usedBy);
});

test('delete refuses certificates in use and the localhost default', function () {
    [, , $svc] = certService();
    $svc->generate('demo.example.test', [], false);
    assertThrows(RuntimeException::class, fn() => $svc->delete('demo.example.test', ['site.test' => '../ssl/demo.example.test/ssl.crt/server.crt']), 'used by');
    assertThrows(RuntimeException::class, fn() => $svc->delete('localhost', []));
    $svc->delete('demo.example.test', []);
    assertSame([], $svc->list([]));
});

test('invalid domains are rejected', function () {
    [, , $svc] = certService();
    assertThrows(InvalidArgumentException::class, fn() => $svc->generate('../evil', [], false));
    assertThrows(InvalidArgumentException::class, fn() => $svc->generate('demo.test', ['bad name'], false));
});

// Regression (final review #5): renewing must not destroy the working certificate.
final class FailingOpensslShell extends CertShell
{
    public function run(array $argv, ?string $cwd = null, int $timeout = 60): ShellResult
    {
        if (str_ends_with($argv[0], 'openssl.exe')) {
            $this->calls[] = $argv;
            return new ShellResult(1, 'unable to load config');
        }
        return parent::run($argv, $cwd, $timeout);
    }
}

test('a failed renewal keeps the existing certificate and its trust', function () {
    [$base, , $svc] = certService();
    $svc->generate('demo.example.test', [], false);
    $crt = "$base/ssl/demo.example.test/ssl.crt/server.crt";
    $before = file_get_contents($crt);

    $shell = new FailingOpensslShell();
    $log = (new CertificateService($shell, 'Q:/fake/openssl.exe'))->generate('demo.example.test', [], true);
    assertSame('error', $log[count($log) - 1][0]);
    assertSame($before, file_get_contents($crt));
    assertTrue(is_file("$base/ssl/demo.example.test/ssl.key/server.key"));
    foreach ($shell->calls as $c) {
        assertTrue(!($c[0] === 'certutil' && $c[1] === '-delstore'), 'old certificate must stay trusted');
    }
    assertSame([], glob("$base/ssl/demo.example.test/*/*.new"));
});

test('renewal keeps the extra domains of the certificate', function () {
    assertSame(['api.demo.test', 'demo.local'],
        CertificateService::extraSans('demo.test', ['demo.test', '*.demo.test', 'localhost', 'api.demo.test', 'demo.local']));
});

test('a renewal whose file swap fails halfway keeps the old key, certificate and trust', function () {
    // Regression (final review 2, #3): key swapped but certificate not = mismatched pair.
    [$base, , $svc] = certService();
    $svc->generate('demo.example.test', [], false);
    $crt = "$base/ssl/demo.example.test/ssl.crt/server.crt";
    $key = "$base/ssl/demo.example.test/ssl.key/server.key";
    file_put_contents($key, 'OLD KEY');
    $oldCrt = file_get_contents($crt);

    $shell = new CertShell();
    $failCrt = static fn(string $from, string $to): bool => str_ends_with($from, 'server.crt.new') ? false : rename($from, $to);
    $log = (new CertificateService($shell, 'Q:/fake/openssl.exe', $failCrt))->generate('demo.example.test', [], true);

    assertSame('error', $log[count($log) - 1][0]);
    assertSame('OLD KEY', file_get_contents($key));
    assertSame($oldCrt, file_get_contents($crt));
    foreach ($shell->calls as $c) {
        assertTrue(!($c[0] === 'certutil' && in_array($c[1], ['-delstore', '-addstore'], true)), 'trust store must not change');
    }
    assertSame([], array_merge(glob("$base/ssl/demo.example.test/*/*.new"), glob("$base/ssl/demo.example.test/*/*.old")));
});

// -- macOS: login keychain via "security" ----------------------------------------------------

final class MacCertShell extends CertShell
{
    public function run(array $argv, ?string $cwd = null, int $timeout = 60): ShellResult
    {
        if ($argv[0] === 'security') {
            $this->calls[] = $argv;
            return new ShellResult($argv[1] === 'verify-cert' ? ($this->trusted ? 0 : 1) : 0, '');
        }
        return parent::run($argv, $cwd, $timeout);
    }
}

test('macOS: trust, untrust and renew use the login keychain', function () {
    [$base] = certService();
    \NginxManager\Support\Platform::override('Darwin');
    putenv('HOME=/Users/tester');
    $shell = new MacCertShell();
    $svc = new CertificateService($shell, 'Q:/fake/openssl.exe');   // the fake shell answers for *openssl.exe
    $svc->generate('demo.example.test', [], true);
    $crt = "$base/ssl/demo.example.test/ssl.crt/server.crt";
    $add = array_values(array_filter($shell->calls, fn($c) => $c[0] === 'security'))[0];
    assertSame(['security', 'add-trusted-cert', '-r', 'trustRoot', '-k', '/Users/tester/Library/Keychains/login.keychain-db', $crt], $add);

    $sha1 = strtoupper((string) openssl_x509_fingerprint((string) file_get_contents($crt), 'sha1'));
    $shell->calls = [];
    $svc->untrustFolder('demo.example.test');
    assertSame(['security', 'delete-certificate', '-t', '-Z', $sha1, '/Users/tester/Library/Keychains/login.keychain-db'], $shell->calls[0]);

    $shell->calls = [];
    $shell->trusted = true;
    $list = $svc->list([]);
    assertTrue($list[0]->trusted);
    $cn = (string) (openssl_x509_parse((string) file_get_contents($crt))['subject']['CN'] ?? '');
    // SSL policy for the certificate's name: "trusted" must mean a browser accepts it.
    assertSame(['security', 'verify-cert', '-q', '-L', '-p', 'ssl', '-s', $cn, '-c', $crt], $shell->calls[0]);
    foreach ($shell->calls as $c) {
        assertTrue($c[0] !== 'certutil', 'no certutil on macOS');
    }
});

test('certificates carry EKU serverAuth, also from a template without it', function () {
    // Final review 3, I6: macOS (and Chrome) reject server certificates without it.
    $tpl = "[ x509_ext ]\nbasicConstraints = CA:FALSE\nsubjectAltName = @alternate_names\n[ req_ext ]\nsubjectAltName = @alternate_names\n[ alternate_names ]\n";
    $conf = CertificateService::buildOpensslConfig($tpl, 'demo.test', []);
    assertSame(1, preg_match('/\[ x509_ext \][^\[]*extendedKeyUsage\s*=\s*serverAuth/', $conf));
    assertSame(1, substr_count(CertificateService::buildOpensslConfig($conf, 'demo.test', []), 'extendedKeyUsage'));
});

test('macOS certificates are valid 825 days (the macOS maximum); Windows keeps 10 years', function () {
    [, $shell, $svc] = certService();
    \NginxManager\Support\Platform::override('Darwin');
    $svc->generate('demo.example.test', [], false);
    $req = array_values(array_filter($shell->calls, fn($c) => str_ends_with($c[0], 'openssl.exe')))[0];
    assertSame('825', $req[array_search('-days', $req, true) + 1]);
    \NginxManager\Support\Platform::override('Windows');
    $shell->calls = [];
    $svc->generate('demo.example.test', [], false);
    $req = array_values(array_filter($shell->calls, fn($c) => str_ends_with($c[0], 'openssl.exe')))[0];
    assertSame('3650', $req[array_search('-days', $req, true) + 1]);
});

test('without openssl.exe (a new PC without Git) certificates are made with PHP\'s own OpenSSL', function () {
    // Real crypto, no fake shell: the result must be a usable server certificate.
    $base = tempDir();
    Paths::overrideBase($base);
    mkdir("$base/ssl", 0777, true);
    mkdir("$base/temp", 0777, true);
    copy(__DIR__ . '/../../ssl/configuration.conf', "$base/ssl/configuration.conf");
    $shell = new CertShell();
    $svc = new CertificateService($shell, '');            // '' = no openssl.exe on this machine
    $log = $svc->generate('shop.example.test', ['api.shop.example.test'], false);
    assertSame('success', $log[count($log) - 1][0], implode(' / ', array_column($log, 1)));
    $crt = (string) file_get_contents("$base/ssl/shop.example.test/ssl.crt/server.crt");
    $key = (string) file_get_contents("$base/ssl/shop.example.test/ssl.key/server.key");
    $x = openssl_x509_parse($crt);
    assertSame('shop.example.test', $x['subject']['CN']);
    assertContains('DNS:*.shop.example.test', $x['extensions']['subjectAltName']);
    assertContains('DNS:api.shop.example.test', $x['extensions']['subjectAltName']);
    assertContains('TLS Web Server Authentication', $x['extensions']['extendedKeyUsage']);
    assertTrue(openssl_x509_check_private_key($crt, $key), 'key matches certificate');
    assertSame([], array_values(array_filter($shell->calls, fn($c) => str_ends_with($c[0], 'openssl.exe'))));
});
