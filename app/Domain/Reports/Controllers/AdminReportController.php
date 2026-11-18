<?php

declare(strict_types=1);

namespace App\Domain\Reports\Controllers;

use App\Domain\Reports\Actions\GetAdminReportAction;
use App\Domain\Reports\Requests\AdminReportRequest;
use App\Support\InertiaControllers;
use Inertia\Response;

class AdminReportController extends InertiaControllers
{
    public function __invoke(AdminReportRequest $request): Response
    {
        return $this->render('admin/Report', [
            'report' => GetAdminReportAction::run($request->start(), $request->end()),
            'maxDays' => GetAdminReportAction::MAX_DAYS,
        ]);
    }
}
