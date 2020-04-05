<?php
namespace Supham\Phpshare\Composer;

use Composer\DependencyResolver\Rule;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Package\AliasPackage;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

class Plugin implements
    Capable,
    CommandProvider,
    EventSubscriberInterface,
    PluginInterface
{
    protected $composer;
    protected $io;
    private $requestedPackage;
    private static $self;

    public function activate($composer, $io)
    {
        self::$self = $this;
        $this->io = $io;
        $this->composer = $composer;
        $this->setLibraryInstaller();
    }

    public static function getInstance()
    {
        return self::$self;
    }

    protected function setLibraryInstaller()
    {
        $manager = $this->composer->getInstallationManager();
        $oldInstaller = $manager->getInstaller(null);
        $libInstaller = new LibraryInstaller($this->io, $this->composer, null, $oldInstaller);
        $manager->addInstaller($libInstaller);
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

        if (!$reason instanceof Rule) {
            $package = null;
        } elseif ($job = $reason->getJob()) {
            $package = $job['packageName'] .'/'. $job['constraint']->getPrettyString();
        } elseif ($reasonData = $reason->getReasonData() and $reasonData instanceof AliasPackage) {
            $package = $reasonData->getName() .'/'. $reasonData->getPrettyVersion();
        } elseif (is_object($reasonData)) {
                $package = $reasonData->getTarget() .'/'. $reasonData->getConstraint()->getPrettyString();
        } else {
            $package = 'unknown';
        }
        $this->requestedPackage = $package;
    }

    public function getRequestedPackage()
    {
        return $this->requestedPackage;
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
