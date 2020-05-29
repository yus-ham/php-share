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
If any framework shipped a custom library installer, A.K.A a subclass of `Composer\Installer\LibraryInstaller`. you must register it.

In example, register [yiisoft/yii2-composer/Installer.php](https://github.com/yiisoft/yii2-composer/blob/64670b37a78f94ebf584405e676a6c88fc6b0d4a/Installer.php)
~~~bash
composer g config extra.lib-installers.{index} yiisoft/yii2-composer/Installer.php
~~~
