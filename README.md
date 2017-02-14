# CakePHP Plugin for Lokalise.co

Lokalise is a service that helps you translated your application strings and keep a track of how those translations
change. The idea of this plugin is that you can upload the translation strings to this services, then after
translating them, download the translations and put them in the right directories for each locale.

## Installation

First add the plugin to composer

```sh
composer require lorenzo/lokalise
```

Then activate the plugin

```
bin/cake plugin load Lokalise
```

Finally add your project id and lokalise API token to `app.php`

```php
// config/app.php
...

'Lokalise' => [
    'project' => 'your project id',
    'api_token' => 'the api token as provided by lokalise'
]
```

## Usage

You first need to run the `i18n extract` that is provided by CakePHP. This task will find all call for the
`__()` of functions and generate at least one `.pot` file in the `src/Locale` folder


```sh
bin/cake i18n extract
```

Once this process is done, you can push your translations to lokalise. You need to be explicit about the locales
you want to create/update in lokalise:


```sh
bin/cake lokalise push --locakes en_US,fr_FR,pt_BR
```

You can now go to your lokalise dashboard and translated all the strings. In order to see the tranlated strings in
your applicaiton, you need to download them back:


```sh
bin/cake lokalise pull
```
