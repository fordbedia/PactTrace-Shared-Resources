<?php

namespace PactTraceSDK\SharedResources\SDK\Database;

use PactTraceSDK\SharedResources\Modules\Invoicing\Repository\Contracts\ClientsContract;
use PactTraceSDK\SharedResources\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

abstract class RepositoryLayer
{
    protected Model $model;

    public function __construct()
    {
        $this->resolver();
    }
    public function resolver()
    {
        $model = app()->make($this->makeModel());
        if (!($model instanceof Model)) {
            throw new \RuntimeException('RepositoryLayer does not implement Model interface');
        }
        $this->model = $model;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    abstract public function makeModel(): string;

    public function find(int $id): ?Model
    {
        return $this->model->find($id);
    }

	public function destroy(int $id)
	{
		return $this->getModelByAuthUser()->whereKey($id)->delete();
	}

    public function findBy(string $field, string $value): self
    {
        $model = $this->model->where($field, $value)->first();

        return $model;
    }

    public function paginate(
        $query = null,
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        int|null $page = null
    ) {
        $query = $query ?: $this->model->newQuery();
        return $query->paginate($perPage, $columns, $pageName, $page);
    }

    public function create(array $data): Model|array
    {
        return $this->model->create($data);
    }

	public function update(array $data, ?int $id = null): Model|bool
	{
		if ($id) {
			return $this->model->whereKey($id)->update($data);
		}

		return $this->model->update($data);
	}

    public function isExists(string $field, string $value): bool
    {
        return $this->model->where($field, $value)->exists();
    }

	/**
	 * @deprecated
	 * @return Builder
	 */
	public function getByUser(): Builder
	{
		return $this->model->where('user_id', auth()->id());
	}

	/**
	 * @return Builder
	 */
	public function getModelByAuthUser(): Builder
	{
		if (! $this->model instanceof User) {
			return $this->model->where('user_id', auth()->id());
		}
		return $this->model->whereKey(auth()->id());
	}
}
