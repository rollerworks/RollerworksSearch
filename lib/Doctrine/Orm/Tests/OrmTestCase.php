<?php

declare(strict_types=1);

/*
 * This file is part of the RollerworksSearch package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Rollerworks\Component\Search\Tests\Doctrine\Orm;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Tests\TestUtil;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Exception;
use PHPUnit\Framework\Warning;
use Psr\SimpleCache\CacheInterface;
use Rollerworks\Component\Search\Doctrine\Dbal\EventSubscriber\SqliteConnectionSubscriber;
use Rollerworks\Component\Search\Doctrine\Orm\AbstractConditionGenerator;
use Rollerworks\Component\Search\Doctrine\Orm\DoctrineOrmFactory;
use Rollerworks\Component\Search\Doctrine\Orm\Functions\SqlFieldConversion;
use Rollerworks\Component\Search\Doctrine\Orm\Functions\SqlValueConversion;
use Rollerworks\Component\Search\SearchCondition;
use Rollerworks\Component\Search\Tests\Doctrine\Dbal\DbalTestCase;
use Rollerworks\Component\Search\Tests\Doctrine\Dbal\SchemaRecord;

abstract class OrmTestCase extends DbalTestCase
{
    protected const CUSTOMER_CLASS = Fixtures\Entity\ECommerceCustomer::class;
    protected const INVOICE_CLASS = Fixtures\Entity\ECommerceInvoice::class;

    /**
     * @var \Doctrine\ORM\EntityManager|null
     */
    protected $em;

    /**
     * @var \Doctrine\DBAL\Connection|null
     */
    protected $conn;

    /**
     * @var \Doctrine\DBAL\Logging\DebugStack|null
     */
    protected $sqlLoggerStack;

    /**
     * Shared connection when a TestCase is run alone (outside of it's functional suite).
     *
     * @var \Doctrine\DBAL\Connection|null
     */
    private static $sharedConn;

    /**
     * @var \Doctrine\ORM\EntityManager|null
     */
    private static $sharedEm;

    protected function setUp(): void
    {
        parent::setUp();

        if (!isset(self::$sharedConn)) {
            $GLOBALS['db_event_subscribers'] = SqliteConnectionSubscriber::class;

            $config = Setup::createAnnotationMetadataConfiguration([__DIR__.'/Fixtures/Entity'], true, null, null, false);
            $config->addCustomStringFunction(
                'RW_SEARCH_FIELD_CONVERSION',
                SqlFieldConversion::class
            );

            $config->addCustomStringFunction(
                'RW_SEARCH_VALUE_CONVERSION',
                SqlValueConversion::class
            );

            self::$sharedConn = TestUtil::getConnection();
            self::$sharedEm = EntityManager::create(self::$sharedConn, $config);

            $schemaTool = new SchemaTool(self::$sharedEm);
            $schemaTool->dropDatabase();
            $schemaTool->updateSchema(self::$sharedEm->getMetadataFactory()->getAllMetadata(), false);

            $recordSets = $this->getDbRecords();

            foreach ($recordSets as $set) {
                $set->executeRecords(self::$sharedConn);
            }
        }

        $this->conn = self::$sharedConn;
        $this->em = self::$sharedEm;

        // Clear the cache between runs
        $this->em->getConfiguration()->getQueryCacheImpl()->flushAll();

        $this->sqlLoggerStack = new \Doctrine\DBAL\Logging\DebugStack();
        $this->conn->getConfiguration()->setSQLLogger($this->sqlLoggerStack);
    }

    protected static function resetSharedConn()
    {
        if (self::$sharedConn) {
            self::$sharedConn->close();
            self::$sharedConn = null;
            self::$sharedEm = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Ensure the connection is reset between class-runs
        self::resetSharedConn();
    }

    protected function getOrmFactory()
    {
        return new DoctrineOrmFactory($this->createMock(CacheInterface::class));
    }

    /**
     * @return SchemaRecord[]
     */
    protected function getDbRecords()
    {
        return [];
    }

    /**
     * Returns the string for the ConditionGenerator.
     *
     * @return Query|NativeQuery
     */
    protected function getQuery()
    {
    }

    /**
     * Configure fields of the ConditionGenerator.
     */
    protected function configureConditionGenerator(AbstractConditionGenerator $conditionGenerator)
    {
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function assertRecordsAreFound(SearchCondition $condition, array $ids)
    {
        $query = $this->getQuery();

        $conditionGenerator = $this->getOrmFactory()->createConditionGenerator($query, $condition);
        $this->configureConditionGenerator($conditionGenerator);

        $whereClause = $conditionGenerator->getWhereClause();
        $conditionGenerator->updateQuery();

        $rows = $query->getArrayResult();
        $idRows = array_map(
            function ($value) {
                return $value['id'];
            },
            $rows
        );

        sort($ids);
        sort($idRows);

        $this->assertEquals(
            $ids,
            array_merge([], array_unique($idRows)),
            sprintf("Found these records instead: \n%s\nWith WHERE-clause: %s", print_r($rows, true), $whereClause)
        );
    }

    protected function onNotSuccessfulTest(\Throwable $e): void
    {
        // Ignore deprecation warnings.
        if ($e instanceof AssertionFailedError || ($e instanceof Warning && strpos($e->getMessage(), ' is deprecated,'))) {
            throw $e;
        }

        if (isset($this->sqlLoggerStack->queries) && \count($this->sqlLoggerStack->queries)) {
            $queries = '';
            $i = \count($this->sqlLoggerStack->queries);

            foreach (array_reverse($this->sqlLoggerStack->queries) as $query) {
                $params = array_map(
                    function ($p) {
                        if (\is_object($p)) {
                            return \get_class($p);
                        }

                        return "'".var_export($p, true)."'";
                    },
                    $query['params'] ?: []
                );

                $queries .= ($i + 1).". SQL: '".$query['sql']."' Params: ".implode(', ', $params).PHP_EOL;
                --$i;
            }

            $trace = $e->getTrace();
            $traceMsg = '';

            foreach ($trace as $part) {
                if (isset($part['file'])) {
                    if (strpos($part['file'], 'PHPUnit/') !== false) {
                        // Beginning with PHPUnit files we don't print the trace anymore.
                        break;
                    }

                    $traceMsg .= $part['file'].':'.$part['line'].PHP_EOL;
                }
            }

            $message =
                '['.\get_class($e).'] '.
                $e->getMessage().
                PHP_EOL.PHP_EOL.
                'With queries:'.PHP_EOL.
                $queries.PHP_EOL.
                'Trace:'.PHP_EOL.
                $traceMsg;

            throw new Exception($message, (int) $e->getCode(), $e instanceof \Exception ? $e : null);
        }

        throw $e;
    }
}
