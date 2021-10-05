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
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class DevToolsCommand
 * @package Grav\Plugin\Console
 */
class DevToolsCommand extends ConsoleCommand
{
    /** @var array */
    protected $component = [];
    /** @var Inflector */
    protected $inflector;
    /** @var UniformResourceLocator */
    protected $locator;
    /** @var Twig */
    protected $twig;
    /** @var GPM */
    protected $gpm;
    /** @var array */
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
        // $this->config->set('theme', $config->get('themes.' . $name));
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

    /**
     * Copies the component type and renames accordingly
     *
     * @return bool
     */
    protected function createComponent(): bool
    {
        $name = $this->component['name'];
        $folder_name = strtolower($this->inflector::hyphenize($name));
        $new_theme = $folder_name;
        $type = $this->component['type'];
        $grav = Grav::instance();
        $config = $grav['config'];
        $current_theme = $config->get('system.pages.theme');
        $template = $this->component['template'];
        $source_theme = null;

        if (isset($this->component['copy'])) {
            $current_theme = $this->component['copy'];
            $source_theme = $this->locator->findResource('themes://' . $current_theme);
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

        if ($template === 'inheritance') {
            $parent_theme = $this->component['extends'];
            $yaml_file = $this->locator->findResource('themes://' . $parent_theme) . '/' . $parent_theme . '.yaml';
            $this->component['config'] = file_get_contents($yaml_file);;
        }

        if (isset($source_theme)) {
            /**
             * Copy existing theme and regex-replace old stuff with new
             */

            // Get source if a symlink
            if (is_link($template_folder)) {
                $template_folder = readlink($template_folder);
                if (false === $template_folder) {
                    $this->output->writeln("<red>Theme {$current_theme} is a bad symlink</red>");
                    return false;
                }
            }

            //Copy All files to component folder
            try {
                Folder::copy($template_folder, $component_folder, '/.git|node_modules/');
            } catch (\Exception $e) {
                $this->output->writeln("<red>" . $e->getMessage() . "</red>");
                return false;
            }

            // Do some filename renaming
            $base_old_filename = $component_folder . '/' . $current_theme;
            $base_new_filename = $component_folder . '/' . $new_theme;
            @rename( $base_old_filename . '.php', $base_new_filename . '.php');
            @rename( $base_old_filename . '.yaml', $base_new_filename . '.yaml');

            $camelized_current = $this->inflector::camelize($current_theme);
            $camelized_new = $this->inflector::camelize($name);

            $hyphenized_current = $this->inflector::hyphenize($current_theme);
            $hyphenized_new = $this->inflector::hyphenize($name);

            $titleized_current = $this->inflector::titleize($current_theme);
            $titleized_new = $this->inflector::titleize($name);

            $underscoreized_current = $this->inflector::underscorize($current_theme);
            $underscoreized_new = $this->inflector::underscorize($name);

            $variations_regex = [
                ["/$camelized_current/", "/$hyphenized_current/"],
                [$camelized_new, $hyphenized_new]
            ];

            if (!in_array("/$titleized_current/", array_values($variations_regex[0]))) {
                $current_regex = $variations_regex[0];
                $new_regex = $variations_regex[1];
                $current_regex[] = "/$titleized_current/";
                $new_regex[] = $titleized_new;
                $variations_regex = [$current_regex, $new_regex];
            }

            if (!in_array("/$underscoreized_current/", array_values($variations_regex[0]))) {
                $current_regex = $variations_regex[0];
                $new_regex = $variations_regex[1];
                $current_regex[] = "/$underscoreized_current/";
                $new_regex[] = $underscoreized_new;
                $variations_regex = [$current_regex, $new_regex];
            }

            $regex_array = [
                $new_theme . '.php' => $variations_regex,
                'blueprints.yaml' => $variations_regex,
                'README.md' => $variations_regex,
            ];

            foreach ($regex_array as $filename => $data) {
                $filename = $component_folder . '/' . $filename;
                if (!file_exists($filename)) {
                    continue;
                }
                $file = file_get_contents($filename);
                if ($file) {
                    $file = preg_replace($data[0], $data[1], $file);
                }
                file_put_contents($filename, $file);
            }

            echo $source_theme;

        } else {
            /**
             * Use components folder and twig processing
             */
            //Copy All files to component folder
            try {
                Folder::copy($template_folder, $component_folder);
            } catch (\Exception $e) {
                $this->output->writeln("<red>" . $e->getMessage() . "</red>");
                return false;
            }

            //Add Twig vars and templates then initialize
            $this->twig->twig_vars['component'] = $this->component;
            $this->twig->twig_paths[] = $template_folder;
            $this->twig->init();

            //Get all templates of component then process each with twig and save
            $templates = Folder::all($component_folder);

            try {
                foreach($templates as $templateFile) {
                    if (Utils::endsWith($templateFile, '.twig') && !Utils::endsWith($templateFile, '.html.twig')) {
                        $content = $this->twig->processTemplate($templateFile);
                        $file = File::instance($component_folder . DS . str_replace('.twig', '', $templateFile));
                        $file->content($content);
                        $file->save();

                        //Delete twig template
                        $file = File::instance($component_folder . DS . $templateFile);
                        $file->delete();
                    }
                }
            } catch (\Exception $e) {
                $this->output->writeln("<red>" . $e->getMessage() . "</red>");
                $this->output->writeln("Rolling back...");
                Folder::delete($component_folder);
                $this->output->writeln($type . "creation failed!");
                return false;
            }
            if ($type !== 'blueprint') {
                rename($component_folder . DS . $type . '.php', $component_folder . DS . $folder_name . '.php');
                rename($component_folder . DS . $type . '.yaml', $component_folder . DS . $folder_name . '.yaml');
            } else {
                $bpname = $this->inflector::hyphenize($this->component['bpname']);
                rename($component_folder . DS . $type . '.yaml', $component_folder . DS . $bpname . '.yaml');
            }
        }

        $this->output->writeln('');
        $this->output->writeln('<green>SUCCESS</green> ' . $type . ' <magenta>' . $name . '</magenta> -> Created Successfully');
        $this->output->writeln('');
        $this->output->writeln('Path: <cyan>' . $component_folder . '</cyan>');
        $this->output->writeln('');
        if ($type === 'plugin') {
            $this->output->writeln('<yellow>Please run `cd ' . $component_folder . '` and `composer update` to initialize the autoloader</yellow>');
            $this->output->writeln('');
        }

        return true;
    }

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
                // Check If name
                if ($value === null || trim($value) === '') {
                    throw new \RuntimeException('Name cannot be empty');
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
                if ($this->isReservedWord(strtolower($value))) {
                    throw new \RuntimeException("\"" . $value . "\" is a reserved word and cannot be used as the name");
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
                if (!preg_match('/^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))$/iD', $value)) {
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
