<?php

namespace PactTraceSDK\SharedResources\Modules\Document\Application\DTO;

use Illuminate\Foundation\Http\FormRequest;

class FolderData
{
	public function __construct(
		public ?int $id,
		public ?int $parent_id,
		public int $provider_id,
		public int $client_id,
		public int $matter_id,
		public string $name,
	)
	{}

	public static function fromRequest(
		?int $id,
		int $provider_id,
		int $client_id,
		int $matter_id,
		?int $parent_id,
		FormRequest $request
	)
	{
		$data = $request->validated();
		return new self(
			id: $id,
			parent_id: $parent_id,
			provider_id: $provider_id,
			client_id: $client_id,
			matter_id: $matter_id,
			name: $data['name'],
		);
	}
}