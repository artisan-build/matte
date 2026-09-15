<?php

declare(strict_types=1);

namespace ArtisanBuild\MatteServer;

use ArtisanBuild\MatteContracts\JobStatus;
use ArtisanBuild\MatteServer\Database\Factories\MatteJobFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $input_ref
 * @property string|null $output_ref
 * @property string $mode
 * @property string $preset
 * @property string|null $model
 * @property JobStatus $status
 * @property string|null $error
 */
final class MatteJob extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'input_ref',
        'output_ref',
        'mode',
        'preset',
        'model',
        'status',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
        ];
    }

    protected static function newFactory(): MatteJobFactory
    {
        return MatteJobFactory::new();
    }
}
