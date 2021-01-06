<?php
namespace Grav\Plugin\Console;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

require_once(__DIR__ . '/../classes/DevToolsCommand.php');

/**
 * Class NewThemeCommand
 * @package Grav\Console\Cli\DevTools
 */
class NewBlueprintCommand extends DevToolsCommand
{

    /**
     * @var array
     */
    protected $options = [];

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName('new-blueprint')
            ->setAliases(['newblueprint','blueprint'])
            ->addOption(
                'bpname',
                'bp',
                InputOption::VALUE_OPTIONAL,
                'The name of your new Grav theme'
            )
            ->addOption(
                'name',
                'bn',
                InputOption::VALUE_OPTIONAL,
                'The name of your new Grav theme'
            )
            ->addOption(
                'template',
                'tp',
                InputOption::VALUE_OPTIONAL,
                'The name/username of the developer'
            )
            ->setDescription('Create a blueprint that extend the default.yaml blueprint files')
            ->setHelp('The <info>new-blueprint</info> command creates a new blueprint file.');
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
        $this->init();

        /**
         * @var array DevToolsCommand $component
         */
        $this->component['type']        = 'blueprint';
        $this->component['template']    = 'modular';
       // $this->component['name']    = 'blueprints';
        $this->component['version']     = '0.1.0';
        $this->component['themename']     = 'bonjour';
        

        $this->options = [
            'name'          => $this->input->getOption('name'),
            'bpname'          => $this->input->getOption('bpname'),
            'template'   => $this->input->getOption('template'),

        ];

        $this->validateOptions();
        $io = new SymfonyStyle($this->input, $this->output);

        $this->component = array_replace($this->component, $this->options);

        if (!$this->options['template']) {
            $question = new ChoiceQuestion(
            'Please choose a template type',
            array('newtab', 'append')
        );

            $this->component['template'] = $io->askQuestion($question);
        }
        if (!$this->options['bpname']) {
            $question = new Question('Enter <yellow>Blueprint Name</yellow>');


            $this->component['bpname'] = $io->askQuestion($question);
        }
    
        $this->createComponent();
    }

}
