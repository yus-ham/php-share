<?php
namespace Supham\Phpshare;

use Composer\DependencyResolver\Rule;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;

class ComposerPlugin
implements
    PluginInterface,
    EventSubscriberInterface,
    CommandProvider,
    Capable
{
    protected $composer;

    protected $io;

    public function activate($composer, $io)
    {
        $this->io = $io;
        $this->composer = $composer;
        $this->setLibraryInstaller();
    }

    protected function setLibraryInstaller()
    {
        $mngr = $this->composer->getInstallationManager();

        if ($oldInstaller = $mngr->getInstaller('default')) {
            $newInstaller = new LibraryInstaller($this->io, $this->composer, $oldInstaller);
            $mngr->addInstaller($newInstaller);
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            'pre-package-install' => 'prePackageInstall',
            'pre-package-update' => 'prePackageInstall',
        ];
    }

    public function prePackageInstall($event)
    {
        $reason = $event->getOperation()->getReason();
        $libInstaller = $this->composer->getInstallationManager()->getInstaller('default');
        $libInstaller->setRequestedPackage(null);

        if ($reason instanceof Rule) {
            if ($job = $reason->getJob()) {
                $package = $job['packageName'] .'/'. $job['constraint']->getPrettyString();
            } elseif ($reasonData = $reason->getReasonData() and is_object($reasonData)) {
                $package = $reasonData->getTarget() .'/'. $reasonData->getConstraint()->getPrettyString();
            }
            $libInstaller->setRequestedPackage($package);
        }
    }

    public function getCapabilities()
    {
        return [CommandProvider::class => __CLASS__];
    }

    public function getCommands()
    {
        return [new ClearOrphanedCommand()];
    }
}
