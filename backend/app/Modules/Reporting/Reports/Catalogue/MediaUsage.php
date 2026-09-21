<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-M01 Media usage: the media items uploaded in the period that completed (not pending uploads), by
 * type, folder, uploader or date, with the storage they use. Items in the trash still use storage until
 * they are purged, so they count.
 */
final class MediaUsage extends SqlReport
{
    public function key(): string
    {
        return 'rpt-m01';
    }

    public function title(): string
    {
        return 'Media usage';
    }

    public function description(): string
    {
        return 'Media items uploaded in the period and the storage they use, by type, folder or uploader.';
    }

    public function group(): string
    {
        return 'media';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['media.view'];
    }

    public function defaultDimension(): string
    {
        return 'type';
    }

    protected function orderBy(): string
    {
        return 'sum(m.size_bytes) DESC NULLS LAST, 1';
    }

    protected function source(): string
    {
        return "(SELECT * FROM media_items WHERE state <> 'pending') m";
    }

    protected function tenantColumn(): string
    {
        return 'm.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'm.created_at';
    }

    public function dimensions(): array
    {
        return [
            'type' => new Dimension('Type', "split_part(m.mime_type, '/', 1)"),
            'mime_type' => new Dimension('File type', 'm.mime_type'),
            'folder' => new Dimension('Folder', 'm.folder_id', 'media_folders'),
            'uploader' => new Dimension('Uploader', 'm.uploaded_by_user_id', 'users'),
            'day' => Dimension::day('m.created_at'),
            'month' => Dimension::month('m.created_at'),
        ];
    }

    public function measures(): array
    {
        return [
            'items' => Measure::count('Items'),
            'bytes' => new Measure('Storage used', 'coalesce(sum(m.size_bytes), 0)', 'bytes'),
            'trashed' => Measure::count('In the trash', 'm.trashed_at IS NOT NULL'),
        ];
    }

    public function filters(): array
    {
        return [
            'folder' => new Filter('Folder', 'm.folder_id', labels: 'media_folders'),
        ];
    }
}
