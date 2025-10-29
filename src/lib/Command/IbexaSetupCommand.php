<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Cloud\Command;

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Exception;
use Ibexa\Cloud\IbexaProductVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

#[AsCommand(name: 'ibexa:cloud:setup', description: 'Runs post install configuration tool.')]
final class IbexaSetupCommand
{
    private VersionParser $versionParser;

    private const string UPSUN_RESOURCES_PATH = __DIR__ . '/../../../resources/upsun';

    /**
     * @throws \Exception
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Install Upsun configuration files')]
        bool $upsun = false,
    ): int {
        if ($upsun === false) {
            $io->warning('No cloud provider was chosen.');

            return Command::SUCCESS;
        }

        $io->info('Installing Upsun config files...');

        $fileSystem = new Filesystem();

        $product = IbexaProductVersion::getInstalledProduct();

        $commonFiles = $this->getCommonFiles($product);
        $productSpecificFiles = $this->getProductSpecificFiles($product);

        // helper array for detecting common file overrides
        $commonFilePathNames = array_fill_keys(
            array_map(static function (SplFileInfo $file): string {
                return $file->getRelativePathname();
            }, iterator_to_array($commonFiles)),
            true
        );

        $io->info('Copying common files');

        $progressBar = new ProgressBar($io);
        $progressBar->start($commonFiles->count());
        foreach ($commonFiles as $file) {
            if ($fileSystem->exists($file->getRelativePathname())) {
                $io->info(
                    sprintf("File '%s' exists and has been overwritten", $file->getRelativePathname()),
                );
            }

            $fileSystem->copy($file->getPathname(), $file->getRelativePathname(), true);
            $progressBar->advance();
        }

        $progressBar->finish();
        $io->info('Copying product specific files');

        $progressBar->start(count($productSpecificFiles));
        foreach ($productSpecificFiles as $relativePathname => $file) {
            if (
                !array_key_exists($relativePathname, $commonFilePathNames)
                && $fileSystem->exists($relativePathname)
            ) {
                $io->info(
                    sprintf("File '%s' exists and has been overwritten", $relativePathname),
                );
            }

            $fileSystem->copy($file->getPathname(), $relativePathname, true);
            $progressBar->advance();
        }

        $progressBar->finish();

        $io->info('Upsun config files installed successfully');

        return Command::SUCCESS;
    }

    /**
     * @throws \Exception
     */
    private function getCommonFiles(string $product): Finder
    {
        $versionDir = $this->getVersionDirectory($product, self::UPSUN_RESOURCES_PATH . '/common');

        $finder = new Finder();
        $finder
            ->in(self::UPSUN_RESOURCES_PATH . '/common/' . $versionDir)
            ->ignoreDotFiles(false)
            ->followLinks()
            ->files();

        return $finder;
    }

    /**
     * @return array<string, SplFileInfo>
     *
     * @throws \Exception
     */
    private function getProductSpecificFiles(string $product): array
    {
        $files = [];
        $fallbackDirectories = $this->getEditionFallbackDirectories($product);

        foreach ($fallbackDirectories as $index => $fallbackDir) {
            $fallbackPath = self::UPSUN_RESOURCES_PATH . '/' . $fallbackDir;
            if (!is_dir($fallbackPath)) {
                continue;
            }

            try {
                $versionDir = $this->getVersionDirectory($product, $fallbackPath);
            } catch (Exception $exception) {
                if ($index === 0) {
                    throw $exception;
                }

                continue;
            }

            $finder = new Finder();
            $finder
                ->in($fallbackPath . '/' . $versionDir . '/')
                ->ignoreDotFiles(false)
                ->followLinks()
                ->files();

            foreach ($finder as $file) {
                $relativePathname = $file->getRelativePathname();

                if (!isset($files[$relativePathname])) {
                    $files[$relativePathname] = $file;
                }
            }
        }

        return $files;
    }

    private function getVersionDirectory(string $product, string $path): string
    {
        $finder = new Finder();
        $finder
            ->in($path)
            ->ignoreDotFiles(false)
            ->directories()
            ->depth(0);

        $versionDirs = array_values(
            array_map(
                static function (SplFileInfo $dir): string {
                    return $dir->getRelativePathname();
                },
                iterator_to_array($finder)
            )
        );

        $allRawDataSets = InstalledVersions::getAllRawData();
        $productPackage = null;

        foreach ($allRawDataSets as $rawData) {
            if (isset($rawData['versions'][$product])) {
                $productPackage = $rawData['versions'][$product];
                break;
            }
        }

        if ($productPackage === null) {
            throw new RuntimeException(sprintf('Could not find product "%s" in installed versions.', $product));
        }

        $aliases = $productPackage['aliases'] ?? [];
        $productVersion = $productPackage['version'] ?? '';

        $normalizedAliases = array_map(function (string $alias): string {
            $normalizedAlias = $this->getVersionParser()->parseNumericAliasPrefix($alias);
            if ($normalizedAlias === false) {
                throw new RuntimeException(sprintf('Unable to parse version. "%s" is invalid.', $alias));
            }

            return trim($normalizedAlias, '.');
        }, $aliases);

        foreach (Semver::rsort($versionDirs) as $versionDir) {
            // directory name:
            //      matches version (i.e. dev-master)
            //      OR is one of the normalized aliases (3.3.x-dev => 3.3)
            //      OR is one of the aliases (3.3.x-dev)
            //      OR matches semver constraint (3.3, 3.3.1)
            if (
                $versionDir === $productVersion
                || in_array($versionDir, $normalizedAliases, true)
                || in_array($versionDir, $aliases, true)
                || (
                    false === strpos($versionDir, 'dev-')
                    && Semver::satisfies($productVersion, '~' . $versionDir)
                )
            ) {
                return $versionDir;
            }
        }

        throw new Exception('Can\'t find directory matching your product version');
    }

    private function getVersionParser(): VersionParser
    {
        return $this->versionParser ??= new VersionParser();
    }

    /**
     * @return string[]
     */
    private function getEditionFallbackDirectories(string $product): array
    {
        $productIndex = array_search($product, IbexaProductVersion::IBEXA_PRODUCTS, true);

        if ($productIndex === false) {
            return [str_replace('/', '-', $product)];
        }

        $fallbacks = array_slice(IbexaProductVersion::IBEXA_PRODUCTS, $productIndex);

        return array_map(
            static fn (string $productName): string => str_replace('/', '-', $productName),
            $fallbacks
        );
    }
}
