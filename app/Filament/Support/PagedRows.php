<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Turns a read service's page of rows into what a custom-data table expects.
 *
 * Rows are keyed by their `id`, which Filament uses as the record key across
 * Livewire requests.
 */
final class PagedRows
{
    /**
     * @param  array{rows: list<array<string, mixed>>, total: int}  $page
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginate(array $page, int $currentPage, int $perPage, string $pageName): LengthAwarePaginator
    {
        $keyed = [];

        foreach ($page['rows'] as $row) {
            $keyed[(int) $row['id']] = $row;
        }

        return new LengthAwarePaginator($keyed, $page['total'], $perPage, $currentPage, ['pageName' => $pageName]);
    }
}
