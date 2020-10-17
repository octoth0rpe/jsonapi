<?php

namespace JsonAPI;
use ORM;

class SqlResource extends Resource
{
    public static $table;
        
    public function save(array $claims) : SqlResource
    {
        $this->preSave($claims);
        if (isset($this->data['id'])) {
            if (is_null($this->fetched)) {
                $this->fetched = ORM::forTable(static::$table)->findOne($this->data['id']);
            }
            $this->fetched->set($this->data);
            $this->fetched->save();
        } else {
            $new = ORM::forTable(static::$table)->create();
            foreach ($this->data as $key => $value) {
                $new->$key = $value;
            }
            $new->save();
            $this->data['id'] = $new->id;
        }
        $this->postSave($claims);
        return $this;
    }

    public static function delete(string $id, array $claims = [])
    {
        $item = ORM::for_table(static::$table)->find_one($id);
        $itemArray = $item->asArray();
        self::preDelete($itemArray, $claims);
        $item->delete();
        self::postDelete($itemArray, $claims);
    }

    public static function fetchResource(string $id, array $claims = []) : ?SqlResource {
        self::preGetResource($id, $claims);
        $query = ORM::forTable(static::$table);
        $columns = static::allColumns();
        foreach ($columns as $externalName => $internalName) {
            $query->select($internalName);
        }
        $class = static::class;

        $fetched = $query->findOne($id);
        $asArray = $fetched->asArray();

        $asArray = static::postGetResource($asArray, $claims);

        if ($fetched) {
            return new $class($asArray, $fetched);
        }
        return null;
    }

    protected static function formatValues($operator, $values) {
        $formattedValues = [];
        foreach ($values as $value) {
            if ($operator == 'cn') {
                $formattedValues[] = '%'.$value.'%';
            } else if ($operator == 'gt' || $operator == 'lt'){
                $formattedValues[] = floatval($value);
            } else if ($operator == 'is'){
                $formattedValues[] = ($value === 'true') ? 1 : 0;
            } else {
                $formattedValues[] = $value;
            }
        }
        return $formattedValues;
    }

    public static function fetchCollection(array $parameters, array $claims) : array {
        static::preGetCollection($parameters, $claims);
        $operatorMap = [
            'cn' => 'like',
            'eq' => '=',
            'ne' => '<>',
            'gt' => '>',
            'lt' => '<',
            'is' => '=',
        ];
        
        $query = ORM::forTable(static::$table);

        foreach ($parameters['columns'] as $column) {
            $query->select($column);
        }

        # apply filters
        foreach ($parameters['filters'] as $filter) {
            $operator = $operatorMap[$filter['operator']];
            $filterValues = self::formatValues($filter['operator'], $filter['values']);
            
            if (count($filter['fields']) === 1) {
                $field = $filter['fields'][0];
                $clauses = [];
                
                foreach ($filter['values'] as $value) {
                    $clauses[] = '`' . $parameters['allColumns'][$field] . '` '.$operator.' ?';
                }
                $filterValues = self::formatValues($filter['operator'], $filter['values']);
                $query->where_raw('('.implode(' or ', $clauses). ')', $filterValues);
            } else {
                foreach($filterValues as $filterValue) {
                    $values = [];
                    $clauses = [];
                    foreach ($filter['fields'] as $field) {
                        $clauses[] = '`' . $parameters['allColumns'][$field] . '` '.$operator.' ?';
                        $values[] = $filterValue;
                    }
                    $query->where_raw('('.implode(' or ', $clauses). ')', $values);
                }
                            
            }
        }

        # apply sorting
        if ($parameters['sortDirection'] === 'asc') {
            $query->orderByAsc($parameters['sortField']);
        } else {
            $query->orderByDesc($parameters['sortField']);
        }

        # determine the max page
        $maxPage = ceil($query->count() / $parameters['pageSize']);

        # apply page, pageSize
        $query
            ->limit($parameters['pageSize'])
            ->offset($parameters['pageNumber'] * $parameters['pageSize']);

        $data = $query->findArray();
        $data = static::postGetCollection($data, $claims);

        return [
            static::makeCollection($data),
            $maxPage,
            $parameters['pageNumber'] + 1,
            $parameters['pageSize'],
        ];
    }
}