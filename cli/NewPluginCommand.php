<?php

namespace Grav\Plugin\Console;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

require_once(__DIR__ . '/../classes/DevToolsCommand.php');

/**
 * Class NewPluginCommand
 * @package Grav\Console\Cli\DevTools
 */
class NewPluginCommand extends DevToolsCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('new-plugin')
            ->setAliases(['newplugin'])
            ->addOption(
                'name',
                null,
                InputOption::VALUE_OPTIONAL,
                'The name of your new Grav plugin'
            )
            ->addOption(
                'desc',
                null,
                InputOption::VALUE_OPTIONAL,
                'A description of your new Grav plugin'
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
                'Plugin template: blank, flex, or api'
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
                'Run composer install automatically after creating the plugin'
            )
            ->setDescription('Creates a new Grav plugin with the basic required files')
            ->setHelp('The <info>new-plugin</info> command creates the scaffolding for a new Grav plugin.');
    }

    /**
     * @return int
     */
    protected function serve(): int
    {
        $this->init();

        $input = $this->getInput();
        $io = $this->getIO();

        $this->intro('Create a new Grav Plugin');

        $this->component['type'] = 'plugin';
        $this->component['version'] = '0.1.0';

        $this->options = [
            'name' => $input->getOption('name'),
            'description' => $input->getOption('desc'),
            'author' => [
                'name' => $input->getOption('dev'),
                'email' => $input->getOption('email'),
                'githubid' => $input->getOption('github')
            ],
            'offline' => $input->getOption('offline'),
        ];

        $this->validateOptions();

        $this->component = array_replace($this->component, $this->options);

        // Shared name / description / author prompts.
        $this->askComponentMeta('Plugin');

        // Which Grav version(s) to target — drives the generated blueprint/composer.
        $target = $this->resolveGravTarget();

        // Pick the plugin template (api requires a Grav 2.0 target).
        $this->component['template'] = $this->resolveTemplate($target->supportsApi);

        if ($this->component['template'] === 'flex') {
            $this->maybeSection('Flex Object');
            $question = new Question('Enter <yellow>Flex Object Name</yellow>');
            $question->setValidator(function ($value) {
                return $this->validate('name', $value);
            });
            $this->component['flex_name'] = $io->askQuestion($question);

            $question = new ChoiceQuestion('Please choose a storage type', [
                'simple' => 'Basic Storage (1 file for all objects) - no media support',
                'file'   => 'File Storage (1 file per object)',
                'folder' => 'Folder Storage (1 folder per object)'
            ]);
            $this->component['flex_storage'] = $io->askQuestion($question);
        }

        if (!$this->confirmSummary('plugin', $this->destinationFor('plugin'))) {
            $io->warning('Aborted — nothing was created.');
            return 1;
        }

        if ($this->createComponent()) {
            $this->offerInstaller('plugin', $this->component['template'], $this->destinationFor('plugin'));
        }

        return 0;
    }

    /**
     * Resolve the plugin template from the --template flag or an interactive
     * choice. The `api` template (API + Admin Next) is only offered/allowed
     * when the target includes Grav 2.0.
     *
     * @param bool $supportsApi
     * @return string
     */
    private function resolveTemplate(bool $supportsApi): string
    {
        $io = $this->getIO();

        $choices = [
            'blank' => 'Basic Plugin',
            'flex'  => 'Basic Plugin prepared for custom Flex Objects',
        ];
        if ($supportsApi) {
            $choices['api'] = 'API + Admin Next integrated plugin (Grav 2.0)';
        }

        $template = $this->getInput()->getOption('template');

        if ($template === null) {
            if ($this->getInput()->isInteractive()) {
                $this->maybeSection('Template');
                $template = $io->askQuestion(new ChoiceQuestion('Please choose a plugin template', $choices, 'blank'));
            } else {
                $template = 'blank';
            }
        }

        if (!isset($choices[$template])) {
            if ($template === 'api' && !$supportsApi) {
                throw new \RuntimeException('The "api" template requires a Grav 2.0 target (use --grav=2.0).');
            }
            throw new \RuntimeException("Unknown plugin template \"{$template}\". Choose one of: " . implode(', ', array_keys($choices)));
        }

        return $template;
    }
}
