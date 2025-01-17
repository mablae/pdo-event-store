<?php
/**
 * This file is part of the prooph/pdo-event-store.
 * (c) 2016-2016 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2016 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStore\PDO;

use PDO;
use Prooph\Common\Event\ActionEvent;
use Prooph\Common\Event\ActionEventEmitter;
use Prooph\Common\Messaging\MessageConverter;
use Prooph\Common\Messaging\MessageFactory;
use Prooph\EventStore\AbstractTransactionalActionEventEmitterEventStore;
use Prooph\EventStore\Exception\ConcurrencyException;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\PDO\Exception\ExtensionNotLoaded;
use Prooph\EventStore\PDO\Exception\RuntimeException;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;

final class PostgresEventStore extends AbstractTransactionalActionEventEmitterEventStore
{
    /**
     * @var MessageFactory
     */
    private $messageFactory;

    /**
     * @var MessageConverter
     */
    private $messageConverter;

    /**
     * @var PDO
     */
    private $connection;

    /**
     * @var PersistenceStrategy
     */
    private $persistenceStrategy;

    /**
     * @var int
     */
    private $loadBatchSize;

    /**
     * @var string
     */
    private $eventStreamsTable;

    /**
     * @throws ExtensionNotLoaded
     */
    public function __construct(
        ActionEventEmitter $actionEventEmitter,
        MessageFactory $messageFactory,
        MessageConverter $messageConverter,
        PDO $connection,
        PersistenceStrategy $persistenceStrategy,
        int $loadBatchSize = 10000,
        string $eventStreamsTable = 'event_streams'
    ) {
        if (! extension_loaded('pdo_pgsql')) {
            throw ExtensionNotLoaded::with('pdo_pgsql');
        }

        $this->actionEventEmitter = $actionEventEmitter;
        $this->messageFactory = $messageFactory;
        $this->messageConverter = $messageConverter;
        $this->connection = $connection;
        $this->persistenceStrategy = $persistenceStrategy;
        $this->loadBatchSize = $loadBatchSize;
        $this->eventStreamsTable = $eventStreamsTable;

        $actionEventEmitter->attachListener(self::EVENT_CREATE, function (ActionEvent $event): void {
            $stream = $event->getParam('stream');

            $streamName = $stream->streamName();

            $this->createSchemaFor($streamName);
            $this->addStreamToStreamsTable($stream);

            $this->appendTo($streamName, $stream->streamEvents());

            $event->setParam('result', true);
        });

        $actionEventEmitter->attachListener(self::EVENT_APPEND_TO, function (ActionEvent $event): void {
            $streamName = $event->getParam('streamName');
            $streamEvents = $event->getParam('streamEvents');

            $countEntries = iterator_count($streamEvents);
            $columnNames = $this->persistenceStrategy->columnNames();
            $data = $this->persistenceStrategy->prepareData($streamEvents);

            if (empty($data)) {
                $event->setParam('result', true);

                return;
            }

            $tableName = $this->persistenceStrategy->generateTableName($streamName);

            $rowPlaces = '(' . implode(', ', array_fill(0, count($columnNames), '?')) . ')';
            $allPlaces = implode(', ', array_fill(0, $countEntries, $rowPlaces));

            $sql = 'INSERT INTO ' . $tableName . ' (' . implode(', ', $columnNames) . ') VALUES ' . $allPlaces;

            $statement = $this->connection->prepare($sql);

            $result = $statement->execute($data);

            if (in_array($statement->errorCode(), $this->persistenceStrategy->uniqueViolationErrorCodes(), true)) {
                throw new ConcurrencyException();
            }

            if (! $result) {
                $event->setParam('result', false);

                return;
            }

            $event->setParam('result', true);
        });

        $actionEventEmitter->attachListener(self::EVENT_LOAD, function (ActionEvent $event): void {
            $streamName = $event->getParam('streamName');
            $fromNumber = $event->getParam('fromNumber');
            $count = $event->getParam('count');
            $metadataMatcher = $event->getParam('metadataMatcher');

            if (null === $count) {
                $count = PHP_INT_MAX;
            }

            if (null === $metadataMatcher) {
                $metadataMatcher = new MetadataMatcher();
            }

            $tableName = $this->persistenceStrategy->generateTableName($streamName);

            $sql = [
                'from' => "SELECT * FROM $tableName",
                'orderBy' => 'ORDER BY no ASC',
            ];

            foreach ($metadataMatcher->data() as $match) {
                $field = $match['field'];
                $operator = $match['operator']->getValue();
                $value = $match['value'];

                if (is_bool($value)) {
                    $value = var_export($value, true);
                    $sql['where'][] = "metadata ->> '$field' $operator '$value'";
                } elseif (is_string($value)) {
                    $value = $this->connection->quote($value);
                    $sql['where'][] = "metadata ->> '$field' $operator $value";
                } else {
                    $sql['where'][] = "metadata ->> '$field' $operator '$value'";
                }
            }

            $limit = $count < $this->loadBatchSize
                ? $count
                : $this->loadBatchSize;

            $query = $sql['from'] . " WHERE no >= $fromNumber";

            if (isset($sql['where'])) {
                $query .= ' AND ';
                $query .= implode(' AND ', $sql['where']);
            }

            $query .= ' ' . $sql['orderBy'];
            $query .= " LIMIT $limit;";

            $statement = $this->connection->prepare($query);
            $statement->setFetchMode(PDO::FETCH_OBJ);
            $statement->execute();

            if (0 === $statement->rowCount()) {
                $event->setParam('stream', false);

                return;
            }

            $event->setParam('stream', new Stream(
                $streamName,
                new PDOStreamIterator(
                    $this->connection,
                    $statement,
                    $this->messageFactory,
                    $sql,
                    $this->loadBatchSize,
                    $fromNumber,
                    $count,
                    true
                )
            ));
        });

        $actionEventEmitter->attachListener(self::EVENT_LOAD_REVERSE, function (ActionEvent $event): void {
            $streamName = $event->getParam('streamName');
            $fromNumber = $event->getParam('fromNumber');
            $count = $event->getParam('count');
            $metadataMatcher = $event->getParam('metadataMatcher');

            if (null === $count) {
                $count = PHP_INT_MAX;
            }

            if (null === $metadataMatcher) {
                $metadataMatcher = new MetadataMatcher();
            }

            $tableName = $this->persistenceStrategy->generateTableName($streamName);

            $sql = [
                'from' => "SELECT * FROM $tableName",
                'orderBy' => 'ORDER BY no DESC',
            ];

            foreach ($metadataMatcher->data() as $match) {
                $field = $match['field'];
                $operator = $match['operator']->getValue();
                $value = $match['value'];

                if (is_bool($value)) {
                    $value = var_export($value, true);
                    $sql['where'][] = "metadata ->> '$field' $operator '$value'";
                } elseif (is_string($value)) {
                    $value = $this->connection->quote($value);
                    $sql['where'][] = "metadata ->> '$field' $operator $value";
                } else {
                    $sql['where'][] = "metadata ->> '$field' $operator '$value'";
                }

                $sql['where'][] = "metadata ->> '$field' $operator $value";
            }

            $limit = $count < $this->loadBatchSize
                ? $count
                : $this->loadBatchSize;

            $query = $sql['from'] . " WHERE no <= $fromNumber";

            if (isset($sql['where'])) {
                $query .= ' AND ';
                $query .= implode(' AND ', $sql['where']);
            }

            $query .= ' ' . $sql['orderBy'];
            $query .= " LIMIT $limit;";

            $statement = $this->connection->prepare($query);

            $statement->setFetchMode(PDO::FETCH_OBJ);
            $statement->execute();

            if (0 === $statement->rowCount()) {
                $event->setParam('stream', false);

                return;
            }

            $event->setParam('stream', new Stream(
                $streamName,
                new PDOStreamIterator(
                    $this->connection,
                    $statement,
                    $this->messageFactory,
                    $sql,
                    $this->loadBatchSize,
                    $fromNumber,
                    $count,
                    false
                )
            ));
        });

        $actionEventEmitter->attachListener(self::EVENT_DELETE, function (ActionEvent $event): void {
            $streamName = $event->getParam('streamName');

            $deleteEventStreamTableEntrySql = <<<EOT
DELETE FROM $this->eventStreamsTable WHERE real_stream_name = ?;
EOT;
            $statement = $this->connection->prepare($deleteEventStreamTableEntrySql);
            $statement->execute([$streamName->toString()]);

            $encodedStreamName = $this->persistenceStrategy->generateTableName($streamName);
            $deleteEventStreamSql = <<<EOT
DROP TABLE IF EXISTS $encodedStreamName;
EOT;
            $statement = $this->connection->prepare($deleteEventStreamSql);
            $statement->execute();

            $event->setParam('result', true);
        });

        $this->actionEventEmitter->attachListener(self::EVENT_BEGIN_TRANSACTION, function (ActionEvent $event): void {
            $this->connection->beginTransaction();

            $event->setParam('inTransaction', true);
        });

        $this->actionEventEmitter->attachListener(self::EVENT_COMMIT, function (ActionEvent $event): void {
            $this->connection->commit();

            $event->setParam('inTransaction', false);
        });

        $this->actionEventEmitter->attachListener(self::EVENT_ROLLBACK, function (ActionEvent $event): void {
            $this->connection->rollBack();

            $event->setParam('inTransaction', false);
        });

        $this->actionEventEmitter->attachListener(self::EVENT_HAS_STREAM, function (ActionEvent $event): void {
            $streamName = $event->getParam('streamName');
            $eventStreamsTable = $this->eventStreamsTable;

            $sql = <<<EOT
SELECT stream_name FROM $eventStreamsTable
WHERE real_stream_name = :streamName;
EOT;
            $statement = $this->connection->prepare($sql);

            $statement->execute(['streamName' => $streamName->toString()]);

            $stream = $statement->fetch(PDO::FETCH_OBJ);

            if (false === $stream) {
                $event->setParam('result', false);
            } else {
                $event->setParam('result', true);
            }
        });

        $this->actionEventEmitter->attachListener(self::EVENT_FETCH_STREAM_METADATA, function (ActionEvent $event): void {
            $streamName = $event->getParam('streamName');
            $eventStreamsTable = $this->eventStreamsTable;

            $sql = <<<EOT
SELECT metadata FROM $eventStreamsTable
WHERE real_stream_name = :streamName; 
EOT;

            $statement = $this->connection->prepare($sql);
            $statement->execute(['streamName' => $streamName->toString()]);

            $stream = $statement->fetch(PDO::FETCH_OBJ);

            if (! $stream) {
                $event->setParam('metadata', false);
            } else {
                $event->setParam('metadata', json_decode($stream->metadata, true));
            }
        });
    }

    private function addStreamToStreamsTable(Stream $stream): void
    {
        $realStreamName = $stream->streamName()->toString();
        $streamName = $this->persistenceStrategy->generateTableName($stream->streamName());
        $metadata = json_encode($stream->metadata());

        $sql = <<<EOT
INSERT INTO $this->eventStreamsTable (real_stream_name, stream_name, metadata)
VALUES (:realStreamName, :streamName, :metadata);
EOT;

        $statement = $this->connection->prepare($sql);
        $result = $statement->execute([
            ':realStreamName' => $realStreamName,
            ':streamName' => $streamName,
            ':metadata' => $metadata,
        ]);

        if (! $result) {
            throw new RuntimeException('Error during addStreamToStreamsTable: ' . implode('; ', $statement->errorInfo()));
        }
    }

    private function createSchemaFor(StreamName $streamName): void
    {
        $tableName = $this->persistenceStrategy->generateTableName($streamName);
        $schema = $this->persistenceStrategy->createSchema($tableName);

        foreach ($schema as $command) {
            $statement = $this->connection->prepare($command);
            $result = $statement->execute();

            if (! $result) {
                throw new RuntimeException('Error during createSchemaFor: ' . implode('; ', $statement->errorInfo()));
            }
        }
    }
}
