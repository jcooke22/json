<?php

/*
 * This file is part of the webmozart/json package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webmozart\Json\Migration;

use stdClass;
use Webmozart\Json\Conversion\ConversionFailedException;
use Webmozart\Json\Conversion\JsonConverter;

/**
 * A decorator for a {@link JsonCoverter} that migrates JSON objects.
 *
 * This decorator supports JSON objects in different versions. The decorated
 * converter can be written for a specific version. Any other version can be
 * supported by supplying a {@link MigrationManager} that is able to migrate
 * a JSON object in that version to the version required by the decorated
 * converter.
 *
 * You need to pass the decorated converter and the migration manager to the
 * constructor:
 *
 * ~~~php
 * // Written for version 3.0
 * $converter = ConfigFileConverter();
 *
 * // Support older versions of the file
 * $migrationManager = new MigrationManager(array(
 *     new ConfigFile10To20Migration(),
 *     new ConfigFile20To30Migration(),
 * ));
 *
 * // Decorate the converter
 * $converter = new MigratingConverter($converter, '3.0', $migrationManager);
 * ~~~
 *
 * You can load JSON data in any version with the method {@link fromJson()}. If
 * the "version" property of the JSON object is different than the version
 * supported by the decorated converter, the JSON object is migrated to the
 * required version.
 *
 * ~~~php
 * $jsonDecoder = new JsonDecoder();
 * $configFile = $converter->fromJson($jsonDecoder->decode($json));
 * ~~~
 *
 * You can also dump data as JSON object with {@link toJson()}:
 *
 * ~~~php
 * $jsonEncoder = new JsonEncoder();
 * $jsonEncoder->encode($converter->toJson($configFile));
 * ~~~
 *
 * By default, data is dumped in the current version. If you want to dump the
 * data in a specific version, pass the "targetVersion" option:
 *
 * ~~~php
 * $jsonEncoder->encode($converter->toJson($configFile, array(
 *     'targetVersion' => '2.0',
 * )));
 * ~~~
 *
 * @since  1.3
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class MigratingConverter implements JsonConverter
{
    /**
     * @var JsonConverter
     */
    private $innerConverter;

    /**
     * @var MigrationManager
     */
    private $migrationManager;

    /**
     * @var string
     */
    private $currentVersion;

    /**
     * @var string[]
     */
    private $knownVersions;

    /**
     * Creates the converter.
     *
     * @param JsonConverter    $innerConverter   The decorated converter
     * @param string           $currentVersion   The version that the decorated
     *                                           converter is compatible with
     * @param MigrationManager $migrationManager The manager for migrating JSON data
     */
    public function __construct(JsonConverter $innerConverter, $currentVersion, MigrationManager $migrationManager)
    {
        $this->innerConverter = $innerConverter;
        $this->migrationManager = $migrationManager;
        $this->currentVersion = $currentVersion;
        $this->knownVersions = $this->migrationManager->getKnownVersions();

        if (!in_array($currentVersion, $this->knownVersions, true)) {
            $this->knownVersions[] = $currentVersion;
            usort($this->knownVersions, 'version_compare');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function toJson($data, array $options = array())
    {
        $targetVersion = isset($options['targetVersion'])
            ? $options['targetVersion']
            : $this->currentVersion;

        $this->assertVersionSupported($targetVersion);

        $jsonData = $this->innerConverter->toJson($data, $options);

        $this->assertObject($jsonData);

        $jsonData->version = $this->currentVersion;

        if ($jsonData->version !== $targetVersion) {
            $this->migrate($jsonData, $targetVersion);
            $jsonData->version = $targetVersion;
        }

        return $jsonData;
    }

    /**
     * {@inheritdoc}
     */
    public function fromJson($jsonData, array $options = array())
    {
        $this->assertObject($jsonData);
        $this->assertVersionIsset($jsonData);
        $this->assertVersionSupported($jsonData->version);

        if ($jsonData->version !== $this->currentVersion) {
            $this->migrationManager->migrate($jsonData, $this->currentVersion);
        }

        return $this->innerConverter->fromJson($jsonData, $options);
    }

    private function migrate(stdClass $jsonData, $targetVersion)
    {
        try {
            $this->migrationManager->migrate($jsonData, $targetVersion);
        } catch (MigrationFailedException $e) {
            throw new ConversionFailedException(sprintf(
                'Could not migrate the JSON data: %s',
                $e->getMessage()
            ), 0, $e);
        }
    }

    private function assertVersionSupported($version)
    {
        if (!in_array($version, $this->knownVersions, true)) {
            throw UnsupportedVersionException::forVersion($version, $this->knownVersions);
        }
    }

    private function assertObject($jsonData)
    {
        if (!$jsonData instanceof stdClass) {
            throw new ConversionFailedException(sprintf(
                'Expected an instance of stdClass, got: %s',
                is_object($jsonData) ? get_class($jsonData) : gettype($jsonData)
            ));
        }
    }

    private function assertVersionIsset(stdClass $jsonData)
    {
        if (!isset($jsonData->version)) {
            throw new ConversionFailedException('Could not find a "version" property.');
        }
    }
}
