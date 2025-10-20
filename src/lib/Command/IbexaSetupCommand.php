<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Cloud\Command;

use Composer\Command\BaseCommand;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Exception;
use Ibexa\Cloud\IbexaProductVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

#[AsCommand(name: 'ibexa:setups', description: 'Runs post install configuration tool.')]
final class IbexaSetupCommand extends BaseCommand
{
    private VersionParser $versionParser;

    private const string UPSUN_RESOURCES_PATH = __DIR__ . '/../../../resources/upsun';

    private const array EDITION_FALLBACKS = [
        'ibexa-commerce' => ['ibexa-commerce', 'ibexa-experience', 'ibexa-headless', 'ibexa-oss'],
        'ibexa-experience' => ['ibexa-experience', 'ibexa-headless', 'ibexa-oss'],
        'ibexa-headless' => ['ibexa-headless', 'ibexa-oss'],
        'ibexa-oss' => ['ibexa-oss'],
    ];

    protected function configure(): void
    {
        $this->addOption(
            'upsun',
            null,
            InputOption::VALUE_NONE,
            'Install Upsun configuration files'
        );
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('upsun')) {
            return Command::SUCCESS;
        }

        $this->getIO()->write('Installing Upsun config files...');

        $fileSystem = new Filesystem();

        $product = IbexaProductVersion::getInstalledProduct();

        dd($product);

        $commonFiles = $this->getCommonFiles($product);
        $productSpecificFiles = $this->getProductSpecificFiles($product);

        // helper array for detecting common file overrides
        $commonFilePathNames = array_fill_keys(
            array_map(static function (SplFileInfo $file): string {
                return $file->getRelativePathname();
            }, iterator_to_array($commonFiles)),
            true
        );

        $output->writeln('Copying common files');

        $progressBar = new ProgressBar($output);
        $progressBar->start($commonFiles->count());
        $this->printNewLine($output);
        foreach ($commonFiles as $file) {
            if ($fileSystem->exists($file->getRelativePathname())) {
                $output->writeln(
                    sprintf("File '%s' exists and has been overwritten", $file->getRelativePathname()),
                    OutputInterface::VERBOSITY_VERBOSE
                );
            }

            $fileSystem->copy($file->getPathname(), $file->getRelativePathname(), true);
            $progressBar->advance();
            $this->printNewLine($output);
        }

        $progressBar->finish();
        $output->writeln("\nCopying product specific files");

        $progressBar->start(count($productSpecificFiles));
        $this->printNewLine($output);
        foreach ($productSpecificFiles as $relativePathname => $file) {
            if (
                !array_key_exists($relativePathname, $commonFilePathNames)
                && $fileSystem->exists($relativePathname)
            ) {
                $output->writeln(
                    sprintf("File '%s' exists and has been overwritten", $relativePathname),
                    OutputInterface::VERBOSITY_VERBOSE
                );
            }

            $fileSystem->copy($file->getPathname(), $relativePathname, true);
            $progressBar->advance();
            $this->printNewLine($output);
        }

        $progressBar->finish();

        $output->writeln("\nUpsun config files installed successfully");

        return Command::SUCCESS;
    }

    /**
     * @throws \Exception
     */
    protected function getCommonFiles(string $product): Finder
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
     * @return array<string,SplFileInfo>
     *
     * @throws \Exception
     */
    protected function getProductSpecificFiles(string $product): array
    {
        $files = [];
        $productDir = str_replace('/', '-', $product);
        $fallbackDirectories = $this->getEditionFallbackDirectories($productDir);

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

    protected function printNewLine(OutputInterface $output): void
    {
        $output->writeln('');
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
        if (!isset($this->versionParser)) {
            $this->versionParser = new VersionParser();
        }

        return $this->versionParser;
    }

    /**
     * @return string[]
     */
    private function getEditionFallbackDirectories(string $productDir): array
    {
        return self::EDITION_FALLBACKS[$productDir] ?? [$productDir];
    }
}
