>NOTE:
><i>Discontinued in favor of [iwink/composer-global-installer](https://github.com/iwink/composer-global-installer)</i>

# php-share composer plugin
Sharing common used composer package to multiple projects by symlink.


# Install globally
~~~bash
composer g require supham/php-share
~~~

# Library Installers
Maybe sometime symlink not created. it usually caused by the package requires an installer plugin and it must be listed in `installers.php`. You can create PR to add it.

# Known Issues
- See [laminas/laminas-zendframework-bridge dependency issue](https://github.com/sup-ham/php-share/issues/2)
