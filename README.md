# php-share
Sharing common used php package to multiple projects.

# Install
~~~bash
composer g require supham/php-share
~~~

# Example
~~~bash
project1$ composer require [vendor]/[name]:[version-constraint]
project2$ composer require [vendor]/[name]:[version-constraint]
~~~

The library saved to `~/.composer/shared/[vendor]/[name]/[resolved-version]`

And then the library will be symlinked
~~~bash
~/.composer/shared/[vendor]/[name]/[version-constraint] -> ~/.composer/shared/[vendor]/[name]/[resolved-version]

project1/vendor/[vendor]/[name] -> ~/.composer/shared/[vendor]/[name]/[version-constraint]
project2/vendor/[vendor]/[name] -> ~/.composer/shared/[vendor]/[name]/[version-constraint]
~~~

# Extra configuration
Register custom library installers that comes from any framework. eg. [yiisoft/yii2-composer](https://github.com/yiisoft/yii2-composer/blob/master/Installer.php).
The clue: Installer is a [subclass of `Composer\Installer\LibraryInstaller`](https://github.com/yiisoft/yii2-composer/blob/64670b37a/Installer.php#L21).

Run to add installer entry
~~~bash
composer g config extra.lib-installers.{index} yiisoft/yii2-composer/Installer.php
~~~
