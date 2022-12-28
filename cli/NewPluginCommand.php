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
                'offline',
                'o',
                InputOption::VALUE_NONE,
                'Skip online name collision check'
            )
            ->setDescription('Creates a new Grav plugin with the basic required files')
            ->setHelp('The <info>new-plugin</info> command creates a new Grav instance and performs the creation of a plugin.');
    }

    /**
     * @return int
     */
    protected function serve(): int
    {
        $this->init();

        $input = $this->getInput();
        $io = $this->getIO();

        $this->component['type'] = 'plugin';
        $this->component['template'] = 'blank';
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

        if (!$this->options['name']) {
            $question = new Question('Enter <yellow>Plugin Name</yellow>');
            $question->setValidator(function ($value) {
                return $this->validate('name', $value);
            });

            $this->component['name'] = $io->askQuestion($question);
        }

        if (!$this->options['description']) {
            $question = new Question('Enter <yellow>Plugin Description</yellow>');
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
            ['blank' => 'Basic Plugin',
             'flex' => 'Basic Plugin prepared for custom Flex Objects'
            ]
        );
        $this->component['template'] = $io->askQuestion($question);

        if ($this->component['template'] === 'flex') {

            $question = new Question('Enter Flex Object Name');
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

        $this->createComponent();

        return 0;
    }
}
