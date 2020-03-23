<?php
namespace Supham\Phpshare;

use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Plugin\PluginInterface;

class ComposerPlugin implements PluginInterface, EventSubscriberInterface
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
        return [];
    }
}
