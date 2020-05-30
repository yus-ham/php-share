# php-share composer plugin
Sharing common used composer package to multiple projects by symlink.

# Install globally
~~~bash
composer g require supham/php-share
~~~

# Extra configuration
If a library depend on a custom library installer, A.K.A a subclass of `Composer\Installer\LibraryInstaller`. you must register path to it.

In example, register [yiisoft/yii2-composer/Installer.php](https://github.com/yiisoft/yii2-composer/blob/64670b37a78f94ebf584405e676a6c88fc6b0d4a/Installer.php)
~~~bash
composer g config extra.lib-installers.{index} yiisoft/yii2-composer/Installer.php
~~~
