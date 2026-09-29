<?php

namespace App\Domain;

/**
 * Labels, enumerations and defaults — the PHP side of packages/shared/src/constants.ts.
 * tests/Unit/SharedRulesParityTest asserts every value against the TypeScript export.
 */
final class Catalog
{
    public const CASE_TYPE_LABELS = ['crown' => 'Crown', 'bridge' => 'Bridge', 'veneer' => 'Veneer', 'implant' => 'Implant', 'denture' => 'Denture', 'appliance' => 'Appliance'];

    public const PRIORITY_META = [
        'normal' => ['label' => 'Normal', 'tone' => 'neutral'],
        'high' => ['label' => 'High', 'tone' => 'warning'],
        'urgent' => ['label' => 'Emergency', 'tone' => 'danger'],
    ];

    public const PAYMENT_STATUSES = ['unpaid', 'partial', 'paid', 'overdue'];

    public const PAYMENT_METHOD_LABELS = ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'card' => 'Card', 'other' => 'Other'];

    public const DELIVERY_METHOD_LABELS = ['clinic_pickup' => 'Clinic pickup', 'lab_courier' => 'Lab courier', 'third_party' => 'Third-party delivery'];

    public const DELIVERY_STATUSES = ['ready', 'out_for_delivery', 'delivered'];

    public const ATTACHMENT_CATEGORY_LABELS = ['photo' => 'Photo', 'scan' => 'Dental scan (STL)', 'xray' => 'X-Ray', 'prescription' => 'Prescription', 'document' => 'Document', 'production' => 'Production', 'qc' => 'QC'];

    public const QC_ISSUE_LABELS = ['fit' => 'Fit / seating', 'margins' => 'Margins', 'occlusion' => 'Occlusion', 'contacts' => 'Contacts', 'shade' => 'Shade mismatch', 'contour' => 'Contour / anatomy', 'finish' => 'Surface finish / glaze', 'other' => 'Other'];

    public const RECORD_STATUSES = ['active', 'inactive'];

    public const GENDERS = ['male', 'female'];

    public const SERVICE_UNIT_MODES = ['tooth', 'denture', 'arch'];

    public const DENTURE_TYPES = [
        ['value' => 'full_upper', 'label' => 'Full Upper', 'units' => 1],
        ['value' => 'full_lower', 'label' => 'Full Lower', 'units' => 1],
        ['value' => 'upper_lower', 'label' => 'Upper + Lower', 'units' => 2],
        ['value' => 'partial', 'label' => 'Partial', 'units' => 1],
    ];

    /** File rules from the prototype: JPG · JPEG · PNG · PDF · STL …, max 50 MB (MAX_UPLOAD_MB may lower it). */
    public const ALLOWED_FILE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'stl', 'ply', 'obj', 'dcm', 'doc', 'docx'];

    public const MAX_FILE_MB = 50;

    /** Settings used until an administrator saves their own (Settings → Lab profile & SLA). */
    public const DEFAULT_LAB_SETTINGS = [
        'labName' => '48HRS Dental Lab',
        'phone' => '',
        'email' => '',
        'address' => '',
        'currency' => 'USD',
        'slaHours' => 48,
        'atRiskHours' => 12,
        'criticalHours' => 4,
        'emergencyFeePerUnit' => 5,
        'invoiceDueDays' => 14,
    ];

    public static function caseTypes(): array
    {
        return array_keys(self::CASE_TYPE_LABELS);
    }

    public static function priorities(): array
    {
        return array_keys(self::PRIORITY_META);
    }

    public static function paymentMethods(): array
    {
        return array_keys(self::PAYMENT_METHOD_LABELS);
    }

    public static function deliveryMethods(): array
    {
        return array_keys(self::DELIVERY_METHOD_LABELS);
    }

    public static function qcIssues(): array
    {
        return array_keys(self::QC_ISSUE_LABELS);
    }

    public static function attachmentCategories(): array
    {
        return array_keys(self::ATTACHMENT_CATEGORY_LABELS);
    }

    public static function dentureTypeValues(): array
    {
        return array_column(self::DENTURE_TYPES, 'value');
    }

    public static function extensionOf(string $name): string
    {
        $parts = explode('.', $name);

        return count($parts) > 1 ? strtolower((string) array_pop($parts)) : '';
    }

    public static function categoryForExtension(string $ext): string
    {
        return match (true) {
            in_array($ext, ['stl', 'ply', 'obj'], true) => 'scan',
            $ext === 'dcm' => 'xray',
            $ext === 'pdf' => 'prescription',
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) => 'photo',
            default => 'document',
        };
    }

    /** An error message, or null when the file is acceptable (size limit in MB). */
    public static function validateFile(string $name, int $size, int $maxMb = self::MAX_FILE_MB): ?string
    {
        $ext = self::extensionOf($name);
        if (! in_array($ext, self::ALLOWED_FILE_EXTENSIONS, true)) {
            return '.'.($ext !== '' ? $ext : 'unknown').' files are not accepted.';
        }
        if ($size > $maxMb * 1_048_576) {
            return "Larger than the {$maxMb} MB limit.";
        }

        return null;
    }
}
