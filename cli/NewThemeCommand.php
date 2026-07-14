<?php

namespace Grav\Plugin\Console;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;

require_once(__DIR__ . '/../classes/DevToolsCommand.php');

/**
 * Class NewThemeCommand
 * @package Grav\Console\Cli\DevTools
 */
class NewThemeCommand extends DevToolsCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('new-theme')
            ->setAliases(['newtheme'])
            ->addOption(
                'name',
                null,
                InputOption::VALUE_OPTIONAL,
                'The name of your new Grav theme'
            )
            ->addOption(
                'desc',
                null,
                InputOption::VALUE_OPTIONAL,
                'A description of your new Grav theme'
            )
            ->addOption(
                'dev',
                null,
                InputOption::VALUE_OPTIONAL,
                'The name/username of the developer'
            )
            ->addOption(
                'github',
                null,
                InputOption::VALUE_OPTIONAL,
                'The developer\'s GitHub ID'
            )
            ->addOption(
                'email',
                'e',
                InputOption::VALUE_OPTIONAL,
                'The developer\'s email'
            )
            ->addOption(
                'grav',
                'g',
                InputOption::VALUE_OPTIONAL,
                'Target Grav version: 1.7, 2.0, or both (default: 2.0)'
            )
            ->addOption(
                'template',
                't',
                InputOption::VALUE_OPTIONAL,
                'Theme template: pure-blank, blades, tailwind, daisyui, inheritance, or copy'
            )
            ->addOption(
                'offline',
                'o',
                InputOption::VALUE_NONE,
                'Skip online name collision check'
            )
            ->addOption(
                'install',
                'i',
                InputOption::VALUE_NONE,
                'Run the theme build (npm install && npm run build) automatically after creating a build-based theme'
            )
            ->setDescription('Creates a new Grav theme with the basic required files')
            ->setHelp('The <info>new-theme</info> command creates the scaffolding for a new Grav theme.');
    }

    /**
     * @return int
     */
    protected function serve(): int
    {
        $this->init();

        $input = $this->getInput();
        $io = $this->getIO();

        $this->intro('Create a new Grav Theme');

        $this->component['type'] = 'theme';
        $this->component['version'] = '0.1.0';

        $this->options = [
            'name' => $input->getOption('name'),
            'description' => $input->getOption('desc'),
            'author' => [
                'name' => $input->getOption('dev'),
                'email' => $input->getOption('email'),
                'githubid' => $input->getOption('github'),
            ],
            'offline' => $input->getOption('offline'),
        ];

        $this->validateOptions();

        $this->component = array_replace($this->component, $this->options);

        // Shared name / description / author prompts.
        $this->askComponentMeta('Theme');

        // Which Grav version(s) to target — drives the generated blueprint/composer.
        $this->resolveGravTarget();

        // Pick the theme template.
        $this->component['template'] = $this->resolveTemplate();

        if ($this->component['template'] === 'inheritance') {
            $question = new ChoiceQuestion('Please choose a theme to extend', $this->installedThemes());
            $this->component['extends'] = $io->askQuestion($question);
        } elseif ($this->component['template'] === 'copy') {
            $question = new ChoiceQuestion('Please choose a theme to copy', $this->installedThemes());
            $this->component['copy'] = $io->askQuestion($question);
        }

        if (!$this->confirmSummary('theme', $this->destinationFor('theme'))) {
            $io->warning('Aborted — nothing was created.');
            return 1;
        }

        if ($this->createComponent()) {
            $this->offerInstaller('theme', $this->component['template'], $this->destinationFor('theme'));
        }

        return 0;
    }

    /**
     * Resolve the theme template from the --template flag or an interactive
     * choice.
     *
     * @return string
     */
    private function resolveTemplate(): string
    {
        $io = $this->getIO();

        $choices = [
            'pure-blank'  => 'Basic Theme using Pure.css',
            'blades'      => 'Basic Theme using the Blades semantic CSS framework (no build step)',
            'tailwind'    => 'Basic Theme using tailwind.css and including Alpine.js',
            'daisyui'     => 'Basic Theme using Tailwind CSS and daisyUI components',
            'inheritance' => 'Inherit from another theme',
            'copy'        => 'Copy another theme',
        ];

        $template = $this->getInput()->getOption('template');

        if ($template === null) {
            if ($this->getInput()->isInteractive()) {
                $this->maybeSection('Template');
                $template = $io->askQuestion(new ChoiceQuestion('Please choose a theme template', $choices, 'pure-blank'));
            } else {
                $template = 'pure-blank';
            }
        }

        if (!isset($choices[$template])) {
            throw new \RuntimeException("Unknown theme template \"{$template}\". Choose one of: " . implode(', ', array_keys($choices)));
        }

        return $template;
    }

    /**
     * List the slugs of every installed theme (for inheritance/copy).
     *
     * @return string[]
     */
    private function installedThemes(): array
    {
        $slugs = [];
        foreach ($this->gpm->getInstalledThemes() as $key => $theme) {
            $slugs[] = $key;
        }

        return $slugs;
    }
}
