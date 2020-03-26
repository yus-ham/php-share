# php-share
Save disk space by sharing common used php package to multiple projects.

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
