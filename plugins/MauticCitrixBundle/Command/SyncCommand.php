<?php

namespace MauticPlugin\MauticCitrixBundle\Command;

use Mautic\CoreBundle\Command\ModeratedCommand;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\MauticCitrixBundle\Helper\CitrixHelper;
use MauticPlugin\MauticCitrixBundle\Helper\CitrixProducts;
use MauticPlugin\MauticCitrixBundle\Model\CitrixModel;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI Command : Synchronizes registrant information from Citrix products.
 *
 * php bin/console mautic:citrix:sync [--product=webinar|meeting|assist|training [--id=%productId%]]
 */
class SyncCommand extends ModeratedCommand
{
    private CitrixModel $citrixModel;

    public function __construct(CitrixModel $citrixModel, PathsHelper $pathsHelper, CoreParametersHelper $coreParametersHelper)
    {
        parent::__construct($pathsHelper, $coreParametersHelper);

        $this->citrixModel = $citrixModel;
    }

    protected function configure()
    {
        $this->setName('mautic:citrix:sync')
            ->setDescription('Synchronizes registrant information from Citrix products')
            ->addOption(
                'product',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Product to sync (webinar, meeting, training, assist)',
                null
            )
            ->addOption('id', 'i', InputOption::VALUE_OPTIONAL, 'The id of an individual registration to sync', null);

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = $input->getOptions();
        $product = $options['product'];

        if (!$this->checkRunStatus($input, $output, $options['product'].$options['id'])) {
            return 0;
        }

        $activeProducts = [];
        if (null === $product) {
            // all products
            foreach (CitrixProducts::toArray() as $p) {
                if (CitrixHelper::isAuthorized('Goto'.$p)) {
                    $activeProducts[] = $p;
                }
            }

            if (0 === count($activeProducts)) {
                $this->completeRun();

                return 0;
            }
        } else {
            if (!CitrixProducts::isValidValue($product)) {
                $output->writeln('<error>Invalid product: '.$product.'. Aborted</error>');
                $this->completeRun();

                return 0;
            }
            $activeProducts[] = $product;
        }

        $count = 0;
        foreach ($activeProducts as $product) {
            $output->writeln('<info>Synchronizing registrants for <comment>GoTo'.ucfirst($product).'</comment></info>');

            /** @var array $citrixChoices */
            $citrixChoices = [];
            $productIds    = [];
            if (null === $options['id']) {
                // all products
                $citrixChoices = CitrixHelper::getCitrixChoices($product, false);
                $productIds    = array_keys($citrixChoices);
            } else {
                $productIds[]                  = $options['id'];
                $citrixChoices[$options['id']] = $options['id'];
            }

            foreach ($productIds as $productId) {
                try {
                    $eventDesc = $citrixChoices[$productId];
                    $eventName = CitrixHelper::getCleanString(
                            $eventDesc
                        ).'_#'.$productId;
                    $output->writeln('Synchronizing: ['.$productId.'] '.$eventName);

                    $this->citrixModel->syncEvent($product, $productId, $eventName, $eventDesc, $count, $output);
                } catch (\Exception $ex) {
                    $output->writeln('<error>Error syncing '.$product.': '.$productId.'.</error>');
                    $output->writeln('<error>'.$ex->getMessage().'</error>');
                    if ('dev' === MAUTIC_ENV) {
                        $output->writeln('<info>'.$ex.'</info>');
                    }
                }
            }
        }

        $output->writeln($count.' contacts synchronized.');
        $output->writeln('<info>Done.</info>');

        $this->completeRun();

        return 0;
    }
}
