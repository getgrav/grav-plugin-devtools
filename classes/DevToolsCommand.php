<?php
namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Common\Data;
use Grav\Common\Theme;
use Grav\Common\Filesystem\Folder;
use Grav\Common\GPM\GPM;
use Grav\Common\Inflector;
use Grav\Common\Twig\Twig;
use Grav\Common\Utils;
use RocketTheme\Toolbox\File\File;
use Grav\Console\ConsoleCommand;

/**
 * Class DevToolsCommand
 * @package Grav\Console\Cli\
 */
class DevToolsCommand extends ConsoleCommand
{

    /**
     * @var array
     */
    protected $component = [];

    /**
     * @var Inflector
     */
    protected $inflector;

    /**
     * @var Locator
     */
    protected $locator;

    /**
     * @var Twig
     */
    protected $twig;

    protected $data;

    /**
     * @var gpm
     */
    protected $gpm;


    /**
     * Initializes the basic requirements for the developer tools
     */
    protected function init()
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
        $this->gpm          = new GPM(true);

        //Add `theme://` to prevent fail
        $this->locator->addPath('theme', '', []);
        $this->locator->addPath('plugin', '', []);
        $this->locator->addPath('blueprint', '', []);
        // $this->config->set('theme', $config->get('themes.' . $name));
        
        
    }

    /**
     * Copies the component type and renames accordingly
     */
    protected function createComponent()
    {
        $name       = $this->component['name'];
        $bpname     = $this->component['bpname'];
        $folderName = strtolower($this->inflector->hyphenize($name));
        $type       = $this->component['type'];
        $grav = Grav::instance();
        $config = $grav['config'];
        $name = $config->get('system.pages.theme');
        $activetheme = $grav['themes']->get($name)->blueprints()->get('name');
        //$themes->configure();
        $activethemefolder   = 'themes' . DS . strtolower($this->inflector->hyphenize($activetheme));
        //$myvar = $myvarold->$name;
        // dump($lowervar);

        

        $template   = $this->component['template'];
        $templateFolder     = __DIR__ . '/../components/' . $type . DS . $template;
        // $componentFolder    = $this->locator->findResource($type . 's://') . DS . '../../user/themes/' . $lowervar . '/blueprints';
        if ($type == 'blueprint') {
            $componentFolder    = USER_DIR . $activethemefolder . '/blueprints';
            dump($componentFolder);
        } else {
            $componentFolder    = $this->locator->findResource($type . 's://') . DS . $folderName;
            dump($componentFolder);
        }
        

        //Copy All files to component folder
        try {
            Folder::copy($templateFolder, $componentFolder);
        } catch (\Exception $e) {
            $this->output->writeln("<red>" . $e->getMessage() . "</red>");
            return false;
        }

        //Add Twig vars and templates then initialize
        $this->twig->twig_vars['component'] = $this->component;
        $this->twig->twig_paths[] = $templateFolder;
        $this->twig->init();

        //Get all templates of component then process each with twig and save
        $templates = Folder::all($componentFolder);

        try {
            foreach($templates as $templateFile) {
                if (Utils::endsWith($templateFile, '.twig') && !Utils::endsWith($templateFile, '.html.twig')) {
                    $content = $this->twig->processTemplate($templateFile);
                    $file = File::instance($componentFolder . DS . str_replace('.twig', '', $templateFile));
                    $file->content($content);
                    $file->save();

                    //Delete twig template
                    $file = File::instance($componentFolder . DS . $templateFile);
                    $file->delete();
                }
            }
        } catch (\Exception $e) {
            $this->output->writeln("<red>" . $e->getMessage() . "</red>");
            $this->output->writeln("Rolling back...");
            Folder::delete($componentFolder);
            $this->output->writeln($type . "creation failed!");
            return false;
        }
        if ($type != 'blueprint') {
        rename($componentFolder . DS . $type . '.php', $componentFolder . DS . $this->inflector->hyphenize($name) . '.php');
        rename($componentFolder . DS . $type . '.yaml', $componentFolder . DS . $this->inflector->hyphenize($name) . '.yaml');
        } else {
        rename($componentFolder . DS . $type . '.yaml', $componentFolder . DS . $this->inflector->hyphenize($bpname) . '.yaml');
        }



        $this->output->writeln('');
        $this->output->writeln('<green>SUCCESS</green> ' . $type . ' <magenta>' . $name . '</magenta> -> Created Successfully');
        $this->output->writeln('');
        $this->output->writeln('Path: <cyan>' . $componentFolder . '</cyan>');
        $this->output->writeln('');
    }

    /**
     * Iterate through all options and validate
     */
    protected function validateOptions()
    {
        foreach (array_filter($this->options) as $type => $value) {
            $this->validate($type, $value);
        }
    }

    /**
     * @param        $type
     * @param        $value
     * @param string $extra
     *
     * @return mixed
     */
    protected function validate($type, $value, $extra = '')
    {
        switch ($type) {
            case 'name':
                //Check If name
                if ($value === null || trim($value) === '') {
                    throw new \RuntimeException('Name cannot be empty');
                }
                if (false !== $this->gpm->findPackage($value)) {
                    throw new \RuntimeException('Package name exists in GPM');
                }

                break;

            case 'description':
                if($value === null || trim($value) === '') {
                    throw new \RuntimeException('Description cannot be empty');
                }

                break;
            case 'themename':
                if($value === null || trim($value) === '') {
                    throw new \RuntimeException('Theme Name cannot be empty');
                }

                break;
            case 'developer':
                if ($value === null || trim($value) === '') {
                    throw new \RuntimeException('Developer\'s Name cannot be empty');
                }

                break;

            case 'githubid':
                // GitHubID can be blank, so nothing here
                break;

            case 'email':
                if (!preg_match('/^([a-z0-9_\.-]+)@([\da-z\.-]+)\.([a-z\.]{2,6})$/', $value)) {
                    throw new \RuntimeException('Not a valid email address');
                }

                break;
        }

        return $value;
    }
}
