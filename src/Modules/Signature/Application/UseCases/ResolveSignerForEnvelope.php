<?php

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Signature\Application\Port\Repository\EnvelopeReadRepository;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Services\EnvelopeNotifier;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use Illuminate\Database\Eloquent\Collection;

class ResolveSignerForEnvelope
{
	public Envelope $envelope;
	protected Signer|Collection|null $signer = null;

	public function __construct(
		public EnvelopeReadRepository $envelopeReadRepository,
		public EnvelopeNotifier $notifier
	)
	{
	}

	public function handle(
		string $matterId,
		string $envelopeId
	)
	{
		$this->envelope = $this->envelopeReadRepository->getForMatter($matterId, $envelopeId);

		return $this;
	}

	public function signers()
	{
		$this->signer = $this->envelope->signers;

		return $this->signer;
	}

	public function filterByEmail(string $signerEmail)
	{
		$this->signer = $this->envelope?->signers->filter(fn ($signer) => $signer->email == $signerEmail)
			->values()
			->first();

		return $this->signer;
	}

	public function notify()
	{
		try {
			if ($this->signer && $this->signer instanceof Signer) {
				if ($this->signer->signing_token_hash) {
					$this->notifier->notifyCoSigners($this->envelope);
				} else {
					// It's a client
					$this->notifier->notifyClient($this->envelope);
				}
			} else {
				$this->signer->each(fn ($signer) => $signer->signing_token_hash ? $this->notifier->notifyCoSigners($this->envelope) : $this->notifier->notifyClient($this->envelope));
			}
		} catch (\Throwable $e) {
			\Log::error($e->getMessage());
		}
	}
}