<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\DisciplinaryCase;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Support\Pdf\AuthorizesHrDocuments;
use App\Modules\HR\Support\Pdf\Documents\CertificateOfServiceDocument;
use App\Modules\HR\Support\Pdf\Documents\DisciplinaryLetterDocument;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues HR PDF documents (certificates and disciplinary correspondence).
 *
 * Authorization is centralised via AuthorizesHrDocuments — HR-privileged users,
 * with an ownership exception so an employee may pull their own certificate.
 */
class HrDocumentController extends Controller
{
    use AuthorizesHrDocuments;

    /**
     * Certificate of Service (Employment Act s.51). Available for terminated or
     * serving employees; withTrashed() so leavers (soft-deleted) are reachable.
     */
    public function certificateOfService(int $employee): Response
    {
        $model = Employee::withTrashed()->findOrFail($employee);

        $this->ensureHrAccessOrOwner($model->id);

        return (new CertificateOfServiceDocument($model))->download();
    }

    /**
     * Disciplinary letter: show_cause | warning | outcome. HR-only.
     */
    public function disciplinaryLetter(int $case, string $type): Response
    {
        abort_unless(in_array($type, DisciplinaryLetterDocument::TYPES, true), 404);

        $this->ensureHrAccess();

        $model = DisciplinaryCase::findOrFail($case);

        return (new DisciplinaryLetterDocument($model, $type))->download();
    }
}
