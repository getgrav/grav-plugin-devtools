<?php

namespace Grav\Plugin\Console;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

require_once(__DIR__ . '/../classes/DevToolsCommand.php');

/**
 * Class I18nBlueprintsCommand
 *
 * Audit (and optionally rewrite) plugin/theme blueprint YAML files for
 * hardcoded English strings that should be translation keys.
 *
 * Examples:
 *   bin/plugin devtools i18n-blueprints
 *   bin/plugin devtools i18n-blueprints user/plugins/editor-pro
 *   bin/plugin devtools i18n-blueprints --details
 *   bin/plugin devtools i18n-blueprints --threshold 0.5
 *   bin/plugin devtools i18n-blueprints user/plugins/foo --fix
 *   bin/plugin devtools i18n-blueprints user/plugins/foo --fix --dry-run
 */
class I18nBlueprintsCommand extends DevToolsCommand
{
    /** Field names whose values should be translatable. */
    private const FIELDS = ['label', 'title', 'description', 'help', 'placeholder', 'hint'];

    /**
     * Translation-key shape: SCREAMING_SNAKE_CASE optionally with dotted
     * segments. Allows digits at the start of subsequent segments
     * (e.g. PLUGIN_LOGIN.2FA_ENABLED_HELP) and single-segment identifiers
     * (e.g. ADMIN_PATH_PLACEHOLDER).
     */
    private const KEY_RE = '/^[A-Z][A-Z0-9_]*(?:\.[A-Z0-9][A-Z0-9_]*)*$/';

    /** @var string[] */
    private array $fieldsList;

    protected function configure(): void
    {
        $this->fieldsList = self::FIELDS;
        $this
            ->setName('i18n-blueprints')
            ->setAliases(['audit-blueprints', 'i18n'])
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Paths to scan: a Grav root, user/plugins dir, container dir, or single plugin/theme. Defaults to current Grav install.'
            )
            ->addOption('details', null, InputOption::VALUE_NONE, 'List every hardcoded value with file:line')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Only show plugins with hardcoded ratio >= N (0–1)', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON instead of a report')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Replace hardcoded strings in blueprints with generated keys, and emit YAML for the lang file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'With --fix: preview without writing any files')
            ->addOption('icu', null, InputOption::VALUE_NONE, 'With --fix: emit keys under ICU.PLUGIN_FOO instead of bare PLUGIN_FOO (admin2-only plugins)')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'With --fix: review each hardcoded value y/n before applying')
            ->addOption('key-length', null, InputOption::VALUE_REQUIRED, 'Max length of generated translation keys (default: 40)', '40')
            ->setDescription('Audit blueprint YAML files for hardcoded English; optionally rewrite to translation keys.')
            ->setHelp("Scans plugin/theme <info>blueprints.yaml</info> and <info>blueprints/**/*.yaml</info> for hardcoded English in <info>label/title/description/help/placeholder/hint</info> fields.\n\nWith <info>--fix</info>, generates a translation key per unique value, rewrites the blueprint YAML, and emits the matching <info>KEY: \"value\"</info> entries to stdout for pasting into the plugin's <info>languages/en.yaml</info>.");
    }

    protected function serve(): int
    {
        $input = $this->getInput();
        $io = $this->getIO();

        $paths = $input->getArgument('paths');
        if (!$paths) {
            // Default: assume cwd is the Grav root.
            $paths = [GRAV_ROOT];
        }

        $threshold = (float) $input->getOption('threshold');
        $details = (bool) $input->getOption('details');
        $jsonOut = (bool) $input->getOption('json');
        $fix = (bool) $input->getOption('fix');
        $dryRun = (bool) $input->getOption('dry-run');
        $icu = (bool) $input->getOption('icu');
        $interactive = (bool) $input->getOption('interactive');
        $keyLength = max(10, (int) $input->getOption('key-length'));

        $plugins = $this->expandRoots($paths);
        if (!$plugins) {
            $io->error('No plugins or themes found at the given path(s).');
            return 1;
        }

        $report = [];
        foreach ($plugins as $pluginDir) {
            $files = $this->findBlueprintFiles($pluginDir);
            if (!$files) {
                continue;
            }
            $perFile = [];
            $totals = ['key' => 0, 'hardcoded' => 0, 'empty' => 0, 'identifier' => 0, 'number' => 0];
            foreach ($files as $file) {
                $findings = $this->scanFile($file);
                if (!$findings) {
                    continue;
                }
                $perFile[$file] = $findings;
                foreach ($findings as $f) {
                    $totals[$f['classification']] = ($totals[$f['classification']] ?? 0) + 1;
                }
            }
            if (!$perFile) {
                continue;
            }
            $report[$pluginDir] = ['files' => $perFile, 'totals' => $totals];
        }

        // Sort by hardcoded ratio descending.
        uasort($report, fn($a, $b) => $this->ratio($b['totals']) <=> $this->ratio($a['totals']));

        if ($jsonOut) {
            $io->writeln(json_encode(array_map(function ($info, $dir) {
                return [
                    'plugin' => basename($dir),
                    'path' => $dir,
                    'totals' => $info['totals'],
                    'ratio' => $this->ratio($info['totals']),
                ];
            }, $report, array_keys($report)), JSON_PRETTY_PRINT));
            return 0;
        }

        if ($fix) {
            return $this->runFix($report, $dryRun, $icu, $interactive, $keyLength, $io);
        }

        $this->printReport($report, $threshold, $details, $io);
        return 0;
    }

    // ----- Path resolution -----

    /**
     * Expand each given path to a list of plugin/theme directories.
     *
     * Detection rules:
     *   - Grav root      → has system/defines.php; expand to user/plugins + user/themes
     *   - Plugin/theme   → has blueprints.yaml; scan it directly
     *   - Container dir  → otherwise; descend one level, pick up subdirs with blueprints.yaml
     */
    private function expandRoots(array $roots): array
    {
        $out = [];
        foreach ($roots as $root) {
            $r = realpath($root) ?: $root;
            if (!is_dir($r)) {
                continue;
            }
            // Grav installation root
            if (file_exists($r . '/system/defines.php')) {
                foreach (['user/plugins', 'user/themes'] as $sub) {
                    $child = $r . '/' . $sub;
                    if (is_dir($child)) {
                        $out = array_merge($out, $this->expandRoots([$child]));
                    }
                }
                continue;
            }
            // Single plugin or theme
            if (file_exists($r . '/blueprints.yaml')) {
                $out[] = $r;
                continue;
            }
            // Container of plugins/themes
            foreach (scandir($r) as $entry) {
                if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                    continue;
                }
                $sub = $r . '/' . $entry;
                if (is_dir($sub) && file_exists($sub . '/blueprints.yaml')) {
                    $out[] = $sub;
                }
            }
        }
        return array_values(array_unique($out));
    }

    private function findBlueprintFiles(string $pluginDir): array
    {
        $files = [];
        if (file_exists($pluginDir . '/blueprints.yaml')) {
            $files[] = $pluginDir . '/blueprints.yaml';
        }
        foreach (['blueprints', 'admin/blueprints'] as $sub) {
            $dir = $pluginDir . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iter as $file) {
                $path = $file->getPathname();
                if (preg_match('/\.ya?ml$/', (string) $path)) {
                    $files[] = $path;
                }
            }
        }
        return $files;
    }

    // ----- Scanning -----

    private function scanFile(string $path): array
    {
        $src = @file_get_contents($path);
        if ($src === false) {
            return [];
        }
        $lines = explode("\n", $src);
        $findings = [];
        $inMultiline = false;
        $multilineIndent = -1;
        $fieldPattern = '/^(\s*)(' . implode('|', $this->fieldsList) . ')\s*:\s*(.*?)\s*$/';
        foreach ($lines as $i => $line) {
            if ($inMultiline) {
                preg_match('/^(\s*)/', $line, $m);
                $indent = strlen($m[1] ?? '');
                if (trim($line) === '' || $indent > $multilineIndent) {
                    continue;
                }
                $inMultiline = false;
            }
            if (!preg_match($fieldPattern, $line, $m)) {
                continue;
            }
            $indent = strlen($m[1]);
            $field = $m[2];
            $rawValue = $m[3];
            $cls = $this->classify($rawValue);
            if ($cls === 'multiline') {
                $inMultiline = true;
                $multilineIndent = $indent;
                continue;
            }
            $findings[] = [
                'line' => $i + 1,
                'field' => $field,
                'rawValue' => $rawValue,
                'value' => $this->stripQuotesAndComment($rawValue),
                'classification' => $cls,
            ];
        }
        return $findings;
    }

    private function classify(?string $rawValue): string
    {
        if ($rawValue === null) {
            return 'empty';
        }
        $v = trim($rawValue);
        if ($v === '' || $v === '~' || $v === 'null' || $v === '""' || $v === "''") {
            return 'empty';
        }
        if ($v === '|' || $v === '>') {
            return 'multiline';
        }
        $v = $this->stripQuotesAndComment($rawValue);
        if ($v === '') {
            return 'empty';
        }
        if (preg_match(self::KEY_RE, $v)) {
            return 'key';
        }
        if (preg_match('/^-?\d/', $v)) {
            return 'number';
        }
        if (preg_match('/^[a-z][\w.-]*$/', $v)) {
            return 'identifier';
        }
        if ($this->looksUntranslatable($v)) {
            return 'identifier';
        }
        return 'hardcoded';
    }

    /**
     * Heuristics for values that look like data, not user-facing text.
     * Conservative on purpose: only matches if the *entire* value fits the
     * pattern. A sentence that contains a URL or filename is still translatable.
     */
    private function looksUntranslatable(string $v): bool
    {
        // Pure punctuation / symbols (===, ---, <<<, *, etc.)
        if (preg_match('/^[^\w\s]+$/u', $v)) {
            return true;
        }
        // URLs
        if (preg_match('#^(https?|ftp|mailto)://#i', $v) || preg_match('#^[\w.+-]+://#', $v)) {
            return true;
        }
        // Email — single token with @ and a TLD-like end
        if (preg_match('/^\S+@\S+\.\S+$/', $v) && !preg_match('/\s/', $v)) {
            return true;
        }
        // File names — whole value is a single token ending in a known extension
        $fileExts = 'svg|png|jpe?g|gif|webp|avif|ico|css|scss|js|mjs|ts|tsx|jsx|html?|md|markdown|ya?ml|json|xml|txt|csv|tsv|ttf|woff2?|eot|otf|pdf|mp[34]|webm|wav|ogg|zip|tar|gz';
        if (preg_match('/^[\w./-]+\.(' . $fileExts . ')$/i', $v)) {
            return true;
        }
        // Phone-ish (mostly digits + separators with at least 7 digits total)
        if (preg_match('/^[+()\d\s.-]+$/', $v) && preg_match_all('/\d/', $v) >= 7) {
            return true;
        }
        // CSS class lists — two or more lowercase-hyphen tokens separated by spaces,
        // no capital letters anywhere (so "Save Changes" isn't caught).
        if (preg_match('/^[a-z][\w-]*(\s+[a-z][\w-]*)+$/', $v) && !preg_match('/[A-Z]/', $v)) {
            return true;
        }
        // API-key-shaped placeholders that contain runs of x/X (xxx-style sentinels)
        if (preg_match('/^[\w.-]+:?[\w.-]*$/', $v) && preg_match('/x{4,}|X{4,}/', $v) && !preg_match('/\s/', $v)) {
            return true;
        }
        // Single non-spaced token with a colon (e.g. "key:value", environment-style)
        if (!preg_match('/\s/', $v) && preg_match('/^[\w.-]+:[\w.-]+$/', $v)) {
            return true;
        }
        return false;
    }

    private function stripQuotesAndComment(string $rawValue): string
    {
        $v = trim($rawValue);
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            return substr($v, 1, -1);
        }
        // Strip trailing inline comment when no quotes are involved.
        if (!str_contains($v, '"') && !str_contains($v, "'")) {
            $hash = strpos($v, '#');
            if ($hash !== false) {
                return rtrim(substr($v, 0, $hash));
            }
        }
        return $v;
    }

    // ----- Reporting -----

    private function ratio(array $totals): float
    {
        $translatable = ($totals['key'] ?? 0) + ($totals['hardcoded'] ?? 0);
        if ($translatable === 0) {
            return 0;
        }
        return ($totals['hardcoded'] ?? 0) / $translatable;
    }

    private function printReport(array $report, float $threshold, bool $details, $io): void
    {
        $io->writeln('<info>=== i18n blueprint scan ===</info>');
        $io->writeln(sprintf('Scanned %d plugin/theme dir(s)', count($report)));
        $io->newLine();
        $io->writeln(sprintf('%-50s  %10s  %5s  %6s', 'Plugin/Theme', 'Hardcoded', 'Keys', 'Ratio'));
        $io->writeln(str_repeat('-', 50) . '  ' . str_repeat('-', 10) . '  ' . str_repeat('-', 5) . '  ' . str_repeat('-', 6));

        $grandHard = 0;
        $grandKeys = 0;
        foreach ($report as $dir => $info) {
            $r = $this->ratio($info['totals']);
            if ($r < $threshold) {
                continue;
            }
            $name = basename($dir);
            $grandHard += $info['totals']['hardcoded'] ?? 0;
            $grandKeys += $info['totals']['key'] ?? 0;
            $colour = $r >= 0.5 ? 'red' : ($r >= 0.2 ? 'yellow' : 'green');
            $io->writeln(sprintf(
                '%-50s  %10d  %5d  <fg=%s>%5d%%</>',
                substr($name, 0, 50),
                $info['totals']['hardcoded'] ?? 0,
                $info['totals']['key'] ?? 0,
                $colour,
                (int) round($r * 100)
            ));
        }
        $io->writeln(str_repeat('-', 50) . '  ' . str_repeat('-', 10) . '  ' . str_repeat('-', 5) . '  ' . str_repeat('-', 6));
        $totalRatio = ($grandHard + $grandKeys) > 0 ? (int) round(($grandHard / ($grandHard + $grandKeys)) * 100) : 0;
        $io->writeln(sprintf('%-50s  %10d  %5d  %5d%%', 'TOTAL', $grandHard, $grandKeys, $totalRatio));

        if ($details) {
            $io->newLine();
            $io->writeln('<info>=== Hardcoded values ===</info>');
            foreach ($report as $dir => $info) {
                if ($this->ratio($info['totals']) < $threshold) {
                    continue;
                }
                $hardcoded = [];
                foreach ($info['files'] as $file => $findings) {
                    foreach ($findings as $f) {
                        if ($f['classification'] === 'hardcoded') {
                            $hardcoded[] = ['file' => $file, 'line' => $f['line'], 'field' => $f['field'], 'value' => $f['value']];
                        }
                    }
                }
                if (!$hardcoded) {
                    continue;
                }
                $io->newLine();
                $io->writeln(sprintf('<comment>### %s</comment>', basename($dir)));
                foreach ($hardcoded as $h) {
                    $rel = ltrim(str_replace($dir, '', $h['file']), '/');
                    $val = mb_strlen((string) $h['value']) > 80 ? mb_substr((string) $h['value'], 0, 77) . '...' : $h['value'];
                    $io->writeln(sprintf('  %s:%d  %s: %s', $rel, $h['line'], $h['field'], $val));
                }
            }
        }
    }

    // ----- Fix mode -----

    /**
     * Rewrite blueprint files to replace hardcoded values with translation keys
     * and emit the corresponding YAML for the plugin's languages/en.yaml.
     */
    private function runFix(array $report, bool $dryRun, bool $icu, bool $interactive, int $keyLength, $io): int
    {
        $io->writeln('<info>=== i18n blueprint fix ===</info>');
        if ($dryRun) {
            $io->writeln('<comment>(dry run — no files will be modified)</comment>');
        }
        if ($interactive) {
            $io->writeln('<comment>Interactive mode: y=yes, n=no, a=accept all remaining, s=skip all remaining, q=quit plugin</comment>');
        }
        $io->newLine();

        foreach ($report as $dir => $info) {
            if (!$this->hasHardcoded($info)) {
                continue;
            }
            $this->fixPlugin($dir, $info, $dryRun, $icu, $interactive, $keyLength, $io);
        }
        return 0;
    }

    private function hasHardcoded(array $info): bool
    {
        return ($info['totals']['hardcoded'] ?? 0) > 0;
    }

    /**
     * Walk findings interactively, prompting y/n/a/s/q for each.
     * Returns the filtered list, or null if the user quit this plugin.
     *
     * @param array $findings each entry: ['file' => ..., 'finding' => [...]]
     */
    private function reviewFindings(array $findings, string $pluginDir, $io): ?array
    {
        $accepted = [];
        $autoMode = null; // 'all' | 'skip' | null

        $total = count($findings);
        foreach ($findings as $idx => $entry) {
            if ($autoMode === 'all') {
                $accepted[] = $entry;
                continue;
            }
            if ($autoMode === 'skip') {
                continue;
            }

            $f = $entry['finding'];
            $rel = ltrim(str_replace($pluginDir, '', $entry['file']), '/');
            $value = $f['value'];
            $truncated = mb_strlen((string) $value) > 100 ? mb_substr((string) $value, 0, 97) . '...' : $value;

            $io->newLine();
            $io->writeln(sprintf(
                '  <fg=cyan>[%d/%d]</> %s:%d  %s:',
                $idx + 1, $total, $rel, $f['line'], $f['field']
            ));
            $io->writeln(sprintf('     <fg=yellow>%s</>', $truncated));

            $decided = false;
            while (!$decided) {
                $raw = $io->ask('  Translate? [Y/n/a/s/q/?]');
                $answer = strtolower(trim((string) $raw));
                $first = $answer === '' ? 'y' : $answer[0];
                switch ($first) {
                    case 'y':
                        $accepted[] = $entry;
                        $decided = true;
                        break;
                    case 'n':
                        $decided = true;
                        break;
                    case 'a':
                        $autoMode = 'all';
                        $accepted[] = $entry;
                        $decided = true;
                        break;
                    case 's':
                        $autoMode = 'skip';
                        $decided = true;
                        break;
                    case 'q':
                        return null;
                    case '?':
                        $io->writeln('     <fg=blue>y</> = yes (translate this one)');
                        $io->writeln('     <fg=blue>n</> = no (leave hardcoded)');
                        $io->writeln('     <fg=blue>a</> = yes to all remaining in this plugin');
                        $io->writeln('     <fg=blue>s</> = skip all remaining in this plugin');
                        $io->writeln('     <fg=blue>q</> = quit this plugin (apply nothing)');
                        break;
                    default:
                        $io->writeln('  <fg=red>Unknown answer. Press ? for help.</>');
                }
            }
        }

        $io->newLine();
        $io->writeln(sprintf('  <fg=green>%d accepted</>, <fg=red>%d skipped</>', count($accepted), $total - count($accepted)));
        return $accepted;
    }

    private function fixPlugin(string $pluginDir, array $info, bool $dryRun, bool $icu, bool $interactive, int $keyLength, $io): void
    {
        $lang = 'en';
        $name = basename($pluginDir);
        $prefix = $this->derivePluginPrefix($pluginDir, $lang);
        $usedKeys = $this->loadExistingKeys($pluginDir, $prefix, $lang);
        $target = $this->targetLangFile($pluginDir, $lang);

        // value -> key mapping for this plugin (deduplicates same value to same key)
        $valueToKey = [];
        // Pass 1: assign keys. Process longest values first so a longer phrase
        // gets the natural key before its prefix-truncated form is taken.
        $allFindings = [];
        foreach ($info['files'] as $file => $findings) {
            foreach ($findings as $f) {
                if ($f['classification'] !== 'hardcoded') {
                    continue;
                }
                $allFindings[] = ['file' => $file, 'finding' => $f];
            }
        }

        if ($interactive) {
            $io->writeln(sprintf('<comment>### %s</comment>  (%d hardcoded value%s to review)', $name, count($allFindings), count($allFindings) === 1 ? '' : 's'));
            $allFindings = $this->reviewFindings($allFindings, $pluginDir, $io);
            if ($allFindings === null) {
                $io->writeln('<fg=yellow>Skipped plugin entirely.</>');
                $io->newLine();
                return;
            }
            if (empty($allFindings)) {
                $io->writeln('No values selected — skipping.');
                $io->newLine();
                return;
            }
        }
        // Sort by value length desc so disambiguation is more stable.
        usort($allFindings, fn($a, $b) => mb_strlen((string) $b['finding']['value']) <=> mb_strlen((string) $a['finding']['value']));

        foreach ($allFindings as $entry) {
            $val = $entry['finding']['value'];
            if (isset($valueToKey[$val])) {
                continue;
            }
            $base = $this->slugifyKey($val, $keyLength);
            if ($base === '') {
                $base = 'KEY';
            }
            $key = $base;
            $n = 2;
            while (isset($usedKeys[$key]) || in_array($key, $valueToKey, true)) {
                if (isset($usedKeys[$key]) && $usedKeys[$key] === $val) {
                    // Already exists with the same value — reuse it.
                    break;
                }
                $key = $base . '_' . $n;
                $n++;
            }
            $valueToKey[$val] = $key;
        }

        // Pass 2: rewrite blueprint files, in memory.
        $fileChanges = [];
        foreach ($info['files'] as $file => $findings) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }
            $lines = explode("\n", $src);
            $changed = 0;
            foreach ($findings as $f) {
                if ($f['classification'] !== 'hardcoded') {
                    continue;
                }
                $val = $f['value'];
                $key = $valueToKey[$val] ?? null;
                if ($key === null) {
                    continue;
                }
                $fullKey = $prefix . '.' . $key;
                $idx = $f['line'] - 1;
                if (!isset($lines[$idx])) {
                    continue;
                }
                $original = $lines[$idx];
                $replaced = preg_replace(
                    '/^(\s*' . preg_quote((string) $f['field'], '/') . '\s*:\s*).*$/',
                    '$1' . $fullKey,
                    $original,
                    1
                );
                if ($replaced !== null && $replaced !== $original) {
                    $lines[$idx] = $replaced;
                    $changed++;
                }
            }
            if ($changed > 0) {
                $fileChanges[$file] = ['contents' => implode("\n", $lines), 'count' => $changed];
            }
        }

        // Compute new entries (skip ones whose key+value already exist in the lang file).
        $newEntries = [];
        foreach ($valueToKey as $value => $key) {
            if (isset($usedKeys[$key]) && $usedKeys[$key] === $value) {
                continue;
            }
            $newEntries[$value] = $key;
        }

        $io->writeln(sprintf(
            '<comment>### %s</comment>  (prefix: %s%s, lang file: %s)',
            $name,
            $icu ? 'ICU.' : '',
            $prefix,
            $target['exists']
                ? ltrim(str_replace($pluginDir, '', $target['path']), '/') . ' [' . $target['kind'] . ']'
                : 'will create ' . ltrim(str_replace($pluginDir, '', $target['path']), '/')
        ));

        // Apply blueprint changes.
        $blueprintLines = array_sum(array_column($fileChanges, 'count'));
        $io->writeln(sprintf('Blueprint changes: %d file(s), %d line(s)', count($fileChanges), $blueprintLines));
        foreach ($fileChanges as $file => $change) {
            $rel = ltrim(str_replace($pluginDir, '', $file), '/');
            $io->writeln(sprintf('  %s  (%d line%s)', $rel, $change['count'], $change['count'] === 1 ? '' : 's'));
            if (!$dryRun) {
                file_put_contents($file, $change['contents']);
            }
        }

        // Apply lang-file changes.
        if (!$newEntries) {
            $io->writeln('Lang file: no new entries to add (everything already covered).');
            $io->newLine();
            return;
        }

        $langChange = $this->buildLangFileChange($target, $prefix, $newEntries, $icu, $lang);
        $relLang = ltrim(str_replace($pluginDir, '', $target['path']), '/');
        $verb = $target['exists'] ? 'Append to' : 'Create';
        $io->writeln(sprintf('Lang file changes: %s %s (+%d entries)', $verb, $relLang, count($newEntries)));

        // Always show the YAML diff for transparency.
        $io->writeln('<info># Lang file additions:</info>');
        foreach (explode("\n", rtrim($langChange['snippet'])) as $line) {
            $io->writeln($line);
        }
        $io->newLine();

        if (!$dryRun) {
            $dir = dirname($target['path']);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($target['path'], $langChange['contents']);
        }
    }

    /**
     * Build the new contents for the plugin's lang file plus a snippet showing
     * just the additions (for stdout). Inserts entries under the appropriate
     * path for the target file's storage kind, creating any missing parent
     * structure.
     *
     * @return array{contents: string, snippet: string}
     */
    private function buildLangFileChange(array $target, string $prefix, array $newEntries, bool $icu, string $lang): array
    {
        // Compute the YAML key path to the prefix block.
        $path = [];
        if ($target['kind'] === 'multi-locale-file') {
            $path[] = $lang;
        }
        if ($icu) {
            $path[] = 'ICU';
        }
        $path[] = $prefix;

        if (!$target['exists']) {
            $contents = "# Auto-generated by `bin/plugin devtools i18n-blueprints --fix`.\n";
            $contents .= "# Edit translations freely; re-running --fix will only append new keys.\n\n";
            $contents .= $this->renderBlock($path, $newEntries, 0);
            return ['contents' => $contents, 'snippet' => $this->renderBlock($path, $newEntries, 0)];
        }

        $src = file_get_contents($target['path']);
        $lines = explode("\n", $src);
        $location = $this->findInsertionPoint($lines, $path);

        $insertIdx = $location['line'];
        $matchedDepth = $location['matchedDepth'];

        // Build the lines we need to insert.
        $newLines = [];
        if ($matchedDepth === count($path)) {
            // Full match — entries go inside the existing block at depth = count($path).
            foreach ($newEntries as $value => $key) {
                $newLines[] = str_repeat('  ', $matchedDepth) . $key . ': ' . $this->yamlScalar($value);
            }
        } else {
            // Partial match — create the missing path segments + entries.
            $depth = $matchedDepth;
            for ($i = $matchedDepth; $i < count($path); $i++) {
                $newLines[] = str_repeat('  ', $depth) . $path[$i] . ':';
                $depth++;
            }
            foreach ($newEntries as $value => $key) {
                $newLines[] = str_repeat('  ', $depth) . $key . ': ' . $this->yamlScalar($value);
            }
        }

        // Snippet shown to user (rendered as if from the top, for readability).
        $snippet = $this->renderBlock($path, $newEntries, 0);

        // If inserting at end of file and file doesn't end with a newline, ensure
        // there's a separator. If inserting mid-file, ensure a blank line separator
        // before the new block when creating new structure (matchedDepth < count(path)).
        if ($insertIdx >= count($lines)) {
            // Append: ensure blank line separator for visual clarity.
            $tail = end($lines);
            if ($tail !== false && trim($tail) !== '') {
                $newLines = array_merge([''], $newLines);
            }
            $lines = array_merge($lines, $newLines);
        } else {
            array_splice($lines, $insertIdx, 0, $newLines);
        }

        return ['contents' => implode("\n", $lines), 'snippet' => $snippet];
    }

    /**
     * Render a block of entries under a key path, with each level indented by 2 spaces.
     */
    private function renderBlock(array $path, array $entries, int $startDepth): string
    {
        $out = '';
        $depth = $startDepth;
        foreach ($path as $segment) {
            $out .= str_repeat('  ', $depth) . $segment . ":\n";
            $depth++;
        }
        foreach ($entries as $value => $key) {
            $out .= str_repeat('  ', $depth) . $key . ': ' . $this->yamlScalar($value) . "\n";
        }
        return $out;
    }

    /**
     * Walk YAML lines looking for a key path. Returns:
     *   line          — line index where new content should be inserted
     *   matchedDepth  — number of path segments matched (0..count(path))
     *
     * If matchedDepth == count(path): inserting entries at this line places them
     * at the end of the matched block.
     * If matchedDepth < count(path): the missing segments need to be created at
     * this line, starting at indent = matchedDepth*2.
     *
     * @return array{line: int, matchedDepth: int}
     */
    private function findInsertionPoint(array $lines, array $path): array
    {
        $matchedDepth = 0;
        $fullyMatched = false;
        $endOfMatchedBlock = null;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $trimmed = trim((string) $line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $indent = strlen((string) $line) - strlen(ltrim((string) $line, ' '));

            if ($fullyMatched) {
                $blockIndent = ($matchedDepth - 1) * 2;
                if ($indent <= $blockIndent) {
                    $endOfMatchedBlock = $i;
                    break;
                }
                continue;
            }

            $expectedIndent = $matchedDepth * 2;
            if ($indent < $expectedIndent) {
                $endOfMatchedBlock = $i;
                break;
            }
            if ($indent === $expectedIndent && $matchedDepth < count($path)) {
                if ($this->lineKey($line) === $path[$matchedDepth]) {
                    $matchedDepth++;
                    if ($matchedDepth === count($path)) {
                        $fullyMatched = true;
                    }
                }
            }
        }

        if ($endOfMatchedBlock === null) {
            $endOfMatchedBlock = count($lines);
            // Trim trailing blank lines so we don't insert past them.
            while ($endOfMatchedBlock > 0 && trim((string) $lines[$endOfMatchedBlock - 1]) === '') {
                $endOfMatchedBlock--;
            }
        }

        return ['line' => $endOfMatchedBlock, 'matchedDepth' => $matchedDepth];
    }

    private function lineKey(string $line): ?string
    {
        if (preg_match('/^\s*([A-Za-z0-9_]+)\s*:/', $line, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Locate the plugin's language file and identify its storage style.
     *
     *   per-locale-file   — languages/<lang>.yaml (top-level keys = PLUGIN_FOO, etc.)
     *   multi-locale-file — languages.yaml        (top-level keys = locales: en, de, ...)
     *
     * If both exist, the multi-locale file is preferred (matches Grav's own load order).
     * If neither exists, returns a path to a new per-locale file at languages/<lang>.yaml.
     *
     * @return array{path: string, kind: string, exists: bool}
     */
    private function targetLangFile(string $pluginDir, string $lang = 'en'): array
    {
        $multi = $pluginDir . '/languages.yaml';
        $perLocale = $pluginDir . '/languages/' . $lang . '.yaml';
        if (file_exists($multi)) {
            return ['path' => $multi, 'kind' => 'multi-locale-file', 'exists' => true];
        }
        if (file_exists($perLocale)) {
            return ['path' => $perLocale, 'kind' => 'per-locale-file', 'exists' => true];
        }
        return ['path' => $perLocale, 'kind' => 'per-locale-file', 'exists' => false];
    }

    /**
     * For a parsed lang doc, return the sub-document where PLUGIN_FOO-style keys live.
     *
     *   per-locale-file:   $doc itself (e.g. ['PLUGIN_FOO' => [...], 'ICU' => [...]])
     *   multi-locale-file: $doc[$lang] (e.g. ['en' => ['PLUGIN_FOO' => ...]])
     */
    private function localeRoot(?array $doc, string $kind, string $lang): array
    {
        if (!is_array($doc)) {
            return [];
        }
        if ($kind === 'multi-locale-file') {
            return is_array($doc[$lang] ?? null) ? $doc[$lang] : [];
        }
        return $doc;
    }

    private function derivePluginPrefix(string $pluginDir, string $lang = 'en'): string
    {
        $target = $this->targetLangFile($pluginDir, $lang);
        if ($target['exists']) {
            try {
                $doc = Yaml::parseFile($target['path']);
                $root = $this->localeRoot($doc, $target['kind'], $lang);
                // Prefer top-level PLUGIN_*/THEME_* keys
                foreach (array_keys($root) as $k) {
                    if (is_string($k) && (str_starts_with($k, 'PLUGIN_') || str_starts_with($k, 'THEME_'))) {
                        return $k;
                    }
                }
                // Fall through to ICU block
                if (isset($root['ICU']) && is_array($root['ICU'])) {
                    foreach (array_keys($root['ICU']) as $k) {
                        if (is_string($k) && (str_starts_with($k, 'PLUGIN_') || str_starts_with($k, 'THEME_'))) {
                            return $k;
                        }
                    }
                }
            } catch (\Throwable) {
                // fall through to derived
            }
        }
        $slug = preg_replace('/^grav-(plugin-|theme-)/', '', basename($pluginDir));
        $upper = strtoupper(str_replace('-', '_', $slug));
        $isTheme = str_contains($pluginDir, '/themes/') || str_contains($pluginDir, '/themes');
        return ($isTheme ? 'THEME_' : 'PLUGIN_') . $upper;
    }

    private function loadExistingKeys(string $pluginDir, string $prefix, string $lang = 'en'): array
    {
        $out = [];
        $target = $this->targetLangFile($pluginDir, $lang);
        if (!$target['exists']) {
            return $out;
        }
        try {
            $doc = Yaml::parseFile($target['path']);
        } catch (\Throwable) {
            return $out;
        }
        $root = $this->localeRoot($doc, $target['kind'], $lang);
        if (!$root) {
            return $out;
        }
        if (isset($root[$prefix]) && is_array($root[$prefix])) {
            foreach ($root[$prefix] as $k => $v) {
                if (is_string($v)) {
                    $out[$k] = $v;
                }
            }
        }
        if (isset($root['ICU'][$prefix]) && is_array($root['ICU'][$prefix])) {
            foreach ($root['ICU'][$prefix] as $k => $v) {
                if (is_string($v)) {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    private function slugifyKey(string $value, int $maxLen = 40): string
    {
        $v = preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? '';
        $v = trim($v, '_');
        $v = strtoupper($v);
        // Strip leading digits (YAML keys shouldn't start with a digit-only segment)
        $v = preg_replace('/^[0-9]+_?/', '', $v) ?? $v;
        if (mb_strlen($v) > $maxLen) {
            $v = mb_substr($v, 0, $maxLen);
            // Always trim back to last underscore so we don't truncate mid-word,
            // unless that would shorten the key to less than ~half the cap.
            $under = strrpos($v, '_');
            if ($under !== false && $under > intdiv($maxLen, 2)) {
                $v = substr($v, 0, $under);
            }
        }
        return $v;
    }

    private function yamlScalar(string $value): string
    {
        // Wrap in double quotes; escape backslashes and double-quotes.
        $escaped = addcslashes($value, "\\\"");
        return '"' . $escaped . '"';
    }
}
