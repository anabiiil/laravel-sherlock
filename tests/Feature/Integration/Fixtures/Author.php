<?php

declare(strict_types=1);

namespace Anabil\Tracing\Tests\Feature\Integration\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 */
final class Author extends Model
{
    protected $table = 'integration_authors';

    public $timestamps = false;

    protected $guarded = [];

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
