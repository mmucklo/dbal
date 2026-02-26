<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Spanner;

use Doctrine\DBAL\Driver\AbstractSpannerDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Spanner\Middleware\RetryConnection;
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Spanner\SpannerClient;
use InvalidArgumentException;
use Throwable;

use function class_exists;
use function getenv;
use function is_array;
use function is_string;

/**
 * Google Cloud Spanner driver.
 */
final class Driver extends AbstractSpannerDriver
{
    private ?SpannerClient $client = null;

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $params
     *
     * @throws Exception
     * @throws GoogleException
     */
    public function connect(array $params): DriverConnection
    {
        if (! class_exists(SpannerClient::class)) {
            throw Exception::fromThrowable(
                new \Exception('The Google Cloud Spanner SDK is not installed. '
                    . 'Please run "composer require google/cloud-spanner".'),
            );
        }

        $clientOptions = $params['driverOptions'] ?? [];
        if (! is_array($clientOptions)) {
            $clientOptions = [];
        }

        $emulatorHost = getenv('SPANNER_EMULATOR_HOST');
        if (is_string($emulatorHost) && $emulatorHost !== '') {
            $clientOptions['emulatorHost'] = $emulatorHost;
        }

        // Force project ID for emulator if not provided.
        $projectId = $params['projectId']
            ?? $params['project']
            ?? (
                getenv('GOOGLE_CLOUD_PROJECT') !== false
                    ? getenv('GOOGLE_CLOUD_PROJECT')
                    : getenv('SPANNER_PROJECT_ID')
            );
        if (! is_string($projectId) || $projectId === '') {
             throw Exception::fromThrowable(
                 new InvalidArgumentException('The "projectId" or "project" parameter is required for Spanner.'),
             );
        }

        $clientOptions['projectId'] = $projectId;

        try {
            $client = new SpannerClient($clientOptions);
        } catch (Throwable $e) {
            throw Exception::fromThrowable($e);
        }

        $instanceId = $params['instance'] ?? $params['instanceId'] ?? getenv('SPANNER_INSTANCE_ID');
        if (! is_string($instanceId) || $instanceId === '') {
            throw Exception::fromThrowable(
                new InvalidArgumentException('The "instance" or "instanceId" parameter is required for Spanner.'),
            );
        }

        $databaseId = $params['dbname'] ?? $params['database'] ?? 'test-database';
        if (! is_string($databaseId) || $databaseId === '') {
            throw Exception::fromThrowable(
                new InvalidArgumentException('The "dbname" or "database" parameter is required for Spanner.'),
            );
        }

        $connection = new Connection(
            $client,
            $instanceId,
            $databaseId,
            $clientOptions,
        );

        return new RetryConnection($connection);
    }

    /**
     * @internal
     *
     * @return SpannerClient
     *
     * @throws Exception
     * @throws GoogleException
     */
    public function getSpannerClient(): object
    {
        if (! class_exists(SpannerClient::class)) {
            throw Exception::fromThrowable(new \Exception('The Google Cloud Spanner SDK is not installed.'));
        }

        if ($this->client === null) {
             $projectId = getenv('GOOGLE_CLOUD_PROJECT');
            if ($projectId === false || $projectId === '') {
                $projectId = getenv('SPANNER_PROJECT_ID');
            }

            if ($projectId === false || $projectId === '') {
                $projectId = 'test-project';
            }

            try {
                 $this->client = new SpannerClient([
                     'projectId' => $projectId,
                     'emulatorHost' => getenv('SPANNER_EMULATOR_HOST'),
                 ]);
            } catch (Throwable $e) {
                throw Exception::fromThrowable($e);
            }
        }

        return $this->client;
    }

    /** @internal */
    public function clearClient(): void
    {
        $this->client = null;
    }
}
