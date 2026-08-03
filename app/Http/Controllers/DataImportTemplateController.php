<?php

namespace App\Http\Controllers;

use App\DataImportType;
use App\Imports\ImportSchema;
use App\Models\DataImport;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataImportTemplateController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        DataImportType $type,
        ImportSchema $schema,
    ): StreamedResponse {
        Gate::authorize('create', DataImport::class);
        $template = $schema->template($type);
        $filename = $type === DataImportType::Customers
            ? 'modelo-clientes.csv'
            : 'modelo-produtos-servicos.csv';

        return response()->streamDownload(function () use ($template): void {
            $stream = fopen('php://output', 'w');

            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $template['headers'], ';', '"', '');
            fputcsv($stream, $template['sample'], ';', '"', '');
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
