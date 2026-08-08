<?php

namespace PactTraceSDK\SharedResources\SDK\Exception;

use Exception;

class RepositoryException extends Exception
{
    protected int $status;
    protected $message;

    public function __construct(string $message, int $status = 500)
    {
        $this->message = $message;
        parent::__construct($message, $status);
        $this->status = $status;
    }

    public function render($request)
    {
        $response = [
            'message' => $this->getMessage(),
            'status' => $this->status,
            'trace' => $this->getTrace(),
        ];

        return response()->json($response, $this->status)->header('Content-Type', 'text/html');
    }
}