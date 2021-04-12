<?php
namespace Supham\Phpshare\Composer;

use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Platform;

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
        parent::install($repo, $package);

        if (!self::isPlugin($package)) {
            return;
        }
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
            parent::uninstall($repo, $package);
            throw $e;
        }
    }

    protected function injectInstallerCode($file)
    {
        $class = __CLASS__;
        $code = file_get_contents($file);
        $replacement = ""
            .PHP_EOL . "// begin php-share plugin code"
            .PHP_EOL . "if (class_exists('$class', false)) { class LibraryInstallerBase extends \\$class {} }"
            .PHP_EOL . "else { class LibraryInstallerBase extends \Composer\Installer\LibraryInstaller {} }"
            .PHP_EOL . "// end php-share plugin code"
            .PHP_EOL . PHP_EOL . '$0Base';

        $code = preg_replace('/class\s+\w+\s+extends\s+LibraryInstaller\b/s', $replacement, $code);
        file_put_contents($file, $code);
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        if ($isPlugin = self::isPlugin($target)) {
            $extra = $target->getExtra();
            if (empty($extra['class'])) {
                throw new \UnexpectedValueException('Error while installing '.$target->getPrettyName().', composer-plugin packages should have a class defined in their extra key to be usable.');
            }
        }

        parent::update($repo, $initial, $target);

        if ($isPlugin) {
            $this->composer->getPluginManager()->registerPackage($target, true);
        }
    }

    protected function installCode($package)
    {
        $savePath = $this->getPackageSavePath($package);
        $constraintPath = $this->getConstraintPath($package);
        $packagePath = $this->getInstallPath($package);

        if (!is_dir($savePath)) {
            @unlink($savePath);
            @unlink($constraintPath);
            $this->downloadManager->download($package, $savePath);
        }
        if (!is_dir($constraintPath)) {
            @unlink($constraintPath);
            $this->linkPackage($savePath, $constraintPath);
        }
        $this->linkPackage($constraintPath, $packagePath);
    }

    protected function getInitialPackageDownloader()
    {
        return Plugin::$isV1
            ? $this->downloadManager->getDownloaderForInstalledPackage($initial)
            : $this->downloadManager->getDownloaderForPackage($initial);
    }

    protected function updateCode($initial, $target)
    {
        $initialPath = $this->getInstallPath($initial); // vend/name
        $targetSavePath = $this->getPackageSavePath($target); // shared/ven/name
        $constraintPath = $this->getConstraintPath($target);

        if (is_dir($targetSavePath)) {
            if ($targetSavePath !== $constraintPath) {
                $this->linkPackage($targetSavePath, $constraintPath);
            }
            $this->linkPackage($constraintPath, $initialPath);
            return;
        }

        $initial->setInstallationSource($initial->getInstallationSource() ?: 'dist');
        $downloader = $this->getInitialPackageDownloader($initial);

        if (!$downloader) {
            return;
        }

        $this->filesystem->removeDirectory($initialPath);
        $installationSource = $initial->getInstallationSource();

        if ('dist' === $installationSource) {
            $initialType = $initial->getDistType();
            $targetType = $target->getDistType();
        } else {
            $initialType = $initial->getSourceType();
            $targetType = $target->getSourceType();
        }

        if (is_file($targetSavePath)) {
            @unlink($targetSavePath);
        }

        // upgrading from a dist stable package to a dev package, force source reinstall
        if ($target->isDev() && 'dist' === $installationSource) {
            $this->downloadManager->download($target, $targetSavePath);
            $this->linkPackage($targetSavePath, $constraintPath);
            $this->linkPackage($constraintPath, $initialPath);
            return;
        }

        $this->downloadManager->download($target, $targetSavePath, 'source' === $installationSource);

        if ($targetSavePath !== $constraintPath) {
            $this->linkPackage($targetSavePath, $constraintPath);
        }
        $this->linkPackage($constraintPath, $initialPath);
    }

    protected function removeCode($package)
    {
        $installPath = $this->getInstallPath($package);
        $this->filesystem->removeDirectory($installPath);
    }

    protected function linkPackage($target, $link)
    {
        $this->filesystem->ensureDirectoryExists(dirname($link));
        $this->filesystem->removeDirectory($link);
        $relativePath = $this->filesystem->findShortestPath($link, $target);

        if (Platform::isWindows()) {
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
    public function getPackageSavePath(PackageInterface $package)
    {
        $devVersion = substr($package->getSourceReference(), 0, 7) ?: 'dev-master';
        $version = $package->isDev() ? $devVersion : $package->getPrettyVersion();
        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/". $package->getName() .'/'. $version;
    }

    protected function getConstraintPath($package = null)
    {
        $version = Plugin::getInstance()->getRequestedPackage();

        if ($package) {
            $name = $package->getName();

            if (is_array($version)) {
                if (isset($version[$name])) {
                    $version = $version[$name];
                } else {
                    $version = $version[0];
                }
            }
        }
        elseif (is_array($version)) {
            $version = $version[0];
        }

        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/$version";
    }
}
