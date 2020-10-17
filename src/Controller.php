<?php

namespace JsonAPI;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

class Controller
{
    public static $ResourceClass;

    public static function parseParameters(array $params = [], $allColumns, $defaultSortField, $defaultSortDirection)
    {
        $columns = $allColumns;
        $filters = [];
        $sortField = $defaultSortField;
        $sortDirection = $defaultSortDirection;
        $pageNumber = 1;
        $pageSize = DEFAULT_PAGE_SIZE;

        foreach (($params['filter'] ?? []) as $filter) {
            $parts = explode('.', $filter);
            $filters[] = [
                'fields' => explode('|', $parts[0]),
                'operator' => $parts[1],
                'values' => explode('|', $parts[2]),
            ];
        }

        # Determine page
        if (isset($params['page'])) {
            $pageNumber = intval($params['page']['number'] ?? $pageNumber);
            $pageSize = intval($params['page']['size'] ?? $pageSize);
        }
        $pageNumber--;

        if (isset($params['sort'])) {
            $sortField = $params['sort'];
            if (substr($sortField, 0, 1) === '-') {
                $sortField = substr($sortField, 1);
                $sortDirection = 'desc';
            }
            $sortField = $allColumns[$sortField];
        }

        return [
            'columns' => array_values($columns),
            'allColumns' => $allColumns,
            'filters' => $filters,
            'sortField' => $sortField,
            'sortDirection' => $sortDirection,
            'pageNumber' => $pageNumber,
            'pageSize' => $pageSize,
        ];
    }

    public function response() : ResponseInterface {
        return new \Laminas\Diactoros\Response;
    }

    public function data(ServerRequestInterface $request) : array {
        if ($request->getMethod() === 'GET') {
            return $request->getQueryParams();
        }
        return json_decode($request->getBody()->getContents(), true);
    }

    public function success(array $data = [], array $meta = []) : ResponseInterface {
        return new \Laminas\Diactoros\Response\JsonResponse([
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    public function failure(array $errors = [], array $meta = []) : ResponseInterface {
        return new \Laminas\Diactoros\Response\JsonResponse([
            'errors' => $errors,
            'meta' => $meta,
        ]);
    }

    public function notFound() : \Exception {
        return new \League\Route\Http\Exception\NotFoundException();
    }

    public function handleGetOne(ServerRequestInterface $request, array $values) : ResponseInterface {
        $resourceClass = static::$ResourceClass;
        $resource = $resourceClass::fetchResource($values['id']);
        if ($resource) {
            return $this->success($resource->asJson());            
        }
        return $this->failure(['notfound']);
    }

    public function handleGetMany(ServerRequestInterface $request) : ResponseInterface {
        $claims = $request->getAttribute('claims');
        $resourceClass = static::$ResourceClass;
        $parameters = self::parseParameters(
            $request->getQueryParams(),
            $resourceClass::allColumns(),
            'id',
            'asc'
        );
        [
            $data,
            $maxPage,
            $pageNumber,
            $pageSize,
        ] = $resourceClass::fetchCollection($parameters, $claims);
        
        return $this->success(
            $data,
            [
                'maxPage' => $maxPage,
                'page' => [
                    'number' => intval($pageNumber),
                    'size' => intval($pageSize),
                ],
            ]
        );
    }

    public function handlePatch(ServerRequestInterface $request, array $values) : ResponseInterface {
        $resourceClass = static::$ResourceClass;
        $claims = $request->getAttribute('claims');
        $update = $request->getParsedBody()['data'];
        $resource = $resourceClass::fetchResource($values['id'], $claims);
        $resource->merge($update);
        $resource->save($claims);
        return $this->success($resource->asJson());
    }

    public function handleDelete(ServerRequestInterface $request, array $values) : ResponseInterface {
        $resourceClass = static::$ResourceClass;
        $claims = $request->getAttribute('claims');
        $resourceClass::delete($values['id'], $claims);
        return $this->success();
    }
    
    public function handlePost(ServerRequestInterface $request) : ResponseInterface {
        $resourceClass = static::$ResourceClass;
        $claims = $request->getAttribute('claims');
        $data = $request->getParsedBody()['data'];
        $resource = new $resourceClass();
        $resource->merge($data);
        $resource->save($claims);
        return $this->success($resource->asJson());
    }
}