<?php
namespace Supham\Phpshare\Composer;

use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Plugin\PluginInterface;

class Plugin implements
    EventSubscriberInterface,
    PluginInterface
{
    /** @var \Composer\Composer */
    protected static $composer;
    protected static $isV1;
    public static $packagesToInstall = [];
    protected $io;

    public function activate($composer, $io)
    {
        self::$composer = $composer;
        $this->io = $io;
        $this->setLibraryInstaller();
    }

    public function deactivate(\Composer\Composer $composer, \Composer\IO\IOInterface $io)
    {
    }

    public function uninstall(\Composer\Composer $composer, \Composer\IO\IOInterface $io)
    {
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
        if (!self::$composer) {
            return [];
        }

        return [
            'pre-pool-create' => 'prePoolCreate',
            'pre-operations-exec' => 'preOpsExec',
        ];
    }

    /**
     * @param \Composer\Plugin\PrePoolCreateEvent $event
     */
    public function prePoolCreate($event)
    {
        /** @var \Composer\Semver\Constraint\ConstraintInterface $constraint */
        foreach ($event->getLoadedPackages() as $name => $constraint) {
            $version = $constraint->getPrettyString();

            if (strlen($version) < 2) {
                continue;
            }

            Plugin::$packagesToInstall[$name] = $version;
        }
    }

    public function preOpsExec()
    {
        foreach (glob(self::$composer->getConfig()->get('vendor-dir')."/*/*") as $path) {
            if (!file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
