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

namespace Prooph\EventStore\PDO\PersistenceStrategy;

use Iterator;
use Prooph\EventStore\PDO\PersistenceStrategy;
use Prooph\EventStore\StreamName;

final class MySQLSimpleStreamStrategy implements PersistenceStrategy
{
    /**
     * @param string $tableName
     * @return string[]
     */
    public function createSchema(string $tableName): array
    {
        $statement = <<<EOT
CREATE TABLE `$tableName` (
    `no` INT(11) NOT NULL AUTO_INCREMENT,
    `event_id` CHAR(36) COLLATE utf8_bin NOT NULL,
    `event_name` VARCHAR(100) COLLATE utf8_bin NOT NULL,
    `payload` JSON NOT NULL,
    `metadata` JSON NOT NULL,
    `created_at` CHAR(26) COLLATE utf8_bin NOT NULL,
    PRIMARY KEY (`no`),
    UNIQUE KEY `ix_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
EOT;

        return [$statement];
    }

    public function columnNames(): array
    {
        return [
            'event_id',
            'event_name',
            'payload',
            'metadata',
            'created_at',
        ];
    }

    public function prepareData(Iterator $streamEvents): array
    {
        $data = [];

        foreach ($streamEvents as $event) {
            $data[] = $event->uuid()->toString();
            $data[] = $event->messageName();
            $data[] = json_encode($event->payload());
            $data[] = json_encode($event->metadata());
            $data[] = $event->createdAt()->format('Y-m-d\TH:i:s.u');
        }

        return $data;
    }

    /**
     * @return string[]
     */
    public function uniqueViolationErrorCodes(): array
    {
        return ['23000'];
    }

    public function generateTableName(StreamName $streamName): string
    {
        return '_' . sha1($streamName->toString());
    }
}
