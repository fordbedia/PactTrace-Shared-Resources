<?php

namespace PactTraceSDK\SharedResources\SDK\Exception;

use Exception;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class ApiException extends Exception implements Responsable
{
	protected int $status;
	protected ?string $type;
	protected array $context;

	public function __construct(
		string $message,
		int $status = Response::HTTP_INTERNAL_SERVER_ERROR,
		?string $type = null,              // e.g. 'validation_failed', 'unauthorized', 'payment_required'
		array $context = [],
		?Throwable $previous = null
	) {
		parent::__construct($message, $status, $previous);
		$this->status  = $status;
		$this->type    = $type;
		$this->context = $context;
	}

	public function getStatus(): int
	{
		return $this->status;
	}

	public function getType(): ?string
	{
		return $this->type;
	}

	public function toResponse($request)
	{
		/** @var Request $request */
		$payload = [
			'exception' => static::class,
			'message' => $this->getMessage(),
			'status'  => $this->status,
			'type'    => $this->type,
		];

		// Only include trace/context when debugging locally
		if (config('app.debug')) {
			$payload['trace'] = $this->getTrace();
			if (!empty($this->context)) {
				$payload['context'] = $this->context;
			}
		}

		// Content negotiation: JSON for APIs; HTML view for browsers
		if ($request->expectsJson() || $request->wantsJson() || $request->is('api/*')) {
			return response()
				->json($payload, $this->status); // Content-Type: application/json
		}

		// Render an HTML error page (make a Blade at resources/views/errors/api.blade.php)
		return response()
			->view('errors.api', [
				'message' => $this->getMessage(),
				'status'  => $this->status,
				'type'    => $this->type,
			], $this->status);
	}
}
