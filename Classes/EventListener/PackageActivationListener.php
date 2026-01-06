<?php

namespace AASHRO\AtMedicare\EventListener;

use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
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

    #[AsEventListener]
    public function __invoke(PackageInitializationEvent $event): void
    {
        $extensionKey = $event->getExtensionKey();

        // Only process this extension
        if ($extensionKey !== 'at_medicare') {
            return;
        }

        // Import site configuration from Initialisation/Site
        $package = $event->getPackage();
        $importAbsFolder = $package->getPackagePath() . 'Initialisation/Site';
        
        if (!is_dir($importAbsFolder)) {
            return;
        }

        $destinationFolder = Environment::getConfigPath() . '/sites';
        GeneralUtility::mkdir($destinationFolder);
        $existingSites = $this->siteConfiguration->resolveAllExistingSites(false);
        
        $finder = GeneralUtility::makeInstance(Finder::class);
        $finder->directories()->ignoreUnreadableDirs()->in($importAbsFolder);
        
        if ($finder->hasResults()) {
            foreach ($finder as $siteConfigDirectory) {
                $siteIdentifier = $siteConfigDirectory->getBasename();
                
                // Skip if site already exists
                if (isset($existingSites[$siteIdentifier])) {
                    continue;
                }
                
                $targetDir = $destinationFolder . '/' . $siteIdentifier;
                
                // Only import if not already imported and target doesn't exist
                if (!$this->registry->get('siteConfigImport', $siteIdentifier) && !is_dir($targetDir)) {
                    GeneralUtility::mkdir($targetDir);
                    GeneralUtility::copyDirectory($siteConfigDirectory->getPathname(), $targetDir);
                    $this->registry->set('siteConfigImport', $siteIdentifier, 1);
                }
            }
        }

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

        // Handle additional configuration for mask
        if (Environment::getProjectPath() !== Environment::getPublicPath()) {
            $additional = Environment::getConfigPath() . '/system/additional.php';
        } else {
            $additional = Environment::getLegacyConfigPath() . '/system/additional.php';
        }

        if (file_exists($additional)) {
            $additionalFileContent = file_get_contents($additional);
            // Content to add or update
            $newCode = "\n// Additional TYPO3 configuration for mask\n" .
                "\$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mask'] = [\n".
                "\t'backend' => 'EXT:at_medicare/Resources/Private/Mask/Backend/Templates',\n".
                "\t'backend_layouts_folder' => '',\n".
                "\t'backendlayout_pids' => '0',\n".
                "\t'content' => 'EXT:at_medicare/Resources/Private/Mask/Frontend/Templates',\n".
                "\t'content_elements_folder' => '',\n".
                "\t'json' => 'EXT:at_medicare/Configuration/Mask/mask.json',\n".
                "\t'layouts' => 'EXT:at_medicare/Resources/Private/Mask/Frontend/Layouts',\n".
                "\t'layouts_backend' => 'EXT:at_medicare/Resources/Private/Mask/Backend/Layouts',\n".
                "\t'loader_identifier' => 'json',\n".
                "\t'override_shared_fields' => '0',\n".
                "\t'partials' => 'EXT:at_medicare/Resources/Private/Mask/Frontend/Partials',\n".
                "\t'partials_backend' => 'EXT:at_medicare/Resources/Private/Mask/Backend/Partials',\n".
                "\t'preview' => 'EXT:at_medicare/Resources/Public/Mask/',\n".
                "];\n";

            // Check if the code already exists to avoid duplicates
            if (strpos($additionalFileContent, $newCode) === false) {
                // Append new code
                $updatedContent = $additionalFileContent . $newCode;

                // Write back to the file
                file_put_contents($additional, $updatedContent);
            }
        }
    }
}
