<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Controller;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\GraphQl\Exception\ExceptionFormatter;
use Magento\Framework\GraphQl\Query\QueryProcessor;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\SchemaGeneratorInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\GraphQl\Query\Fields as QueryFields;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Front controller for web API GraphQL area.
 *
 * @api
 */
class GraphQl implements FrontControllerInterface
{
    /**
     * @var SchemaGeneratorInterface
     */
    private $schemaGenerator;

    /**
     * @var SerializerInterface
     */
    private $jsonSerializer;

    /**
     * @var QueryProcessor
     */
    private $queryProcessor;

    /**
     * @var ExceptionFormatter
     */
    private $graphQlError;

    /**
     * @var ContextInterface
     */
    private $resolverContext;

    /**
     * @var HttpRequestProcessor
     */
    private $requestProcessor;

    /**
     * @var QueryFields
     */
    private $queryFields;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @param SchemaGeneratorInterface $schemaGenerator
     * @param SerializerInterface $jsonSerializer
     * @param QueryProcessor $queryProcessor
     * @param ExceptionFormatter $graphQlError
     * @param ContextInterface $resolverContext
     * @param HttpRequestProcessor $requestProcessor
     * @param QueryFields $queryFields
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        SchemaGeneratorInterface $schemaGenerator,
        SerializerInterface $jsonSerializer,
        QueryProcessor $queryProcessor,
        ExceptionFormatter $graphQlError,
        ContextInterface $resolverContext,
        QueryFields $queryFields,
        HttpRequestProcessor $requestProcessor,
        JsonFactory $jsonFactory
    ) {
        $this->schemaGenerator = $schemaGenerator;
        $this->jsonSerializer = $jsonSerializer;
        $this->queryProcessor = $queryProcessor;
        $this->graphQlError = $graphQlError;
        $this->resolverContext = $resolverContext;
        $this->requestProcessor = $requestProcessor;
        $this->queryFields = $queryFields;
        $this->jsonFactory = $jsonFactory;
    }

    /**
     * Handle GraphQL request
     *
     * @param RequestInterface $request
     * @return ResultInterface
     */
    public function dispatch(RequestInterface $request)
    {
        $statusCode = 200;
        $jsonResult = $this->jsonFactory->create();
        try {
            /** @var Http $request */
            $this->requestProcessor->validateRequest($request);

            $data = $this->getDataFromRequest($request);
            $query = $data['query'] ?? '';
            $variables = $data['variables'] ?? null;

            // We must extract queried field names to avoid instantiation of unnecessary fields in webonyx schema
            // Temporal coupling is required for performance optimization
            $this->queryFields->setQuery($query, $variables);
            $schema = $this->schemaGenerator->generate();

            $result = $this->queryProcessor->process(
                $schema,
                $query,
                $this->resolverContext,
                $data['variables'] ?? []
            );
        } catch (\Exception $error) {
            $result['errors'] = isset($result) && isset($result['errors']) ? $result['errors'] : [];
            $result['errors'][] = $this->graphQlError->create($error);
            $statusCode = ExceptionFormatter::HTTP_GRAPH_QL_SCHEMA_ERROR_STATUS;
        }

        $jsonResult->setHttpResponseCode($statusCode);
        $jsonResult->setData($result);
        return $jsonResult;
    }

    /**
     * Get data from request body or query string
     *
     * @param RequestInterface $request
     * @return array
     */
    private function getDataFromRequest(RequestInterface $request) : array
    {
        /** @var Http $request */
        if ($request->isPost()) {
            $data = $this->jsonSerializer->unserialize($request->getContent());
        } elseif ($request->isGet()) {
            $data = $request->getParams();
            $data['variables'] = isset($data['variables']) ?
                $this->jsonSerializer->unserialize($data['variables']) : null;
        } else {
            return [];
        }

        return $data;
    }
}
