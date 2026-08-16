<?php

namespace App\Modules\Reports\Services;

use App\Support\Tenancy\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ReportPdfExportService
{
    private const RECORD_PREVIEW_LIMIT = 12;

    public function __construct(
        private readonly ReportAnalysisService $analysis,
        private readonly ReportPdfPresenter $presenter,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function download(string $module, int $year, array $filters): Response
    {
        $viewData = $this->viewData($module, $year, $filters);
        $tenantName = $viewData['tenant_name'];
        $report = $viewData['report'];
        $filename = sprintf('%s-analysis-%d.pdf', Str::slug($module), $year);

        /** @var DomPdfDocument $pdf */
        $pdf = Pdf::loadView('reports.analysis-pdf', $viewData)
            ->setPaper('a4', 'landscape')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false)
            ->addInfo([
                'Title' => sprintf('%s - %s - %d', $tenantName, $report['title'], $year),
                'Author' => $tenantName.' via FruitionHR',
                'Subject' => 'Filtered HR report analysis and record preview',
                'Keywords' => 'FruitionHR, '.$module.', report, analysis',
            ]);

        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $footerColour = [0.39, 0.45, 0.55];
        $canvas->page_text(
            32,
            $canvas->get_height() - 18,
            'Generated securely by FruitionHR | '.$tenantName,
            $font,
            7.5,
            $footerColour,
        );
        $canvas->page_text(
            ($canvas->get_width() / 2) - 55,
            $canvas->get_height() - 18,
            'Confidential company report',
            $font,
            7.5,
            $footerColour,
        );
        $canvas->page_text(
            $canvas->get_width() - 112,
            $canvas->get_height() - 18,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            7.5,
            $footerColour,
        );

        $response = $pdf->download($filename);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function viewData(string $module, int $year, array $filters): array
    {
        $tenant = $this->currentTenant->get();

        return [
            'tenant_name' => $tenant?->name ?? 'Company',
            'tenant_initial' => Str::upper(Str::substr($tenant?->name ?? 'F', 0, 1)),
            'logo_data_uri' => $this->tenantLogoDataUri(),
            'report' => $this->presenter->present(
                $this->analysis->build($module, $year, $filters, self::RECORD_PREVIEW_LIMIT),
            ),
        ];
    }

    private function tenantLogoDataUri(): ?string
    {
        $tenant = $this->currentTenant->get();
        $disk = Storage::disk('local');

        if (! $tenant?->logo_path || ! $disk->exists($tenant->logo_path)) {
            return null;
        }

        $mime = $disk->mimeType($tenant->logo_path) ?: '';
        if (! in_array($mime, ['image/gif', 'image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($tenant->logo_path));
    }
}
