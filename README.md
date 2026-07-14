# Grav Devtools Plugin

The `devtools` is a [Grav](https://github.com/getgrav/grav) Plugin that lets you quickly create a scaffolding for your new plugins and themes.  The plugin provides CLI commands that allow for the quick and easy deployment of a sample scaffolding for your new plugin.

# Installation

## GPM Installation (Preferred)

The simplest way to install this plugin is via the [Grav Package Manager (GPM)](https://learn.getgrav.org/advanced/grav-gpm).  From the root of your Grav install type:

    bin/gpm install devtools

## Manual Installation 

If for some reason you can't use GPM you can manually install this plugin. Download the zip version of this repository and unzip it under `/your/site/grav/user/plugins`. Then, rename the folder to `devtools`.

You should now have all the plugin files under

	/your/site/grav/user/plugins/devtools
	
## Configuration

By default, devtools will perform a check with the online gpm repository to ensure name-collision avoidance. If you wish to not perform this online check, change the devtools.yaml at `user/config/plugins` from `collision_check: true` to `collision_check: false`.

# Usage

## Plugin Scaffolding

To create a new plugin you simply need to run: `bin/plugin devtools new-plugin` and fill in the few questions at the prompts:

```
> bin/plugin devtools new-plugin
Enter Plugin Name: MyPlugin
Enter Plugin Description: My New Custom Plugin
Enter Developer Name: Johnny Rotten
Enter GitHub ID (can be blank): pretty-vacant
Enter Developer Email: johnny@rotten.com
Which Grav version should this target?
  [2.0 ] Grav 2.0 only (modern, PHP 8.3+)
  [both] Grav 1.7 and 2.0 (maximum compatibility)
  [1.7 ] Grav 1.7 only (legacy)
 > 2.0
Please choose a plugin template
  [blank] Basic Plugin
  [flex ] Basic Plugin prepared for custom Flex Objects
  [api  ] API + Admin Next integrated plugin (Grav 2.0)
 > blank

 [OK] PLUGIN MyPlugin created successfully

Path: /home/johnnyr/webroot/grav-installation/user/plugins/my-plugin
```

The **Developer Name** and **Developer Email** prompts default to your local `git config` values, so you can usually just hit enter.

### Plugin templates

- `blank` — a minimal plugin.
- `flex` — a plugin pre-wired for custom [Flex Objects](https://learn.getgrav.org/advanced/flex).
- `api` — *(Grav 2.0 only)* a plugin integrated with the [Grav API plugin](https://github.com/getgrav/grav-plugin-api) and Admin Next: a REST route + controller, its own admin permissions, and an Admin Next sidebar item and screen. After creating it, run `composer install` in the plugin folder so the controller autoloads.

## Theme Scaffolding

To create a new theme you simply need to run: `bin/plugin devtools new-theme` and fill in the few questions at the prompts:

```
> bin/plugin devtools new-theme
Enter Theme Name: MyTheme
Enter Theme Description: My New Custom Theme
Enter Developer Name: Johnny Rotten
Enter GitHub ID (can be blank): pretty-vacant
Enter Developer Email: johnny@rotten.com
Which Grav version should this target?
 > 2.0
Please choose a theme template
  [pure-blank ] Basic Theme using Pure.css
  [blades     ] Basic Theme using the Blades semantic CSS framework (no build step)
  [tailwind   ] Basic Theme using tailwind.css and including Alpine.js
  [daisyui    ] Basic Theme using Tailwind CSS and daisyUI components
  [inheritance] Inherit from another theme
  [copy       ] Copy another theme
 > pure-blank

 [OK] THEME MyTheme created successfully

Path: /home/johnnyr/webroot/grav-installation/user/themes/my-theme
```

There are **six template creation options**

1. `pure-blank` - A very basic blank theme that uses the [Pure CSS framework](https://purecss.io/).
2. `blades` - A semantic, no-build theme using the [Blades CSS framework](https://github.com/anyblades/blades) (a modern Pico CSS successor loaded from a CDN).
3. `tailwind` - A basic theme using [Tailwind CSS](https://tailwindcss.com/) and [Alpine.js](https://alpinejs.dev/).
4. `daisyui` - A theme using [Tailwind CSS](https://tailwindcss.com/) with the [daisyUI](https://daisyui.com/) component library.
5. `inheritance` - A minimal theme that inherits a base theme. To find out more, [read about theme inheritance on the Grav Learn site](https://learn.getgrav.org/themes/customization#theme-inheritance).
6. `copy` - Create a new theme based on an existing installed theme. The simplest way to get started by using another theme as the basis.

## Grav Version Targeting

Both `new-plugin` and `new-theme` ask which Grav version to target, and generate the correct `compatibility`, dependency, and PHP version for that choice:

| Target | `compatibility` | Grav dependency | PHP |
|--------|-----------------|-----------------|-----|
| `2.0`  | `['2.0']`       | `>=2.0.0`       | `>=8.3.0` |
| `both` | `['1.7', '2.0']`| `>=1.7.0`       | `>=8.0.0` |
| `1.7`  | `['1.7']`       | `>=1.7.0`       | `>=7.3.6` |

Pass `--grav=1.7|2.0|both` (or `-g`) to set it without the prompt (default is `2.0`). You can also pass `--template` (`-t`) to pick the template non-interactively, e.g.:

    bin/plugin devtools new-plugin --grav=2.0 --template=api --name="My Plugin" --desc="..." --dev="Me" --email="me@example.com"

## Installing Dependencies Automatically

After creating a component, the wizard offers to run its installer for you — `composer install` for plugins, or `npm install && npm run build` for the Tailwind and daisyUI themes. Pass `--install` (`-i`) to run it automatically without the prompt.

Your developer name, email, and GitHub ID are remembered between runs (saved to `user/config/plugins/devtools.yaml`) and pre-fill the prompts next time; the name and email also fall back to your local `git config`.

## Skipping Online Project Name Collision Checking

By default, devtools will check your project's name with the existing gpm ecosystem to ensure no collisions.  In order to skip this check, add an `--offline` or `-o` to your command:

    `bin/plugin devtools new-theme --offline`
or

    `bin/plugin devtools new-theme -o`
