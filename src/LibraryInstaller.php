<?php
namespace Supham\Phpshare;

use Composer\Util\Platform;
use Symfony\Component\Filesystem\Filesystem;

class LibraryInstaller extends \Composer\Installer\LibraryInstaller
{
    protected $fs;
    protected $sharedVendorDir = 'shared';
    protected $requestedPackage;

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

    public function __construct($io, $composer, $old)
    {
        $this->composer = $old->composer;
        $this->downloadManager = $old->downloadManager;
        $this->io = $old->io;
        $this->type = null;
        $this->filesystem = $old->filesystem;
        $this->vendorDir = $old->vendorDir;
        $this->binaryInstaller = $old->binaryInstaller;
        $this->fs = new Filesystem();
    }

    protected function installCode($package)
    {
        echo PHP_EOL.'  ['.__METHOD__.']'.PHP_EOL;

        $downloadPath = $this->getPackageDownloadPath($package);
        $constraintPath = $this->getConstraintPath();
        $packagePath = $this->getInstallPath($package);

        if (!is_dir($downloadPath)) {
            $this->downloadManager->download($package, $downloadPath);
        }
        $this->linkPackage($downloadPath, $constraintPath);
        $this->linkPackage($constraintPath, $packagePath);
    }

    protected function updateCode($initial, $target)
    {
        echo '  ['.__METHOD__.']'.PHP_EOL;
        $initialDownloadPath = $this->getInstallPath($initial); // vend/lib
        $targetDownloadPath = $this->getPackageDownloadPath($target); // shared/ven/lib
        $this->downloadUpdateCode($initial, $target, $targetDownloadPath);
    }

    protected function downloadUpdateCode($initial, $target, $sharedDir)
    {
        echo '  ['.__METHOD__.']'.PHP_EOL;
        $downloadPath = $this->getPackageDownloadPath($target); // shared/ven/lib
        $initialPath = $this->getPackageDownloadPath($initial);
        $packageParh = $this->getInstallPath($target);
        $constraintPath = $this->getConstraintPath();

        if (is_dir($downloadPath)) {
            $this->linkPackage($downloadPath, $constraintPath);
            $this->linkPackage($constraintPath, $packageParh);
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
            $this->downloadManager->download($target, $sharedDir);
            $this->linkPackage($downloadPath, $constraintPath);
            $this->linkPackage($constraintPath, $packageParh);
            return;
        }

        $this->downloadManager->download($target, $downloadPath, 'source' === $installationSource);
        $this->linkPackage($downloadPath, $constraintPath);
        $this->linkPackage($constraintPath, $packageParh);
    }

    protected function removeCode($package)
    {
        echo '  ['.__METHOD__.']'.PHP_EOL;
        $linkTarget = $this->getPackageBasePath($package);
        $this->unlink($package, $this->getPackageDownloadPath($package), $linkTarget);
    }

    protected function link($src, $linkTarget)
    {
        @unlink($linkTarget);

        try {
            $this->linkPackage($src, $linkTarget);
            $this->saveLink($src, $linkTarget);
        } catch (\Exception $e) {
            $this->io->writeError(sprintf("\nError: %s", $e->getMessage()), false);
        }
    }

    protected function linkPackage($src, $linkTarget)
    {
        @unlink($linkTarget);
        if (Platform::isWindows()) {
            // Implement symlinks as NTFS junctions on Windows
            $this->io->writeError(sprintf("  - Junctioning %s -> %s\n", $linkTarget, $src), false);
            $this->filesystem->junction($src, $linkTarget);
        } else {
            $this->io->writeError(sprintf("  - Symlinking %s -> %s\n", $linkTarget, $src), false);
            $this->fs->symlink($src, $linkTarget);
        }
    }

    protected function saveLink($src, $linkTarget)
    {
        $linksFile = "$src.links";
        $links = @file_get_contents($linksFile);
        $alreadyLinked = $links && strpos("$linkTarget\n", $links) !== false;

        if (!$alreadyLinked) {
            file_put_contents("$src.links", "$linkTarget\n", LOCK_EX|FILE_APPEND);
        }
    }

    protected function unlink($srcPackage, $srcPath, $linkTarget)
    {
        echo "  - Delete symlink vendor/". $srcPackage->getName() ." -> $srcPath\n";

        @unlink($linkTarget);
        $linksFile = "$srcPath.links";

        if (!is_file($linksFile)) {
            return;
        }
        $links = (array)file($linksFile);
        foreach ($links as $i => $link) {
            if (trim($link) === $linkTarget) {
                unset($links[$i]);
            }
        }

        if ($links) {
            return file_put_contents($linksFile, $links, LOCK_EX);
        }
        unlink($linksFile);
        $this->filesystem->removeDirectory($srcPath);

        // TODO: delete empty folder
    }

    public function getPackageDownloadPath($package)
    {
        $version = $package->isDev() ? ($package->getSourceReference() ?: 'dev-master') : $package->getPrettyVersion();
        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/". $package->getName() .'/'. $version;
    }

    protected function getConstraintPath()
    {
        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/". strtr($this->requestedPackage, self::$transOpName);
    }

    /**
     * @param string $package package name and the requested version constraint
     */
    public function setRequestedPackage($package)
    {
        return $this->requestedPackage = $package;
    }
}
