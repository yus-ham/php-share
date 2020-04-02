<?php
namespace Supham\Phpshare;

use Composer\DependencyResolver\Rule;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Package\AliasPackage;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

class ComposerPlugin
implements
    Capable,
    CommandProvider,
    EventSubscriberInterface,
    PluginInterface
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

        if (!$reason instanceof Rule) {
            $package = null;
        } elseif ($job = $reason->getJob()) {
            $package = $job['packageName'] .'/'. $job['constraint']->getPrettyString();
        } elseif ($reasonData = $reason->getReasonData() and $reasonData instanceof AliasPackage) {
            $package = $reasonData->getName() .'/'. $reasonData->getPrettyVersion();
        } elseif (is_object($reasonData)) {
            $package = $reasonData->getTarget() .'/'. $reasonData->getConstraint()->getPrettyString();
        }

        $libInstaller->setRequestedPackage($package);
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
