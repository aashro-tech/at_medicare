<?php

namespace AASHRO\AtMedicare\EventListener;

use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Package\Event\PackageInitializationEvent;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PackageActivationListener
{
    public function __construct(
        private readonly Registry $registry,
        private readonly SiteConfiguration $siteConfiguration,
    ) {}

    public function __invoke(PackageInitializationEvent $event): void
    {
        $extensionKey = $event->getExtensionKey();

        // Only process this extension
        if ($extensionKey !== 'at_medicare') {
            return;
        }

        $package = $event->getPackage();
        $this->importSiteConfiguration($package->getPackagePath());

        // Import static data if pages do not exist
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $count = $queryBuilder->count('uid')->from('pages')->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1)))->executeQuery()->fetchOne();

        if ($count == 0) {
            $sqlFile = $package->getPackagePath() . 'ext_tables_static+adt.sql';
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
                $connection->executeStatement($sql);
            }
        }

        // Copy initial files to fileadmin
        $filesSource = $package->getPackagePath() . 'Initialisation/Files';
        $filesDest = Environment::getPublicPath() . '/fileadmin';
        if (is_dir($filesSource) && !$this->registry->get('filesImported', 'at_medicare')) {
            GeneralUtility::mkdir($filesDest);
            GeneralUtility::copyDirectory($filesSource, $filesDest);
            $this->registry->set('filesImported', 'at_medicare', 1);
        }

    }

    private function importSiteConfiguration(string $packagePath): void
    {
        $importAbsFolder = $packagePath . 'Initialisation/Site';
        if (!is_dir($importAbsFolder)) {
            return;
        }

        $destinationFolder = Environment::getConfigPath() . '/sites';
        GeneralUtility::mkdir($destinationFolder);
        $existingSites = $this->siteConfiguration->resolveAllExistingSites(false);

        $finder = GeneralUtility::makeInstance(Finder::class);
        $finder->directories()->ignoreUnreadableDirs()->in($importAbsFolder);

        if (!$finder->hasResults()) {
            return;
        }

        foreach ($finder as $siteConfigDirectory) {
            $siteIdentifier = $siteConfigDirectory->getBasename();

            if (isset($existingSites[$siteIdentifier])) {
                continue;
            }

            $targetDir = $destinationFolder . '/' . $siteIdentifier;

            if ($this->registry->get('siteConfigImport', $siteIdentifier) || is_dir($targetDir)) {
                continue;
            }

            GeneralUtility::mkdir($targetDir);
            GeneralUtility::copyDirectory($siteConfigDirectory->getPathname(), $targetDir);
            $this->registry->set('siteConfigImport', $siteIdentifier, 1);
        }
    }
}
