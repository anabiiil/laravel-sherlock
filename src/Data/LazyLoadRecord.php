<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

/**
 * One Eloquent relation lazy loaded on a collection (Model::preventLazyLoading() violation),
 * deduplicated per trace: "App\Models\User::posts" lazy loaded $count times.
 */
final readonly class LazyLoadRecord
{
    /**
     * @param  class-string|string  $model  parent model class
     * @param  string|null  $table  table of the related model, when it could be resolved
     */
    public function __construct(
        public string $model,
        public string $relation,
        public int $count = 1,
        public ?string $table = null,
    ) {}

    /** "User::posts" */
    public function shortName(): string
    {
        $position = strrpos($this->model, '\\');

        return ($position === false ? $this->model : substr($this->model, $position + 1)).'::'.$this->relation;
    }

    /**
     * @return array{model: string, relation: string, count: int, table: string|null}
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'relation' => $this->relation,
            'count' => $this->count,
            'table' => $this->table,
        ];
    }
}
