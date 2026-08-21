<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\DTO;

use Illuminate\Foundation\Http\FormRequest;

class MattersData
{
	public function __construct(
		public ?int $id,
		public ?int $provider_id,
		public int $workspace_id,
		public int $client_id,
		public string $name,
		public ?string $description,
		public string $status,
		public ?string $start_date,
		public ?string $due_date,
	)
	{}

	public static function fromRequest(int $providerId, int $workspaceId, FormRequest $request)
	{
		$data = $request->validated();

		return new self(
			id: $data['id'],
			provider_id: $providerId,
			workspace_id: $workspaceId,
			client_id: $data['client_id'],
			name: $data['name'],
			description: $data['description'],
			status: $data['status'],
			start_date: $data['start_date'],
			due_date: $data['due_date'],
		);
	}
}