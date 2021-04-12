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
    /** @var \Composer\Composer */
    protected static $composer;
    protected static $isV1;
    protected $io;
    private $requestedPackage;

    public function activate($composer, $io)
    {
        $GLOBALS['Supham\Phpshare\Composer\Plugin'] = $this;
        self::$composer = $composer;
        self::$isV1 = strpos(self::$composer->getVersion(), '1.') === 0;
        $this->io = $io;
        $this->setLibraryInstaller();
    }

    public static function getInstance()
    {
        return $GLOBALS['Supham\Phpshare\Composer\Plugin'];
    }

    protected function setLibraryInstaller()
    {
        $manager = self::$composer->getInstallationManager();
        $oldInstaller = $manager->getInstaller(null);
        $libInstaller = new LibraryInstaller($this->io, self::$composer, null, $oldInstaller);
        $manager->addInstaller($libInstaller);
    }

    public static function getSubscribedEvents()
    {
        return [
            'pre-package-install' => 'prePackageInstall',
            'command' => 'preCommand',
        ];
    }

    /**
     * @param \Composer\Plugin\CommandEvent $event
     */
    public function preCommand($event)
    {
        if ($event->getCommandName() !== 'require') {
            return;
        }
        $cmd = new ReqCommand();

        $reqs = $cmd->pubDetermineRequirements(
            $event->getInput(),
            $event->getOutput(),
            self::$composer
        );

        foreach ($reqs as $name => $version) {
            $this->requestedPackage[$name] = $name .'/con-'. substr(sha1($version), 0, 10);
        }
    }

    /**
     * @param \Composer\Installer\PackageEvent $event
     */
    public function prePackageInstall($event)
    {
        $package = $event->getOperation()->getPackage();

        if (!self::$isV1) {
            $name = $package->getName();
            $version = $package->getPrettyVersion();
            return $this->requestedPackage[0] = $name .'/con-'. substr(sha1($version), 0, 10);
        }

        $reason = $event->getOperation()->getReason();

        if (!($reason instanceof Rule)) {
            return;
        }

        if ($job = $reason->getJob()) {
            return $this->setRequestedPackage($job['packageName'], $job['constraint']->getPrettyString());
        }

        if ($reasonData = $reason->getReasonData() and $reasonData instanceof AliasPackage) {
            return $this->setRequestedPackage($reasonData->getName(), $reasonData->getPrettyVersion());
        }

        if (is_object($reasonData)) {
            return $this->setRequestedPackage($reasonData->getTarget(), $reasonData->getConstraint()->getPrettyString());
        }
    }

    protected function setRequestedPackage($name, $version)
    {
        if (self::$isV1) {
            $this->requestedPackage = $name .'/con-'. substr(sha1($version), 0, 10);
        } else {
            $this->requestedPackage[$name] = $name .'/con-'. substr(sha1($version), 0, 10);
        }
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
