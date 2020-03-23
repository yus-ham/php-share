<?php
namespace Supham\Phpshare;

use Composer\Util\Platform;
use Symfony\Component\Filesystem\Filesystem;

class LibraryInstaller extends \Composer\Installer\LibraryInstaller
{
    protected $sharedVendorDir = 'shared';

    public function __construct($io, $composer, $old)
    {
        $this->composer = $old->composer;
        $this->downloadManager = $old->downloadManager;
        $this->io = $old->io;
        $this->type = null;
        $this->filesystem = $old->filesystem;
        $this->vendorDir = $old->vendorDir;
        $this->binaryInstaller = $old->binaryInstaller;
    }

    protected function installCode($package)
    {
        echo '  ['.__METHOD__.']'.PHP_EOL;
        $downloadPath = $this->getPackageDownloadPath($package);
        $link = $this->getInstallPath($package);

        if (is_file("$downloadPath.links")) {
          return $this->link($downloadPath, $link);
        }
        $this->downloadManager->download($package, $downloadPath);
        $this->link($downloadPath, $link);
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
        $linkTarget = $this->getInstallPath($target);

        if (is_file("$downloadPath.links")) {
            $this->link($downloadPath, $linkTarget);
            $this->unlink($initial, $initialPath, $linkTarget);
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
            $downloader->remove($initial, $sharedDir);
            $this->downloadManager->download($target, $sharedDir);
            return;
        }

        $this->downloadManager->download($target, $downloadPath, 'source' === $installationSource);
        $this->link($downloadPath, $linkTarget);
        $this->unlink($initial, $initialPath, $linkTarget);
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
        if (Platform::isWindows()) {
            // Implement symlinks as NTFS junctions on Windows
            $this->io->writeError(sprintf("  - Junctioning from %s\n", $src), false);
            $this->filesystem->junction($src, $linkTarget);
        } else {
            $this->io->writeError(sprintf("  - Symlinking from %s\n", $src), false);
            (new Filesystem)->symlink($src, $linkTarget);
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
        return $this->composer->getConfig()->get('data-dir') ."/{$this->sharedVendorDir}/". $package->getName() .'/'.
                  ($package->isDev() ? $package-> getSourceReference() : $package->getPrettyVersion());
    }
}
