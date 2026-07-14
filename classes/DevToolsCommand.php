<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Common\Filesystem\Folder;
use Grav\Common\GPM\GPM;
use Grav\Common\Inflector;
use Grav\Common\Twig\Twig;
use Grav\Common\Utils;
use RocketTheme\Toolbox\File\File;
use Grav\Console\ConsoleCommand;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . '/GravTarget.php';

/**
 * Class DevToolsCommand
 *
 * Base class for the scaffolding commands. Owns the shared wizard prompts
 * (name / description / author / Grav target) and the generation engine that
 * turns a component template into a real plugin or theme.
 *
 * @package Grav\Plugin\Console
 */
class DevToolsCommand extends ConsoleCommand
{
    /** @var array The component being generated (name, type, template, author, target, ...) */
    protected $component = [];
    /** @var Inflector */
    protected $inflector;
    /** @var UniformResourceLocator */
    protected $locator;
    /** @var Twig */
    protected $twig;
    /** @var GPM */
    protected $gpm;
    /** @var array Raw options collected from CLI flags */
    protected $options = [];

    /** @var array */
    protected $reserved_keywords = ['__halt_compiler', 'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'namespace', 'new', 'or', 'print', 'private', 'protected', 'public', 'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor'];


    /**
     * Initializes the basic requirements for the developer tools
     *
     * @return void
     */
    protected function init(): void
    {
        if (!function_exists('curl_version')) {
            exit('FATAL: DEVTOOLS requires PHP Curl module to be installed');
        }

        $grav = Grav::instance();
        $grav['config']->init();
        $grav['uri']->init();

        $this->inflector    = $grav['inflector'];
        $this->locator      = $grav['locator'];
        $this->twig         = $grav['twig'];
        $this->gpm          = new GPM();

        //Add `theme://` to prevent fail
        $this->locator->addPath('theme', '', []);
        $this->locator->addPath('plugin', '', []);
        $this->locator->addPath('blueprint', '', []);
    }

    /**
     * Backwards compatibility to Grav 1.6.
     *
     * @return InputInterface
     */
    public function getInput(): InputInterface
    {
        return $this->input;
    }

    /**
     * Backwards compatibility to Grav 1.6.
     *
     * @return SymfonyStyle
     */
    public function getIO(): SymfonyStyle
    {
        $output = $this->output;
        if (!$output instanceof SymfonyStyle) {
            $this->output = $output = new SymfonyStyle($this->input, $this->output);
        }

        return $this->output;
    }

    /* ---------------------------------------------------------------------
     * Shared wizard prompts
     * ------------------------------------------------------------------- */

    /**
     * Ask the questions common to plugins and themes (name, description,
     * author name/github/email), skipping any already supplied via flags.
     * Developer name and email default to the local git config when blank.
     *
     * @param string $label Human label for the component type ("Plugin"/"Theme")
     * @return void
     */
    protected function askComponentMeta(string $label): void
    {
        $io = $this->getIO();
        $saved = $this->savedAuthor();

        $this->maybeSection('Details');

        if (!$this->options['name']) {
            $question = new Question("Enter <yellow>{$label} Name</yellow>");
            $question->setValidator(fn ($value) => $this->validate('name', $value));
            $this->component['name'] = $io->askQuestion($question);
        }

        // Show the slug/folder the name resolves to, so there are no surprises.
        if (!empty($this->component['name']) && $this->getInput()->isInteractive()) {
            $slug = strtolower((string) $this->inflector::hyphenize($this->component['name']));
            $io->writeln("  <fg=gray>Folder / slug:</> <info>{$slug}</info>");
            $io->newLine();
        }

        if (!$this->options['description']) {
            $question = new Question("Enter <yellow>{$label} Description</yellow>");
            $question->setValidator(fn ($value) => $this->validate('description', $value));
            $this->component['description'] = $io->askQuestion($question);
        }

        if (!$this->options['author']['name']) {
            $default = $saved['name'] ?? $this->gitConfig('user.name');
            $question = new Question('Enter <yellow>Developer Name</yellow>', $default);
            $question->setValidator(fn ($value) => $this->validate('developer', $value));
            $this->component['author']['name'] = $io->askQuestion($question);
        }

        if (!$this->options['author']['githubid']) {
            $question = new Question('Enter <yellow>GitHub ID</yellow> (can be blank)', $saved['githubid'] ?? null);
            $question->setValidator(fn ($value) => $this->validate('githubid', $value));
            $this->component['author']['githubid'] = $io->askQuestion($question);
        }

        if (!$this->options['author']['email']) {
            $default = $saved['email'] ?? $this->gitConfig('user.email');
            $question = new Question('Enter <yellow>Developer Email</yellow>', $default);
            $question->setValidator(fn ($value) => $this->validate('email', $value));
            $this->component['author']['email'] = $io->askQuestion($question);
        }
    }

    /**
     * Resolve the Grav target from the --grav flag, or ask for it when the
     * flag is absent and we're running interactively. Stores the flattened
     * target array on the component for the Twig context.
     *
     * @return GravTarget
     */
    protected function resolveGravTarget(): GravTarget
    {
        $io = $this->getIO();
        $flag = $this->getInput()->getOption('grav');

        if ($flag === null && $this->getInput()->isInteractive()) {
            $this->maybeSection('Grav Version');
            $question = new ChoiceQuestion(
                'Which <yellow>Grav version</yellow> should this target?',
                GravTarget::choices(),
                GravTarget::DEFAULT
            );
            // With associative choices, askQuestion() returns the selected key.
            $flag = $io->askQuestion($question);
        }

        $target = GravTarget::fromString($flag);
        $this->component['target'] = $target->toArray();

        return $target;
    }

    /**
     * Print a summary of what is about to be generated and ask for
     * confirmation. Auto-confirms when non-interactive.
     *
     * @param string $type   plugin|theme
     * @param string $target Target folder the component will be written to
     * @return bool
     */
    protected function confirmSummary(string $type, string $target): bool
    {
        $io = $this->getIO();

        $rows = [
            ['Type', ucfirst($type)],
            ['Name', $this->component['name'] ?? ''],
            ['Template', $this->component['template'] ?? ''],
        ];
        if (isset($this->component['target']['label'])) {
            $rows[] = ['Grav target', $this->component['target']['label']];
        }
        if (!empty($this->component['flex_name'])) {
            $rows[] = ['Flex object', $this->component['flex_name']];
        }
        $rows[] = ['Location', $target];

        $io->newLine();
        $io->table(['Setting', 'Value'], $rows);

        if (!$this->getInput()->isInteractive()) {
            return true;
        }

        return $io->askQuestion(new ConfirmationQuestion('Create this ' . $type . '? [Y/n] ', true));
    }

    /**
     * Compute the folder a plugin/theme will be generated into, for display
     * in the confirmation summary before anything is written.
     *
     * @param string $type plugin|theme
     * @return string
     */
    protected function destinationFor(string $type): string
    {
        $folder = strtolower((string) $this->inflector::hyphenize($this->component['name']));

        return $this->locator->findResource($type . 's://') . DS . $folder;
    }

    /**
     * Read a value from the local git config, returning null when unset.
     *
     * @param string $key e.g. 'user.name' or 'user.email'
     * @return string|null
     */
    protected function gitConfig(string $key): ?string
    {
        $value = @exec('git config --get ' . escapeshellarg($key), $out, $code);

        return ($code === 0 && $value !== '') ? $value : null;
    }

    /**
     * Print the wizard banner.
     *
     * @param string $title
     * @return void
     */
    protected function intro(string $title): void
    {
        $this->getIO()->title($title);
    }

    /**
     * Print a section header, but only when running interactively (so a
     * fully-flagged non-interactive run stays quiet).
     *
     * @param string $title
     * @return void
     */
    protected function maybeSection(string $title): void
    {
        if ($this->getInput()->isInteractive()) {
            $this->getIO()->section($title);
        }
    }

    /**
     * Author details remembered from a previous run, used as prompt defaults.
     *
     * @return array<string, string>
     */
    protected function savedAuthor(): array
    {
        $file = $this->locator->findResource('config://plugins/devtools.yaml');
        if ($file && is_file($file)) {
            $data = Yaml::parseFile($file) ?: [];
            if (isset($data['author']) && is_array($data['author'])) {
                return $data['author'];
            }
        }

        return [];
    }

    /**
     * Persist the author details to the user devtools config so they pre-fill
     * next time. Merges into any existing config, preserving other keys.
     *
     * @param array $author
     * @return void
     */
    protected function rememberAuthor(array $author): void
    {
        $clean = array_filter([
            'name'     => $author['name'] ?? null,
            'email'    => $author['email'] ?? null,
            'githubid' => $author['githubid'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        if (!$clean) {
            return;
        }

        // Resolve a writable path, creating the config/plugins folder if needed.
        $file = $this->locator->findResource('config://plugins/devtools.yaml', true, true);
        if (!$file) {
            return;
        }

        $data = is_file($file) ? (Yaml::parseFile($file) ?: []) : [];
        $data['author'] = $clean;

        file_put_contents($file, Yaml::dump($data, 4, 2));
    }

    /**
     * After creating a component, offer to run its installer (composer for
     * plugins, npm for the build-based themes). Runs when the --install flag
     * is set, or when the user confirms interactively.
     *
     * @param string $type             plugin|theme
     * @param string $template
     * @param string $component_folder
     * @return void
     */
    protected function offerInstaller(string $type, string $template, string $component_folder): void
    {
        $steps = $this->installerSteps($type, $template);
        if (!$steps || !is_dir($component_folder)) {
            return;
        }

        $io = $this->getIO();
        $joined = implode(' && ', $steps);

        $run = (bool) $this->getInput()->getOption('install');
        if (!$run) {
            if (!$this->getInput()->isInteractive()) {
                return;
            }
            $run = $io->askQuestion(new ConfirmationQuestion("Run <cyan>{$joined}</cyan> now? [Y/n] ", true));
        }

        if (!$run) {
            return;
        }

        if (!function_exists('passthru')) {
            $io->warning('Cannot run the installer automatically (passthru disabled). Run manually: ' . $joined);
            return;
        }

        foreach ($steps as $step) {
            $io->section('Running: ' . $step);
            passthru('cd ' . escapeshellarg($component_folder) . ' && ' . $step, $code);
            if ($code !== 0) {
                $io->warning("`{$step}` exited with code {$code}. Finish the setup manually.");
                return;
            }
        }

        $io->success('Dependencies installed.');
    }

    /**
     * The installer commands for a given component, or [] when none apply.
     *
     * @param string $type
     * @param string $template
     * @return string[]
     */
    private function installerSteps(string $type, string $template): array
    {
        if ($type === 'plugin') {
            return ['composer install'];
        }

        if ($type === 'theme' && in_array($template, ['tailwind', 'daisyui'], true)) {
            return ['npm install', 'npm run build'];
        }

        return [];
    }

    /* ---------------------------------------------------------------------
     * Generation engine
     * ------------------------------------------------------------------- */

    /**
     * Copies the component template and renames it into a real component.
     *
     * @return bool
     */
    protected function createComponent(): bool
    {
        // Blueprints identify by `bpname`; plugins/themes by `name`.
        $name        = (string) ($this->component['name'] ?? $this->component['bpname'] ?? '');
        $folder_name = strtolower((string) $this->inflector::hyphenize($name));
        $type        = $this->component['type'];
        $template    = $this->component['template'];

        $grav          = Grav::instance();
        $current_theme = $grav['config']->get('system.pages.theme');
        $source_theme  = null;

        // Resolve the template source and destination folders.
        if (isset($this->component['copy'])) {
            $current_theme   = $this->component['copy'];
            $source_theme    = $this->locator->findResource('themes://' . $current_theme);
            $template_folder = $source_theme;
        } else {
            $template_folder = __DIR__ . "/../components/{$type}/{$template}";
        }

        if ($type === 'blueprint') {
            $component_folder = $this->locator->findResource('themes://' . $current_theme) . '/blueprints';
        } else {
            $component_folder = $this->locator->findResource($type . 's://') . DS . $folder_name;
        }

        if (false === $template_folder) {
            $this->output->writeln("<red>Theme {$current_theme} does not exist</red>");
            return false;
        }

        // Inheritance templates need the parent theme's config injected.
        if ($template === 'inheritance') {
            $parent_theme = $this->component['extends'];
            $yaml_file    = $this->locator->findResource('themes://' . $parent_theme) . '/' . $parent_theme . '.yaml';
            $this->component['config'] = file_get_contents($yaml_file);
        }

        $ok = $source_theme !== null
            ? $this->cloneExistingTheme($template_folder, $component_folder, $current_theme, $name)
            : $this->renderTemplate($template_folder, $component_folder, $type);

        if (!$ok) {
            return false;
        }

        // Remember author details for next time (plugins/themes only).
        if (!empty($this->component['author'])) {
            $this->rememberAuthor($this->component['author']);
        }

        $this->finish($type, $name, $component_folder);

        return true;
    }

    /**
     * "Copy another theme" strategy: duplicate an existing theme and
     * regex-rename every variation of its name to the new name. No Twig.
     *
     * @return bool
     */
    private function cloneExistingTheme(string $template_folder, string $component_folder, string $current_theme, string $name): bool
    {
        $new_theme = strtolower((string) $this->inflector::hyphenize($name));

        // Follow a symlinked source theme to its real path.
        if (is_link($template_folder)) {
            $template_folder = readlink($template_folder);
            if (false === $template_folder) {
                $this->output->writeln("<red>Theme {$current_theme} is a bad symlink</red>");
                return false;
            }
        }

        try {
            Folder::copy($template_folder, $component_folder, '/.git|node_modules/');
        } catch (\Exception $e) {
            $this->output->writeln('<red>' . $e->getMessage() . '</red>');
            return false;
        }

        // Rename the entry files (theme.php / theme.yaml).
        $base_old = $component_folder . '/' . $current_theme;
        $base_new = $component_folder . '/' . $new_theme;
        @rename($base_old . '.php', $base_new . '.php');
        @rename($base_old . '.yaml', $base_new . '.yaml');

        // Build a set of [search, replace] pairs across every name variation.
        [$search, $replace] = $this->nameVariations($current_theme, $name);

        $files = [
            $new_theme . '.php',
            'blueprints.yaml',
            'README.md',
        ];
        foreach ($files as $filename) {
            $path = $component_folder . '/' . $filename;
            if (!file_exists($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents) {
                file_put_contents($path, preg_replace($search, $replace, $contents));
            }
        }

        // Align the cloned blueprint's Grav compatibility with the chosen target.
        $this->applyTargetToBlueprint($component_folder . '/blueprints.yaml');

        return true;
    }

    /**
     * Build regex search/replace arrays covering camelized, hyphenized,
     * titleized and underscoreized variations of a name.
     *
     * @return array{0: string[], 1: string[]}
     */
    private function nameVariations(string $current, string $new): array
    {
        $search  = [];
        $replace = [];

        $forms = ['camelize', 'hyphenize', 'titleize', 'underscorize'];
        foreach ($forms as $form) {
            $from = (string) $this->inflector::$form($current);
            $to   = (string) $this->inflector::$form($new);
            $pattern = '/' . $from . '/';
            if (!in_array($pattern, $search, true)) {
                $search[]  = $pattern;
                $replace[] = $to;
            }
        }

        return [$search, $replace];
    }

    /**
     * Standard strategy: copy a template folder, render every `*.twig`
     * (except `*.html.twig`) with the component context, then rename the
     * generic entry files to the component name.
     *
     * @return bool
     */
    private function renderTemplate(string $template_folder, string $component_folder, string $type): bool
    {
        try {
            Folder::copy($template_folder, $component_folder);
        } catch (\Exception $e) {
            $this->output->writeln('<red>' . $e->getMessage() . '</red>');
            return false;
        }

        // Prime Twig with the component context and the template path.
        $this->twig->twig_vars['component'] = $this->component;
        $this->twig->twig_paths[] = $template_folder;
        $this->twig->init();

        try {
            foreach (Folder::all($component_folder) as $templateFile) {
                if (!Utils::endsWith($templateFile, '.twig') || Utils::endsWith($templateFile, '.html.twig')) {
                    continue;
                }
                $content = $this->twig->processTemplate($templateFile);
                $rendered = File::instance($component_folder . DS . str_replace('.twig', '', $templateFile));
                $rendered->content($content);
                $rendered->save();

                // Remove the source .twig once rendered.
                File::instance($component_folder . DS . $templateFile)->delete();
            }
        } catch (\Exception $e) {
            $this->output->writeln('<red>' . $e->getMessage() . '</red>');
            $this->output->writeln('Rolling back...');
            Folder::delete($component_folder);
            $this->output->writeln($type . ' creation failed!');
            return false;
        }

        $this->renameEntryFiles($component_folder, $type);

        if (!empty($this->component['flex_name'])) {
            $this->applyFlexRenames($component_folder, $type);
        }

        // The API template ships an admin-next page component named generically;
        // rename it to the plugin slug so the API plugin auto-discovers it.
        $page_script = $component_folder . DS . 'admin-next' . DS . 'pages' . DS . 'plugin.js';
        if (is_file($page_script)) {
            $folder_name = strtolower((string) $this->inflector::hyphenize($this->component['name']));
            rename($page_script, dirname($page_script) . DS . $folder_name . '.js');
        }

        return true;
    }

    /**
     * Rename the generic `plugin.*` / `theme.*` (or blueprint) entry files
     * to the component's real name.
     *
     * @return void
     */
    private function renameEntryFiles(string $component_folder, string $type): void
    {
        if ($type === 'blueprint') {
            $bpname = $this->inflector::hyphenize($this->component['bpname']);
            rename($component_folder . DS . $type . '.yaml', $component_folder . DS . $bpname . '.yaml');
            return;
        }

        $folder_name = strtolower((string) $this->inflector::hyphenize($this->component['name']));
        rename($component_folder . DS . $type . '.php', $component_folder . DS . $folder_name . '.php');
        rename($component_folder . DS . $type . '.yaml', $component_folder . DS . $folder_name . '.yaml');
    }

    /**
     * Rename the Flex object scaffolding (class folder, Object/Collection
     * classes and the flex-objects blueprint) to the chosen Flex name.
     *
     * @return void
     */
    private function applyFlexRenames(string $component_folder, string $type): void
    {
        $flex_classes_folder = $component_folder . DS . 'classes' . DS . 'Flex' . DS . 'Types';
        $flex_name       = strtolower((string) $this->inflector::underscorize($this->component['flex_name']));
        $flex_name_camel = $this->inflector::camelize($this->component['flex_name']);

        rename($flex_classes_folder . DS . 'flex_name', $flex_classes_folder . DS . $flex_name_camel);
        rename(
            $flex_classes_folder . DS . $flex_name_camel . DS . 'Object.php',
            $flex_classes_folder . DS . $flex_name_camel . DS . $flex_name_camel . 'Object.php'
        );
        rename(
            $flex_classes_folder . DS . $flex_name_camel . DS . 'Collection.php',
            $flex_classes_folder . DS . $flex_name_camel . DS . $flex_name_camel . 'Collection.php'
        );
        rename(
            $component_folder . DS . 'blueprints' . DS . 'flex-objects' . DS . $type . '.yaml',
            $component_folder . DS . 'blueprints' . DS . 'flex-objects' . DS . $flex_name . '.yaml'
        );
    }

    /**
     * Rewrite a cloned theme's blueprint so its `compatibility` and grav
     * `dependencies` line reflect the chosen Grav target. No-op when no
     * target was resolved (e.g. blueprint command).
     *
     * @return void
     */
    private function applyTargetToBlueprint(string $blueprint): void
    {
        $target = $this->component['target'] ?? null;
        if (!$target || !file_exists($blueprint)) {
            return;
        }

        $yaml = file_get_contents($blueprint);
        if ($yaml === false) {
            return;
        }

        // Update the grav dependency floor if one is present.
        $yaml = preg_replace(
            "/(name:\s*grav,\s*version:\s*')[^']*(')/",
            '${1}>=' . $target['grav_dep'] . '${2}',
            $yaml
        );

        // Ensure a compatibility block matching the target exists.
        $list = implode(', ', array_map(static fn ($v) => "'{$v}'", $target['compatibility']));
        if (preg_match('/^compatibility:/m', $yaml)) {
            $yaml = preg_replace('/(^compatibility:\s*\n\s*grav:\s*)\[[^\]]*\]/m', '${1}[' . $list . ']', $yaml);
        } elseif (preg_match('/^dependencies:/m', $yaml)) {
            $yaml = preg_replace('/^dependencies:/m', "compatibility:\n  grav: [{$list}]\n\ndependencies:", $yaml, 1);
        }

        file_put_contents($blueprint, $yaml);
    }

    /**
     * Print the success summary and any post-generation instructions.
     *
     * @return void
     */
    private function finish(string $type, string $name, string $component_folder): void
    {
        $io = $this->getIO();

        $io->newLine();
        $io->success(strtoupper($type) . " {$name} created successfully");
        $io->writeln('Path: <cyan>' . $component_folder . '</cyan>');

        $steps = $this->installerSteps($type, $this->component['template'] ?? '');
        if ($steps) {
            $io->newLine();
            $io->writeln('<yellow>Next steps</yellow>');
            $lines = ['cd ' . $component_folder];
            foreach ($steps as $step) {
                $lines[] = $step;
            }
            $io->listing($lines);
        }
    }

    /* ---------------------------------------------------------------------
     * Validation
     * ------------------------------------------------------------------- */

    /**
     * Iterate through all options and validate
     *
     * @return void
     */
    protected function validateOptions(): void
    {
        foreach (array_filter($this->options) as $type => $value) {
            $this->validate($type, $value);
        }
    }

    /**
     * @param string $type
     * @param mixed $value
     * @return mixed
     */
    protected function validate(string $type, $value)
    {
        switch ($type) {
            case 'name':
                // Check if name is empty
                if ($value === null || trim((string) $value) === '') {
                    throw new \RuntimeException('Name cannot be empty');
                }

                // Check if name starts with a numeric character
                if (is_numeric($value[0])) {
                    throw new \RuntimeException('Name must start with an alphabetic character (A-Z, a-z)');
                }

                if (!$this->options['offline']) {
                    // Check for name collision with online gpm.
                    if (false !== $this->gpm->findPackage($value)) {
                        throw new \RuntimeException('Package name exists in GPM');
                    }
                } else {
                    $this->output->writeln('');
                    $this->output->writeln('  <red>Warning</red>: Please note that by skipping the online check, your project\'s plugin or theme name may conflict with an existing plugin or theme.');
                }

                // Check if it's reserved
                if ($this->isReservedWord(strtolower((string) $value))) {
                    throw new \RuntimeException("\"" . $value . "\" is a reserved word and cannot be used as the name");
                }

                break;

            case 'description':
                if ($value === null || trim((string) $value) === '') {
                    throw new \RuntimeException('Description cannot be empty');
                }

                break;
            case 'themename':
                if ($value === null || trim((string) $value) === '') {
                    throw new \RuntimeException('Theme Name cannot be empty');
                }

                break;
            case 'developer':
                if ($value === null || trim((string) $value) === '') {
                    throw new \RuntimeException('Developer\'s Name cannot be empty');
                }

                break;

            case 'githubid':
                // GitHubID can be blank, so nothing here
                break;

            case 'email':
                if (!preg_match('/^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))$/iD', (string) $value)) {
                    throw new \RuntimeException('Not a valid email address');
                }

                break;
        }

        return $value;
    }

    /**
     * @param string $word
     * @return bool
     */
    public function isReservedWord(string $word): bool
    {
        return in_array($word, $this->reserved_keywords, true);
    }
}
