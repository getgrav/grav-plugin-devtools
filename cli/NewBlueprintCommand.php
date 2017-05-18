<?php
namespace Grav\Plugin\Console;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Helper;
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
            ->setAliases(['newblueprint'])
            ->addOption(
                'name',
                'pn',
                InputOption::VALUE_OPTIONAL,
                'The name of your new Grav theme'
            )
            ->addOption(
                'description',
                'd',
                InputOption::VALUE_OPTIONAL,
                'A description of your new Grav theme'
            )
            ->addOption(
                'themename',
                'tn',
                InputOption::VALUE_OPTIONAL,
                'A description of your new Grav theme'
            )
            ->addOption(
                'developer',
                'dv',
                InputOption::VALUE_OPTIONAL,
                'The name/username of the developer'
            )
            ->addOption(
                'email',
                'e',
                InputOption::VALUE_OPTIONAL,
                'The developer\'s email'
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
        $this->component['version']     = '0.1.0';
        $this->component['themename']     = 'bonjour';
        

        $this->options = [
            'name'          => $this->input->getOption('name'),
            'themename'     => $this->input->getOption('themename'),
            'description'   => $this->input->getOption('description'),
            'author'        => [
                'name'      => $this->input->getOption('developer'),
                'email'     => $this->input->getOption('email')
            ]
        ];

        $this->validateOptions();

        $this->component = array_replace($this->component, $this->options);

        $helper = $this->getHelper('question');

        $question = new ChoiceQuestion(
            'Please choose a template type',
            array('modular', 'newtest')
        );
        $this->component['template'] = $helper->ask($this->input, $this->output, $question);
    
        $this->createComponent();
    }

}
