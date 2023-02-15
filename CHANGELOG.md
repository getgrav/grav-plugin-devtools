# v1.7.0
## 02/15/2023

1. [](#improved)
    * Created non-opinionated and basic Tailwind CSS theme as default `tailwind` solution
    * Moved the opinionated Tailwind CSS theme with Laravel Mix to a separate option

# v1.6.1
## 01/02/2023

1. [](#improved)
   * Improved FlexObjects default blueprint

# v1.6.0
## 12/28/2022

1. [](#new)
   * Added new "FlexObjects" basic plugin type [#77](https://github.com/getgrav/grav-plugin-devtools/pull/77)
1. [](#improved)
   * Improvements for Tailwind CSS theme + AlpineJS [#74](https://github.com/getgrav/grav-plugin-devtools/pull/74)   
   * Updated `languages.yaml` [#76](https://github.com/getgrav/grav-plugin-devtools/pull/76)
   * Updated links + default branch [#72](https://github.com/getgrav/grav-plugin-devtools/pull/72)
1. [](#bugfix)
   * Various PSR Fixes [#71](https://github.com/getgrav/grav-plugin-devtools/pull/71)

# v1.5.4
## 10/26/2021

1. [](#improved)
   * Moved offline check to CLI flag [#70](https://github.com/getgrav/grav-plugin-devtools/issues/70)
   * Updated footer copyrights for Pure Blank

# v1.5.3
## 06/16/2021

1. [](#bugfix)
   * Fixes over-zealous regex that caused duplication in copy tasks [#69](https://github.com/getgrav/grav-plugin-devtools/issues/69)
   
# v1.5.2
## 05/19/2021

1. [](#new)
    * Added basic TailwindCSS theme [#65](https://github.com/getgrav/grav-plugin-devtools/pull/65)
1. [](#improved)
    * Fixed typo [#67](https://github.com/getgrav/grav-plugin-devtools/pull/67)
    * Use canonical URLs [#58](https://github.com/getgrav/grav-plugin-devtools/pull/58)
    * Replace `theme_config` with `config.theme` [#60](https://github.com/getgrav/grav-plugin-devtools/pull/60)
1. [](#bugfix)
    * Fixed a bad path regarding composer install after plugin creation

# v1.5.1
## 03/17/2021

1. [](#improved)
   * Clearer instructions for composer initialization [#62](https://github.com/getgrav/grav-plugin-devtools/pull/62)
   * Comment out autoload subscription event by default now that Grav 1.7 is out [#62](https://github.com/getgrav/grav-plugin-devtools/pull/62)

# v1.5.0
## 02/18/2021

1. [](#new)
   * Updated CLI commands for latest standards
   * Pass phpstan level 8 tests
1. [](#improved)
   * Add default configuration to an inherited theme's YAML file [getgrav/grav-premium-issues#50](https://github.com/getgrav/grav-premium-issues/issues/50)
1. [](#bugfix)
   * Output cmd does not correctly show colors [#56](https://github.com/getgrav/grav-plugin-devtools/issues/56)

# v1.4.2
## 12/02/2020

1. [](#improved)
    * User return typehints in plugin.php
    * Add proper twig escapes into a new theme

# v1.4.1
## 05/20/2020

1. [](#improved)
    * Make name key Composer 2.0 compatible [#48](https://github.com/getgrav/grav-plugin-devtools/pull/48)
1. [](#bugfix)
    * Correct type for themes [#49](https://github.com/getgrav/grav-plugin-devtools/pull/49)

# v1.4.0
## 04/27/2020

1. [](#new)
    * Added new required `slug:` and `type:` attributes to blueprints
1. [](#improved)
    * Fixed plugin autoload

# v1.3.1
## 02/24/2020

1. [](#improved)
    * Set `validation: loose` in plugin blueprints by default
    * Add Grav 1.6 dependency to all new plugins and themes

# v1.3.0
## 02/13/2020

1. [](#improved)
    * Added composer-based autoloader to the `new-plugin` command

# v1.2.4
## 11/06/2019

1. [](#improved)
    * Added the ability to use devtools without an online connection to GPM
1. [](#bugfix)
    * Regression fix for missing `theme_config` in pure-blank [#45](https://github.com/getgrav/grav-plugin-devtools/issues/45)

# v1.2.3
## 06/20/2019

1. [](#improved)
    * pure-blank: Use new 'deferred' blocks for header
    * pure-blank: Use `home_url` variable
    * pure-blank: Improved `README.md.twig`

# v1.2.2
## 04/21/2019

1. [](#bugfix)
    * Add Github username field to new-theme template [#39](https://github.com/getgrav/grav-plugin-devtools/pull/39)

# v1.2.1
## 08/04/2018

1. [](#bugfix)
    * Fixed incorrect folder name as a result of renaming typo of `inheritence` to `inheritance` [#32](https://github.com/getgrav/grav-plugin-devtools/issues/32)

# v1.2.0
## 07/25/2018

1. [](#new)
    * Internationalization for blank plugin component [#30](https://github.com/getgrav/grav-plugin-devtools/issues/30)
1. [](#improved)
    * Added a new check for reserved PHP words [#7](https://github.com/getgrav/grav-plugin-devtools/issues/7)
    * Improved regex for valid emails [#21](https://github.com/getgrav/grav-plugin-devtools/issues/21)
1. [](#bugfix)
    * Fix broken renaming when doing a theme 'copy'
    * Typos [#31](https://github.com/getgrav/grav-plugin-devtools/pull/31)

# v1.1.1
## 03/29/2018

1. [](#bugfix)
    * Fixed theme inheritance bug [#25](https://github.com/getgrav/grav-plugin-devtools/pull/25)

# v1.1.0
## 03/29/2018

1. [](#new)
    * Added new Theme `copy` option to create a new theme from another
1. [](#improved)
    * Stop flushing GPM cache on each call to speed things up considerably!
1. [](#bugfix)
    * Updated README.md [#23](https://github.com/getgrav/grav-plugin-devtools/pull/23)
    * Properly extend Theme or Plugin [#24](https://github.com/getgrav/grav-plugin-devtools/pull/24)

# v1.0.8
## 10/02/2017

1. [](#bugfix)
    inherited theme is after new theme [#9](https://github.com/getgrav/grav-plugin-devtools/issues/9)

# v1.0.7
## 10/02/2017

1. [](#bugfix)
    * Various fixes for things that broke with the blueprint generation PR [#20](https://github.com/getgrav/grav-plugin-devtools/issues/20)

# v1.0.6
## 09/28/2017

1. [](#new)
    * Added blueprint generation [#17](https://github.com/getgrav/grav-plugin-devtools/pull/17)
1. [](#improved)
    * changed Pure CDN location [#19](https://github.com/getgrav/grav-plugin-devtools/pull/19)
1. [](#bugfix)
    * Fixed readme referencing `githubid` [#13](https://github.com/getgrav/grav-plugin-devtools/pull/13)

# v1.0.5
## 02/26/2017

1. [](#improved)
    * Added GitHub ID prompt [#5](https://github.com/getgrav/grav-plugin-devtools/pull/5)
1. [](#bugfix)
    * Added missing closing html tag [#12](https://github.com/getgrav/grav-plugin-devtools/pull/12)

# v1.0.4
## 10/19/2016

1. [](#improved)
    * More complete README.md
    * Typo in Error template

# v1.0.3
## 09/16/2016

1. [](#bugfix)
    * Removed `Theme` from theme's class causing events to not process - https://github.com/getgrav/grav/issues/1047
    * Typo in README.md

# v1.0.2
## 07/20/2016

1. [](#bugfix)
    * Removed old `header.html.twig`

# v1.0.1
## 05/06/2016

1. [](#bugfix)
    * Fix for Grav 1.0.x

# v1.0.0
## 04/19/2016

1. [](#new)
    * ChangeLog started...
