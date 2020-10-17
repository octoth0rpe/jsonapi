<?php

namespace JsonAPI;

class RedisResource extends Resource
{
    public static $table;
    public static $client;
    public static $keyPrefix;
        
    public function save(array $claims) : RedisResource
    {
        $this->preSave($claims);

        if (isset($this->data['id']) === false) {
            $this->data['id'] = self::$client->incr(static::$keyPrefix.'.key');
            self::$client->sadd(
                static::$keyPrefix . '.index',
                $this->data['id']
            );
        }

        self::$client->hmset(
            static::$keyPrefix . '.' . $this->data['id'],
            $this->data
        );

        $this->postSave($claims);
        return $this;
    }

    public static function delete(string $id, array $claims = [])
    {
        $data = self::$client->hgetall(static::$keyPrefix . '.' . $id);
        self::preDelete($data, $claims);
        self::$client->srem(static::$keyPrefix . '.index', $id);
        self::$client->del(static::$keyPrefix . '.' . $id);
        self::postDelete($data, $claims);
    }

    public static function fetchResource(string $id, array $claims = []) : ?RedisResource {
        self::preGetResource($id, $claims);

        $data = self::$client->hgetall(static::$keyPrefix . '.' . $id);
        $data = static::postGetResource($data, $claims);

        if ($data) {
            $class = static::class;
            return new $class($data, $class);
        }
        return null;
    }


    protected static function mergePipeline($data, $results) {
        $index = -1;
        return array_map(
            function($item) use ($index, $data) {
                $index++;
                return array_merge($data[$index], $item);
            },
            $results
        );
    }

    public static function fetchCollection(array $parameters, array $claims) : array {
        static::preGetCollection($parameters, $claims);
        $data = [];
        
        $maxPage = 0;
        $ids = self::$client->smembers(static::$keyPrefix.'.index');
        $data = array_map(function($id) { return ['id' => $id ]; }, $ids);
        $fieldNames = array_flip(static::$attributeMap);

        if (count($ids) > 0) {

            $pipeline = self::$client->pipeline();
            foreach ($ids as $id) {
                $pipeline->hgetall(static::$keyPrefix . '.' . $id);
            }
            $data = self::mergePipeline($data, $pipeline->execute());

            foreach ($parameters['filters'] as $filter) {
                $data = array_filter(
                    $data,
                    function($item) use ($filter, $fieldNames) {
                        $passes = false;
                        foreach ($filter['fields'] as $field) {
                            $value = $item[$field === 'id' ? 'id' : $fieldNames[$field]];
                            switch ($filter['operator']) {
                                case 'cn':
                                    $value = strtolower($value);
                                    foreach ($filter['values'] as $allowed) {
                                        $allowed = strtolower($allowed);
                                        if (strstr($value, $allowed) !== false) {
                                            $passes = true;
                                        }
                                    }
                                    break;
                                case 'eq':
                                    foreach ($filter['values'] as $allowed) {
                                        if ($value === $allowed) {
                                            $passes = true;
                                        }
                                    }
                                    break;
                                case 'gt':
                                    foreach ($filter['values'] as $allowed) {
                                        if ($value > $allowed) {
                                            $passes = true;
                                        }
                                    }
                                    break;
                                case 'lt':
                                    foreach ($filter['values'] as $allowed) {
                                        if ($value < $allowed) {
                                            $passes = true;
                                        }
                                    }
                                case 'is':
                                    foreach ($filter['values'] as $allowed) {
                                        if ($allowed === 'true' && $value === '1') {
                                            $passes = true;
                                        }
                                        if ($allowed === 'false' && $value === '0') {
                                            $passes = true;
                                        }
                                    }
                                    break;
                            }
                        }
                        return $passes;
                    }
                );
            }

            $sortField = $parameters['sortField'] === 'id' ? 'id' : $fieldNames[$parameters['sortField']];
            $sortDirection = $parameters['sortDirection'] === 'asc' ? -1 : 1;
            usort($data, function ($a, $b) use ($sortField, $sortDirection) {
                $a = strtolower($a[$sortField]);
                $b = strtolower($b[$sortField]);
                return $sortDirection * ($b <=> $a);
            });

            $start = $parameters['pageNumber'] * $parameters['pageSize'];
            $maxPage = ceil(count($data) / $parameters['pageSize']);
            $data = array_slice($data, $start, $parameters['pageSize']);
        }

        $data = static::postGetCollection($data, $claims);

        return [
            static::makeCollection($data),
            $maxPage,
            $parameters['pageNumber'] + 1,
            $parameters['pageSize'],
        ];
    }
}