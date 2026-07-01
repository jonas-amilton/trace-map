#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * trace-map.php
 *
 * Dependency-free PHP call-trace mapper for Laravel/PHP projects.
 * Uses only PHP built-ins (token_get_all + SQLite-free in-memory index).
 *
 * Usage:
 *   php trace-map.php --log=storage/logs/laravel.log
 *   cat storage/logs/laravel.log | php trace-map.php
 *   php trace-map.php --symbol='App\\Http\\Controllers\\CheckoutController::store'
 *
 * Limitations: static, best-effort analysis. Dynamic calls, macros, reflection,
 * closures used as container bindings, and runtime-generated classes are reported
 * as unresolved rather than guessed.
 */

const TM_VERSION = '0.1.3';

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return strpos($haystack, $needle) !== false;
    }
}

final class TraceMap
{
    private string $root;
    private int $depth;
    private bool $includeTests;
    /** @var array<string,array<string,mixed>> */
    private array $classes = [];
    /** @var array<string,array<string,mixed>> */
    private array $methods = [];
    /** @var array<string,list<string>> */
    private array $shortClassIndex = [];
    /** @var array<string,list<array<string,mixed>>> */
    private array $bindings = [];
    /** @var array<string,array<string,mixed>> */
    private array $files = [];
    /** @var array<string,list<array<string,mixed>>> */
    private array $callsCache = [];

    public function __construct(string $root, int $depth, bool $includeTests)
    {
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $this->depth = max(1, min($depth, 12));
        $this->includeTests = $includeTests;
    }

    public function index(): void
    {
        foreach ($this->phpFiles() as $file) {
            $this->indexFile($file);
        }

        $this->indexLaravelBindings();
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $skip = [
            '.git', 'vendor', 'node_modules', 'storage', 'bootstrap/cache',
            'coverage', '.idea', '.vscode', 'public/build', 'dist', 'build',
        ];

        $result = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relative = $this->relative($path);
            if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            if (!$this->includeTests && str_starts_with($relative, 'tests/')) {
                continue;
            }

            if (basename($path) === 'trace-map.php') {
                continue;
            }

            foreach ($skip as $needle) {
                if ($relative === $needle || str_starts_with($relative, $needle . '/')) {
                    continue 2;
                }
            }

            $result[] = $path;
        }

        sort($result);
        return $result;
    }

    private function indexFile(string $path): void
    {
        $code = @file_get_contents($path);
        if ($code === false || trim($code) === '') {
            return;
        }

        try {
            $rawTokens = token_get_all($code, TOKEN_PARSE);
        } catch (ParseError) {
            // TOKEN_PARSE rejects incomplete/broken source. Retry to retain best-effort indexing.
            $rawTokens = token_get_all($code);
        }

        $tokens = $this->withOffsets($rawTokens);
        $relative = $this->relative($path);
        $namespace = $this->parseNamespace($tokens);
        $imports = $this->parseImports($tokens, $namespace);

        $file = [
            'path' => $path,
            'relative' => $relative,
            'code' => $code,
            'namespace' => $namespace,
            'imports' => $imports,
            'tokens' => $tokens,
        ];
        $this->files[$path] = $file;

        $bracePairs = $this->bracePairs($tokens);
        $classSpans = $this->parseClasses($tokens, $bracePairs, $namespace, $imports, $file);
        foreach ($classSpans as $class) {
            $this->classes[$class['fqcn']] = $class;
            $short = $this->shortName($class['fqcn']);
            $this->shortClassIndex[$short][] = $class['fqcn'];
        }

        foreach ($this->parseMethods($tokens, $bracePairs, $classSpans, $file) as $method) {
            $this->methods[$method['id']] = $method;
        }
    }

    /** @param list<mixed> $rawTokens @return list<array<string,mixed>> */
    private function withOffsets(array $rawTokens): array
    {
        $tokens = [];
        $offset = 0;
        $line = 1;
        foreach ($rawTokens as $raw) {
            if (is_array($raw)) {
                [$id, $text, $line] = $raw;
            } else {
                $id = null;
                $text = $raw;
            }
            $tokens[] = [
                'id' => $id,
                'text' => $text,
                'line' => $line,
                'start' => $offset,
                'end' => $offset + strlen($text),
            ];
            $offset += strlen($text);
            $line += substr_count($text, "\n");
        }
        return $tokens;
    }

    /** @param list<array<string,mixed>> $tokens */
    private function parseNamespace(array $tokens): string
    {
        foreach ($tokens as $i => $token) {
            if ($token['id'] !== T_NAMESPACE) {
                continue;
            }
            $parts = [];
            for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
                $t = $tokens[$j];
                if ($t['text'] === ';' || $t['text'] === '{') {
                    return trim(implode('', $parts), "\\ \t\r\n");
                }
                $parts[] = $t['text'];
            }
        }
        return '';
    }

    /** @param list<array<string,mixed>> $tokens @return array<string,string> */
    private function parseImports(array $tokens, string $namespace): array
    {
        $imports = [];
        $depth = 0;
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t['text'] === '{') { $depth++; continue; }
            if ($t['text'] === '}') { $depth--; continue; }
            if ($depth !== 0 || $t['id'] !== T_USE) {
                continue;
            }

            $statement = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $statement .= $tokens[$j]['text'];
                if ($tokens[$j]['text'] === ';') {
                    break;
                }
            }
            $i = $j;

            $statement = trim(rtrim($statement, ';'));
            if ($statement === '' || str_starts_with($statement, 'function ') || str_starts_with($statement, 'const ')) {
                continue;
            }

            // Group-use: use Foo\{Bar, Baz as Alias};
            if (preg_match('/^([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\\\\{(.+)}$/s', $statement, $gm)) {
                $baseNs = $gm[1];
                foreach (explode(',', $gm[2]) as $member) {
                    $member = trim($member);
                    if ($member === '') {
                        continue;
                    }
                    $memberPieces = preg_split('/\s+as\s+/i', $member, 2) ?: [];
                    $memberName = trim($memberPieces[0]);
                    $alias = trim($memberPieces[1] ?? $memberName);
                    $imports[$alias] = $baseNs . '\\' . $memberName;
                }
            } else {
                foreach (explode(',', $statement) as $part) {
                    $part = trim($part);
                    if ($part === '' || str_contains($part, '{')) {
                        continue;
                    }
                    $pieces = preg_split('/\s+as\s+/i', $part, 2) ?: [];
                    $fqcn = trim($pieces[0] ?? '', "\\ ");
                    if ($fqcn === '') {
                        continue;
                    }
                    $alias = trim($pieces[1] ?? $this->shortName($fqcn));
                    $imports[$alias] = $fqcn;
                }
            }
        }
        return $imports;
    }

    /** @param list<array<string,mixed>> $tokens @return array<int,int> */
    private function bracePairs(array $tokens): array
    {
        $stack = [];
        $pairs = [];
        foreach ($tokens as $i => $t) {
            if ($t['text'] === '{') {
                $stack[] = $i;
            } elseif ($t['text'] === '}') {
                $open = array_pop($stack);
                if ($open !== null) {
                    $pairs[$open] = $i;
                }
            }
        }
        return $pairs;
    }

    /**
     * @param list<array<string,mixed>> $tokens
     * @param array<int,int> $bracePairs
     * @param array<string,string> $imports
     * @param array<string,mixed> $file
     * @return list<array<string,mixed>>
     */
    private function parseClasses(array $tokens, array $bracePairs, string $namespace, array $imports, array $file): array
    {
        $classes = [];
        $kindTokens = [T_CLASS => 'class', T_INTERFACE => 'interface', T_TRAIT => 'trait'];
        if (defined('T_ENUM')) {
            $kindTokens[constant('T_ENUM')] = 'enum';
        }

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if (!isset($kindTokens[$token['id']])) {
                continue;
            }

            $kind = $kindTokens[$token['id']];
            $name = null;
            $openBrace = null;
            $header = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if ($name === null && $t['id'] === T_STRING) {
                    $name = $t['text'];
                }
                $header .= $t['text'];
                if ($t['text'] === '{') {
                    $openBrace = $j;
                    break;
                }
                if ($t['text'] === ';') {
                    break;
                }
            }

            if ($name === null || $openBrace === null || !isset($bracePairs[$openBrace])) {
                continue;
            }

            $fqcn = $namespace !== '' ? $namespace . '\\' . $name : $name;
            $parent = null;
            $implements = [];

            if (preg_match('/\bextends\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)/i', $header, $m)) {
                $parent = $this->resolveClassName($m[1], $namespace, $imports);
            }
            if (preg_match('/\bimplements\s+(.+?)(?:\{|$)/is', $header, $m)) {
                foreach (explode(',', trim($m[1])) as $candidate) {
                    $candidate = trim($candidate);
                    if ($candidate !== '') {
                        $implements[] = $this->resolveClassName($candidate, $namespace, $imports);
                    }
                }
            }

            $classes[] = [
                'fqcn' => $fqcn,
                'name' => $name,
                'kind' => $kind,
                'parent' => $parent,
                'implements' => $implements,
                'file' => $file['path'],
                'relative' => $file['relative'],
                'line' => $token['line'],
                'body_start_token' => $openBrace,
                'body_end_token' => $bracePairs[$openBrace],
                'body_start_offset' => $tokens[$openBrace]['end'],
                'body_end_offset' => $tokens[$bracePairs[$openBrace]]['start'],
                'namespace' => $namespace,
                'imports' => $imports,
                'properties' => [],
            ];
        }

        foreach ($classes as &$class) {
            $class['properties'] = $this->parseTypedProperties($file['code'], $class);
        }
        unset($class);

        return $classes;
    }

    /** @param array<string,mixed> $class @return array<string,string> */
    private function parseTypedProperties(string $code, array $class): array
    {
        $segment = substr($code, (int)$class['body_start_offset'], (int)$class['body_end_offset'] - (int)$class['body_start_offset']);
        $properties = [];
        if (preg_match_all('/(?:public|protected|private)\s+(?:readonly\s+)?([\\\\A-Za-z_][\\\\A-Za-z0-9_|?&]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)\s*(?:=|;)/', $segment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $properties[$m[2]] = $this->resolveType($m[1], $class);
            }
        }
        // Constructor-promoted properties.
        if (preg_match_all('/(?:public|protected|private)\s+(?:readonly\s+)?([\\\\A-Za-z_][\\\\A-Za-z0-9_|?&]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $segment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (!isset($properties[$m[2]])) {
                    $properties[$m[2]] = $this->resolveType($m[1], $class);
                }
            }
        }
        return $properties;
    }

    /**
     * @param list<array<string,mixed>> $tokens
     * @param array<int,int> $bracePairs
     * @param list<array<string,mixed>> $classSpans
     * @param array<string,mixed> $file
     * @return list<array<string,mixed>>
     */
    private function parseMethods(array $tokens, array $bracePairs, array $classSpans, array $file): array
    {
        $methods = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]['id'] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $this->nextMeaningful($tokens, $i + 1);
            if ($nameIndex !== null && $tokens[$nameIndex]['text'] === '&') {
                $nameIndex = $this->nextMeaningful($tokens, $nameIndex + 1);
            }
            if ($nameIndex === null || $tokens[$nameIndex]['id'] !== T_STRING) {
                continue; // Closure/anonymous function.
            }

            $name = $tokens[$nameIndex]['text'];
            $openParen = $this->nextChar($tokens, $nameIndex + 1, '(');
            if ($openParen === null) {
                continue;
            }
            $closeParen = $this->matchingParen($tokens, $openParen);
            if ($closeParen === null) {
                continue;
            }

            $bodyStart = null;
            $terminator = null;
            for ($j = $closeParen + 1; $j < $n; $j++) {
                if ($tokens[$j]['text'] === '{' || $tokens[$j]['text'] === ';') {
                    $terminator = $j;
                    if ($tokens[$j]['text'] === '{') {
                        $bodyStart = $j;
                    }
                    break;
                }
            }
            if ($terminator === null) {
                continue;
            }

            $offset = (int)$tokens[$i]['start'];
            $class = $this->classAtOffset($classSpans, $offset);
            if ($class === null) {
                continue; // Global functions are outside the tool's method call graph.
            }

            $paramsRaw = $this->tokensText($tokens, $openParen + 1, $closeParen - 1);
            $returnRaw = $this->returnTypeFromTokens($tokens, $closeParen + 1, $terminator - 1);
            $endToken = $bodyStart !== null ? ($bracePairs[$bodyStart] ?? $bodyStart) : $terminator;
            $bodyStartOffset = $bodyStart !== null ? (int)$tokens[$bodyStart]['end'] : (int)$tokens[$terminator]['start'];
            $bodyEndOffset = $bodyStart !== null ? (int)$tokens[$endToken]['start'] : $bodyStartOffset;

            $paramTypes = $this->parseParameterTypes($paramsRaw, $class);
            $id = $class['fqcn'] . '::' . $name;
            $methods[] = [
                'id' => $id,
                'class' => $class['fqcn'],
                'name' => $name,
                'file' => $file['path'],
                'relative' => $file['relative'],
                'line' => $tokens[$i]['line'],
                'end_line' => $tokens[$endToken]['line'],
                'params_raw' => $this->normaliseSignaturePart($paramsRaw),
                'params' => $paramTypes,
                'return' => $returnRaw !== '' ? $this->normaliseSignaturePart($returnRaw) : null,
                'body_start_offset' => $bodyStartOffset,
                'body_end_offset' => $bodyEndOffset,
                'class_meta' => $class,
            ];
        }
        return $methods;
    }

    /** @param list<array<string,mixed>> $tokens */
    private function nextMeaningful(array $tokens, int $start): ?int
    {
        for ($i = $start, $n = count($tokens); $i < $n; $i++) {
            if (trim($tokens[$i]['text']) !== '') {
                return $i;
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $tokens */
    private function nextChar(array $tokens, int $start, string $char): ?int
    {
        for ($i = $start, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]['text'] === $char) {
                return $i;
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $tokens */
    private function matchingParen(array $tokens, int $open): ?int
    {
        $level = 0;
        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]['text'] === '(') { $level++; }
            if ($tokens[$i]['text'] === ')') {
                $level--;
                if ($level === 0) { return $i; }
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $tokens */
    private function tokensText(array $tokens, int $start, int $end): string
    {
        $text = '';
        for ($i = $start; $i <= $end && isset($tokens[$i]); $i++) {
            $text .= $tokens[$i]['text'];
        }
        return $text;
    }

    /** @param list<array<string,mixed>> $tokens */
    private function returnTypeFromTokens(array $tokens, int $start, int $end): string
    {
        $text = $this->tokensText($tokens, $start, $end);
        if (preg_match('/:\s*(.+)$/s', $text, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** @param list<array<string,mixed>> $classes @return array<string,mixed>|null */
    private function classAtOffset(array $classes, int $offset): ?array
    {
        $candidate = null;
        foreach ($classes as $class) {
            if ($offset >= $class['body_start_offset'] && $offset <= $class['body_end_offset']) {
                if ($candidate === null || ($class['body_end_offset'] - $class['body_start_offset']) < ($candidate['body_end_offset'] - $candidate['body_start_offset'])) {
                    $candidate = $class;
                }
            }
        }
        return $candidate;
    }

    /** @param array<string,mixed> $class @return array<string,string|array<string,mixed>> */
    private function parseParameterTypes(string $raw, array $class): array
    {
        $result = [];
        foreach ($this->splitTopLevel($raw, ',') as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            if (preg_match('/(?:(?<type>\??[\\\\A-Za-z_][\\\\A-Za-z0-9_|?&]*)\s+)?&?\s*(?:\.\.\.)?\s*(?<var>\$[A-Za-z_][A-Za-z0-9_]*)/', $part, $m)) {
                $var = ltrim($m['var'], '$');
                $type = trim($m['type'] ?? '');
                if ($type !== '') {
                    $result[$var] = $this->resolveType($type, $class);
                }
            }
        }
        return $result;
    }

    /** @return list<string> */
    private function splitTopLevel(string $input, string $separator): array
    {
        $parts = [];
        $current = '';
        $level = 0;
        $quote = null;
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote && ($i === 0 || $input[$i - 1] !== '\\')) { $quote = null; }
                continue;
            }
            if ($char === "'" || $char === '"') { $quote = $char; $current .= $char; continue; }
            if (in_array($char, ['(', '[', '{'], true)) { $level++; }
            if (in_array($char, [')', ']', '}'], true)) { $level--; }
            if ($char === $separator && $level === 0) { $parts[] = $current; $current = ''; continue; }
            $current .= $char;
        }
        $parts[] = $current;
        return $parts;
    }

    /** @param array<string,mixed> $class @return string|array<string,mixed> */
    private function resolveType(string $type, array $class)
    {
        $type = trim($type);
        $type = ltrim($type, '?');
        if (preg_match('/[|&]/', $type)) {
            $parts = preg_split('/[|&]/', $type);
            $resolved = [];
            foreach ($parts as $part) {
                $part = trim(trim($part), "[] ");
                if ($part === '' || in_array(strtolower($part), ['int', 'string', 'float', 'bool', 'array', 'iterable', 'callable', 'mixed', 'object', 'void', 'null', 'false', 'true'], true)) {
                    $resolved[] = $part;
                } elseif ($part === 'self' || $part === 'static') {
                    $resolved[] = $class['fqcn'];
                } elseif ($part === 'parent') {
                    $resolved[] = (string)($class['parent'] ?? 'parent');
                } else {
                    $resolved[] = $this->resolveClassName($part, $class['namespace'], $class['imports']);
                }
            }
            return ['types' => $resolved, 'union' => true];
        }
        $type = trim($type, "[] ");
        if ($type === '' || in_array(strtolower($type), ['int', 'string', 'float', 'bool', 'array', 'iterable', 'callable', 'mixed', 'object', 'void', 'null', 'false', 'true'], true)) {
            return $type;
        }
        if ($type === 'self' || $type === 'static') { return $class['fqcn']; }
        if ($type === 'parent') { return (string)($class['parent'] ?? 'parent'); }
        return $this->resolveClassName($type, $class['namespace'], $class['imports']);
    }

    /** @param array<string,string> $imports */
    private function resolveClassName(string $name, string $namespace, array $imports): string
    {
        $name = trim($name, " \\?");
        if ($name === '') { return $name; }
        if (str_contains($name, '\\')) {
            $first = explode('\\', $name, 2)[0];
            if (isset($imports[$first])) {
                return $imports[$first] . (str_contains($name, '\\') ? '\\' . explode('\\', $name, 2)[1] : '');
            }
            return $name;
        }
        if (isset($imports[$name])) { return $imports[$name]; }
        return $namespace !== '' ? $namespace . '\\' . $name : $name;
    }

    private function indexLaravelBindings(): void
    {
        foreach ($this->files as $file) {
            $code = $file['code'];
            $namespace = $file['namespace'];
            $imports = $file['imports'];
            if (!preg_match_all('/(?:->|::)(?:bind|singleton|scoped|instance)\s*\(\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class\s*,\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class/s', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches as $m) {
                $abstract = $this->resolveClassName($m[1][0], $namespace, $imports);
                $concrete = $this->resolveClassName($m[2][0], $namespace, $imports);
                $line = substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
                $this->bindings[$abstract][] = [
                    'abstract' => $abstract,
                    'concrete' => $concrete,
                    'file' => $file['path'],
                    'relative' => $file['relative'],
                    'line' => $line,
                ];
            }
        }
    }

    /** @return array<string,mixed>|null */
    public function findMethod(string $symbol): ?array
    {
        $symbol = ltrim(trim($symbol), '\\');
        if (isset($this->methods[$symbol])) {
            return $this->methods[$symbol];
        }
        if (!str_contains($symbol, '::')) {
            return null;
        }
        [$class, $method] = explode('::', $symbol, 2);
        $resolvedClass = $this->resolveKnownClass($class);
        return $resolvedClass !== null ? ($this->methods[$resolvedClass . '::' . $method] ?? null) : null;
    }

    private function resolveKnownClass(string $name): ?string
    {
        $name = ltrim($name, '\\');
        if (isset($this->classes[$name])) { return $name; }
        $short = $this->shortName($name);
        $candidates = $this->shortClassIndex[$short] ?? [];
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** @return list<array<string,mixed>> */
    public function callsFrom(array $method): array
    {
        if (isset($this->callsCache[$method['id']])) {
            return $this->callsCache[$method['id']];
        }

        $code = $this->files[$method['file']]['code'];
        $body = substr($code, (int)$method['body_start_offset'], (int)$method['body_end_offset'] - (int)$method['body_start_offset']);
        $calls = [];
        $seen = [];
        $class = $method['class_meta'];
        $lineBase = substr_count(substr($code, 0, (int)$method['body_start_offset']), "\n") + 1;
        $varTypes = $method['params'];
        $varSources = [];

        foreach ($method['params'] as $pName => $pType) {
            $varSources[$pName] = 'receiver resolved from typed method parameter';
        }

        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:app|resolve)\s*\(\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class\s*\)/', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $varTypes[$m[1]] = $this->resolveClassName($m[2], $class['namespace'], $class['imports']);
                $varSources[$m[1]] = 'receiver resolved from app()/resolve() helper';
            }
        }
        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*[\(;]/', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $varTypes[$m[1]] = $this->resolveClassName($m[2], $class['namespace'], $class['imports']);
                $varSources[$m[1]] = 'receiver resolved from new expression';
            }
        }
        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$this->([A-Za-z_][A-Za-z0-9_]*)\s*;/', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (isset($class['properties'][$m[2]])) {
                    $varTypes[$m[1]] = $class['properties'][$m[2]];
                    $varSources[$m[1]] = 'receiver resolved from declared property type';
                }
            }
        }
        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $var = $m[1][0];
                $className = $this->resolveClassName($m[2][0], $class['namespace'], $class['imports']);
                $mName = $m[3][0];
                $varTypes[$var] = $className;
                $shortClass = $this->shortName($className);
                $varSources[$var] = 'receiver inferred from assignment: $' . $var . ' = ' . $shortClass . '::' . $mName . '(...)';
            }
        }

        $patterns = [
            ['kind' => 'self_call', 'regex' => '/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(([^()]*(?:\([^)]*\)[^()]*)*)\)/'],
            ['kind' => 'property_call', 'regex' => '/\$this->([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)\s*\(([^()]*(?:\([^)]*\)[^()]*)*)\)/'],
            ['kind' => 'variable_call', 'regex' => '/\$(?!this->)([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)\s*\(([^()]*(?:\([^)]*\)[^()]*)*)\)/'],
            ['kind' => 'static_call', 'regex' => '/(?<![\\\\A-Za-z0-9_])([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)\s*\(([^()]*(?:\([^)]*\)[^()]*)*)\)/'],
            ['kind' => 'new_call', 'regex' => '/new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\([^;]*?\)\s*->([A-Za-z_][A-Za-z0-9_]*)\s*\(([^()]*(?:\([^)]*\)[^()]*)*)\)/'],
        ];

        foreach ($patterns as $entry) {
            if (!preg_match_all($entry['regex'], $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches as $m) {
                $kind = $entry['kind'];
                $receiverType = null;
                $receiverEvidence = null;
                $candidates = [];
                $called = '';
                $args = '';
                if ($kind === 'self_call') {
                    $receiverType = $method['class']; $called = $m[1][0]; $args = $m[2][0];
                    $receiverEvidence = 'receiver resolved from self context';
                } elseif ($kind === 'property_call') {
                    $receiverType = $class['properties'][$m[1][0]] ?? null; $called = $m[2][0]; $args = $m[3][0];
                    $receiverEvidence = 'receiver resolved from declared property type';
                } elseif ($kind === 'variable_call') {
                    $vt = $varTypes[$m[1][0]] ?? null;
                    if (is_array($vt) && ($vt['union'] ?? false)) {
                        $candidates = $vt['types'];
                        $receiverType = $candidates[0] ?? null;
                        $receiverEvidence = 'receiver ambiguous: ' . implode(' | ', $candidates);
                    } elseif (is_string($vt)) {
                        $receiverType = $vt;
                        $receiverEvidence = $varSources[$m[1][0]] ?? 'receiver resolved from variable inference';
                    }
                    $called = $m[2][0]; $args = $m[3][0];
                } else {
                    $receiverType = $this->resolveClassName($m[1][0], $class['namespace'], $class['imports']); $called = $m[2][0]; $args = $m[3][0];
                    $receiverEvidence = 'receiver resolved from imported static class';
                }

                $line = $lineBase + substr_count(substr($body, 0, $m[0][1]), "\n");
                $edge = $this->resolveCall($method, $receiverType, $called, $this->normaliseSignaturePart($args), $kind, $line);
                $edge['receiver_evidence'] = $receiverEvidence;
                $key = $edge['target_id'] . '|' . $edge['line'] . '|' . $edge['called'];
                if (!isset($seen[$key])) {
                    $calls[] = $edge;
                    $seen[$key] = true;
                }
            }
        }

        usort($calls, static function (array $a, array $b): int {
            return $a['line'] <=> $b['line'];
        });
        return $this->callsCache[$method['id']] = $calls;
    }

    /** @return array<string,mixed> */
    private function resolveCall(array $caller, ?string $receiverType, string $called, string $args, string $kind, int $line): array
    {
        $base = [
            'kind' => $kind,
            'caller_id' => $caller['id'],
            'receiver_type' => $receiverType,
            'called' => $called,
            'args' => $args,
            'line' => $line,
            'source_relative' => $caller['relative'],
            'target_id' => null,
            'confidence' => 'unresolved',
            'evidence' => 'receiver type could not be determined statically',
            'binding' => null,
            'candidates' => [],
        ];
        if ($receiverType === null || $receiverType === '') {
            return $base;
        }

        $bindingCandidates = $this->bindings[$receiverType] ?? [];
        $receiverIsInterface = ($this->classes[$receiverType]['kind'] ?? null) === 'interface';

        // For an interface receiver, prefer a declared Laravel binding because it leads to
        // the concrete runtime implementation; keep the receiver type in the rendered edge.
        if ($receiverIsInterface) {
            foreach ($bindingCandidates as $binding) {
                $target = $this->resolveMethodOnClass($binding['concrete'], $called);
                if ($target !== null) {
                    $base['target_id'] = $target['id'];
                    $base['confidence'] = 'inferred';
                    $base['evidence'] = 'Laravel container binding';
                    $base['binding'] = $binding;
                    return $base;
                }
            }
        }

        $direct = $this->resolveMethodOnClass($receiverType, $called);
        if ($direct !== null) {
            $base['target_id'] = $direct['id'];
            $base['confidence'] = 'confirmed';
            $base['evidence'] = 'declared method resolved from static receiver type';
            return $base;
        }

        foreach ($bindingCandidates as $binding) {
            $target = $this->resolveMethodOnClass($binding['concrete'], $called);
            if ($target !== null) {
                $base['target_id'] = $target['id'];
                $base['confidence'] = 'inferred';
                $base['evidence'] = 'Laravel container binding';
                $base['binding'] = $binding;
                return $base;
            }
        }

        $candidates = $this->findImplementationsWithMethod($receiverType, $called);
        if (count($candidates) === 1) {
            $base['target_id'] = $candidates[0]['id'];
            $base['confidence'] = 'inferred';
            $base['evidence'] = 'single implementation found';
            return $base;
        }
        if ($candidates) {
            $base['confidence'] = 'candidate';
            $base['evidence'] = 'multiple implementations found';
            $base['candidates'] = array_map(static function (array $m): string {
                return $m['id'];
            }, $candidates);
        } else {
            $base['evidence'] = 'method not found in indexed class hierarchy';
        }
        return $base;
    }

    /** @return array<string,mixed>|null */
    private function resolveMethodOnClass(string $class, string $method): ?array
    {
        $seen = [];
        $current = $class;
        while ($current !== '' && !isset($seen[$current])) {
            $seen[$current] = true;
            $id = $current . '::' . $method;
            if (isset($this->methods[$id])) {
                return $this->methods[$id];
            }
            $current = (string)($this->classes[$current]['parent'] ?? '');
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function findImplementationsWithMethod(string $interfaceOrAbstract, string $method): array
    {
        $matches = [];
        foreach ($this->classes as $class) {
            if ($class['kind'] !== 'class') { continue; }
            if (!in_array($interfaceOrAbstract, $class['implements'], true) && ($class['parent'] ?? null) !== $interfaceOrAbstract) {
                continue;
            }
            $candidate = $this->resolveMethodOnClass($class['fqcn'], $method);
            if ($candidate !== null) { $matches[] = $candidate; }
        }
        return $matches;
    }

    /** @return array<string,mixed> */
    public function parseLog(string $log): array
    {
        $exception = null;
        if (preg_match('/(?:Call to undefined method|Error|Exception|TypeError|RuntimeException)[^\n]*/', $log, $m)) {
            $exception = trim($m[0]);
        }

        $errorSymbol = null;
        if (preg_match('/(?:undefined method\s+)?([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $log, $m)) {
            $errorSymbol = ltrim($m[1], '\\') . '::' . $m[2];
        }

        $frames = [];
        if (preg_match_all('/#\d+\s+(.+?)\((\d+)\):\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:->|::)([A-Za-z_][A-Za-z0-9_]*)\(([^\n]*)\)/m', $log, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $file = trim($m[1]);
                $relative = $this->normaliseFramePath($file);
                $frames[] = [
                    'file' => $relative,
                    'line' => (int)$m[2],
                    'class' => ltrim($m[3], '\\'),
                    'method' => $m[4],
                    'args' => trim($m[5]),
                    'symbol' => ltrim($m[3], '\\') . '::' . $m[4],
                    'app_frame' => $relative !== null && !str_starts_with($relative, 'vendor/'),
                ];
            }
        }

        return ['exception' => $exception, 'error_symbol' => $errorSymbol, 'frames' => $frames];
    }

    private function normaliseFramePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->root);
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }
        $needle = '/app/';
        $pos = strpos($path, $needle);
        if ($pos !== false) {
            return substr($path, $pos + 1);
        }
        $pos = strpos($path, '/vendor/');
        if ($pos !== false) {
            return substr($path, $pos + 1);
        }
        return $path !== '' ? $path : null;
    }

    public function renderFromLog(string $log): string
    {
        $parsed = $this->parseLog($log);
        $out = [];
        $out[] = 'TRACE MAP (dependency-free static analysis)';
        $out[] = str_repeat('=', 48);
        $out[] = 'Project: ' . $this->root;
        $out[] = 'Indexed: ' . count($this->classes) . ' classes/interfaces/traits, ' . count($this->methods) . ' methods';

        if ($parsed['exception']) {
            $out[] = '';
            $out[] = 'Exception';
            $out[] = str_repeat('-', 48);
            $out[] = $parsed['exception'];
        }

        $appFrames = array_values(array_filter($parsed['frames'], static function (array $f): bool {
            return $f['app_frame'];
        }));
        if ($appFrames) {
            $out[] = '';
            $out[] = 'Runtime stack (evidence from log)';
            $out[] = str_repeat('-', 48);
            foreach (array_reverse($appFrames) as $i => $frame) {
                $method = $this->findMethod($frame['symbol']);
                $label = $method ? $this->formatMethod($method) : $frame['symbol'] . '(' . $frame['args'] . ')';
                $indent = str_repeat('    ', $i);
                $branch = $i === 0 ? '└── ' : '└── ';
                $out[] = $indent . $branch . $label;
                $out[] = $indent . '    file: ' . $frame['file'] . ':' . $frame['line'] . ' [confirmed]';
            }
        }

        $roots = [];
        if ($appFrames) {
            $rootFrame = end($appFrames);
            $method = $this->findMethod($rootFrame['symbol']);
            if ($method) { $roots[] = $method; }
        }
        if (!$roots && $parsed['error_symbol']) {
            $method = $this->findMethod($parsed['error_symbol']);
            if ($method) { $roots[] = $method; }
        }

        if ($roots) {
            $out[] = '';
            $out[] = 'Static call tree (relationships inferred from source)';
            $out[] = str_repeat('-', 48);
            $seen = [];
            foreach ($roots as $root) {
                $this->renderMethodTree($root, 0, $this->depth, $seen, $out, null);
            }
        } elseif ($parsed['error_symbol']) {
            $out[] = '';
            $out[] = 'Unresolved error symbol';
            $out[] = str_repeat('-', 48);
            $out[] = '└── ' . $parsed['error_symbol'] . '()';
            $out[] = '    definition: not found in indexed project files';
            $out[] = '    note: this can indicate a stale caller, dynamic macro/magic method, an external package, or a missing deployment artifact.';
        } else {
            $out[] = '';
            $out[] = 'No usable application stack frame or class::method symbol found in the input.';
            $out[] = 'Use --symbol="Namespace\\Class::method" to inspect a known method directly.';
        }

        return implode(PHP_EOL, $out) . PHP_EOL;
    }

    /** @param array<string,bool> $seen @param list<string> $out */
    private function renderMethodTree(array $method, int $level, int $remaining, array &$seen, array &$out, ?array $via): void
    {
        $indent = str_repeat('│   ', $level);
        $prefix = ($via['is_last'] ?? false) ? '└── ' : '├── ';
        if ($via !== null) {
            $args = $via['args'] !== '' ? $via['args'] : '';
            $out[] = $indent . $prefix . 'calls ' . ($via['receiver_type'] ?: '$dynamic') . '::' . $via['called'] . '(' . $args . ')';
            $detailIndent = ($via['is_last'] ?? false)
                ? $indent . '    '
                : $indent . '│   ';
            $out[] = $detailIndent . 'line: ' . $via['source_relative'] . ':' . $via['line'] . ' [' . $via['confidence'] . ']';
            if ($via['receiver_evidence'] ?? null) {
                $out[] = $detailIndent . '[' . $via['receiver_evidence'] . ']';
            }
            if ($via['binding']) {
                $b = $via['binding'];
                $out[] = $detailIndent . 'binding: ' . $b['abstract'] . ' => ' . $b['concrete'] . ' (' . $b['relative'] . ':' . $b['line'] . ')';
            }
            if ($via['candidates']) {
                $out[] = $detailIndent . 'candidates: ' . implode(', ', $via['candidates']);
            }
            $level++;
            $indent = ($via['is_last'] ?? false)
                ? str_repeat('│   ', max(0, $level - 1)) . '    '
                : str_repeat('│   ', $level);
        }

        $id = $method['id'];
        $out[] = $indent . '└── ' . $this->formatMethod($method);
        $out[] = $indent . '    file: ' . $method['relative'] . ':' . $method['line'];

        if (isset($seen[$id])) {
            $out[] = $indent . '    cycle: method already visited; traversal stopped';
            return;
        }
        $seen[$id] = true;
        if ($remaining <= 0) {
            $out[] = $indent . '    depth limit reached';
            return;
        }

        $calls = $this->callsFrom($method);
        if (!$calls) {
            $out[] = $indent . '    no statically resolved outgoing method calls';
            return;
        }

        foreach ($calls as $idx => $call) {
            $isLastCall = $idx === count($calls) - 1;
            if ($call['target_id'] && isset($this->methods[$call['target_id']])) {
                $call['is_last'] = $isLastCall;
                $this->renderMethodTree($this->methods[$call['target_id']], $level, $remaining - 1, $seen, $out, $call);
            } else {
                $childIndent = str_repeat('│   ', $level);
                $branch = $isLastCall ? '└── ' : '├── ';
                $out[] = $childIndent . $branch . 'calls ' . ($call['receiver_type'] ?: '$dynamic') . '::' . $call['called'] . '(' . $call['args'] . ')';
                $detailIndent = $isLastCall ? ($childIndent . '    ') : ($childIndent . '│   ');
                $out[] = $detailIndent . 'line: ' . $method['relative'] . ':' . $call['line'] . ' [' . $call['confidence'] . ']';
                if ($call['receiver_evidence'] ?? null) {
                    $out[] = $detailIndent . '[' . $call['receiver_evidence'] . ']';
                }
                if ($call['candidates']) {
                    $out[] = $detailIndent . 'candidates: ' . implode(', ', $call['candidates']);
                }
            }
        }
    }

    /** @param array<string,mixed> $method */
    private function formatMethod(array $method): string
    {
        $params = trim((string)$method['params_raw']);
        $return = $method['return'] ? ': ' . $method['return'] : '';
        return $method['id'] . '(' . $params . ')' . $return;
    }

    private function relative(string $path): string
    {
        $normal = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->root);
        return str_starts_with($normal, $root . '/') ? substr($normal, strlen($root) + 1) : $normal;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        return (string)end($parts);
    }

    private function normaliseSignaturePart(string $text): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}

function selfTest(): int
{
    $tmp = sys_get_temp_dir() . '/trace-map-test-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/app/Http/Controllers', 0777, true);
    mkdir($tmp . '/app/Http/Requests', 0777, true);
    mkdir($tmp . '/app/Models', 0777, true);

    file_put_contents($tmp . '/app/Http/Controllers/GameController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Http\Requests\GameRequest;
use App\Models\Game;

class GameController
{
    public function store(GameRequest $request)
    {
        $form = $request->validated();
        $game = Game::create($form);

        return $game->toResource();
    }
}
PHP
    );

    file_put_contents($tmp . '/app/Http/Requests/GameRequest.php', <<<'PHP'
<?php

namespace App\Http\Requests;

class GameRequest
{
}
PHP
    );

    file_put_contents($tmp . '/app/Models/Game.php', <<<'PHP'
<?php

namespace App\Models;

class Game
{
    public function toResource()
    {
        return [];
    }
}
PHP
    );

    $map = new TraceMap($tmp, 5, false);
    $map->index();

    $method = $map->findMethod("App\\Http\\Controllers\\GameController::store");
    if ($method === null) {
        echo "SELF-TEST FAIL: could not find GameController::store\n";
        recursiveDelete($tmp);
        return 1;
    }

    $symbol = $method['id'];
    $log = "Static inspection: {$symbol}()\n#0 {$method['file']}({$method['line']}): {$symbol}()\n";
    $output = $map->renderFromLog($log);

    $checks = [
        'App\Http\Requests\GameRequest::validated()' => false,
        'App\Models\Game::create($form)' => false,
        'App\Models\Game::toResource()' => false,
    ];

    foreach (array_keys($checks) as $needle) {
        if (str_contains($output, $needle)) {
            $checks[$needle] = true;
        }
    }

    recursiveDelete($tmp);

    $failures = array_keys(array_filter($checks, static function (bool $v): bool {
        return !$v;
    }));
    if ($failures) {
        echo "SELF-TEST FAIL: missing expected calls:\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
        echo "\nFull output:\n{$output}\n";
        return 1;
    }

    echo "SELF-TEST PASS: all expected call receivers resolved correctly.\n";
    return 0;
}

function recursiveDelete(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) { rmdir($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($dir);
}

function usage(): void
{
    $ver = TM_VERSION;
    echo <<<TXT
trace-map.php v{$ver}
Usage:
  php trace-map.php --log=storage/logs/laravel.log [--depth=5] [--include-tests]
  cat storage/logs/laravel.log | php trace-map.php [--depth=5]
  php trace-map.php --symbol="App\\Http\\Controllers\\CheckoutController::store" [--depth=5]

Options:
  --log=FILE         Log file. If omitted, reads STDIN.
  --symbol=FQCN::m   Inspect a known method without a log.
  --path=DIR         Project root (default: current directory).
  --depth=N          Maximum static call-tree depth, 1..12 (default: 5).
  --include-tests    Include tests/ in the index.
  --version          Print version and exit.
  --self-test        Run internal validation tests.
  --help             Show this help.
TXT;
}

$options = getopt('', ['log::', 'symbol::', 'path::', 'depth::', 'include-tests', 'help', 'version', 'self-test']);
if (isset($options['help'])) {
    usage();
    exit(0);
}
if (isset($options['version'])) {
    echo 'trace-map.php ' . TM_VERSION . PHP_EOL;
    exit(0);
}
if (isset($options['self-test'])) {
    exit(selfTest());
}

$root = realpath((string)($options['path'] ?? getcwd()));
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "Invalid project path.\n");
    exit(1);
}

$depth = isset($options['depth']) ? (int)$options['depth'] : 5;
$map = new TraceMap($root, $depth, array_key_exists('include-tests', $options));
$map->index();

if (!empty($options['symbol'])) {
    $method = $map->findMethod((string)$options['symbol']);
    if ($method === null) {
        fwrite(STDERR, "Method not found in indexed project files: {$options['symbol']}\n");
        exit(2);
    }

    // Synthetic log retains one renderer and explicitly labels the root as a static inspection.
    $symbol = $method['id'];
    $log = "Static inspection: {$symbol}()\n#0 {$method['file']}({$method['line']}): {$symbol}()\n";
    echo $map->renderFromLog($log);
    exit(0);
}

$log = '';
if (!empty($options['log'])) {
    $log = @file_get_contents((string)$options['log']);
    if ($log === false) {
        fwrite(STDERR, "Could not read log file: {$options['log']}\n");
        exit(1);
    }
} else {
    $log = stream_get_contents(STDIN);
}

if (trim($log) === '') {
    usage();
    exit(1);
}

echo $map->renderFromLog($log);
