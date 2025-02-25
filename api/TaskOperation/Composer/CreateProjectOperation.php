<?php

declare(strict_types=1);

/*
 * This file is part of Contao Manager.
 *
 * (c) Contao Association
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\ManagerApi\TaskOperation\Composer;

use Contao\ManagerApi\ApiKernel;
use Contao\ManagerApi\Composer\Environment;
use Contao\ManagerApi\Task\TaskConfig;
use Contao\ManagerApi\TaskOperation\AbstractInlineOperation;
use Contao\ManagerApi\TaskOperation\ConsoleOutput;
use Symfony\Component\Filesystem\Filesystem;

class CreateProjectOperation extends AbstractInlineOperation
{
    /**
     * @var array
     */
    private static $supportedVersions = ['4.4', '4.9', '4.13'];

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @var ApiKernel
     */
    private $kernel;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var string
     */
    private $version;

    /**
     * Constructor.
     */
    public function __construct(TaskConfig $taskConfig, Environment $environment, ApiKernel $kernel, Filesystem $filesystem)
    {
        parent::__construct($taskConfig);

        $this->environment = $environment;
        $this->kernel = $kernel;
        $this->filesystem = $filesystem;
        $this->version = $taskConfig->getOption('version');

        if (!\in_array($this->version, static::$supportedVersions, true)) {
            throw new \InvalidArgumentException('Unsupported Contao version');
        }

        if ($this->kernel->getProjectDir() === $this->kernel->getPublicDir()) {
            throw new \RuntimeException('Cannot install without a public directory.');
        }
    }

    public function getSummary(): string
    {
        return 'composer create-project contao/managed-edition:'.$this->version;
    }

    public function getConsole(): ConsoleOutput
    {
        return $this->addConsoleOutput(new ConsoleOutput());
    }

    protected function getName(): string
    {
        return 'create-project';
    }

    protected function doRun(): bool
    {
        $protected = [
            $this->environment->getJsonFile(),
            $this->environment->getLockFile(),
            $this->environment->getVendorDir(),
        ];

        if ($this->filesystem->exists($protected)) {
            throw new \RuntimeException('Cannot install into existing application');
        }

        $this->filesystem->dumpFile(
            $this->environment->getJsonFile(),
            $this->generateComposerJson(
                $this->taskConfig->getOption('version'),
                (bool) $this->taskConfig->getOption('core-only', false)
            )
        );

        return true;
    }

    private function generateComposerJson($version, bool $coreOnly = false)
    {
        if ($coreOnly) {
            $require = <<<JSON
        "contao/conflicts": "*@dev",
        "contao/manager-bundle": "$version.*"
JSON;
        } else {
            $require = <<<JSON
        "contao/conflicts": "*@dev",
        "contao/manager-bundle": "$version.*",
        "contao/calendar-bundle": "$version.*",
        "contao/comments-bundle": "$version.*",
        "contao/faq-bundle": "$version.*",
        "contao/listing-bundle": "$version.*",
        "contao/news-bundle": "$version.*",
        "contao/newsletter-bundle": "$version.*"
JSON;
        }

        // https://github.com/contao/contao-manager/issues/627
        if (version_compare($version, '4.12', '>=')) {
            $publicDir = basename($this->kernel->getPublicDir());
            $script = '@php vendor/bin/contao-setup';
        } else {
            $publicDir = 'web';
            $script = 'Contao\\\\ManagerBundle\\\\Composer\\\\ScriptHandler::initializeApplication';
        }

        return <<<JSON
{
    "type": "project",
    "require": {
$require
    },
    "extra": {
        "public-dir": "$publicDir",
        "contao-component-dir": "assets"
    },
    "scripts": {
        "post-install-cmd": [
            "$script"
        ],
        "post-update-cmd": [
            "$script"
        ]
    }
}
JSON;
    }
}
