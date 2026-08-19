<?php

namespace PactTrackSDK\SharedResources\SDK\Foundation\Enums;

enum StubsPathEnum: string
{
    case MIGRATION = 'migration.';
    case CONTROLLER = 'controller.';
    case MODEL = 'model.';
    case FACTORY = 'factory.';
    case SEEDER = 'seeder.';
	case RESOURCE = 'resource.';
	case PROVIDER = 'provider.';

	case MAIL = 'mail.';

	case JOB = 'job.queued.';

	case OBSERVER = 'observer.';

	case REQUEST = 'request.';

	case MIDDLEWARE = 'middleware.';

	case POLICY = 'policy.';

	case SCOPE = 'scope.';

	case COMMAND = 'console.';

	CASE NOTIFICATION = 'notification.';

    public function getFullPath()
    {
        return '/vendor/pacttrack/shared-resources/src/SDK/Foundation/stubs/' . $this->value;
    }
}
