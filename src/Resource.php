<?php

namespace JsonAPI;

abstract class Resource
{
    public static $type = null;
    public static $url = null;
    public static $attributeMap = [];
    public static $relationshipMap = [];
    public static $transformBooleans = [];
    public static $transformFloats = [];
    public static $transformInts = [];

    protected $data = [];
    protected $fetched = null;

    abstract public function save(array $claims) : Resource;
    abstract public static function delete(string $id, array $claims);
    abstract public static function fetchResource(string $id, array $claims) : ?Resource;
    abstract public static function fetchCollection(array $parameters, array $claims) : array;

    public function __construct(array $data = [], $fetched = null)
    {
        $this->data = $data;
        $this->fetched = $fetched;
    }

    public function merge(array $resource) : Resource {
        $attributes = $resource['attributes'] ?? [];
        foreach (static::$attributeMap as $internalName => $externalName) {
            if (isset($attributes[$externalName])) {
                $this->data[$internalName] = $attributes[$externalName];
            }
        }
        $relationships = $resource['relationships'] ?? [];
        foreach (static::$relationshipMap as $internalName => $config) {
            if (isset($relationships[$config[0]])) {
                $this->data[$internalName] = $relationships[$config[0]]['data']['id'];
            }
        }
        return $this;
    }

    public static function allColumns() : array {
        $columns = ['id' => 'id'];
        foreach (static::$attributeMap as $internalName => $externalName) {
            $columns[$externalName] = $internalName;
        }
        foreach (static::$relationshipMap as $internalName => $config) {
            $columns[$config[0]] = $internalName;
        }
        return $columns;
    }

    public function asJson($isFullResource = true)
    {
        $json = [
            'id' => $this->data['id'],
            'type' => static::$type,
        ];
        if ($isFullResource) {
            $attributes = self::makeAttributesJson($this->data);
            if (count($attributes) > 0) {
                $json['attributes'] = $attributes;
            }
            $relationships = self::makeRelationshipsJson($this->data);
            if (count($relationships) > 0) {
                $json['relationships'] = $relationships;
            }
        } else {
            return [
                'data' => $json,
                'links' => self::makeSelfLink($this->data['id'])
            ];
        }

        $json['links'] = self::makeSelfLink($this->data['id']);

        return $json;
    }

    public static function makeAttributesJson($data) {
        $attributes = [];
        foreach (static::$attributeMap as $internalName => $externalName) {
            $attributes[$externalName] = $data[$internalName] ?? null;
            if (in_array($internalName, static::$transformBooleans)) {
                $attributes[$externalName] = ($attributes[$externalName] === '1');
            }
            if (in_array($internalName, static::$transformFloats)) {
                $attributes[$externalName] = floatval($attributes[$externalName]);
            }
            if (in_array($internalName, static::$transformInts)) {
                $attributes[$externalName] = intval($attributes[$externalName]);
            }
        }
        return $attributes;
    }

    public static function makeRelationshipsJson($data) {
        $relationships = [];
        foreach (static::$relationshipMap as $internalName => $item) {
            $externalName = $item[0];
            $class = $item[1];
            if (isset($data[$internalName])) {
                $relationships[$externalName] = (new $class(['id' => $data[$internalName]]))->asJson(false);
            }
        }
        return $relationships;
    }

    public static function makeCollection($data) : array {
        $items = [];
        $class = static::class;
        foreach ($data as $item) {
            $items[] = (new $class($item))->asJson();
        }
        return $items;
    }

    public static function makeResource($id) {
        return [
            'data' => [
                'type' => static::$type,
                'id' => $id,
            ],
            'links' => self::makeSelfLink($id),
        ];
    }

    public static function makeSelfLink($id) {
        return [
            'self' => static::$url.$id,
        ];
    }

    public static function preDelete(array $data, array $claims = []) {}
    public static function postDelete(array $data, array $claims = []) {}
    public function preSave(array $claims = []) {}
    public function postSave(array $claims = []) {}
    public static function preGetCollection(array $parameters, array $claims = []) {}
    public static function postGetCollection(array $data, array $claims = []) : array {
        return $data;
    }
    public static function preGetResource(string $id, array $claims = []) {}
    public static function postGetResource(array $data, array $claims = []) : array {
        return $data;
    }
}