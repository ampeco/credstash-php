<?php

namespace CredStash\Store;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use CredStash\Exception\DuplicateCredentialVersionException;
use CredStash\Exception\CredentialNotFoundException;

/**
 * A DynamoDB credential store.
 * 
 * @author Carson Full <carsonfull@gmail.com>
 */
class DynamoDbStore implements StoreInterface
{
    const DEFAULT_TABLE_NAME = 'credential-store';

    /** @var DynamoDbClient */
    protected $db;
    /** @var string */
    protected $tableName;

    /**
     * Constructor.
     *
     * @param DynamoDbClient $db
     * @param string         $tableName
     */
    public function __construct(DynamoDbClient $db, $tableName = self::DEFAULT_TABLE_NAME)
    {
        $this->db = $db;
        $this->tableName = $tableName;
    }

    /**
     * {@inheritdoc}
     */
    public function listCredentials()
    {
        $response = $this->db->scan([
            'TableName'                => $this->tableName,
            'ProjectionExpression'     => '#N, version',
            'ExpressionAttributeNames' => [
                '#N' => 'name',
            ],
        ]);

        if ($response['Count'] === 0) {
            return [];
        }

        $result = [];
        foreach ($response['Items'] as $item) {
            $result[$item['name']['S']] = $item['version']['S'];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function get($name)
    {
        $response = $this->query($name);

        if ($response['Count'] === 0) {
            throw new CredentialNotFoundException($name);
        }

        $item = $this->normalizeItem($response['Items'][0]);

        return $item;
    }

    /**
     * {@inheritdoc}
     */
    public function getAtVersion($name, $version)
    {
        $response = $this->db->getItem([
            'TableName' => $this->tableName,
            'Key'       => [
                'name'    => ['S' => $name],
                'version' => ['S' => $version],
            ],
        ]);

        $item = $this->normalizeItem($response['Item']);

        return $item;
    }

    /**
     * {@inheritdoc}
     */
    public function getHighestVersion($name)
    {
        $response = $this->query($name, 'version');

        if ($response['Count'] === 0) {
            return '0';
        }

        return $response['Items'][0]['version']['S'];
    }

    /**
     * {@inheritdoc}
     */
    public function put($name, $contents, $key, $hmac, $version)
    {
        $item = [
            'name'     => ['S' => $name],
            'version'  => ['S' => $version],
            'key'      => ['S' => $key],
            'contents' => ['S' => $contents],
            'hmac'     => ['S' => $hmac],
        ];

        $params = [
            'TableName'                => $this->tableName,
            'Item'                     => $item,
            'ConditionExpression'      => 'attribute_not_exists(#N)',
            'ExpressionAttributeNames' => [
                '#N' => 'name',
            ],
        ];

        try {
            $this->db->putItem($params);
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() === 'ConditionalCheckFailedException') {
                throw new DuplicateCredentialVersionException($item['name']['S'], $item['version']['S']);
            }

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete($name)
    {
        $response = $this->db->scan([
            'TableName'                 => $this->tableName,
            'FilterExpression'          => '#N = :name',
            'ProjectionExpression'      => '#N, version',
            'ExpressionAttributeNames'  => [
                '#N' => 'name',
            ],
            'ExpressionAttributeValues' => [
                ':name' => ['S' => $name],
            ],
        ]);

        foreach ($response['Items'] as $item) {
            $this->db->deleteItem([
                'TableName' => $this->tableName,
                'Key' => $item,
            ]);
        }
    }

    /**
     * Queries the DB for the secret.
     * 
     * @param string     $name
     * @param string|null $projection Optional projection expression
     *
     * @return \Aws\Result
     */
    private function query($name, $projection = null)
    {
        $params = [
            'TableName'                 => $this->tableName,
            'Limit'                     => 1,
            'ScanIndexForward'          => false,
            'ConsistentRead'            => true,
            'KeyConditionExpression'    => '#N = :name',
            'ExpressionAttributeNames'  => [
                '#N' => 'name',
            ],
            'ExpressionAttributeValues' => [
                ':name' => ['S' => $name],
            ],
        ];
        if ($projection !== null) {
            $params['ProjectionExpression'] = $projection;
        }

        return $this->db->query($params);
    }

    private function normalizeItem($item)
    {
        return array_map(function ($prop) {
            return $prop['S'];
        }, $item);
    }
}
