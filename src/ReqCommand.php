<?php
namespace Supham\Phpshare\Composer;

class ReqCommand extends \Composer\Command\RequireCommand
{
    public function pubDetermineRequirements($input, $output, $composer) {
        // copied from RequireCommand::execute()
        if ($composer->getPackage()->getPreferStable()) {
            $preferredStability = 'stable';
        } else {
            $preferredStability = $composer->getPackage()->getMinimumStability();
        }

        $reqs = parent::determineRequirements(
            $input,
            $output,
            $input->getArgument('packages'),
            $platformRepo = null,
            $preferredStability,
            !$input->getOption('no-update'),
            $input->getOption('fixed')
        );

        return $this->formatRequirements($reqs);
    }
}
