<?php

namespace JsonAPI;

define('MONGO_TYPEMAP', ['root' => 'array', 'document' => 'array']);

class MongoResource extends Resource
{
    public static $collection;
    public static $client;

    protected static function col() {
        $collection = static::$collection;
        return static::$client->test->$collection;
    }
        
    public function save(array $claims) : MongoResource
    {
        $this->preSave($claims);
        if (isset($this->data['id']) === false) {
            $this->data['_id'] = uniqid(static::$collection . '-');
            static::col()->insertOne($this->data);
            $this->data['id'] = $this->data['_id'];
            unset($this->data['_id']);
        } else {
            $data = $this->data;
            unset($data['id']);
            static::col()->findOneAndUpdate(
                ['_id' => $this->data['id']],
                $data
            );
        }
        $this->postSave($claims);
        return $this;
    }

    public static function delete(string $id, array $claims = [])
    {
        self::preDelete($data, $claims);
        $filters = ['_id' => $id];
        $data = static::col()->deleteOne($filters, MONGO_TYPEMAP);
        self::postDelete($data, $claims);
    }

    public static function fetchResource(string $id, array $claims = []) : ?MongoResource {
        self::preGetResource($id, $claims);
        $filters = ['_id' => $id];
        $data = static::col()->findOne($filters, MONGO_TYPEMAP);
        $data['id'] = $id;
        $class = static::class;
        return new $class($data, $class);
    }

    public static function fetchCollection(array $parameters, array $claims) : array {
        static::preGetCollection($parameters, $claims);
        $fieldNames = array_flip(static::$attributeMap);
        $data = [];

        $maxPage = 0;
        $filters = [];
        foreach ($parameters['filters'] as $filter) {
            $fields = array_map(
                function($field) use($fieldNames) { return $fieldNames[$field]; },
                $filter['fields']
            );
            $values = $filter['values'];
            $operator = $filter['operator'];

            $newFilters = [];
            foreach ($fields as $field) {
                $newFilter = [];
                foreach ($values as $value) {
                    switch ($operator) {
                        case 'eq':
                            $match = $value;
                            break;
                        case 'cn':
                            $match = ['$regex' => $value, '$options' => 'i'];
                            break;
                        case 'ne':
                            $match = ['$ne' => $value];
                            break;
                    }
                    $newFilter[] = ["{$field}" => $match];
                }
                if (count($newFilter) === 1) {
                    $newFilter = $newFilter[0];
                } else {
                    $newFilter =  ['$or' => $newFilter];
                }
                $newFilters[] = $newFilter;
            }
            
            if (count($newFilters) === 1) {
                $filters[] = $newFilters[0];                                
            } else {
                $filters[] = ['$or' => $newFilters];
            }
        }

        $filterCount = count($filters);
        if ($filterCount === 1) {
            $filters = $filters[0];
        } else if ($filterCount > 1) {
            $filters = ['$and' => $filters];
        }
        
        $count = static::col()->count($filters);

        $sortField = $parameters['sortField'] === 'id'
             ? '_id'
             :$fieldNames[$parameters['sortField']];

        $maxPage = ceil($count / $parameters['pageSize']);
        $sortLimit = [
            'limit' => $parameters['pageSize'],
            'skip' => $parameters['pageSize'] * $parameters['pageNumber'],
            'sort' => [],
            "typeMap" => MONGO_TYPEMAP,
        ];
        $sortLimit['sort'][$sortField] = $parameters['sortDirection'] === 'asc' ? 1 : -1;

        $data = static::col()
            ->find($filters, $sortLimit, MONGO_TYPEMAP)
            ->toArray();

        foreach ($data as $key => $_) {
            $data[$key]['id'] = $data[$key]['_id'];
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