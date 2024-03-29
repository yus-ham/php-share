<?php
namespace Supham\Phpshare\Composer;

use Composer\Package\PackageInterface;

class LibraryInstaller extends \Composer\Installer\LibraryInstaller
{
    protected $sharedVendorDir = 'shared';

    public function __construct($io, $composer, $type = 'library', $old = null)
    {
        if ($old) {
            $this->io = $old->io;
            $this->composer = $old->composer;
            $this->type = $type;
            $this->downloadManager = $old->downloadManager;
            $this->filesystem = $old->filesystem;
            $this->vendorDir = $old->vendorDir;
            $this->binaryInstaller = $old->binaryInstaller;
        } else {
            parent::__construct($io, $composer, $type);
        }
    }

    private static function isPlugin(PackageInterface $package)
    {
        return $package->getType() === 'composer-plugin';
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $promise = parent::install($repo, $package);

        if (!self::isPlugin($package)) {
            return $promise;
        }

        $afterInstall = function() use($repo, $package) {
            try {
                $installers = require __DIR__.'/installers.php';

                foreach ($installers as $file) {
                    $file = $this->vendorDir .'/'. $file;

                    if (is_file($file)) {
                        $this->injectInstallerCode($file);
                    }
                }
                $this->composer->getPluginManager()->registerPackage($package, true);
            } catch (\Exception $e) {
                // Rollback installation
                $this->io->writeError('Plugin installation failed, rolling back');

                try {
                    parent::uninstall($repo, $package);
                } catch (\Exception $e) {
                }

                throw $e;
            }
        };

        try {
            return $promise->then($afterInstall);
        } catch (\Exception $e) {
            $afterInstall();
            return $promise;
        }
    }

    protected function injectInstallerCode($file)
    {
        $class = __CLASS__;
        $code = file_get_contents($file);
        $replacement = ""
            .PHP_EOL . "/** begin php-share plugin injected code **/"
            .PHP_EOL . "if (class_exists('$class', false)) { class LibraryInstallerBase extends \\$class {} }"
            .PHP_EOL . "else { class LibraryInstallerBase extends \Composer\Installer\LibraryInstaller {} }"
            .PHP_EOL . "/** end php-share plugin injected code **/"
            .PHP_EOL . PHP_EOL . '$0Base';

        $code = preg_replace('/class\s+\w+\s+extends\s+LibraryInstaller\b/s', $replacement, $code);
        file_put_contents($file, $code);
    }

    protected function afterInstall($path)
    {
        $this->linkPackage($path['savePath'], $path['constraintPath']);
        $this->linkPackage($path['constraintPath'], $path['packagePath']);
    }

    protected function installCode($package)
    {
        $path['savePath'] = $this->getSavePath($package); // <shared>/vend/name/vers
        $path['constraintPath'] = $this->getConstraintPath($package); // <shared>/vend/name/constraint
        $path['packagePath'] = $this->getInstallPath($package); // <project>/vend/name

        if (!is_file("$path[savePath]/composer.json")) {
            if ($promise = $this->downloadManager->install($package, $path['savePath'])) {
                return $promise->then(function() use($path) { $this->afterInstall($path); });
            }
        } else {
            $this->afterInstall($path);
        }
    }

    protected function updateCode($initial, $target)
    {
        $path['packagePath'] = $this->getInstallPath($initial);
        $path['savePath'] = $this->getSavePath($target);
        $path['constraintPath'] = $this->getConstraintPath($target);

        if (!is_file("$path[savePath]/composer.json")) {
            if ($promise = $this->downloadManager->install($target, $path['savePath'])) {
                return $promise->then(function () use ($path) { $this->afterInstall($path); });
            }
        } else {
            $this->afterInstall($path);
        }
    }

    protected function linkPackage($target, $link)
    {
        $this->filesystem->ensureDirectoryExists(dirname($link));
        $this->filesystem->removeDirectory($link);
        $relativePath = $this->filesystem->findShortestPath($link, $target);

        if (\Composer\Util\Platform::isWindows()) {
            // Implement symlinks as NTFS junctions on Windows
            $cwd = getcwd();
            $link = str_replace('\\', '/', $link);
            chdir(dirname($link));
            $this->io->writeError("\n    [php-share] Junctioning $relativePath -> $link\n", false);
            $this->filesystem->junction($relativePath, $link);
            chdir($cwd);
        } else {
            $this->io->writeError("\n    [php-share] Symlinking $relativePath -> $link\n", false);
            $this->filesystem->relativeSymlink($target, $link);
        }
    }

    /**
     * Get actual saving path 
     */
    public function getSavePath(PackageInterface $package)
    {
        $version = $package->isDev()
            ? (substr($package->getSourceReference(), 0, 7) ?: 'dev-master')
            : $package->getPrettyVersion();

        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/". $package->getName() .'/'. $version;
    }

    /** @var \Composer\Package\BasePackage $package */
    protected function getConstraintPath($package)
    {
        $name = $package->getName();

        if (isset(Plugin::$packagesToInstall[$name])) {
            $version = Plugin::$packagesToInstall[$name];
        } else {
            $version = $package->getPrettyVersion();
        }

        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/$name/con-". substr(md5($version), 0, 10);
    }
}
