<?php
namespace Supham\Phpshare\Composer;

use Composer\Util\Platform;
use Composer\Util\Filesystem;

class ClearOrphanedCommand extends \Composer\Command\BaseCommand
{
    protected $fs;
    protected $sharedVendorDir = 'shared';

    protected function configure()
    {
        $this
            ->setName('clear-orphaned')
            ->setDescription('[PhpShare] Clears composer\'s internal shared packages that not symlinked to any project.')
            ->setHelp('')
        ;
    }

    protected function execute($input, $output)
    {
        $packages = glob($this->composer . $this->sharedVendorDir .'/*/*/*');
        $activepackages = [];

        foreach ($packages as $package) {
            if (is_link($package)) {
                $activePackages[] = readlink($package);
            }
        }

        $filesystem = new Filesystem();
        foreach ($packages as $package) {
            if (!is_link($package) && !in_array($package, $activePackages)) {
                $filesystem->removeDirectory($package);
            }
        }
    }
}
