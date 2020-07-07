<?php
namespace Supham\Phpshare\Composer;

use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Platform;
use Symfony\Component\Filesystem\Filesystem;

class LibraryInstaller extends \Composer\Installer\LibraryInstaller
{
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
            $prefix = $this->vendorDir .'/'. $package->getName();

            foreach ($installers as $file) {
                $file = $prefix .'/'. $file;
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
        $constraintPath = $this->getConstraintPath();
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

    protected function updateCode($initial, $target)
    {
        $initialPath = $this->getInstallPath($initial); // vend/lib
        $targetSavePath = $this->getPackageSavePath($target); // shared/ven/lib
        $constraintPath = $this->getConstraintPath($target);

        if (is_dir($targetSavePath)) {
            if ($targetSavePath !== $constraintPath) {
            $this->linkPackage($targetSavePath, $constraintPath);
            }
            $this->linkPackage($constraintPath, $initialPath);
            return;
        }

        $initial->setInstallationSource($initial->getInstallationSource() ?: 'dist');
        $downloader = $this->downloadManager->getDownloaderForInstalledPackage($initial);

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
            chdir(dirname($link));
            $this->io->writeError("\n    [php-share] Junctioning $relativePath -> $link\n", false);
            $this->filesystem->junction($link, $relativePath);
            chdir($cwd);
        } else {
            $this->io->writeError("\n    [php-share] Symlinking $relativePath -> $link\n", false);
            $this->filesystem->relativeSymlink($target, $link);
        }
    }

    public function getPackageSavePath(PackageInterface $package)
    {
        $devVersion = substr($package->getSourceReference(), 0, 7) ?: 'dev-master';
        $version = $package->isDev() ? $devVersion : $package->getPrettyVersion();
        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/". $package->getName() .'/'. $version;
    }

    protected function getConstraintPath($package = null)
    {
        $version = $package ? $package->getName() .'/'. $package->getPrettyVersion() : Plugin::getInstance()->getRequestedPackage();
        $version = preg_replace('/ +/', '_', strtr($version, self::$transOpName));
        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/". $version;
    }
}
