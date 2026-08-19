<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\DTO;

use Illuminate\Http\Request;

final readonly class ClientSearchData
{
	public function __construct(
		public int $provider_id,
		public string $search,
		public int $limit,
	)
	{}

	public static function fromRequest(Request $request, int $provider_id): self
	{
		return new self(
			$provider_id,
			trim((string) $request->query('search', '')),
			min(20, max(5, (int) $request->query('limit', 5))),
		);
	}
}
