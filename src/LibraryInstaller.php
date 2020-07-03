<?php
namespace Supham\Phpshare\Composer;

use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Platform;
use Symfony\Component\Filesystem\Filesystem;

class LibraryInstaller extends \Composer\Installer\LibraryInstaller
{
    protected $fs;
    protected $sharedVendorDir = 'shared';

    private static $transOpName = [
        '==' => 'eq',
        '='  => 'eq',
        '>=' => 'ge',
        '<=' => 'le',
        '<'  => 'lt',
        '>'  => 'gt',
        '<>' => 'ne',
        '!=' => 'ne',
        '^'  => 'hat',
        '*'  => 'any',
        ' || ' => ' or ',
        ' | '  => ' or ',
        '||'   => ' or ',
        '|'    => ' or ',
    ];

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
        $this->fs = new Filesystem();
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
            $extra = $this->composer->getPluginManager()->getGlobalComposer()->getPackage()->getExtra();
            $installers = array();

            if (isset($extra['lib-installers'])) {
                $installers = (array)$extra['lib-installers'];
            }

            $installPath = $this->getInstallPath($package);
            $prefix = $package->getName();

            foreach ($installers as $class) {
                $file = strtr($class, [$prefix => $installPath]);
                if (!is_file($file)) {
                    continue;
                }
                $this->injectInstallerCode($file);
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
        $packageSavePath = $this->getPackageDownloadPath($package);
        $constraintPath = $this->getConstraintPath();
        $packagePath = $this->getInstallPath($package);

        if (!is_dir($packageSavePath)) {
            @unlink($packageSavePath);
            @unlink($constraintPath);
            $this->downloadManager->download($package, $packageSavePath);
        }
        if (!is_dir($constraintPath)) {
            @unlink($constraintPath);
            $this->linkPackage($packageSavePath, $constraintPath);
        }
        $this->linkPackage($constraintPath, $packagePath);
    }

    protected function updateCode($initial, $target)
    {
        $initialDownloadPath = $this->getInstallPath($initial); // vend/lib
        $initialDownloadPath = realpath($initialDownloadPath);
        $targetDownloadPath = $this->getPackageDownloadPath($target); // shared/ven/lib
        $constraintPath = $this->getConstraintPath();
        $this->filesystem->rename($initialDownloadPath, $targetDownloadPath);

        if (is_dir($targetDownloadPath)) {
            $this->linkPackage($targetDownloadPath, $constraintPath);
            $this->linkPackage($constraintPath, $initialDownloadPath);
            return;
        }

        $initial->setInstallationSource($initial->getInstallationSource() ?: 'dist');
        $downloader = $this->downloadManager->getDownloaderForInstalledPackage($initial);
        if (!$downloader) {
            return;
        }

        $installationSource = $initial->getInstallationSource();

        if ('dist' === $installationSource) {
            $initialType = $initial->getDistType();
            $targetType = $target->getDistType();
        } else {
            $initialType = $initial->getSourceType();
            $targetType = $target->getSourceType();
        }

        // upgrading from a dist stable package to a dev package, force source reinstall
        if ($target->isDev() && 'dist' === $installationSource) {
            $this->downloadManager->download($target, $targetDownloadPath);
            $this->linkPackage($targetDownloadPath, $constraintPath);
            $this->linkPackage($constraintPath, $initialDownloadPath);
            return;
        }

        $this->downloadManager->download($target, $targetDownloadPath, 'source' === $installationSource);
        $this->linkPackage($targetDownloadPath, $constraintPath);
        $this->linkPackage($constraintPath, $initialDownloadPath);
    }

    protected function removeCode($package)
    {
        $installPath = $this->getInstallPath($package);
        $this->filesystem->removeDirectory($installPath);
    }

    protected function linkPackage($src, $linkTarget)
    {
        $this->filesystem->ensureDirectoryExists(dirname($linkTarget));
        $this->filesystem->removeDirectory($linkTarget);

        if (Platform::isWindows()) {
            // Implement symlinks as NTFS junctions on Windows
            $this->io->writeError(sprintf("\n    [php-share] Junctioning %s -> %s\n", $linkTarget, $src), false);
            $this->filesystem->junction($src, $linkTarget);
        } else {
            $this->io->writeError(sprintf("\n    [php-share] Symlinking %s -> %s\n", $linkTarget, $src), false);
            $this->fs->symlink($src, $linkTarget);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $path = strtr(parent::getInstallPath($package), '/', DIRECTORY_SEPARATOR);
        return $this->filesystem->normalizePath($path);
    }

    public function getPackageDownloadPath(PackageInterface $package)
    {
        $version = $package->isDev() ? ($package->getSourceReference() ?: 'dev-master') : $package->getPrettyVersion();
        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/". $package->getName() .'/'. $version;
    }

    protected function getConstraintPath()
    {
        $version = strtr(Plugin::getInstance()->getRequestedPackage(), self::$transOpName);
        $version = preg_replace('/ +/', '_', $version);
        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/". $version;
    }
}
