<?php
/******************************************************************************
 *
 *  Project : nginx-manager-2.0
 *  File    : PhpIni.php
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
 * File: manager/src/Php/PhpIni.php
 * Purpose: Reads and switches extension lines in a php.ini without touching anything else.
 * Responsibilities:
 *   - extensions(): name => {enabled, zend} for every "extension=" / "zend_extension=" line,
 *     active or commented. Names are normalised: "php_curl.dll", "curl" and "ext/php_curl.dll"
 *     are all "curl". Trailing "; comments" and quotes are understood.
 *   - setEnabled(name, on, zend):
 *       on  -> nothing if already active; else uncomment the first commented line;
 *              else append under "; ---- Extensions managed by Nginx Manager ----"
 *       off -> comment EVERY active line of that extension (php.ini may list one twice)
 *   - text(): the result; untouched lines keep their exact bytes and line endings
 * Used by: ExtensionManager, bin/manager.php ext:enable
 */

declare(strict_types=1);

namespace NginxManager\Php;

final class PhpIni
{
    public const SECTION = '; ---- Extensions managed by Nginx Manager ----';
    private const LINE = '/^(\s*)(;\s*)?(zend_extension|extension)(\s*=\s*)"?([^"\s;]+)"?(.*)$/i';

    /** @var list<string> */
    private array $lines;
    private string $eol;
    private bool $finalEol;

    /** @param ?string $phpVersion  e.g. "5.6.40"; below 7.2 new lines use php_<name>.dll */
    public function __construct(string $text, private readonly ?string $phpVersion = null)
    {
        $this->eol = str_contains($text, "\r\n") ? "\r\n" : "\n";
        $this->finalEol = $text === '' || str_ends_with($text, "\n");
        $this->lines = explode($this->eol, $this->finalEol && $text !== '' ? substr($text, 0, -strlen($this->eol)) : $text);
    }

    public static function fromFile(string $path): self
    {
        return new self(is_file($path) ? (string) file_get_contents($path) : '');
    }

    public static function normalise(string $value): string
    {
        $name = strtolower(basename(str_replace('\\', '/', $value)));
        $name = (string) preg_replace('/\.(dll|so)$/', '', $name);   // Windows / macOS
        return str_starts_with($name, 'php_') ? substr($name, 4) : $name;
    }

    /** @return array<string, array{enabled:bool, zend:bool}> */
    public function extensions(): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            if (!preg_match(self::LINE, $line, $m)) {
                continue;
            }
            $name = self::normalise($m[5]);
            $out[$name] ??= ['enabled' => false, 'zend' => false, 'file' => ''];
            $out[$name]['enabled'] = $out[$name]['enabled'] || $m[2] === '';
            $out[$name]['zend'] = $out[$name]['zend'] || strtolower($m[3]) === 'zend_extension';
            $out[$name]['file'] = $out[$name]['file'] ?: $m[5];   // as written: "curl", "php_curl.dll" or a full path
        }
        ksort($out);
        return $out;
    }

    public function setEnabled(string $name, bool $on, bool $zend): void
    {
        $name = self::normalise($name);
        $active = [];
        $commented = [];
        foreach ($this->lines as $i => $line) {
            if (preg_match(self::LINE, $line, $m) && self::normalise($m[5]) === $name) {
                $m[2] === '' ? $active[] = $i : $commented[] = $i;
            }
        }

        if (!$on) {
            foreach ($active as $i) {
                $this->lines[$i] = (string) preg_replace('/^(\s*)/', '$1;', $this->lines[$i], 1);
            }
            return;
        }
        if ($active) {
            return;
        }
        if ($commented) {
            $i = $commented[0];
            $this->lines[$i] = (string) preg_replace('/^(\s*);\s*/', '$1', $this->lines[$i], 1);
            return;
        }
        if (!in_array(self::SECTION, $this->lines, true)) {
            if (end($this->lines) !== '') {
                $this->lines[] = '';
            }
            $this->lines[] = self::SECTION;
        }
        // Loading by short name ("extension=redis") only exists since PHP 7.2.
        $legacy = $this->phpVersion !== null && version_compare($this->phpVersion, '7.2', '<');
        $file = \NginxManager\Support\Platform::isWindows() ? 'php_' . $name . '.dll' : $name . '.so';
        $this->lines[] = ($zend ? 'zend_extension=' : 'extension=') . ($legacy ? $file : $name);
    }

    public function text(): string
    {
        return implode($this->eol, $this->lines) . ($this->finalEol ? $this->eol : '');
    }
}
