<?php

namespace AASHRO\AtMedicare\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ImportDataCommand extends Command
{
    public function __construct(
        private readonly Registry $registry,
        private readonly SiteConfiguration $siteConfiguration,
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
        private readonly PackageManager $packageManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Import initial data for at_medicare extension (pages, content, files, site configuration)');
        $this->setHelp('This command imports all initial data including pages, content records, files, and site configuration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importing at_medicare extension data');

        $package = $this->packageManager->getPackage('at_medicare');
        $packagePath = $package->getPackagePath();

        // Import site configuration
        $io->section('Importing site configuration...');
        $this->importSiteConfiguration($packagePath, $io);

        // Import SQL data
        $io->section('Importing SQL data (pages, content, etc.)...');
        $this->importSqlData($packagePath, $io);

        // Import files
        $io->section('Importing files...');
        $this->importFiles($packagePath, $io);

        // Handle additional configuration for mask
        $io->section('Configuring mask extension...');
        $this->handleMaskConfiguration($io);

        // Mark as imported
        $this->registry->set('at_medicare', 'dataImported', 1);

        $io->success('Data import completed successfully!');
        $io->note('Please clear the TYPO3 cache: ./vendor/bin/typo3 cache:flush');

        return Command::SUCCESS;
    }

    private function importSiteConfiguration(string $packagePath, SymfonyStyle $io): void
    {
        $importAbsFolder = $packagePath . 'Initialisation/Site';
        
        if (!is_dir($importAbsFolder)) {
            $io->warning('Site configuration folder not found: ' . $importAbsFolder);
            return;
        }

        $destinationFolder = Environment::getConfigPath() . '/sites';
        GeneralUtility::mkdir($destinationFolder);
        $existingSites = $this->siteConfiguration->resolveAllExistingSites(false);
        
        $finder = GeneralUtility::makeInstance(\Symfony\Component\Finder\Finder::class);
        $finder->directories()->ignoreUnreadableDirs()->in($importAbsFolder);
        
        $imported = 0;
        if ($finder->hasResults()) {
            foreach ($finder as $siteConfigDirectory) {
                $siteIdentifier = $siteConfigDirectory->getBasename();
                
                // Skip if site already exists
                if (isset($existingSites[$siteIdentifier])) {
                    $io->text("Site '{$siteIdentifier}' already exists, skipping...");
                    continue;
                }
                
                $targetDir = $destinationFolder . '/' . $siteIdentifier;
                
                // Only import if not already imported and target doesn't exist
                if (!$this->registry->get('siteConfigImport', $siteIdentifier) && !is_dir($targetDir)) {
                    GeneralUtility::mkdir($targetDir);
                    GeneralUtility::copyDirectory($siteConfigDirectory->getPathname(), $targetDir);
                    $this->registry->set('siteConfigImport', $siteIdentifier, 1);
                    $io->text("Imported site configuration: {$siteIdentifier}");
                    $imported++;
                }
            }
        }

        if ($imported > 0) {
            $io->success("Imported {$imported} site configuration(s)");
        } else {
            $io->info('No new site configurations to import');
        }
    }

    private function importSqlData(string $packagePath, SymfonyStyle $io): void
    {
        $sqlFile = $packagePath . 'ext_tables_static+adt.sql';
        
        if (!file_exists($sqlFile)) {
            $io->warning('SQL file not found: ' . $sqlFile);
            return;
        }

        // Check if root page already exists
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $existingPage = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1)))
                ->executeQuery()
                ->fetchOne();
            
            if ($existingPage) {
                $io->warning('Root page (UID 1) already exists. Skipping SQL import.');
                $io->note('If you want to re-import, delete the existing pages first.');
                return;
            }
        } catch (\Exception $e) {
            // Table might not exist, continue
        }

        $sqlContent = file_get_contents($sqlFile);
        
        if (empty($sqlContent)) {
            $io->warning('SQL file is empty');
            return;
        }

        // Normalize line endings
        $sqlContent = str_replace(["\r\n", "\r"], "\n", $sqlContent);
        
        // Remove comments
        $sqlContent = preg_replace('/--.*$/m', '', $sqlContent);
        $sqlContent = preg_replace('/\/\*.*?\*\//s', '', $sqlContent);
        
        // Split SQL content into individual statements
        $statements = preg_split('/;\s*(?=\n|$)/', $sqlContent, -1, PREG_SPLIT_NO_EMPTY);
        
        // Execute each statement
        $connection = $this->connectionPool->getConnectionByName('Default');
        $imported = 0;
        $errors = 0;
        
        $progressBar = $io->createProgressBar(count($statements));
        $progressBar->start();
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            
            // Skip empty statements
            if (empty($statement)) {
                $progressBar->advance();
                continue;
            }
            
            // Skip DROP and CREATE TABLE statements
            if (preg_match('/^(DROP TABLE|CREATE TABLE)/i', $statement)) {
                $progressBar->advance();
                continue;
            }
            
            // Only process INSERT statements
            if (preg_match('/^INSERT\s+INTO/i', $statement)) {
                try {
                    $connection->executeStatement($statement);
                    $imported++;
                } catch (\Exception $e) {
                    // Skip duplicate key errors
                    if (strpos($e->getMessage(), 'Duplicate entry') === false && 
                        strpos($e->getMessage(), 'already exists') === false) {
                        $errors++;
                    }
                }
            }
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $io->newLine(2);

        if ($imported > 0) {
            $io->success("Imported {$imported} SQL statement(s)");
        }
        if ($errors > 0) {
            $io->warning("Encountered {$errors} error(s) during import");
        }
    }

    private function importFiles(string $packagePath, SymfonyStyle $io): void
    {
        $filesSourcePath = $packagePath . 'Initialisation/Files';
        
        if (!is_dir($filesSourcePath)) {
            $io->warning('Files folder not found: ' . $filesSourcePath);
            return;
        }

        try {
            // Get the default storage (usually storage 1 for fileadmin)
            $storage = $this->storageRepository->findByUid(1);
            
            if (!$storage) {
                $storages = $this->storageRepository->findAll();
                if (empty($storages)) {
                    $io->error('No file storage found');
                    return;
                }
                $storage = reset($storages);
            }

            // Get or create the target folder
            $targetFolderIdentifier = 'at_medicare/';
            $targetFolder = null;
            
            try {
                $targetFolder = $storage->getFolder($targetFolderIdentifier);
            } catch (\Exception $e) {
                $rootFolder = $storage->getRootLevelFolder();
                $targetFolder = $storage->createFolder($targetFolderIdentifier, $rootFolder);
            }

            // Copy files recursively
            $imported = $this->copyFilesRecursively($filesSourcePath, $targetFolder, $storage, $io);
            
            if ($imported > 0) {
                $io->success("Imported {$imported} file(s)");
            } else {
                $io->info('No new files to import');
            }
        } catch (\Exception $e) {
            $io->error('File import error: ' . $e->getMessage());
        }
    }

    private function copyFilesRecursively(string $sourcePath, $targetFolder, $storage, SymfonyStyle $io): int
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $imported = 0;
        foreach ($iterator as $item) {
            $relativePath = str_replace($sourcePath . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);
            
            // Get the directory part
            $dirPath = dirname($relativePath);
            if ($dirPath === '.') {
                $dirPath = '';
            }
            
            // Navigate to or create the target folder
            $currentFolder = $targetFolder;
            if (!empty($dirPath)) {
                $pathParts = explode('/', $dirPath);
                foreach ($pathParts as $part) {
                    if (empty($part)) {
                        continue;
                    }
                    try {
                        $currentFolder = $storage->getFolder($currentFolder->getIdentifier() . $part . '/');
                    } catch (\Exception $e) {
                        try {
                            $currentFolder = $storage->createFolder($part, $currentFolder);
                        } catch (\Exception $e2) {
                            continue 2;
                        }
                    }
                }
            }
            
            if ($item->isFile()) {
                $fileName = basename($relativePath);
                
                try {
                    // Check if file already exists
                    try {
                        $storage->getFile($currentFolder->getIdentifier() . $fileName);
                        // File exists, skip
                        continue;
                    } catch (\Exception $e) {
                        // File doesn't exist, proceed with copy
                    }
                    
                    // Copy file to storage
                    $storage->addFile(
                        $item->getPathname(),
                        $currentFolder,
                        $fileName
                    );
                    $imported++;
                } catch (\Exception $e) {
                    // Skip file if it can't be copied
                    continue;
                }
            }
        }
        
        return $imported;
    }

    private function handleMaskConfiguration(SymfonyStyle $io): void
    {
        if (Environment::getProjectPath() !== Environment::getPublicPath()) {
            $additional = Environment::getConfigPath() . '/system/additional.php';
        } else {
            $additional = Environment::getLegacyConfigPath() . '/system/additional.php';
        }

        if (file_exists($additional)) {
            $additionalFileContent = file_get_contents($additional);
            $newCode = "\n// Additional TYPO3 configuration for mask\n" .
                "\$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mask'] = [\n".
                "\t'backend' => 'EXT:at_medicare/Resources/Private/Mask/Backend/Templates',\n".
                "\t'content' => 'EXT:at_medicare/Resources/Private/Mask/Frontend/Templates',\n".
                "\t'json' => 'EXT:at_medicare/Configuration/Mask/mask.json',\n".
                "\t'layouts' => 'EXT:at_medicare/Resources/Private/Mask/Frontend/Layouts',\n".
                "\t'layouts_backend' => 'EXT:at_medicare/Resources/Private/Mask/Backend/Layouts',\n".
                "\t'partials' => 'EXT:at_medicare/Resources/Private/Mask/Frontend/Partials',\n".
                "\t'partials_backend' => 'EXT:at_medicare/Resources/Private/Mask/Backend/Partials',\n".
                "\t'preview' => 'EXT:at_medicare/Resources/Public/Mask/',\n".
                "];\n";

            if (strpos($additionalFileContent, $newCode) === false) {
                $updatedContent = $additionalFileContent . $newCode;
                file_put_contents($additional, $updatedContent);
                $io->success('Mask configuration added');
            } else {
                $io->info('Mask configuration already exists');
            }
        } else {
            $io->warning('Additional configuration file not found');
        }
    }
}

