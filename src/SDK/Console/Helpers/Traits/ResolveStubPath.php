<?php
namespace PactTraceSDK\SharedResources\SDK\Console\Helpers\Traits;

use PactTraceSDK\SharedResources\SDK\Foundation\Enums\StubsPathEnum;

trait ResolveStubPath
{
    /**
     * @param string $stub
     * @return string
     */
    public function resolveMigrationStubPath(string $stub): string
    {
        $published = base_path('stubs/' . $stub);

        return file_exists($published)
            ? $published
            : base_path(StubsPathEnum::MIGRATION->getFullPath() . $stub);
    }

    /**
     * @param string $stub
     * @return string
     */
    public function resolveControllerStubPath(string $stub): string
    {
        return base_path(StubsPathEnum::CONTROLLER->getFullPath() . $stub);
    }

    /**
     * @param string $stub
     * @return string
     */
    public function resolveModelStubPath(string $stub): string
    {
        return base_path(StubsPathEnum::MODEL->getFullPath() . $stub);
    }

    /**
     * @param string $stub
     * @return string
     */
    public function resolveFactoryStubPath(string $stub): string
    {
        return base_path(StubsPathEnum::FACTORY->getFullPath() . $stub);
    }

    public function resolveSeederStubPath(string $stub): string
    {
        return base_path(StubsPathEnum::SEEDER->getFullPath() . $stub);
    }

		public function resolveResourceStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::RESOURCE->getFullPath() . $stub);
		}

		public function resolveProviderStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::PROVIDER->getFullPath() . $stub);
		}

		public function resolveRequestStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::REQUEST->getFullPath() . $stub);
		}

		public function resolveObserverStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::OBSERVER->getFullPath() . $stub);
		}

		public function resolveMailStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::MAIL->getFullPath() . $stub);
		}

		public function resolveJobStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::JOB->getFullPath() . $stub);
		}

		public function resolveMiddlewareStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::MIDDLEWARE->getFullPath() . $stub);
		}

		public function resolvePolicyStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::POLICY->getFullPath() . $stub);
		}

		public function resolveScopeStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::SCOPE->getFullPath() . $stub);
		}

		public function resolveCommandStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::COMMAND->getFullPath() . $stub);
		}

		public function resolveNotificationStubPath(string $stub): string
		{
			return base_path(StubsPathEnum::NOTIFICATION->getFullPath() . $stub);
		}

}
