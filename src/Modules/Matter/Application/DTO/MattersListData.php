<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Application\DTO;

use Illuminate\Http\Request;

final readonly class MattersListData
{
	public function __construct(
		public int $provider_id,
		public string $filter,
		public int $per_page,
		public ?int $page
	)
	{}

	public static function fromRequest(Request $request, int $provider_id): self
	{
		return new self(
			$provider_id,
			(string) $request->query('filter', 'all'),
			(int) $request->query('per_page', 15),
			$request->filled('page') ? (int) $request->query('page') : null
		);
	}
}
