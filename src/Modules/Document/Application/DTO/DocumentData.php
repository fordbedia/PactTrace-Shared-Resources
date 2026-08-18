<?php

namespace PactTraceSDK\SharedResources\Modules\Document\Application\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class DocumentData
{
	public function __construct(
		public UploadedFile $file,
		public int $provider_id,
		public int $uploaded_by,
		public ?int $matter_id,
		public ?int $client_id,
		public ?int $folder_id,
	)
	{}

	public static function fromRequest(
		int $provider_id,
		int $uploaded_by,
		?int $matter_id,
		?int $client_id,
		?int $folder_id,
		FormRequest $request
	): self
	{
		return new self(
			file: $request->file('file'),
			provider_id: $provider_id,
			uploaded_by: $uploaded_by,
			matter_id: $matter_id,
			client_id: $client_id,
			folder_id: $folder_id,
		);
	}
}
