# Asset Plugin

Dealing with javascript files and css files is easy. You just include all your stylesheets and scripts on every page. However, with all these mobile devices accessing your app we must make an effort to reduce loading time. Non-mobile users will also appreciate efforts to optimize frontend performance of your app, as it's in most cases a far more serious bottleneck than the performance of your backend (php, mysql, etc).

The Asset plugin does exactly that for you - it combines and minifies scripts and stylesheets and makes sure, that only the assets needed for a specific view are loaded. It also supports preprocessors like LESS, Coffeescript and Kaffeine. Here is the full feature set:

## Features

* Combining js files and css files.

* Minification of combined files with support for different algorithmns (uglifyjs, jsmin, closure compiler and cssmin). There is an easy way to add your own algorithmns as well.
* Ability to specify which assets should be loaded for a given Controller::action pair. This ensures you only load the files that you really need on the page.
* Support for preprocessors like LESSCSS with support for prepending a file to the package that contains variables and less mixins. For javascript there is support for CoffeeScript and Kaffeine.
* Automatic detection and conversion for image paths in stylesheets.
* Combining and minification of files is optional, so that you can load the proper files in development mode to keep error messages pointing to the correct files and lines.
* Support for javascript internationalisation using the __('some text to translate') syntax
* Automagic including of files that belong to your controller action or layout. For example if you access /posts/edit/2 the plugin will try to load the stylesheet /app/webroot/css/views/posts/edit.less and the script /app/webroot/js/views/posts/edit.js.

 It will also load /app/webroot/css/views/layouts/default.less and /app/webroot/js/views/layouts/default.js or whatever layout has been set in your PostsController.  
 You can also configure these auto-include paths to your liking.
 * A shell to prebuild all packaged asset files for all Controller::action pairs on deployment
   -> This is optional, as in most cases the conversion can be done on the fly as it's really fast
   -> This builds packaged files or all languages (think of javascript i18n) and all layouts (think of the auto include paths)

* Handles external stylesheets and scripts gracefully, by not including them into packaged files.
* Only rebuilds packaged files if any of the stylesheets or scripts included in them changed.


## Requirements & Installation


The plugin has been designed to work with CakePHP 1.3.7 stable, but it also works with 1.2.x.

In case you intend to use LESSCSS, you require Nodejs version 0.2.2 or later. Likewise for Coffeescript, Kaffeine and Uglifyjs.


1. Move the plugin to /app/plugins or wherever your plugins reside.

2. Create the folders /app/webroot/css/aggregate and /app/webroot/js/aggregate, chmod them to 644 and and then add the execute flag to directories recursively. Make sure to chown them to www-data.www-data:

```
  chmod -R 644 /path/to/project/app/webroot/js/aggregate
  chmod -R +X /path/to/project/app/webroot/js/aggregate
  chown www-data.www-data /path/to/project/app/webroot/js/aggregate
```

.. and likewise for /css/aggregate.

Make sure to create these folders for all environments (production, staging, etc).

If you use Git, it's a good idea to add an "empty" file to each folder and just add that file to the repository while the the directories themselves are added to your .gitignore file. This makes sure all environments get the folders, but the contents are not in your git repository.

3. Add Configure::write('Assets.packaging', true) to your core.php file. Set it to false if you don't want packaged and minified files. It's a good idea to keep this to true for production environments and to false for everything else.

4. Create the files /app/config/css_includes.php and /app/config/js_includes.php and add the following 2 lines in your /app/config/bootstrap.php file:

   Configure::load('css_includes');
   Configure::load('js_includes');

5. Now open the css_includes.php file and add all css (or less) files that you want to load for specific controllers/action pairs:

Example:

```php
    <?php
    $config = array(
        'CssIncludes' => array(
            'vars_and_mixins.less' => '*:*', // always loaded
            'assemblies.less' => 'Posts:*', // loaded for all actions of the Posts Controller
            'stats.less' => 'Statistics:*, !Statistics:dashboard', // loaded for all actions in the StatisticsController except for the dashboard action
            'home.less' => 'Home:view', // only loaded for HomeController::view()
            'admin.less' => '*:admin_*', // loaded for all actions prefixed by "admin_"
            '//fonts.googleapis.com/css?family=Inconsolata' => '*:*' // an external stylesheet loaded everywhere
    // ...
        )
    );
    ?>
```

Do this likewise for javascript files in your js_includes.php file:

```php
    <?php
    $config = array(
        'JsIncludes' => array(
            'dep/jquery.js' => '*:*',
            'plugins/flot/jquery.flot.min.js' => 'Statistics:admin_dashboard',
            'plugins/flot/jquery.flot.selection.js' => 'Statistics:admin_dashboard',
            // ...
        )
    );
    ?>
```

6. Create the files /app/views/elements/css_includes.ctp and /app/views/elements/js_includes.ctp and fill them with the following contents:

css_includes.ctp:

```php
    <?php
    if (!isset($asset)) {
        return;
    }

    $inclusionRules = Configure::read('CssIncludes');
    $settings = array(
        'type' => 'css',
        'packaging' => Configure::read('Assets.packaging'),
        'css' => array(
            'mixins_file' => 'vars_and_mixins.less' // if you need support for less
        )
    );
    $asset->includeFiles($inclusionRules, $settings);
    ?>
```

js_includes.ctp:

```php
    <?php
    if (!isset($asset)) {
        return;
    }

    $inclusionRules = Configure::read('JsIncludes');
    $settings = array(
        'type' => 'js',
        'packaging' => Configure::read('Assets.packaging')
    );

    // IE sometimes has problems with minifications.
    // Better turn minification off for IE.
    $isIe = isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') !== false;
    if ($isIe) {
        $settings['minify'] = false;
    }
    $asset->includeFiles($inclusionRules, $settings);
    ?>
```

7. Make sure to load the css_includes element in the header of your layouts:

```php
    <?php echo $this->element('css_includes'); ?>
```

8. Make sure to load the js_includes element in the footer of your layout:

```php
    <?php echo $this->element('js_includes'); ?>
```

That's it!



## Usage & Options


### The Shell

To prebuild all your assets just run the prebuild_assets shell:

./cake prebuild_assets

This will build all packaged and minified files for all combinations of languages and layouts.

You can supply the list of languages you want to build javascript files for via the lang parameter.

./cake prebuild_assets -lang "en,fr,de"

Make sure to run the shell as root or in sudo as www-data to avoid permission problems.


### CSS Stylesheets

Here is a list of all options you can set for css files:

```php
    <?php
    $inclusionRules = Configure::read('CssIncludes');
    $settings = array(
        'type' => 'css', // the type of inclusion to do; can be "css" or "js"
        'css' => array(
          'path' => CSS, // the path where to look for stylesheets and where your "aggregate" folder is
          'ext' => 'css', // the extension of the result file(s)
          'delim' => "\n\n", // delimiter to use between the contents of css files in the combined css file
          'preprocessor' => array(
            'method' => 'less', // the preprocessor to use
            'pre_include_file' => 'vars_and_mixins.less', // the file to prepend to less conversions so variables and mixins are properly used, default is ''
            'ext' => 'less', // the extension of the files prior to conversion to LESS; set this to false disable LESS conversion; default is 'less'
          ),
          'minification' => array(
            'method' => 'cssmin', // which algorithmn to use for css minifications, default is cssmin
            'per_file' => false // if the minification should be run for each included css file or only once on the combined file; default is false
          )
        )
    );
    $asset->includeFiles($inclusionRules, $settings);
```


### Javascript

Here is a list of all options you can set for js files:

```php
    <?php
    $inclusionRules = Configure::read('JsIncludes');
    $settings = array(
        'type' => 'js',
        'js' => array(
            'path' => JS, // the path where to look for scripts and where your "aggregate" folder is
            'ext' => 'js', // the extension of the result file(s)
            'delim' => ";\n\n", // delimiter to use between the contents of css files in the combined css
            'locale' => 'de', // whether to translate __('some test') occurences in your javascript files into the specified locale; default value is false, so that no translation takes place
            'minification' => array(
                'method' => 'uglifyjs', // which algorithmn to use for js minifications, default is "uglifys", can also be "jsmin" or "google_closure"
                'per_file' => true // if the minification should be run for each included js file or only once on the combined file; default is true
            )
        ),
    );
    $asset->includeFiles($inclusionRules, $settings);
    ?>
```

### General Options

#### Packaging

To switch off packaging for development mode for example to have errors appear in proper files and on proper lines, use the 'packaging' key:

```php
    $settings = array(
      'packaging' => false,
      'type' => 'js',
      'js' => array(
        // your js settings
      ),
    );
```

The default value is `true`.

#### Minification

To switch off any minification, use the 'minify' key:

```php
    $settings = array(
      'minify' => false,
      'type' => 'js',
      'js' => array(
        // your js settings
      ),
    );
```

The default value is `true`.


#### Configuring auto include paths

Auto include paths are a nice means to have certain assets automatically included for your specific view. The plugin will automatically try to load the file in /app/webroot/js/views/layouts/default.js if your CakePHP view is in the `default` layout.

Also if you access /posts/edit/12 for example and you are rendering the view in /app/views/posts/edit.ctp, the plugin will try to include /app/webroot/css/views/posts/edit.less.

If you use a preprocessor, it will look for files ending with the specific preprocessor file extension.

This is really nice, but you can customize this further with your own paths:

```php
    $settings = array(
      'type' => 'js',
      'js' => array(
        // your js settings
      ),
      'auto_include_paths' => array(
        ':path:views/layouts/:layout:',
        ':path:views/:controller:/:action:',
        ':path:views/:controller:/:action:_:pass:'
      )
    );
```

:path: represents your outer path for everything, usually /app/webroot/js.
:controller: is the name of the currently used controller
:action: is the name of the currently used view
:pass: is the pass variable, useful to include /app/webroot/js/views/pages/view_pricing.less for the pricing page that is handled by Cake's default PagesController.


#### Directory cleaning

With all the packaging and file creation going on for each different request, the number of files in your /webroot/css/aggregate and /webroot/js/aggregate folders can grow pretty easily.

If the combination of files is the same, but some of them changed, we need to create a new packaged version for this set of asset files. The plugin is smart enough to remove the old version for this combination of files.

if you don't want this behavior, turn it off with the `cleanDir` key:

```php
    $settings = array(
      'type' => 'js',
      'js' => array(
        // your js settings
      ),
      'cleanDir' => false
    );
```

The default value is `true`.

#### Internationalisation

The plugin can translate your javascript for you. Enclose strings to translate in your javascript with __('some string') (remember that from the normal i18n in Cake?). If you specify a locale key in your js settings, the plugin will translate them according to your .po file for that locale.

```php
    $settings = array(
      'type' => 'js',
      'js' => array(
        'locale' => 'de', // translate into German
        // your other js settings
      )
    );
```

By default, `locale` is false, so no translations are done.


#### Path to Node executable

If your node executable is not in `/usr/local/bin/node` changed the pathToNode key accordingly:

```php
    $settings = array(
      'type' => 'js',
      'js' => array(
        // your js settings
      ),
      'pathToNode' => '/my/path/to/node'
    );
```



## Adding your own minification algorithm.

Creating your own minification algorithmn is easy. First, fork the repository.
Then just set the 'method' key in the 'minification' part of your settings to a function name, like 'myminify'. Finally, in the asset.php helper, create a method _myminify($content) {}. It should return the minified version of $content. You are done.


# Changelog

0.2.2 Changing api to remove $lang property. It's now called 'locale' and sits in the js specific settings. Some more internal refactoring done. Removed dependency from Html helper.

0.2.1 Changing the api for minification engines and preprocessors. Adding support for coffeescript and kaffeine. Adding support for uglifyjs compressor. jsmin is still available, but uglifyjs is also the default by now.