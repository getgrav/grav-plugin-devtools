<?php

namespace Grav\Plugin\Console;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

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
                null,
                InputOption::VALUE_OPTIONAL,
                'The developer\'s email'
            )
            ->addOption(
                'offline',
                'o',
                InputOption::VALUE_NONE,
                'Skip online name collision check'
            )
            ->setDescription('Creates a new Grav theme with the basic required files')
            ->setHelp('The <info>new-theme</info> command creates a new Grav instance and performs the creation of a theme.');
    }

    /**
     * @return int
     */
    protected function serve(): int
    {
        $this->init();

        $input = $this->getInput();
        $io = $this->getIO();

        $this->component['type'] = 'theme';
        $this->component['template'] = 'blank';
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

        if (!$this->options['name']) {
            $question = new Question('Enter <yellow>Theme Name</yellow>');
            $question->setValidator(function ($value) {
                return $this->validate('name', $value);
            });

            $this->component['name'] = $io->askQuestion($question);
        }

        if (!$this->options['description']) {
            $question = new Question('Enter <yellow>Theme Description</yellow>');
            $question->setValidator(function ($value) {
                return $this->validate('description', $value);
            });

            $this->component['description'] = $io->askQuestion($question);
        }

        if (!$this->options['author']['name']) {
            $question = new Question('Enter <yellow>Developer Name</yellow>');
            $question->setValidator(function ($value) {
                return $this->validate('developer', $value);
            });

            $this->component['author']['name'] = $io->askQuestion($question);
        }

        if (!$this->options['author']['githubid']) {
            $question = new Question('Enter <yellow>GitHub ID</yellow> (can be blank)');
            $question->setValidator(function ($value) {
                return $this->validate('githubid', $value);
            });

            $this->component['author']['githubid'] = $io->askQuestion($question);
        }

        if (!$this->options['author']['email']) {
            $question = new Question('Enter <yellow>Developer Email</yellow>');
            $question->setValidator(function ($value) {
                return $this->validate('email', $value);
            });

            $this->component['author']['email'] = $io->askQuestion($question);
        }

        $question = new ChoiceQuestion(
            'Please choose an option',
            ['pure-blank' => 'Basic Theme using Pure.css', 'tailwind' => 'Basic Theme using tailwind.css', 'inheritance' => 'Inherit from another theme', 'copy' => 'Copy another theme']
        );
        $this->component['template'] = $io->askQuestion($question);

        if ($this->component['template'] === 'inheritance') {
            $themes = $this->gpm->getInstalledThemes();
            $installedThemes = [];
            foreach ($themes as $key => $theme) {
                $installedThemes[] = $key;
            }

            $question = new ChoiceQuestion('Please choose a theme to extend', $installedThemes);
            $this->component['extends'] = $io->askQuestion($question);
        } elseif ($this->component['template'] === 'copy') {
            $themes = $this->gpm->getInstalledThemes();
            $installedThemes = [];
            foreach ($themes as $key => $theme) {
                $installedThemes[] = $key;
            }

            $question = new ChoiceQuestion(
                'Please choose a theme to copy',
                $installedThemes
            );
            $this->component['copy'] = $io->askQuestion($question);
        }
        $this->createComponent();

        return 0;
    }
}
