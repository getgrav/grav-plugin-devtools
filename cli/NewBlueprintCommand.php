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
class NewBlueprintCommand extends DevToolsCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('new-blueprint')
            ->setAliases(['newblueprint','blueprint'])
            ->addOption(
                'bpname',
                null,
                InputOption::VALUE_OPTIONAL,
                'The name of your new Grav theme'
            )
            ->addOption(
                'name',
                null,
                InputOption::VALUE_OPTIONAL,
                'The name of your new Grav theme'
            )
            ->addOption(
                'template',
                null,
                InputOption::VALUE_OPTIONAL,
                'The name/username of the developer'
            )
            ->setDescription('Create a blueprint that extend the default.yaml blueprint files')
            ->setHelp('The <info>new-blueprint</info> command creates a new blueprint file.');
    }

    /**
     * @return int
     */
    protected function serve(): int
    {
        $this->init();

        $input = $this->getInput();
        $io = $this->getIO();

        $this->component['type'] = 'blueprint';
        $this->component['template'] = 'modular';
        $this->component['version'] = '0.1.0';
        $this->component['themename'] = 'bonjour';

        $this->options = [
            'name' => $input->getOption('name'),
            'bpname' => $input->getOption('bpname'),
            'template' => $input->getOption('template'),

        ];

        $this->validateOptions();

        $this->component = array_replace($this->component, $this->options);

        if (!$this->options['template']) {
            $question = new ChoiceQuestion('Please choose a template type', ['newtab', 'append']);

            $this->component['template'] = $io->askQuestion($question);
        }
        if (!$this->options['bpname']) {
            $question = new Question('Enter <yellow>Blueprint Name</yellow>');

            $this->component['bpname'] = $io->askQuestion($question);
        }
    
        $this->createComponent();

        return 0;
    }
}
