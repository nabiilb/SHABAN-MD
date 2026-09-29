<?php

namespace App\Policies;

use App\Domain\Workflow;
use App\Models\DentalCase;
use App\Models\User;

/** Row-level access to a case and its workflow steps — the shared rules the web app uses to show buttons. */
class DentalCasePolicy
{
    public function view(User $user, DentalCase $case): bool
    {
        return Workflow::canViewCase($user->actor(), ['technicianId' => $case->technician_id, 'clinicId' => $case->clinic_id]);
    }

    /** One workflow action (accept, assign, qc_pass …) on this case, from its current status. */
    public function perform(User $user, DentalCase $case, string $action): bool
    {
        return Workflow::canPerformAction($action, ['status' => $case->status, 'technicianId' => $case->technician_id, 'clinicId' => $case->clinic_id], $user->actor());
    }
}
